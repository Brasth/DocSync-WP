import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { LayoutPresetSelector } from '../../shared/ui/layout-preset-selector';
import { LoadingState } from '../../shared/ui/loading-state';
import type { SyncResult } from '../../api';
import { getAdminConfig } from '../../config';
import { ensureLazyStyle, useLazyDriveBrowserPanel } from './lazy-drive-browser-panel';
import { AdvancedSourcePanel } from './advanced-source-panel';
import { OutputTypeChoice } from './output-type-choice';
import { SourceModeTabs } from './source-mode-tabs';
import { type DocSourceTarget, useDocSourceModal } from './use-doc-source-modal';

export type { DocSourceTarget } from './use-doc-source-modal';

type Props = {
  isOpen: boolean;
  target: DocSourceTarget | null;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
};

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const DocSourceModal = ({ isOpen, target, onClose, onCompleted }: Props): JSX.Element | null => {
  const modal = useDocSourceModal({ isOpen, target, onClose, onCompleted });
  const uiMode = modal.uiMode;
  const compatibility = modal.metadata?.syncCompatibility;
  const driveBrowser = useLazyDriveBrowserPanel(isOpen && uiMode === 'browse');
  const DriveBrowserPanel = driveBrowser.Component;
  const config = getAdminConfig();
  const markUrl = config.pluginUrl ? `${trimTrailingSlash(config.pluginUrl)}/resources/images/brasth-mark.png` : '';
  const useElementorPreset = modal.canChooseElementor && modal.outputType === 'elementor';
  const elementorDefaultPreset = 'elementor_feature_block';
  const elementorDefaultLabel = config.availableElementorLayoutPresets.find((preset) => preset.id === elementorDefaultPreset)?.label || elementorDefaultPreset;
  const elementorDefaultOptionLabel = target?.mode === 'existing' && target.elementorPreset === null
    ? __('Legacy Elementor output', 'brasth-document-sync-for-google-docs')
    : sprintf(__('Use Elementor default (%s)', 'brasth-document-sync-for-google-docs'), elementorDefaultLabel);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    getAdminConfig().docSourceModalStyleUrls.forEach((href, index) => {
      ensureLazyStyle(href, `docsync-wp-doc-source-modal-style-${index}`);
    });
  }, [isOpen]);

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
            <div className="docsync-wp-modal__heading">
              {markUrl ? (
                <img
                  alt=""
                  aria-hidden="true"
                  className="docsync-wp-modal__mark"
                  height="40"
                  src={markUrl}
                  width="40"
                />
              ) : null}
              <div className="docsync-wp-modal__title">
                <span>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</span>
                <Dialog.Title asChild>
                  <h2>{__('Link Google Doc', 'brasth-document-sync-for-google-docs')}</h2>
                </Dialog.Title>
                <Dialog.Description asChild>
                  <p>{__('Google Docs is source of truth. Sync overwrites WordPress content.', 'brasth-document-sync-for-google-docs')}</p>
                </Dialog.Description>
              </div>
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
              DriveBrowserPanel ? (
                <DriveBrowserPanel
                  busy={modal.busy}
                  onSelect={modal.selectDocument}
                  selectedDocument={modal.metadata}
                />
              ) : (
                <LoadingState className="docsync-wp-drive-browser__state" variant="skeleton">
                  {driveBrowser.error || __('Loading Google Drive browser...', 'brasth-document-sync-for-google-docs')}
                </LoadingState>
              )
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
              <div className="docsync-wp-doc-preview" aria-label={__('Selected Google Doc', 'brasth-document-sync-for-google-docs')}>
                <div className="docsync-wp-doc-preview__summary">
                  <span className="docsync-wp-doc-preview__label">{__('Selected Google Doc', 'brasth-document-sync-for-google-docs')}</span>
                  <strong>{modal.metadata.name}</strong>
                  <span>{modal.metadata.webViewLink || modal.metadata.fileId}</span>
                </div>
                <div className="docsync-wp-doc-preview__options">
                  {modal.canChooseElementor ? (
                    <OutputTypeChoice
                      disabled={modal.busy}
                      onChange={modal.setOutputType}
                      value={modal.outputType}
                    />
                  ) : null}
                  {useElementorPreset ? (
                    <LayoutPresetSelector
                      availableLayoutPresets={config.availableElementorLayoutPresets}
                      defaultLayoutPreset={elementorDefaultPreset}
                      defaultOptionLabel={elementorDefaultOptionLabel}
                      disabled={modal.busy}
                      helpText={__('Choose how this Google Doc becomes Elementor sections. Gutenberg presets stay separate.', 'brasth-document-sync-for-google-docs')}
                      label={__('Elementor layout preset', 'brasth-document-sync-for-google-docs')}
                      onChange={modal.setElementorPreset}
                      value={modal.elementorPreset}
                    />
                  ) : (
                    <LayoutPresetSelector
                      availableLayoutPresets={config.availableLayoutPresets}
                      defaultLayoutPreset={config.defaultLayoutPreset}
                      disabled={modal.busy}
                      onChange={modal.setLayoutPreset}
                      value={modal.layoutPreset}
                    />
                  )}
                </div>
              </div>
            ) : null}
          </div>

          <div className="docsync-wp-modal__footer">
            {!modal.canAttach ? (
              <span className="docsync-wp-modal__footer-hint">
                {uiMode === 'browse'
                  ? __('Choose an accessible Google Doc to continue.', 'brasth-document-sync-for-google-docs')
                  : __('Inspect a Google Doc before linking it.', 'brasth-document-sync-for-google-docs')}
              </span>
            ) : null}
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
              onClick={() => modal.attach()}
              variant="primary"
            >
              {target.mode === 'new' ? __('Create synced draft', 'brasth-document-sync-for-google-docs') : __('Link source', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        </Dialog.Content>
      </Dialog.Portal>
      <ConfirmDialog
        busy={modal.busy}
        confirmLabel={__('Transfer sync responsibility', 'brasth-document-sync-for-google-docs')}
        description={__('This linked source currently uses another operator\'s Google connection for scheduled syncs. Transfer responsibility to your connected account? Existing WordPress content, revisions, and source settings are retained.', 'brasth-document-sync-for-google-docs')}
        open={modal.ownershipTransferRequired}
        title={__('Transfer scheduled sync responsibility?', 'brasth-document-sync-for-google-docs')}
        onConfirm={() => modal.attach(true)}
        onOpenChange={modal.setOwnershipTransferRequired}
      />
    </Dialog.Root>
  );
};
