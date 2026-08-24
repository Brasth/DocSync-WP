import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { LayoutPresetSelector } from '../../shared/ui/layout-preset-selector';
import { LoadingState } from '../../shared/ui/loading-state';
import type { FolderWatchRecord, SyncResult } from '../../api';
import { getAdminConfig } from '../../config';
import { ensureLazyStyle, useLazyDriveBrowserPanel } from './lazy-drive-browser-panel';
import { AdvancedSourcePanel } from './advanced-source-panel';
import { OutputTypeChoice } from './output-type-choice';
import { SourceModeTabs } from './source-mode-tabs';
import { FolderWatchConfirmPanel } from './folder-watch-confirm-panel';
import { SourceIntentCards } from './source-intent-cards';
import { shouldShowDriveBrowser } from './folder-watch-modal-visibility';
import { type DocSourceTarget, useDocSourceModal } from './use-doc-source-modal';
import { useFolderWatchFlow } from './use-folder-watch-flow';

export type { DocSourceTarget } from './use-doc-source-modal';

type Props = {
  initialIntent?: 'document' | 'folder';
  isOpen: boolean;
  target: DocSourceTarget | null;
  onClose: () => void;
  onCompleted: (result: SyncResult) => void;
  onFolderWatchCreated?: (watch: FolderWatchRecord) => void;
};

const trimTrailingSlash = (value: string): string => value.replace(/\/$/, '');

