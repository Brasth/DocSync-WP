import { createElement, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord, UpdateFolderWatchPayload } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { StatusPill } from '../../shared/ui/status-pill';
import { FolderWatchEditForm, type FolderWatchEditDraft } from './folder-watch-edit-form';
import { formatScanTiming } from './folder-watch-format-time';
import { FolderWatchInventoryPanel } from './folder-watch-inventory-panel';
import { foldersScreenUrl, sourcesForWatchUrl, watchScheduleLabel, watchStatusLabel } from './folder-watch-labels';
import { buildUpdatePayload, draftFromWatch } from './folder-watch-update-helpers';
import { useFolderWatchInventory } from './use-folder-watch-inventory';

type Props = {
  busy: boolean;
  watch: FolderWatchRecord;
  onBack: () => void;
  onPause: (watchId: string) => Promise<void>;
  onRemove: (watchId: string) => Promise<void>;
  onResume: (watchId: string) => Promise<void>;
  onRetry: (watchId: string) => Promise<void>;
  onSave: (watchId: string, payload: UpdateFolderWatchPayload) => Promise<FolderWatchRecord>;
  onScan: (watchId: string) => Promise<void>;
};

export const FolderWatchDetailPage = ({
  busy,
  watch,
  onBack,
  onPause,
  onRemove,
  onResume,
  onRetry,
  onSave,
  onScan
}: Props): JSX.Element => {
  const [draft, setDraft] = useState<FolderWatchEditDraft>(draftFromWatch(watch));
  const [excludedFileIds, setExcludedFileIds] = useState<string[]>(watch.excludedFileIds ?? []);
  const [removeOpen, setRemoveOpen] = useState(false);
  const { inventory, loading, error } = useFolderWatchInventory(watch, draft.includeSubfolders);
  const scanTiming = formatScanTiming(watch.lastScanAt, watch.nextScanAt);

  useEffect(() => {
    setDraft(draftFromWatch(watch));
    setExcludedFileIds(watch.excludedFileIds ?? []);
  }, [watch.id]);

  const handleSave = async () => {
    try {
      const updated = await onSave(watch.id, buildUpdatePayload(watch, draft, excludedFileIds));
      setDraft(draftFromWatch(updated));
      setExcludedFileIds(updated.excludedFileIds ?? []);
    } catch {
      // Keep unsaved edits when persistence fails.
    }
  };

  return (
    <div className="docsync-wp-folder-watch-detail-page">
      <div className="docsync-wp-folder-watch-detail-page__toolbar">
        <AdminButton onClick={onBack} variant="link">
          {__('← Back to Drive Folders', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        <div className="docsync-wp-folder-watch-detail-page__quick-actions">
          <AdminButton disabled={busy || watch.status === 'paused'} onClick={() => void onScan(watch.id)} size="small">
            {__('Scan now', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
          {watch.status === 'paused' ? (
            <AdminButton disabled={busy} onClick={() => void onResume(watch.id)} size="small">
              {__('Resume', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          ) : (
            <AdminButton disabled={busy} onClick={() => void onPause(watch.id)} size="small">
              {__('Pause', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          )}
          <a className="button button-secondary docsync-wp-button docsync-wp-button--small" href={sourcesForWatchUrl(watch.id)}>
            {__('View synced posts', 'brasth-document-sync-for-google-docs')}
          </a>
          <AdminButton disabled={busy} onClick={() => setRemoveOpen(true)} size="small">
            {__('Remove', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      </div>

      <header className="docsync-wp-folder-watch-detail-page__header">
        <div>
          <p className="docsync-wp-folder-watch-detail-page__eyebrow">{__('Folder watch', 'brasth-document-sync-for-google-docs')}</p>
          <h1 className="docsync-wp-folder-watch-detail-page__title">
            {watch.webViewLink ? (
              <a href={watch.webViewLink} rel="noreferrer" target="_blank">{watch.folderName}</a>
            ) : watch.folderName}
          </h1>
          <div className="docsync-wp-folder-watch-detail-page__meta">
            {watch.ownerDisplayName ? (
              <span>{sprintf(
                /* translators: %s: WordPress user display name. */
                __('Owner: %s', 'brasth-document-sync-for-google-docs'),
                watch.ownerDisplayName
              )}</span>
            ) : null}
            <span className="docsync-wp-row-tag">{watch.postType}</span>
            <span className="docsync-wp-row-tag">{watch.postStatus}</span>
            <span>{watchScheduleLabel(watch)}</span>
            {watch.includeSubfolders ? (
              <span className="docsync-wp-row-tag">{__('Subfolders', 'brasth-document-sync-for-google-docs')}</span>
            ) : null}
          </div>
        </div>
        <div className="docsync-wp-folder-watch-detail-page__status">
          <StatusPill status={watchStatusLabel(watch.status)} />
          <p className="docsync-wp-tabular" title={scanTiming.title}>
            {scanTiming.label}
          </p>
          <p className="docsync-wp-tabular">
            {sprintf(
              /* translators: 1: imported count, 2: pending count, 3: failed count. */
              __('%1$d imported · %2$d pending · %3$d failed', 'brasth-document-sync-for-google-docs'),
              watch.importedCount,
              watch.pendingCount,
              watch.failed.length
            )}
          </p>
          {watch.lastError ? (
            <p className="docsync-wp-inline-warning">{watch.lastError}</p>
          ) : null}
        </div>
      </header>

      <div className="docsync-wp-folder-watch-detail-page__grid">
        <section className="docsync-wp-card docsync-wp-folder-watch-detail-page__settings">
          <h2>{__('Watch settings', 'brasth-document-sync-for-google-docs')}</h2>
          <FolderWatchEditForm busy={busy} draft={draft} nextScanAt={watch.nextScanAt} onChange={setDraft} postType={watch.postType} />
          <div className="docsync-wp-folder-watch-detail-page__save">
            <AdminButton disabled={busy} onClick={onBack}>{__('Cancel', 'brasth-document-sync-for-google-docs')}</AdminButton>
            <AdminButton
              disabled={busy}
              onClick={() => void handleSave()}
              variant="primary"
            >
              {__('Save changes', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        </section>

        <FolderWatchInventoryPanel
          busy={busy}
          excludedFileIds={excludedFileIds}
          failed={watch.failed}
          inventory={inventory}
          inventoryError={error}
          loading={loading}
          onExcludeToggle={(fileId) => {
            setExcludedFileIds((current) => current.includes(fileId)
              ? current.filter((id) => id !== fileId)
              : [...current, fileId]);
          }}
          onExcludedChange={setExcludedFileIds}
          onRetryFailed={() => void onRetry(watch.id)}
        />
      </div>

      <ConfirmDialog
        busy={busy}
        confirmLabel={__('Remove watch', 'brasth-document-sync-for-google-docs')}
        description={__('This stops watching the Drive folder. Synced drafts and posts stay in WordPress.', 'brasth-document-sync-for-google-docs')}
        open={removeOpen}
        title={__('Stop watching this folder?', 'brasth-document-sync-for-google-docs')}
        onConfirm={() => {
          setRemoveOpen(false);
          void onRemove(watch.id);
        }}
        onOpenChange={setRemoveOpen}
      />
    </div>
  );
};

export const folderWatchDetailUrl = (watchId: string): string => {
  return `${foldersScreenUrl}&watch=${encodeURIComponent(watchId)}`;
};
