import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import type { FolderWatchPrimaryAction } from './folder-watch-ops';
import { sourcesForWatchUrl } from './folder-watch-labels';

type Props = {
  busy: boolean;
  paused: boolean;
  primaryAction: FolderWatchPrimaryAction;
  watchId: string;
  onEdit: () => void;
  onPause: () => void;
  onRemove: () => void;
  onResume: () => void;
  onScan: () => void;
};

const primaryLabel = (action: FolderWatchPrimaryAction): string => {
  if (action === 'resume') {
    return __('Resume', 'brasth-document-sync-for-google-docs');
  }

  if (action === 'fix') {
    return __('Fix failures', 'brasth-document-sync-for-google-docs');
  }

  if (action === 'manage') {
    return __('Manage', 'brasth-document-sync-for-google-docs');
  }

  return __('Scan now', 'brasth-document-sync-for-google-docs');
};

export const FolderWatchRowActions = ({
  busy,
  paused,
  primaryAction,
  watchId,
  onEdit,
  onPause,
  onRemove,
  onResume,
  onScan
}: Props): JSX.Element => {
  const runPrimary = () => {
    if (primaryAction === 'resume') {
      onResume();
      return;
    }

    if (primaryAction === 'scan') {
      onScan();
      return;
    }

    onEdit();
  };

  return (
    <div className="docsync-wp-folder-watch-row-actions">
      <AdminButton disabled={busy || (primaryAction === 'scan' && paused)} onClick={runPrimary} size="small" variant="primary">
        {primaryLabel(primaryAction)}
      </AdminButton>
      <details className="docsync-wp-row-actions-menu">
        <summary
          aria-label={__('More actions', 'brasth-document-sync-for-google-docs')}
          className="button button-secondary docsync-wp-button docsync-wp-button--small docsync-wp-row-actions-menu__trigger"
        >
          ⋯
        </summary>
        <div className="docsync-wp-row-actions-menu__panel">
          {primaryAction !== 'manage' && primaryAction !== 'fix' ? (
            <AdminButton disabled={busy} onClick={onEdit} size="small">
              {__('Manage', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          ) : null}
          {paused ? (
            primaryAction === 'resume' ? null : (
              <AdminButton disabled={busy} onClick={onResume} size="small">
                {__('Resume', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            )
          ) : (
            <AdminButton disabled={busy} onClick={onPause} size="small">
              {__('Pause', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          )}
          {primaryAction === 'scan' ? null : (
            <AdminButton disabled={busy || paused} onClick={onScan} size="small">
              {__('Scan now', 'brasth-document-sync-for-google-docs')}
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
