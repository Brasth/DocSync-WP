import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SourceRecord, SyncResult } from '../../api';
import { DocSourceModal, type DocSourceTarget } from '../doc-source-modal/doc-source-modal';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import { SyncProgress } from '../../shared/ui/sync-progress';
import { BackgroundSyncPoller } from './background-sync-poller';
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
        ? nextSource?.syncMessage || __('Google Doc sync queued.', 'docsync-wp')
        : actions.source ? __('Google Doc changed.', 'docsync-wp') : __('Google Doc linked.', 'docsync-wp')
    });
  };

  const onTerminal = (source: SourceRecord) => {
    const isError = source.syncStatus === 'error';
    actions.setSource(source);
    const message = isError ? source.syncError || __('Google Doc sync failed.', 'docsync-wp') : source.syncMessage || __('Google Doc sync complete.', 'docsync-wp');

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
    const message = __('Still syncing. Refresh this editor to check again later.', 'docsync-wp');

    actions.setNotice({ type: 'warning', message });
    speak(message);
  };

  return (
    <Fragment>
      <div className="docsync-wp-post-box">
        <p>{sourceLabel(actions.source)}</p>
        {actions.source ? (
          <SyncProgress
            message={actions.source.syncMessage}
            progress={actions.source.syncProgress}
          />
        ) : null}
        {actions.source?.lastSyncedAt ? <p><strong>{__('Last sync:', 'docsync-wp')}</strong> {actions.source.lastSyncedAt}</p> : null}
        {actions.source?.syncError ? <p className="docsync-wp-list-error">{actions.source.syncError}</p> : null}
        <AdminNotice className="inline" notice={actions.notice} />
        <div className="docsync-wp-post-box__actions">
          <AdminButton disabled={actions.busy || isSyncing} onClick={() => setModalTarget({ mode: 'existing', postId, postType })} variant="primary">
            {actions.source ? __('Change Doc', 'docsync-wp') : __('Link Google Doc', 'docsync-wp')}
          </AdminButton>
          {actions.source ? <AdminButton disabled={actions.busy} onClick={actions.syncNow}>{__('Sync now', 'docsync-wp')}</AdminButton> : null}
          {actions.source ? <AdminButton disabled={actions.busy || isSyncing} onClick={actions.detach} variant="delete">{__('Detach', 'docsync-wp')}</AdminButton> : null}
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
