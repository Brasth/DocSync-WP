import { speak } from '@wordpress/a11y';
import { useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
  getGoogleAccount,
  getGoogleAuthUrl,
  getWorkspace,
  listFolderWatches,
  listSources,
  syncAllSources,
  syncSource,
  type FolderWatchRecord,
  type GoogleAccount,
  type SourceRecord,
  type SyncResult,
  type WorkspaceResponse
} from '../api';
import { AdminApiError } from '../api/client';
import { listContent } from '../api/journey-api';
import type { ContentItem, ContentKind, ContentOrderBy, ImportCommitResult } from '../api/journey-types';
import { getAdminConfig } from '../config';
import { readMatchUrlState, writeMatchUrlState } from '../features/add-content/link-existing-posts';
import { type AddContentView, consumeOAuthReturn } from '../features/add-content/use-add-content';
import type { SourceListFilters } from '../features/sources/sources-table';
import type { AdminNoticeState } from '../shared/ui/admin-notice';
import { useSourceSyncProgress } from './use-source-sync-progress';

const sourcePageSize = 100;
const emptyAccount: GoogleAccount = { connected: false, hasRequiredScope: false };
const CONTENT_KINDS: readonly ContentKind[] = ['all', 'google', 'oneTime'];
const CONTENT_ORDER_BY: readonly ContentOrderBy[] = ['modified', 'title', 'date'];

/**
 * Sources lists Google-linked posts and one-time imports in one server-paged listing
 * (`GET /content`). Sync status and folder filters only describe Google sources, so those
 * two filters list through the legacy `/sources` route, which pages and sorts on the server too.
 */
export type SourcesContentFilters = SourceListFilters & {
  kind: ContentKind;
  orderBy: ContentOrderBy;
  order: 'asc' | 'desc';
};

export type SourcesContentRow =
  | { kind: 'google'; postId: number; importedFrom: { format: 'docx'; originalName: string; importedAt: string } | null }
  | { kind: 'oneTime'; postId: number; item: ContentItem };

export type SourcesListingMode = 'content' | 'sources';

export type MatchViewState = { open: boolean; jobId: string | null };

export const usesLegacySourcesListing = (filters: SourcesContentFilters, contentAvailable: boolean): boolean => {
  return !contentAvailable || filters.status !== '' || filters.folderWatchId !== '';
};

const readSourceFiltersFromUrl = (): SourcesContentFilters => {
  const params = new URL(window.location.href).searchParams;
  const kind = params.get('kind');
  const orderBy = params.get('orderby');

  return {
    search: params.get('search') || '',
    postType: params.get('post_type') || '',
    status: params.get('status') || '',
    folderWatchId: params.get('folder_watch_id') || '',
    kind: CONTENT_KINDS.find((candidate) => candidate === kind) ?? 'all',
    orderBy: CONTENT_ORDER_BY.find((candidate) => candidate === orderBy) ?? 'modified',
    order: params.get('order') === 'asc' ? 'asc' : 'desc'
  };
};

/** Bulk linking opens from its URL state or from a matching OAuth continuation. */
const readInitialMatchView = (): MatchViewState => {
  const params = new URL(window.location.href).searchParams;

  if (params.get('docsync_resume') === 'matching') {
    const oauth = consumeOAuthReturn();

    if (oauth.resumeKind === 'matching' && oauth.resumeId) {
      writeMatchUrlState({ open: true, jobId: oauth.resumeId });
      return { open: true, jobId: oauth.resumeId };
    }
  }

  return readMatchUrlState();
};

const writeSourceFiltersToUrl = (filters: SourcesContentFilters) => {
  const url = new URL(window.location.href);
  const values = {
    search: filters.search,
    post_type: filters.postType,
    status: filters.status,
    folder_watch_id: filters.folderWatchId,
    kind: filters.kind === 'all' ? '' : filters.kind,
    orderby: filters.orderBy === 'modified' ? '' : filters.orderBy,
    order: filters.order === 'desc' ? '' : filters.order
  };

  Object.entries(values).forEach(([key, value]) => {
    if (value) {
      url.searchParams.set(key, value);
    } else {
      url.searchParams.delete(key);
    }
  });

  window.history.replaceState(window.history.state, '', url.toString());
};

