import { createElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { StatusPill } from '../../shared/ui/status-pill';

type Props = {
  busy: boolean;
  watches: FolderWatchRecord[];
  onPause: (watchId: string) => Promise<void>;
  onRemove: (watchId: string) => Promise<void>;
  onResume: (watchId: string) => Promise<void>;
  onScan: (watchId: string) => Promise<void>;
};

export const SourcesFolderWatches = ({
  busy,
  watches,
  onPause,
  onRemove,
  onResume,
  onScan
}: Props): JSX.Element | null => {
  const [removeId, setRemoveId] = useState('');

  if (watches.length === 0) {
    return null;
  }

  return (
    <section className="docsync-wp-folder-watches" aria-label={__('Folder watches', 'brasth-document-sync-for-google-docs')}>
      <header className="docsync-wp-folder-watches__header">
        <h3>{__('Drive folders', 'brasth-document-sync-for-google-docs')}</h3>
        <span className="docsync-wp-tabular">{watches.length}</span>
      </header>
      <ul className="docsync-wp-folder-watches__list">
        {watches.map((watch) => (
          <li className="docsync-wp-folder-watches__item" key={watch.id}>
            <div>
              <strong>{watch.folderName}</strong>
              <span className="docsync-wp-tabular">
                {sprintf(
                  /* translators: 1: imported count, 2: total count. */
                  __('%1$d/%2$d imported', 'brasth-document-sync-for-google-docs'),
                  watch.importedCount,
                  watch.totalCount
                )}
              </span>
            </div>
            <StatusPill status={watch.status === 'importing' ? 'syncing' : watch.status === 'watching' ? 'synced' : watch.status === 'error' ? 'error' : 'linked'} />
            <div className="docsync-wp-folder-watches__actions">
              <AdminButton disabled={busy || watch.status === 'paused'} onClick={() => void onScan(watch.id)} variant="secondary">
                {__('Scan now', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
              {watch.status === 'paused' ? (
                <AdminButton disabled={busy} onClick={() => void onResume(watch.id)} variant="secondary">
                  {__('Resume', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              ) : (
                <AdminButton disabled={busy} onClick={() => void onPause(watch.id)} variant="secondary">
                  {__('Pause', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              )}
              <AdminButton disabled={busy} onClick={() => setRemoveId(watch.id)} variant="secondary">
                {__('Remove watch', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            </div>
          </li>
        ))}
      </ul>
      <ConfirmDialog
        busy={busy}
        confirmLabel={__('Remove watch', 'brasth-document-sync-for-google-docs')}
        description={__('This stops watching the Drive folder. Synced drafts and posts stay in WordPress.', 'brasth-document-sync-for-google-docs')}
        open={removeId !== ''}
        title={__('Stop watching this folder?', 'brasth-document-sync-for-google-docs')}
        onConfirm={() => {
          const watchId = removeId;
          setRemoveId('');
          void onRemove(watchId);
        }}
        onOpenChange={(open) => {
          if (!open) {
            setRemoveId('');
          }
        }}
      />
    </section>
  );
};
