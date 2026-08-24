import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getFolderWatch, type FolderWatchRecord } from '../../api';

const fastPollingDurationMs = 30000;
const longRunningMs = 600000;
const slowPollingIntervalMs = 15000;

type Props = {
  watchId: string;
  onError: (message: string) => void;
  onStatus: (watch: FolderWatchRecord) => void;
  onTimeout: () => void;
};

export const FolderWatchPoller = ({ watchId, onError, onStatus, onTimeout }: Props): JSX.Element | null => {
  useEffect(() => {
    let cancelled = false;
    let timer: number | undefined;
    const startedAt = Date.now();
    let consecutiveFailures = 0;
    let longRunningNotified = false;

    const schedulePoll = (delayMs: number) => {
      timer = window.setTimeout(poll, delayMs);
    };

    async function poll() {
      try {
        const watch = await getFolderWatch(watchId);

        if (cancelled) {
          return;
        }

        consecutiveFailures = 0;
        onStatus(watch);

        if (watch.status === 'error' || watch.status === 'paused' || (watch.status === 'watching' && watch.pendingCount === 0)) {
          return;
        }

        const elapsed = Date.now() - startedAt;

        if (elapsed >= longRunningMs && !longRunningNotified) {
          longRunningNotified = true;
          onTimeout();
        }

        if (watch.status === 'importing' || watch.pendingCount > 0) {
          schedulePoll(elapsed < fastPollingDurationMs ? 2000 : elapsed < longRunningMs ? 5000 : slowPollingIntervalMs);
        }
      } catch (caught) {
        if (cancelled) {
          return;
        }

        consecutiveFailures += 1;

        if (consecutiveFailures >= 3 && consecutiveFailures % 3 === 0) {
          onError(caught instanceof Error ? caught.message : __('Could not check folder import status.', 'brasth-document-sync-for-google-docs'));
        }

        schedulePoll(Math.min(slowPollingIntervalMs, 3000 * consecutiveFailures));
      }
    }

    schedulePoll(2000);

    return () => {
      cancelled = true;

      if (timer) {
        window.clearTimeout(timer);
      }
    };
  }, [watchId]);

  return null;
};
