import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getSourceContent, type SourceRecord, type SyncResult } from '../../api';
import { DocSourceModal, type DocSourceTarget } from '../doc-source-modal/doc-source-modal';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import { shouldShowSyncProgress, SyncProgress } from '../../shared/ui/sync-progress';
import { BackgroundSyncPoller } from './background-sync-poller';
import { applyPostContentToEditor, getEditorDirtyState } from './post-editor-content';
import { sourceLabel } from './post-sync-dom';
import { usePostSyncActions } from './use-post-sync-actions';

type Props = {
  postId: number;
  postType: string;
  initialSource: SourceRecord | null;
};

export const PostMetaBoxApp = ({ postId, postType, initialSource }: Props): JSX.Element => {
  const [modalTarget, setModalTarget] = useState<DocSourceTarget | null>(null);
  const actions = usePostSyncActions(postId, initialSource);
  const isSyncing = actions.source?.syncStatus === 'syncing';

  const onCompleted = (result: SyncResult) => {
    const nextSource = result.source ?? actions.source;
    const queued = result.queued || result.status === 'queued' || nextSource?.syncStatus === 'syncing';

    actions.setSource(nextSource);
    actions.setNotice({
      type: queued ? 'info' : 'success',
      message: queued
        ? nextSource?.syncMessage || __('Google Doc sync queued.', 'brasth-document-sync-for-google-docs')
        : actions.source ? __('Google Doc changed.', 'brasth-document-sync-for-google-docs') : __('Google Doc linked.', 'brasth-document-sync-for-google-docs')
    });
  };

  const applySyncedContent = async (source: SourceRecord) => {
    actions.setNotice({ type: 'info', message: __('Applying synced Google Doc content.', 'brasth-document-sync-for-google-docs') });

    try {
      const response = await getSourceContent(source.postId);
      const applied = applyPostContentToEditor(response.content);

      actions.setSource(response.source ?? source);

      if (!applied) {
        const message = __('Google Doc sync complete, but this editor cannot be updated without reopening the screen.', 'brasth-document-sync-for-google-docs');
        actions.setNotice({ type: 'warning', message });
        speak(message, 'assertive');
        return;
      }

      const message = __('Synced Google Doc content applied to the editor.', 'brasth-document-sync-for-google-docs');
      actions.setNotice({ type: 'success', message });
      speak(message, 'polite');
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not load synced Google Doc content.', 'brasth-document-sync-for-google-docs');
      actions.setNotice({ type: 'error', message });
      speak(message, 'assertive');
    }
  };

  const onTerminal = (source: SourceRecord) => {
    const isError = source.syncStatus === 'error';
    actions.setSource(source);
    const message = isError ? source.syncError || __('Google Doc sync failed.', 'brasth-document-sync-for-google-docs') : source.syncMessage || __('Google Doc sync complete.', 'brasth-document-sync-for-google-docs');

    if (!isError) {
      const isDirty = getEditorDirtyState();

      if (isDirty === false) {
        applySyncedContent(source).catch(() => undefined);
        return;
      }

      actions.setNotice({
        actionLabel: __('Apply synced content', 'brasth-document-sync-for-google-docs'),
        onAction: () => {
          applySyncedContent(source).catch(() => undefined);
        },
        type: isDirty ? 'warning' : 'success',
        message: isDirty
          ? __('Google Doc sync complete. Applying it will replace the current editor content.', 'brasth-document-sync-for-google-docs')
          : __('Google Doc sync complete. Apply the synced content to the editor.', 'brasth-document-sync-for-google-docs')
      });
      speak(message, 'polite');
      return;
    }

    actions.setNotice({
      type: isError ? 'error' : 'success',
      message
    });
    speak(message, isError ? 'assertive' : 'polite');
  };

  const onPollingError = (message: string) => {
    actions.setNotice({ type: 'error', message });
    speak(message, 'assertive');
  };

  const onPollingTimeout = () => {
    const message = __('Still syncing. Leave this editor open to keep checking progress.', 'brasth-document-sync-for-google-docs');

    actions.setNotice({ type: 'warning', message });
    speak(message);
  };

  return (
    <Fragment>
      <div className="docsync-wp-post-box">
        <p>{sourceLabel(actions.source)}</p>
        {shouldShowSyncProgress(actions.source) ? (
          <SyncProgress
            message={actions.source.syncMessage}
            progress={actions.source.syncProgress}
            updatedAt={actions.source.syncUpdatedAt}
          />
        ) : null}
        {actions.source?.lastSyncedAt ? <p><strong>{__('Last sync:', 'brasth-document-sync-for-google-docs')}</strong> {actions.source.lastSyncedAt}</p> : null}
        {actions.source?.syncError ? <p className="docsync-wp-list-error">{actions.source.syncError}</p> : null}
        <AdminNotice className="inline" notice={actions.notice} />
        <div className="docsync-wp-post-box__actions">
          <AdminButton disabled={actions.busy || isSyncing} onClick={() => setModalTarget({ mode: 'existing', postId, postType })} variant="primary">
            {actions.source ? __('Change Doc', 'brasth-document-sync-for-google-docs') : __('Link Google Doc', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
          {actions.source ? <AdminButton disabled={actions.busy} onClick={actions.syncNow}>{__('Sync now', 'brasth-document-sync-for-google-docs')}</AdminButton> : null}
          {actions.source ? <AdminButton disabled={actions.busy || isSyncing} onClick={actions.detach} variant="delete">{__('Detach', 'brasth-document-sync-for-google-docs')}</AdminButton> : null}
        </div>
      </div>
      {actions.source?.syncStatus === 'syncing' ? (
        <BackgroundSyncPoller
          onError={onPollingError}
          onStatus={actions.setSource}
          onTerminal={onTerminal}
          onTimeout={onPollingTimeout}
          postId={postId}
        />
      ) : null}
      <DocSourceModal
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={onCompleted}
        target={modalTarget}
      />
    </Fragment>
  );
};
