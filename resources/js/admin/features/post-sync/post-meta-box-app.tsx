import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SourceRecord, SyncResult } from '../../api';
import { DocSourceModal, type DocSourceTarget } from '../doc-source-modal/doc-source-modal';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
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

  const onCompleted = (result: SyncResult) => {
    actions.setSource(result.source ?? actions.source);
    actions.setNotice({
      type: 'success',
      message: actions.source ? __('Google Doc changed.', 'docsync-wp') : __('Google Doc linked.', 'docsync-wp')
    });
  };

  return (
    <Fragment>
      <div className="docsync-wp-post-box">
        <p>{sourceLabel(actions.source)}</p>
        {actions.source?.lastSyncedAt ? <p><strong>{__('Last sync:', 'docsync-wp')}</strong> {actions.source.lastSyncedAt}</p> : null}
        {actions.source?.syncError ? <p className="docsync-wp-list-error">{actions.source.syncError}</p> : null}
        <AdminNotice className="inline" notice={actions.notice} />
        <div className="docsync-wp-post-box__actions">
          <AdminButton disabled={actions.busy} onClick={() => setModalTarget({ mode: 'existing', postId, postType })} variant="primary">
            {actions.source ? __('Change Doc', 'docsync-wp') : __('Link Google Doc', 'docsync-wp')}
          </AdminButton>
          {actions.source ? <AdminButton disabled={actions.busy} onClick={actions.syncNow}>{__('Sync now', 'docsync-wp')}</AdminButton> : null}
          {actions.source ? <AdminButton disabled={actions.busy} onClick={actions.detach} variant="delete">{__('Detach', 'docsync-wp')}</AdminButton> : null}
        </div>
      </div>
      <DocSourceModal
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={onCompleted}
        target={modalTarget}
      />
    </Fragment>
  );
};
