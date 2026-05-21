import * as Dialog from '@radix-ui/react-dialog';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { DriveBrowserPanel } from '../drive-browser/drive-browser-panel';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import type { SyncResult } from '../../api';
import { AdvancedSourcePanel } from './advanced-source-panel';
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
            <div>
              <Dialog.Title asChild>
                <h2>{__('Link Google Doc', 'docsync-wp')}</h2>
              </Dialog.Title>
              <Dialog.Description asChild>
                <p>{__('Google Docs is source of truth. Sync overwrites WordPress content.', 'docsync-wp')}</p>
              </Dialog.Description>
            </div>
            <Dialog.Close asChild>
              <AdminButton variant="link">{__('Close', 'docsync-wp')}</AdminButton>
            </Dialog.Close>
          </div>

          <div className="docsync-wp-modal__body">
            {!modal.advancedOpen ? (
              <DriveBrowserPanel
                busy={modal.busy}
                onSelect={modal.selectDocument}
                selectedDocument={modal.metadata}
              />
            ) : null}

            <AdminButton
              className="docsync-wp-advanced-toggle"
              onClick={modal.toggleAdvanced}
              variant="secondary"
            >
              {modal.advancedOpen ? __('Browse Google Docs', 'docsync-wp') : __('Paste URL or file ID', 'docsync-wp')}
            </AdminButton>

            {modal.advancedOpen ? (
              <AdvancedSourcePanel
                documentInput={modal.documentInput}
                onInputChange={modal.setDocumentInput}
                onModeChange={modal.changeSourceMode}
                sourceMode={modal.sourceMode}
              />
            ) : null}

            <AdminNotice className="inline" notice={modal.error ? { type: 'error', message: modal.error } : null} />

            {modal.metadata ? (
              <div className="docsync-wp-doc-preview">
                <span>{__('Selected Google Doc', 'docsync-wp')}</span>
                <strong>{modal.metadata.name}</strong>
                <span>{modal.metadata.webViewLink || modal.metadata.fileId}</span>
              </div>
            ) : null}
          </div>

          <div className="docsync-wp-modal__footer">
            <Dialog.Close asChild>
              <AdminButton disabled={modal.busy}>{__('Cancel', 'docsync-wp')}</AdminButton>
            </Dialog.Close>
            {modal.advancedOpen ? (
              <AdminButton
                disabled={modal.busy || modal.documentInput.trim() === ''}
                onClick={modal.inspect}
                variant="secondary"
              >
                {__('Inspect', 'docsync-wp')}
              </AdminButton>
            ) : null}
            <AdminButton
              disabled={modal.busy || !modal.metadata}
              onClick={modal.attach}
              variant="primary"
            >
              {target.mode === 'new' ? __('Create synced draft', 'docsync-wp') : __('Link source', 'docsync-wp')}
            </AdminButton>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
