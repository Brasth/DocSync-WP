import * as Dialog from '@radix-ui/react-dialog';
import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { listFolderDocuments, type FolderDocumentInventory, type FolderWatchRecord, type UpdateFolderWatchPayload } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { FolderWatchEditForm, type FolderWatchEditDraft } from './folder-watch-edit-form';
import { FolderWatchInventoryList } from './folder-watch-inventory-list';
import { sourcesForWatchUrl } from './folder-watch-labels';

type Props = {
  busy: boolean;
  watch: FolderWatchRecord | null;
  onClose: () => void;
  onRetry: (watchId: string) => Promise<void>;
  onSave: (watchId: string, payload: UpdateFolderWatchPayload) => Promise<void>;
};

const draftFromWatch = (watch: FolderWatchRecord): FolderWatchEditDraft => ({
  syncInterval: watch.syncInterval,
  postStatus: watch.postStatus === 'publish' ? 'publish' : 'draft',
  includeSubfolders: watch.includeSubfolders,
  layoutPreset: watch.layoutPreset,
  elementorSync: watch.elementorSync,
  elementorPreset: watch.elementorPreset
});

export const FolderWatchDetailDrawer = ({ busy, watch, onClose, onRetry, onSave }: Props): JSX.Element => {
  const [draft, setDraft] = useState<FolderWatchEditDraft | null>(watch ? draftFromWatch(watch) : null);
  const [excludedFileIds, setExcludedFileIds] = useState<string[]>(watch?.excludedFileIds ?? []);
  const [inventory, setInventory] = useState<FolderDocumentInventory | null>(null);

  useEffect(() => {
    if (!watch) {
      setDraft(null);
      setExcludedFileIds([]);
      setInventory(null);
      return;
    }

    setDraft(draftFromWatch(watch));
    setExcludedFileIds(watch.excludedFileIds ?? []);
    setInventory(null);
    void listFolderDocuments(watch.folderId, {
      driveId: watch.driveId || undefined,
      includeSubfolders: watch.includeSubfolders
    }).then(setInventory).catch(() => setInventory({
      documents: [],
      folderId: watch.folderId,
      driveId: watch.driveId,
      overflow: false,
      includeSubfolders: watch.includeSubfolders,
      scannedFolderCount: 0
    }));
  }, [watch]);

  useEffect(() => {
    if (!watch || !draft || draft.includeSubfolders === watch.includeSubfolders) {
      return;
    }

    void listFolderDocuments(watch.folderId, {
      driveId: watch.driveId || undefined,
      includeSubfolders: draft.includeSubfolders
    }).then(setInventory).catch(() => undefined);
  }, [draft?.includeSubfolders, watch]);

  const open = watch !== null && draft !== null;

  return (
    <Dialog.Root onOpenChange={(nextOpen) => {
      if (!nextOpen) {
        onClose();
      }
    }} open={open}>
      <Dialog.Portal>
        <Dialog.Overlay className="docsync-wp-modal-backdrop" />
        <Dialog.Content className="docsync-wp-folder-watch-drawer" aria-describedby={undefined}>
          <Dialog.Title>{watch?.folderName || __('Edit folder watch', 'brasth-document-sync-for-google-docs')}</Dialog.Title>
          {watch && draft ? (
            <>
              <FolderWatchEditForm busy={busy} draft={draft} onChange={setDraft} postType={watch.postType} />
              <FolderWatchInventoryList
                busy={busy}
                excludedFileIds={excludedFileIds}
                failed={watch.failed}
                inventory={inventory}
                onExcludeToggle={(fileId) => {
                  setExcludedFileIds((current) => current.includes(fileId)
                    ? current.filter((id) => id !== fileId)
                    : [...current, fileId]);
                }}
                onRetryFailed={() => void onRetry(watch.id)}
              />
              <div className="docsync-wp-folder-watch-drawer__actions">
                <a className="button button-secondary docsync-wp-button" href={sourcesForWatchUrl(watch.id)}>
                  {__('View synced posts', 'brasth-document-sync-for-google-docs')}
                </a>
                <AdminButton disabled={busy} onClick={onClose}>{__('Cancel', 'brasth-document-sync-for-google-docs')}</AdminButton>
                <AdminButton
                  disabled={busy}
                  onClick={() => void onSave(watch.id, {
                    syncInterval: draft.syncInterval as UpdateFolderWatchPayload['syncInterval'],
                    postStatus: draft.postStatus,
                    includeSubfolders: draft.includeSubfolders,
                    layoutPreset: draft.layoutPreset,
                    elementorSync: draft.elementorSync,
                    elementorPreset: draft.elementorPreset,
                    excludedFileIds
                  })}
                  variant="primary"
                >
                  {__('Save changes', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              </div>
            </>
          ) : null}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
};
