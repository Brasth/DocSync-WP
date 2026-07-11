import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SourceRecord } from '../api';
import type { AdminNoticeState } from '../shared/ui/admin-notice';

type SetSources = (value: SourceRecord[] | ((current: SourceRecord[]) => SourceRecord[])) => void;
type SetNotice = (value: AdminNoticeState | null) => void;

export const useSourceSyncProgress = (setSources: SetSources, setNotice: SetNotice) => {
  const [trackedSourceIds, setTrackedSourceIds] = useState<number[]>([]);

  const mergeSources = (updates: SourceRecord[]) => {
    if (updates.length === 0) {
      return;
    }

    setSources((current) => current.map((source) => updates.find((update) => update.postId === source.postId) ?? source));
  };

  const trackSourceIds = (postIds: number[]) => {
    const nextIds = postIds.filter((postId) => postId > 0);

    if (nextIds.length === 0) {
      return;
    }

    setTrackedSourceIds((current) => Array.from(new Set([...current, ...nextIds])));
  };

  const stopTrackingSource = (postId: number) => {
    setTrackedSourceIds((current) => current.filter((trackedPostId) => trackedPostId !== postId));
  };

  const handleSourceStatus = (source: SourceRecord) => {
    mergeSources([source]);
  };

  const handleSourceTerminal = (source: SourceRecord, announce = true) => {
    const isError = source.syncStatus === 'error';
    const message = isError
      ? source.syncError || __('Google Doc sync failed.', 'brasth-document-sync-for-google-docs')
      : source.syncMessage || sprintf(__('Source %d sync complete.', 'brasth-document-sync-for-google-docs'), source.postId);

    mergeSources([source]);
    stopTrackingSource(source.postId);

    if (announce) {
      setNotice({ type: isError ? 'error' : 'success', message });
      speak(message, isError ? 'assertive' : 'polite');
    }
  };

  const handleSourcePollingError = (_postId: number, message: string) => {
    setNotice({ type: 'warning', message });
    speak(message);
  };

  const handleSourcePollingTimeout = (postId: number) => {
    const message = __('Still syncing. Refresh sources to check again later.', 'brasth-document-sync-for-google-docs');

    stopTrackingSource(postId);
    setNotice({ type: 'warning', message });
    speak(message);
  };

  return {
    handleSourcePollingError,
    handleSourcePollingTimeout,
    handleSourceStatus,
    handleSourceTerminal,
    mergeSources,
    trackSourceIds,
    trackedSourceIds
  };
};
