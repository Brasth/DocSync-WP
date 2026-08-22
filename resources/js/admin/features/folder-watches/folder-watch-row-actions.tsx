import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { sourcesForWatchUrl } from './folder-watch-labels';

type Props = {
  busy: boolean;
  paused: boolean;
  watchId: string;
  onEdit: () => void;
  onPause: () => void;
  onRemove: () => void;
  onResume: () => void;
  onScan: () => void;
};

export const FolderWatchRowActions = ({
  busy,
  paused,
  watchId,
  onEdit,
  onPause,
  onRemove,
  onResume,
  onScan
}: Props): JSX.Element => {
  return (
    <div className="docsync-wp-folder-watch-row-actions">
      <AdminButton disabled={busy || paused} onClick={onScan} size="small">
        {__('Scan', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      <AdminButton disabled={busy} onClick={onEdit} size="small" variant="primary">
        {__('Manage', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      <details className="docsync-wp-row-actions-menu">
        <summary
          aria-label={__('More actions', 'brasth-document-sync-for-google-docs')}
          className="button button-secondary docsync-wp-button docsync-wp-button--small docsync-wp-row-actions-menu__trigger"
        >
          ⋯
        </summary>
        <div className="docsync-wp-row-actions-menu__panel">
          {paused ? (
            <AdminButton disabled={busy} onClick={onResume} size="small">
              {__('Resume', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          ) : (
            <AdminButton disabled={busy} onClick={onPause} size="small">
              {__('Pause', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          )}
          <a className="button button-secondary docsync-wp-button docsync-wp-button--small" href={sourcesForWatchUrl(watchId)}>
            {__('View posts', 'brasth-document-sync-for-google-docs')}
          </a>
          <AdminButton disabled={busy} onClick={onRemove} size="small">
            {__('Remove', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      </details>
    </div>
  );
};
