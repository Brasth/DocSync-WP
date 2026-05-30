import { useEffect } from '@wordpress/element';

import { getSource, type SourceRecord } from '../../api';

const terminalStatuses = new Set(['synced', 'skipped', 'error']);
const fastPollingDurationMs = 30000;
const timeoutMs = 600000;

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

    const poll = async () => {
      try {
        const source = await getSource(postId);

        if (cancelled) {
          return;
        }

        onStatus(source);

        if (terminalStatuses.has(source.syncStatus)) {
          onTerminal(source);
          return;
        }

        const elapsed = Date.now() - startedAt;

        if (elapsed >= timeoutMs) {
          onTimeout();
          return;
        }

        timer = window.setTimeout(poll, elapsed < fastPollingDurationMs ? 2000 : 5000);
      } catch (caught) {
        if (!cancelled) {
          onError(caught instanceof Error ? caught.message : 'Could not check sync status.');
        }
      }
    };

    timer = window.setTimeout(poll, 2000);

    return () => {
      cancelled = true;

      if (timer) {
        window.clearTimeout(timer);
      }
    };
  }, [postId]);

  return null;
};