export const useSourcesApp = () => {
  const config = useMemo(() => getAdminConfig(), []);
  const [workspace, setWorkspace] = useState<WorkspaceResponse | null>(null);
  const [account, setAccount] = useState<GoogleAccount>(emptyAccount);
  const [sources, setSources] = useState<SourceRecord[]>([]);
  const [rows, setRows] = useState<SourcesContentRow[]>([]);
  const [listingMode, setListingMode] = useState<SourcesListingMode>('content');
  const [contentTruncated, setContentTruncated] = useState(false);
  const [folderWatches, setFolderWatches] = useState<FolderWatchRecord[]>([]);
  const [sourcePage, setSourcePage] = useState(1);
  const [sourceFilters, setSourceFilters] = useState<SourcesContentFilters>(readSourceFiltersFromUrl);
  const [hasMoreSources, setHasMoreSources] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);
  const [sourceModalOpen, setSourceModalOpen] = useState(false);
  const [sourceIntent, setSourceIntent] = useState<'folder' | 'document'>('folder');
  const [sourceView, setSourceView] = useState<AddContentView>('google');
  const [matchView, setMatchView] = useState<MatchViewState>(readInitialMatchView);
  const contentAvailable = useRef(true);
  const [activationSource, setActivationSource] = useState<SourceRecord | null>(null);
  const sourceSync = useSourceSyncProgress(setSources, setNotice);
  const sourceModalTrigger = useRef<HTMLElement | null>(null);
  const restoreModalFocus = useRef(true);
  const requestGeneration = useRef(0);
  const sourceFiltersRef = useRef(sourceFilters);

  type ListingPage = { mode: SourcesListingMode; sources: SourceRecord[]; rows: SourcesContentRow[]; hasMore: boolean; truncated: boolean };

  const legacyListingPage = async (filters: SourcesContentFilters, page: number): Promise<ListingPage> => {
    const response = await listSources({ ...filters, page, perPage: sourcePageSize });

    return {
      mode: 'sources',
      sources: response.sources,
      rows: response.sources.map((source): SourcesContentRow => ({ kind: 'google', postId: source.postId, importedFrom: null })),
      hasMore: Boolean(response.has_more ?? response.hasMore),
      truncated: false
    };
  };

  /* One listing per query: `/content` for every kind, or `/sources` for Google-only status and folder filters. */
  const listingPage = async (filters: SourcesContentFilters, page: number): Promise<ListingPage> => {
    if (usesLegacySourcesListing(filters, contentAvailable.current)) {
      return legacyListingPage(filters, page);
    }

    try {
      const response = await listContent({
        page,
        perPage: sourcePageSize,
        kind: filters.kind,
        postType: filters.postType,
        search: filters.search,
        orderBy: filters.orderBy,
        order: filters.order
      });
      const listedSources: SourceRecord[] = [];
      const listedRows = response.items.map((item): SourcesContentRow => {
        if (item.provenance.kind === 'google') {
          listedSources.push(item.provenance.source);
          return { kind: 'google', postId: item.postId, importedFrom: item.provenance.importedFrom };
        }

        return { kind: 'oneTime', postId: item.postId, item };
      });

      return { mode: 'content', sources: listedSources, rows: listedRows, hasMore: response.hasMore, truncated: response.truncated };
    } catch (caught) {
      // Without the Journey 2 routes the site still lists its Google sources.
      if (caught instanceof AdminApiError && caught.code === 'rest_no_route') {
        contentAvailable.current = false;
        return legacyListingPage(filters, page);
      }

      throw caught;
    }
  };

  const refreshSources = async (filters = sourceFiltersRef.current, page = 1, append = false) => {
    const generation = ++requestGeneration.current;
    let responses: [WorkspaceResponse, GoogleAccount, ListingPage, Awaited<ReturnType<typeof listFolderWatches>>];

    try {
      responses = await Promise.all([
        getWorkspace(),
        getGoogleAccount(),
        listingPage(filters, page),
        listFolderWatches()
      ]);
    } catch (caught) {
      if (generation !== requestGeneration.current) {
        return false;
      }

      throw caught;
    }

    if (generation !== requestGeneration.current) {
      return false;
    }

    const [workspaceResponse, accountResponse, listing, foldersResponse] = responses;

    setWorkspace(workspaceResponse);
    setAccount(accountResponse);
    setFolderWatches(foldersResponse.folders);
    sourceFiltersRef.current = filters;
    setSourceFilters(filters);
    setListingMode(listing.mode);
    setSources((current) => append ? [...current.filter((source) => !listing.sources.some((next) => next.postId === source.postId)), ...listing.sources] : listing.sources);
    setRows((current) => append ? [...current, ...listing.rows.filter((row) => !current.some((existing) => existing.postId === row.postId))] : listing.rows);
    setContentTruncated(listing.truncated);
    setSourcePage(page);
    setHasMoreSources(listing.hasMore);
    sourceSync.trackSourceIds(listing.sources.filter((source) => source.syncStatus === 'syncing').map((source) => source.postId));

    return true;
  };

  const refresh = async () => {
    await refreshSources(sourceFiltersRef.current, 1);
  };

  const runAction = async (action: () => Promise<void>) => {
    setBusy(true);
    setNotice(null);

    try {
      await action();
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Action failed.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const loadMoreSources = async () => {
    await runAction(async () => {
      const nextPage = sourcePage + 1;
      await refreshSources(sourceFiltersRef.current, nextPage, true);
    });
  };

  const syncOne = async (postId: number) => {
    await runAction(async () => {
      const result = await syncSource(postId, 'background');
      const source = result.source ?? null;
      const message = source?.syncMessage || sprintf(__('Source %d sync queued.', 'brasth-document-sync-for-google-docs'), postId);

      if (source) {
        sourceSync.mergeSources([source]);
        setActivationSource((current) => current?.postId === postId ? source : current);
      }

      sourceSync.trackSourceIds([postId]);
      setNotice({ type: 'info', message });
      speak(message);
    });
  };

  const syncAll = async () => {
    await runAction(async () => {
      const result = await syncAllSources();
      const syncedSources = result.results
        .map((item) => item.source)
        .filter((source): source is SourceRecord => Boolean(source));
      const queuedIds = result.results
        .filter((item) => item.queued || item.status === 'queued' || item.source?.syncStatus === 'syncing')
        .map((item) => item.postId);
      const message = result.hasMore ? sprintf(__('Queued sync for %d source(s). Run sync all again for more.', 'brasth-document-sync-for-google-docs'), result.count) : sprintf(__('Queued sync for %d source(s).', 'brasth-document-sync-for-google-docs'), result.count);

      sourceSync.mergeSources(syncedSources);
      sourceSync.trackSourceIds(queuedIds);
      setNotice({ type: 'info', message });
      speak(message);
    });
  };

  const applySourceFilters = async (filters: SourcesContentFilters) => {
    await runAction(async () => {
      const committed = await refreshSources(filters, 1);

      if (committed) {
        writeSourceFiltersToUrl(filters);
      }
    });
  };

  const connectGoogle = async () => {
    await runAction(async () => {
      const response = await getGoogleAuthUrl();
      window.location.assign(response.authUrl);
    });
  };

  /* Upload commits create drafts only; the listing re-reads so one-time imports appear with their provenance. */
  const handleImported = (result: ImportCommitResult) => {
    const created = result.files.filter((file) => file.status === 'created').length;
    const failed = result.files.filter((file) => file.status === 'failed').length;

    if (created === 0 && failed === 0) {
      return;
    }

    const message = failed > 0
      ? sprintf(
        /* translators: 1: drafts created, 2: files that failed. */
        __('%1$d drafts created from your uploads. %2$d files failed; their reasons stay in Add content.', 'brasth-document-sync-for-google-docs'),
        created,
        failed
      )
      : sprintf(
        /* translators: %d: drafts created from uploaded files. */
        _n('%d draft created from your upload.', '%d drafts created from your uploads.', created, 'brasth-document-sync-for-google-docs'),
        created
      );

    setNotice({ type: failed > 0 ? 'warning' : 'success', message });
    speak(message, failed > 0 ? 'assertive' : 'polite');
    void refreshSources(sourceFiltersRef.current, 1).catch((caught) => {
      const refreshMessage = caught instanceof Error ? caught.message : __('The drafts were created, but Sources could not refresh.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'warning', message: refreshMessage });
    });
  };

  const handleSourceCreated = (result: SyncResult) => {
    if (!result.source) {
      return;
    }

    setActivationSource(result.source);
    restoreModalFocus.current = !['synced', 'skipped', 'error'].includes(result.source.syncStatus);
    if (!['synced', 'skipped', 'error'].includes(result.source.syncStatus)) {
      sourceSync.trackSourceIds([result.postId]);
    }
    void refreshSources(sourceFiltersRef.current, 1).catch((caught) => {
      const message = caught instanceof Error ? caught.message : __('The source was created, but the filtered list could not refresh.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'warning', message });
    });
  };

  const handleSourceTerminal = (source: SourceRecord) => {
    const isActivationSource = activationSource?.postId === source.postId;

    sourceSync.handleSourceTerminal(source, !isActivationSource);
    setActivationSource((current) => current?.postId === source.postId ? source : current);
    void refreshSources(sourceFiltersRef.current, 1).catch((caught) => {
      const message = caught instanceof Error ? caught.message : __('Sync completed, but Sources could not refresh.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'warning', message });
    });
  };

  const handleSourceStatus = (source: SourceRecord) => {
    sourceSync.handleSourceStatus(source);
    setActivationSource((current) => current?.postId === source.postId ? source : current);
  };

  const retryActivationSource = async () => {
    if (!activationSource) {
      return;
    }

    await syncOne(activationSource.postId);
  };

  const openSourceModal = (intent: 'folder' | 'document' = 'folder', view: AddContentView = 'google') => {
    setSourceIntent(intent === 'document' ? 'document' : 'folder');
    setSourceView(view);
    sourceModalTrigger.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    restoreModalFocus.current = true;
    setSourceModalOpen(true);
  };

  const closeSourceModal = () => {
    setSourceModalOpen(false);

    if (restoreModalFocus.current) {
      window.setTimeout(() => sourceModalTrigger.current?.focus(), 0);
    }

    restoreModalFocus.current = true;
  };

  const openAddContent = (view: AddContentView = 'google') => openSourceModal('document', view);

  const openMatchView = () => {
    writeMatchUrlState({ open: true, jobId: null });
    setMatchView({ open: true, jobId: null });
  };

  const closeMatchView = () => {
    writeMatchUrlState({ open: false, jobId: null });
    setMatchView({ open: false, jobId: null });
    void refreshSources(sourceFiltersRef.current, 1).catch(() => undefined);
  };

  const handleMatchLinked = (count: number) => {
    if (count <= 0) {
      return;
    }

    const message = sprintf(
      /* translators: %d: posts linked to Google Docs. */
      _n('%d post linked. Its content stays the same until its next sync.', '%d posts linked. Their content stays the same until their next sync.', count, 'brasth-document-sync-for-google-docs'),
      count
    );

    setNotice({ type: 'success', message });
    speak(message);
  };

  return {
    account,
    activationSource,
    closeMatchView,
    contentTruncated,
    handleImported,
    handleMatchLinked,
    listingMode,
    matchView,
    openAddContent,
    openMatchView,
    rows,
    sourceView,
    applySourceFilters,
    busy,
    connectGoogle,
    closeSourceModal,
    config,
    folderWatches,
    hasMoreSources,
    handleSourceCreated,
    loadMoreSources,
    notice,
    openSourceModal,
    refresh,
    retryActivationSource,
    runAction,
    sourceIntent,
    sourceModalOpen,
    sourceFilters,
    sources,
    syncAll,
    syncOne,
    trackedSourceIds: sourceSync.trackedSourceIds,
    handleSourcePollingError: sourceSync.handleSourcePollingError,
    handleSourcePollingTimeout: sourceSync.handleSourcePollingTimeout,
    handleSourceStatus,
    handleSourceTerminal,
    workspace
  };
};
