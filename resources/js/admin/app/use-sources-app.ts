import { speak } from '@wordpress/a11y';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
  getSettings,
  listSources,
  syncAllSources,
  syncSource,
  type SettingsResponse,
  type SourceRecord
} from '../api';
import { getAdminConfig } from '../config';
import type { SourceListFilters } from '../features/sources/sources-table';
import type { AdminNoticeState } from '../shared/ui/admin-notice';
import { useSourceSyncProgress } from './use-source-sync-progress';

const sourcePageSize = 100;
const defaultSourceFilters: SourceListFilters = { search: '', postType: '', status: '' };

export const useSourcesApp = () => {
  const config = useMemo(() => getAdminConfig(), []);
  const [settings, setSettings] = useState<SettingsResponse | null>(null);
  const [sources, setSources] = useState<SourceRecord[]>([]);
  const [sourcePage, setSourcePage] = useState(1);
  const [sourceFilters, setSourceFilters] = useState<SourceListFilters>(defaultSourceFilters);
  const [hasMoreSources, setHasMoreSources] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);
  const sourceSync = useSourceSyncProgress(setSources, setNotice);

  const refreshSources = async (filters = sourceFilters, page = 1, append = false) => {
    const [settingsResponse, sourcesResponse] = await Promise.all([
      getSettings(),
      listSources({
        ...filters,
        page,
        perPage: sourcePageSize
      })
    ]);

    setSettings(settingsResponse);
    setSourceFilters(filters);
    setSources((current) => append ? [...current, ...sourcesResponse.sources] : sourcesResponse.sources);
    setSourcePage(page);
    setHasMoreSources(Boolean(sourcesResponse.has_more ?? sourcesResponse.hasMore));
    sourceSync.trackSourceIds(sourcesResponse.sources.filter((source) => source.syncStatus === 'syncing').map((source) => source.postId));
  };

  const refresh = async () => {
    await refreshSources(sourceFilters, 1);
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
      await refreshSources(sourceFilters, nextPage, true);
    });
  };

  const syncOne = async (postId: number) => {
    await runAction(async () => {
      const result = await syncSource(postId, 'background');
      const source = result.source ?? null;
      const message = source?.syncMessage || sprintf(__('Source %d sync queued.', 'brasth-document-sync-for-google-docs'), postId);

      if (source) {
        sourceSync.mergeSources([source]);
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
      await refreshSources(filters, 1);
    });
  };

  return {
    applySourceFilters,
    busy,
    config,
    hasMoreSources,
    loadMoreSources,
    notice,
    refresh,
    runAction,
    settings,
    sourceFilters,
    sources,
    syncAll,
    syncOne,
    trackedSourceIds: sourceSync.trackedSourceIds,
    handleSourcePollingError: sourceSync.handleSourcePollingError,
    handleSourcePollingTimeout: sourceSync.handleSourcePollingTimeout,
    handleSourceStatus: sourceSync.handleSourceStatus,
    handleSourceTerminal: sourceSync.handleSourceTerminal
  };
};
