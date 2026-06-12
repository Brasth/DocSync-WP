import * as Dialog from '@radix-ui/react-dialog';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { DriveBrowserPanel } from '../drive-browser/drive-browser-panel';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import type { SyncResult } from '../../api';
import { AdvancedSourcePanel } from './advanced-source-panel';
import { SourceModeTabs } from './source-mode-tabs';
import { type DocSourceTarget, useDocSourceModal } from './use-doc-source-modal';

export type { DocSourceTarget } from './use-doc-source-modal';

type Props = {
  isOpen: boolean;
  target: DocSourceTarget | null;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
};

export const DocSourceModal = ({ isOpen, target, onClose, onCompleted }: Props): JSX.Element | null => {
  const modal = useDocSourceModal({ isOpen, target, onClose, onCompleted });
  const uiMode = modal.uiMode;
  const compatibility = modal.metadata?.syncCompatibility;

  if (!isOpen || !target) {
    return null;
  }

  return (
    <Dialog.Root open={isOpen} onOpenChange={(open) => {
      if (!open) {
        onClose();
      }
    }}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-modal-backdrop" />
        <Dialog.Content
          className="docsync-wp-modal"
          onPointerDownOutside={(event) => event.preventDefault()}
        >
          <div className="docsync-wp-modal__header">
            <div className="docsync-wp-modal__title">
              <Dialog.Title asChild>
                <h2>{__('Link Google Doc', 'brasth-document-sync-for-google-docs')}</h2>
              </Dialog.Title>
              <Dialog.Description asChild>
                <p>{__('Google Docs is source of truth. Sync overwrites WordPress content.', 'brasth-document-sync-for-google-docs')}</p>
              </Dialog.Description>
            </div>
            <div className="docsync-wp-modal__mode-switch">
              <SourceModeTabs
                modes={['browse', 'url', 'file_id']}
                onChange={modal.changeSourceMode}
                sourceMode={uiMode}
              />
            </div>
            <Dialog.Close asChild>
              <button aria-label={__('Close', 'brasth-document-sync-for-google-docs')} className="docsync-wp-modal__close" type="button">
                <span aria-hidden="true">&times;</span>
              </button>
            </Dialog.Close>
          </div>

          <div className="docsync-wp-modal__body">
            {uiMode === 'browse' ? (
              <DriveBrowserPanel
                busy={modal.busy}
                onSelect={modal.selectDocument}
                selectedDocument={modal.metadata}
              />
            ) : null}

            {uiMode !== 'browse' ? (
              <AdvancedSourcePanel
                documentInput={modal.documentInput}
                onInputChange={modal.setDocumentInput}
                sourceMode={uiMode}
              />
            ) : null}

            <AdminNotice className="inline" notice={modal.error ? { type: 'error', message: modal.error } : null} />
            <AdminNotice
              className="inline"
              notice={compatibility?.warningMessage ? {
                type: compatibility.warningCode === 'download_blocked' ? 'error' : 'warning',
                message: compatibility.warningMessage
              } : null}
            />

            {modal.metadata ? (
              <div className="docsync-wp-doc-preview">
                <span>{__('Selected Google Doc', 'brasth-document-sync-for-google-docs')}</span>
                <strong>{modal.metadata.name}</strong>
                <span>{modal.metadata.webViewLink || modal.metadata.fileId}</span>
              </div>
            ) : null}
          </div>

          <div className="docsync-wp-modal__footer">
            <Dialog.Close asChild>
              <AdminButton disabled={modal.busy}>{__('Cancel', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </Dialog.Close>
            {uiMode !== 'browse' ? (
              <AdminButton
                disabled={modal.busy || modal.documentInput.trim() === ''}
                onClick={modal.inspect}
                variant="secondary"
              >
                {__('Inspect', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
            <AdminButton
              disabled={modal.busy || !modal.canAttach}
              onClick={modal.attach}
              variant="primary"
            >
              {target.mode === 'new' ? __('Create synced draft', 'brasth-document-sync-for-google-docs') : __('Link source', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
