import * as Dialog from '@radix-ui/react-dialog';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { AdminButton } from './admin-button';

type ConfirmDialogVariant = 'default' | 'danger';

type Props = {
  busy?: boolean;
  cancelLabel?: string;
  children?: ReactNode;
  confirmLabel: string;
  description: ReactNode;
  open: boolean;
  title: ReactNode;
  variant?: ConfirmDialogVariant;
  onConfirm: () => void | Promise<void>;
  onOpenChange: (open: boolean) => void;
};

export const ConfirmDialog = ({
  busy = false,
  cancelLabel = __('Cancel', 'brasth-document-sync-for-google-docs'),
  children,
  confirmLabel,
  description,
  open,
  title,
  variant = 'default',
  onConfirm,
  onOpenChange
}: Props): JSX.Element => {
  const contentClassName = [
    'docsync-wp-confirm-dialog',
    variant === 'danger' ? 'docsync-wp-confirm-dialog--danger' : ''
  ].filter(Boolean).join(' ');

  return (
    <Dialog.Root open={open} onOpenChange={(nextOpen) => {
      if (!busy) {
        onOpenChange(nextOpen);
      }
    }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-confirm-dialog__overlay" />
        <Dialog.Content aria-busy={busy} className={contentClassName}>
          <div className="docsync-wp-confirm-dialog__body">
            <Dialog.Title asChild>
              <h2>{title}</h2>
            </Dialog.Title>
            <Dialog.Description asChild>
              <p>{description}</p>
            </Dialog.Description>
            {children ? <div className="docsync-wp-confirm-dialog__extra">{children}</div> : null}
          </div>
          <div className="docsync-wp-confirm-dialog__footer">
            <Dialog.Close asChild>
              <AdminButton disabled={busy}>
                {cancelLabel}
              </AdminButton>
            </Dialog.Close>
            <AdminButton
              className={variant === 'danger' ? 'docsync-wp-confirm-dialog__confirm--danger' : ''}
              disabled={busy}
              onClick={onConfirm}
              variant="primary"
            >
              {busy ? __('Working...', 'brasth-document-sync-for-google-docs') : confirmLabel}
            </AdminButton>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
