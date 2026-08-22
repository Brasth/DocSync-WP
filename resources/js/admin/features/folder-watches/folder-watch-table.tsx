import { createElement, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { EmptyState } from '../../shared/ui/empty-state';
import { StatusPill } from '../../shared/ui/status-pill';
import { formatScanTiming } from './folder-watch-format-time';
import { FolderWatchRowActions } from './folder-watch-row-actions';
import { watchScheduleLabel, watchStatusLabel } from './folder-watch-labels';

type Props = {
  busy: boolean;
  watches: FolderWatchRecord[];
  onCreateWatch: () => void;
  onEdit: (watch: FolderWatchRecord) => void;
  onPause: (watchId: string) => Promise<void>;
  onRemove: (watchId: string) => Promise<void>;
  onResume: (watchId: string) => Promise<void>;
  onScan: (watchId: string) => Promise<void>;
};

const docsSummary = (watch: FolderWatchRecord): string => {
  return sprintf(
    /* translators: 1: imported count, 2: pending count, 3: failed count. */
    __('%1$d imported · %2$d pending · %3$d failed', 'brasth-document-sync-for-google-docs'),
    watch.importedCount,
    watch.pendingCount,
    watch.failed.length
  );
};

export const FolderWatchTable = ({
  busy,
  watches,
  onCreateWatch,
  onEdit,
  onPause,
  onRemove,
  onResume,
  onScan
}: Props): JSX.Element => {
  const [removeId, setRemoveId] = useState('');

  if (watches.length === 0) {
    return (
      <EmptyState
        action={(
          <AdminButton disabled={busy} onClick={onCreateWatch} variant="primary">
            {__('Watch your first Drive folder', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        )}
        className="docsync-wp-table-empty-state"
        description={__('Choose a Google Drive folder to create drafts and keep them on a folder schedule.', 'brasth-document-sync-for-google-docs')}
        title={__('No Drive folders watched yet', 'brasth-document-sync-for-google-docs')}
        variant="folders"
      />
    );
  }

  return (
    <section className="docsync-wp-card docsync-wp-card--wide">
      <div className="docsync-wp-table-scroll docsync-wp-folder-watch-table-wrap">
        <table className="docsync-wp-data-table docsync-wp-folder-watch-table">
          <thead>
            <tr>
              <th className="docsync-wp-folder-watch-table__folder-col">{__('Folder', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Schedule', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Sync', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Docs', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Status', 'brasth-document-sync-for-google-docs')}</th>
              <th className="docsync-wp-folder-watch-table__actions-col">{__('Actions', 'brasth-document-sync-for-google-docs')}</th>
            </tr>
          </thead>
          <tbody>
            {watches.map((watch) => {
              const scanTiming = formatScanTiming(watch.lastScanAt, watch.nextScanAt);

              return (
                <tr key={watch.id}>
                  <td className="docsync-wp-folder-watch-table__folder-col">
                    <div className="docsync-wp-source-target">
                      {watch.webViewLink ? (
                        <a className="docsync-wp-source-target__title" href={watch.webViewLink} rel="noreferrer" target="_blank">
                          {watch.folderName}
                        </a>
                      ) : (
                        <strong>{watch.folderName}</strong>
                      )}
                      <div className="docsync-wp-source-target__meta">
                        {watch.ownerDisplayName ? (
                          <span>{watch.ownerDisplayName}</span>
                        ) : null}
                        <span className="docsync-wp-row-tag">{watch.postType}</span>
                        <span className="docsync-wp-row-tag">{watch.postStatus}</span>
                        {watch.includeSubfolders ? (
                          <span className="docsync-wp-row-tag">{__('Subfolders', 'brasth-document-sync-for-google-docs')}</span>
                        ) : null}
                      </div>
                    </div>
                  </td>
                  <td>{watchScheduleLabel(watch)}</td>
                  <td>
                    <span className="docsync-wp-tabular docsync-wp-folder-watch-table__sync" title={scanTiming.title}>
                      {scanTiming.label}
                    </span>
                  </td>
                  <td>
                    <span className="docsync-wp-tabular">{docsSummary(watch)}</span>
                  </td>
                  <td><StatusPill status={watchStatusLabel(watch.status)} /></td>
                  <td className="docsync-wp-folder-watch-table__actions-col">
                    <FolderWatchRowActions
                      busy={busy}
                      onEdit={() => onEdit(watch)}
                      onPause={() => void onPause(watch.id)}
                      onRemove={() => setRemoveId(watch.id)}
                      onResume={() => void onResume(watch.id)}
                      onScan={() => void onScan(watch.id)}
                      paused={watch.status === 'paused'}
                      watchId={watch.id}
                    />
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
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
