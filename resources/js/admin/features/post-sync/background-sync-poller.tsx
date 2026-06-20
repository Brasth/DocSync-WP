import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getSource, type SourceRecord } from '../../api';

const terminalStatuses = new Set(['synced', 'skipped', 'error']);
const fastPollingDurationMs = 30000;
const longRunningMs = 600000;
const slowPollingIntervalMs = 15000;

type Props = {
  postId: number;
  onError: (message: string) => void;
  onStatus: (source: SourceRecord) => void;
  onTerminal: (source: SourceRecord) => void;
  onTimeout: () => void;
};

export const BackgroundSyncPoller = ({ postId, onError, onStatus, onTerminal, onTimeout }: Props): JSX.Element | null => {
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
        const source = await getSource(postId);

        if (cancelled) {
          return;
        }

        consecutiveFailures = 0;
        onStatus(source);

        if (terminalStatuses.has(source.syncStatus)) {
          onTerminal(source);
          return;
        }

        const elapsed = Date.now() - startedAt;

        if (elapsed >= longRunningMs && !longRunningNotified) {
          longRunningNotified = true;
          onTimeout();
        }

        schedulePoll(elapsed < fastPollingDurationMs ? 2000 : elapsed < longRunningMs ? 5000 : slowPollingIntervalMs);
      } catch (caught) {
        if (cancelled) {
          return;
        }

        consecutiveFailures += 1;

        if (consecutiveFailures >= 3 && consecutiveFailures % 3 === 0) {
          onError(caught instanceof Error ? caught.message : __('Could not check sync status.', 'brasth-document-sync-for-google-docs'));
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
  }, [postId]);

  return null;
};
