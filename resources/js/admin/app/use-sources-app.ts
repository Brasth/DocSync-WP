import { speak } from '@wordpress/a11y';
import { useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
  getGoogleAccount,
  getGoogleAuthUrl,
  getWorkspace,
  listSources,
  syncAllSources,
  syncSource,
  type GoogleAccount,
  type SourceRecord,
  type SyncResult,
  type WorkspaceResponse
} from '../api';
import { getAdminConfig } from '../config';
import type { SourceListFilters } from '../features/sources/sources-table';
import type { AdminNoticeState } from '../shared/ui/admin-notice';
import { useSourceSyncProgress } from './use-source-sync-progress';

const sourcePageSize = 100;
const emptyAccount: GoogleAccount = { connected: false, hasRequiredScope: false };

const readSourceFiltersFromUrl = (): SourceListFilters => {
  const params = new URL(window.location.href).searchParams;

  return {
    search: params.get('search') || '',
    postType: params.get('post_type') || '',
    status: params.get('status') || ''
  };
};

const writeSourceFiltersToUrl = (filters: SourceListFilters) => {
  const url = new URL(window.location.href);
  const values = { search: filters.search, post_type: filters.postType, status: filters.status };

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
  const [sourcePage, setSourcePage] = useState(1);
  const [sourceFilters, setSourceFilters] = useState<SourceListFilters>(readSourceFiltersFromUrl);
  const [hasMoreSources, setHasMoreSources] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);
  const [sourceModalOpen, setSourceModalOpen] = useState(false);
  const [activationSource, setActivationSource] = useState<SourceRecord | null>(null);
  const sourceSync = useSourceSyncProgress(setSources, setNotice);
  const sourceModalTrigger = useRef<HTMLElement | null>(null);
  const restoreModalFocus = useRef(true);
  const requestGeneration = useRef(0);
  const sourceFiltersRef = useRef(sourceFilters);

  const refreshSources = async (filters = sourceFiltersRef.current, page = 1, append = false) => {
    const generation = ++requestGeneration.current;
    let responses: [WorkspaceResponse, GoogleAccount, Awaited<ReturnType<typeof listSources>>];

    try {
      responses = await Promise.all([
        getWorkspace(),
        getGoogleAccount(),
        listSources({
          ...filters,
          page,
          perPage: sourcePageSize
        })
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

    const [workspaceResponse, accountResponse, sourcesResponse] = responses;

    setWorkspace(workspaceResponse);
    setAccount(accountResponse);
    sourceFiltersRef.current = filters;
    setSourceFilters(filters);
    setSources((current) => append ? [...current, ...sourcesResponse.sources] : sourcesResponse.sources);
    setSourcePage(page);
    setHasMoreSources(Boolean(sourcesResponse.has_more ?? sourcesResponse.hasMore));
    sourceSync.trackSourceIds(sourcesResponse.sources.filter((source) => source.syncStatus === 'syncing').map((source) => source.postId));

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

  const applySourceFilters = async (filters: SourceListFilters) => {
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

  const openSourceModal = () => {
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

  return {
    account,
    activationSource,
    applySourceFilters,
    busy,
    connectGoogle,
    closeSourceModal,
    config,
    hasMoreSources,
    handleSourceCreated,
    loadMoreSources,
    notice,
    openSourceModal,
    refresh,
    retryActivationSource,
    runAction,
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
