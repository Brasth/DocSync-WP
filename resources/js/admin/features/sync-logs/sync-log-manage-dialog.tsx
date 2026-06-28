import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';

type Props = {
  busy: boolean;
  canClearSourceLogs: boolean;
  open: boolean;
  parsedPostId: number | null | undefined;
  postId: string;
  onClear: (scope: 'source' | 'all') => void | Promise<void>;
  onOpenChange: (open: boolean) => void;
};

export const SyncLogManageDialog = ({
  busy,
  canClearSourceLogs,
  open,
  parsedPostId,
  postId,
  onClear,
  onOpenChange
}: Props): JSX.Element => {
  const sourceDescription = canClearSourceLogs && typeof parsedPostId === 'number'
    ? sprintf(__('Only stored events for source post ID %d will be cleared.', 'brasth-document-sync-for-google-docs'), parsedPostId)
    : __('Enter a valid source post ID before clearing source logs.', 'brasth-document-sync-for-google-docs');

  return (
    <ConfirmDialog
      busy={busy}
      confirmLabel={__('Clear all logs', 'brasth-document-sync-for-google-docs')}
      description={__('Clears stored events only. Source links, sync status, credentials, progress, and synced content stay intact.', 'brasth-document-sync-for-google-docs')}
      open={open}
      title={__('Manage logs', 'brasth-document-sync-for-google-docs')}
      variant="danger"
      onConfirm={() => onClear('all')}
      onOpenChange={onOpenChange}
    >
      {postId.trim() ? (
        <div className="docsync-wp-log-manage-option">
          <div>
            <strong>{__('Clear source logs', 'brasth-document-sync-for-google-docs')}</strong>
            <span>{sourceDescription}</span>
          </div>
          <AdminButton
            className="docsync-wp-confirm-dialog__confirm--danger"
            disabled={busy || !canClearSourceLogs}
            onClick={() => onClear('source')}
            size="small"
            variant="primary"
          >
            {__('Clear source logs', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      ) : null}
    </ConfirmDialog>
  );
};
