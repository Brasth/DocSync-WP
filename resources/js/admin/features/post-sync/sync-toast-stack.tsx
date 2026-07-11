import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { normalizeSyncProgress, SyncProgress } from '../../shared/ui/sync-progress';

export type SyncToast = {
  id: string;
  message: string;
  title: string;
  tone: 'info' | 'success' | 'error' | 'warning';
  busy?: boolean;
  indeterminate?: boolean;
  progress?: number;
  actionLabel?: string;
  onAction?: () => void;
  onDismiss: () => void;
};

type Props = {
  toasts: SyncToast[];
};

export const SyncToastStack = ({ toasts }: Props): JSX.Element | null => {
  if (toasts.length === 0) {
    return null;
  }

  return (
    <div className="docsync-wp-toast-stack">
      {toasts.map((toast) => (
        <div className={`docsync-wp-toast is-${toast.tone}`} key={toast.id}>
          <div className="docsync-wp-toast__body">
            <div className="docsync-wp-toast__content">
              <strong>{toast.title}</strong>
              <p>
                {toast.message}
                {!toast.indeterminate && typeof toast.progress === 'number' ? <span className="docsync-wp-toast__percent">{normalizeSyncProgress(toast.progress)}%</span> : null}
              </p>
            </div>
            <div className="docsync-wp-toast__actions">
              {toast.actionLabel && toast.onAction ? (
                <AdminButton className="button-small" onClick={toast.onAction} variant="secondary">
                  {toast.actionLabel}
                </AdminButton>
              ) : null}
              <button aria-label={__('Dismiss notification', 'brasth-document-sync-for-google-docs')} className="docsync-wp-toast__dismiss" onClick={toast.onDismiss} type="button">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          </div>
          {toast.busy || typeof toast.progress === 'number' ? (
            <div className="docsync-wp-toast__progress-row">
              <SyncProgress indeterminate={toast.indeterminate || typeof toast.progress !== 'number'} progress={toast.progress} showDetails={false} />
            </div>
          ) : null}
        </div>
      ))}
    </div>
  );
};