export const DocSourceModal = ({ initialIntent = 'document', isOpen, target, onClose, onCompleted, onFolderWatchCreated }: Props): JSX.Element | null => {
  const modal = useDocSourceModal({ isOpen, target, onClose, onCompleted });
  const canUseFolderIntent = target?.mode === 'new';
  const folderFlow = useFolderWatchFlow({
    canChooseElementor: modal.canChooseElementor,
    initialIntent,
    isOpen,
    postType: target?.mode === 'new' ? target.postType : 'post',
    onWatchCreated: (watch) => onFolderWatchCreated?.(watch)
  });
  const folderMode = canUseFolderIntent && folderFlow.intent === 'folder';
  const showFolderConfirm = folderMode && folderFlow.inventory !== null;
  const showDriveBrowser = shouldShowDriveBrowser(modal.uiMode, showFolderConfirm);
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
                  <h2>{__('Choose source', 'brasth-document-sync-for-google-docs')}</h2>
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
            {canUseFolderIntent && uiMode === 'browse' ? (
              <SourceIntentCards
                disabled={modal.busy || folderFlow.busy || Boolean(folderFlow.watch)}
                onChange={folderFlow.setIntent}
                value={folderFlow.intent}
              />
            ) : null}
            {uiMode === 'browse' ? (
              DriveBrowserPanel ? (
                <div className="docsync-wp-modal__drive-browser" hidden={!showDriveBrowser}>
                  <DriveBrowserPanel
                    allowMultiSelect={modal.allowMultiSelect && !folderMode}
                    busy={modal.busy || folderFlow.busy}
                    folderMode={folderMode}
                    onLocationChange={folderFlow.setLocation}
                    onSelect={modal.selectDocument}
                    selectedDocument={modal.metadata}
                    selectedDocuments={modal.selectedDocuments}
                  />
                </div>
              ) : showDriveBrowser ? (
                <LoadingState className="docsync-wp-drive-browser__state" variant="skeleton">
                  {driveBrowser.error || __('Loading Google Drive browser...', 'brasth-document-sync-for-google-docs')}
                </LoadingState>
              ) : null
            ) : null}

            {uiMode !== 'browse' ? (
              <AdvancedSourcePanel
                documentInput={modal.documentInput}
                onInputChange={modal.setDocumentInput}
                sourceMode={uiMode}
              />
            ) : null}

            {showFolderConfirm && folderFlow.inventory && folderFlow.location ? (
              <FolderWatchConfirmPanel
                busy={modal.busy || folderFlow.busy}
                canChooseElementor={modal.canChooseElementor}
                excludedFileIds={folderFlow.excludedFileIds}
                folderName={folderFlow.location.folderName}
                includeSubfolders={folderFlow.includeSubfolders}
                inventory={folderFlow.inventory}
                interval={folderFlow.syncInterval}
                layoutPreset={folderFlow.layoutPreset}
                outputType={folderFlow.outputType}
                postStatus={folderFlow.postStatus}
                postType={target.mode === 'new' ? target.postType : 'post'}
                watch={folderFlow.watch}
                onChangeFolder={folderFlow.watch ? undefined : folderFlow.changeFolder}
                onExcludeToggle={folderFlow.toggleExcluded}
                onIncludeSubfoldersChange={folderFlow.changeIncludeSubfolders}
                onIntervalChange={folderFlow.setSyncInterval}
                onLayoutPresetChange={folderFlow.setLayoutPreset}
                onOutputTypeChange={folderFlow.setOutputType}
                onPostStatusChange={folderFlow.setPostStatus}
                onRetryFailed={folderFlow.watch ? folderFlow.retryFailed : undefined}
              />
            ) : null}

            <AdminNotice className="inline" notice={modal.error ? { type: 'error', message: modal.error } : null} />
            <AdminNotice className="inline" notice={folderFlow.error ? { type: 'error', message: folderFlow.error } : null} />
            <AdminNotice
              className="inline"
              notice={compatibility?.warningMessage ? {
                type: compatibility.warningCode === 'download_blocked' ? 'error' : 'warning',
                message: compatibility.warningMessage
              } : null}
            />

            {!folderMode && (modal.selectedCount > 0 || modal.metadata) ? (
              <div className="docsync-wp-doc-preview" aria-label={__('Selected Google Doc', 'brasth-document-sync-for-google-docs')}>
                <div className="docsync-wp-doc-preview__summary">
                  <span className="docsync-wp-doc-preview__label">
                    {modal.selectedCount > 1
                      ? sprintf(
                        /* translators: %d: number of selected Google Docs. */
                        __('Selected Google Docs (%d)', 'brasth-document-sync-for-google-docs'),
                        modal.selectedCount
                      )
                      : __('Selected Google Doc', 'brasth-document-sync-for-google-docs')}
                  </span>
                  <strong>
                    {modal.selectedCount > 1
                      ? modal.selectedDocuments.map((document) => document.name).join(', ')
                      : modal.metadata?.name}
                  </strong>
                  <span>
                    {modal.selectedCount > 1
                      ? __('New Google Docs become WordPress drafts. You publish.', 'brasth-document-sync-for-google-docs')
                      : modal.metadata?.webViewLink || modal.metadata?.fileId}
                  </span>
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
            {folderMode && folderFlow.location?.isRoot && !folderFlow.watch ? (
              <label className="docsync-wp-modal__footer-hint">
                <input
                  checked={folderFlow.confirmRoot}
                  disabled={modal.busy || folderFlow.busy}
                  onChange={(event) => folderFlow.setConfirmRoot(event.currentTarget.checked)}
                  type="checkbox"
                />
                {__('I want to watch the top of this Drive (first 50 Docs).', 'brasth-document-sync-for-google-docs')}
              </label>
            ) : modal.attachProgress ? (
              <span className="docsync-wp-modal__footer-hint">{modal.attachProgress}</span>
            ) : !folderMode && !modal.canAttach ? (
              <span className="docsync-wp-modal__footer-hint">
                {uiMode === 'browse'
                  ? modal.allowMultiSelect
                    ? __('Choose one or more accessible Google Docs in this folder.', 'brasth-document-sync-for-google-docs')
                    : __('Choose an accessible Google Doc to continue.', 'brasth-document-sync-for-google-docs')
                  : __('Inspect a Google Doc before linking it.', 'brasth-document-sync-for-google-docs')}
              </span>
            ) : null}
            <Dialog.Close asChild>
              <AdminButton disabled={modal.busy || folderFlow.busy}>{__('Cancel', 'brasth-document-sync-for-google-docs')}</AdminButton>
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
            {folderMode && !folderFlow.inventory ? (
              <AdminButton
                disabled={modal.busy || folderFlow.busy || !folderFlow.location || (folderFlow.location.isRoot && !folderFlow.confirmRoot)}
                onClick={() => void folderFlow.loadInventory()}
                variant="primary"
              >
                {__('Use this folder', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
            {folderMode && folderFlow.inventory && !folderFlow.watch ? (
              <AdminButton
                disabled={modal.busy || folderFlow.busy || (folderFlow.location?.isRoot && !folderFlow.confirmRoot)}
                onClick={() => void folderFlow.startWatch()}
                variant="primary"
              >
                {__('Start folder sync', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
            {folderMode && folderFlow.watch ? (
              <AdminButton onClick={onClose} variant="primary">
                {__('View in Sources', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
            {!folderMode ? (
              <AdminButton
                disabled={modal.busy || !modal.canAttach}
                onClick={() => modal.attach()}
                variant="primary"
              >
                {target.mode === 'new'
                  ? modal.selectedCount > 1
                    ? sprintf(
                      /* translators: %d: number of drafts to create. */
                      __('Create %d drafts', 'brasth-document-sync-for-google-docs'),
                      modal.selectedCount
                    )
                    : __('Create synced draft', 'brasth-document-sync-for-google-docs')
                  : __('Link source', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
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
