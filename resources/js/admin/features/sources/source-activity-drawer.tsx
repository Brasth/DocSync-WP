import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { listSyncLogEntries, type SyncLogEntry } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { SyncLogEventsTable } from '../sync-logs/sync-log-events-table';

type Props = {
  postId: number | null;
  title: string;
  onClose: () => void;
};

const drawerPageSize = 20;

/** Right-side drawer with recent sync events for one source. */
export const SourceActivityDrawer = ({ postId, title, onClose }: Props): JSX.Element => {
  const [entries, setEntries] = useState<SyncLogEntry[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (postId === null) {
      return undefined;
    }

    let cancelled = false;

    setLoaded(false);
    setError('');
    setEntries([]);

    listSyncLogEntries({ postId, perPage: drawerPageSize })
      .then((response) => {
        if (!cancelled) {
          setEntries(response.entries);
          setLoaded(true);
        }
      })
      .catch((caught: unknown) => {
        if (!cancelled) {
          setError(caught instanceof Error ? caught.message : __('Could not load activity.', 'brasth-document-sync-for-google-docs'));
          setLoaded(true);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [postId]);

  return (
    <Dialog.Root open={postId !== null} onOpenChange={(open) => { if (!open) { onClose(); } }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-confirm-dialog__overlay" />
        <Dialog.Content aria-busy={!loaded} className="docsync-wp-confirm-dialog docsync-wp-activity-drawer">
          <div className="docsync-wp-activity-drawer__header">
            <div>
              <Dialog.Title asChild>
                <h2>{__('Sync activity', 'brasth-document-sync-for-google-docs')}</h2>
              </Dialog.Title>
              <Dialog.Description asChild>
                <p>{title}</p>
              </Dialog.Description>
            </div>
            <Dialog.Close asChild>
              <AdminButton size="small">{__('Close', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </Dialog.Close>
          </div>
          <div className="docsync-wp-activity-drawer__body">
            {error ? <p className="docsync-wp-list-error" role="alert">{error}</p> : null}
            <SyncLogEventsTable
              busy={!loaded}
              entries={entries}
              hasActiveFilters={false}
              hasLoaded={loaded}
              level=""
              postId={postId === null ? '' : String(postId)}
              search=""
              status=""
              step=""
            />
          </div>
          {postId !== null ? (
            <div className="docsync-wp-activity-drawer__footer">
              <a href={`admin.php?page=brasth-document-sync-for-google-docs-logs&post_id=${encodeURIComponent(String(postId))}`}>
                {sprintf(__('Open full activity for this source', 'brasth-document-sync-for-google-docs'))}
              </a>
            </div>
          ) : null}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
