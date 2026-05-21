import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { syncSource, type SyncResult } from '../../api';
import { DocSourceModal, type DocSourceTarget } from '../doc-source-modal/doc-source-modal';
import { AdminButton } from '../../shared/ui/admin-button';
import type { AdminNoticeState } from '../../shared/ui/admin-notice';
import { InlineNotice } from './inline-notice';
import { updateListRowSource } from './post-sync-dom';

export const ListEntryApp = ({ postType }: { postType: string }): JSX.Element => {
  const [modalTarget, setModalTarget] = useState<DocSourceTarget | null>(null);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);

  useEffect(() => {
    const onClick = async (event: MouseEvent) => {
      const link = (event.target as Element | null)?.closest('.docsync-wp-row-action') as HTMLElement | null;

      if (!link) {
        return;
      }

      event.preventDefault();
      const postId = Number(link.dataset.postId ?? 0);
      const rowPostType = link.dataset.postType ?? postType;

      if (link.dataset.mode === 'sync') {
        await syncRowAction(link, postId, setNotice);
        return;
      }

      setModalTarget({ mode: 'existing', postId, postType: rowPostType });
    };

    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, [postType]);

  const onCompleted = (result: SyncResult) => {
    const editLink = result.source?.editUrl;
    updateListRowSource(result.source ?? null);
    setNotice({
      type: 'success',
      message: result.created && editLink ? __('Synced draft created. Refreshing list...', 'docsync-wp') : sprintf(__('Sync %s.', 'docsync-wp'), result.status)
    });

    if (result.created) {
      window.setTimeout(() => window.location.reload(), 600);
    }
  };

  return (
    <Fragment>
      <AdminButton className="docsync-wp-add-sync-doc" onClick={() => setModalTarget({ mode: 'new', postType })} variant="primary">
        {__('Add Sync Doc', 'docsync-wp')}
      </AdminButton>
      <InlineNotice notice={notice} />
      <DocSourceModal
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={onCompleted}
        target={modalTarget}
      />
    </Fragment>
  );
};

const syncRowAction = async (
  link: HTMLElement,
  postId: number,
  setNotice: (notice: AdminNoticeState) => void
) => {
  if (link.dataset.busy === 'true') {
    return;
  }

  const originalText = link.textContent || __('Sync Doc', 'docsync-wp');

  try {
    link.dataset.busy = 'true';
    link.setAttribute('aria-busy', 'true');
    link.textContent = __('Syncing...', 'docsync-wp');
    const result = await syncSource(postId);
    const message = sprintf(__('Source %d sync %s.', 'docsync-wp'), postId, result.status);
    updateListRowSource(result.source ?? null);

    if (!result.source) {
      link.textContent = originalText;
    }

    setNotice({ type: 'success', message });
    speak(message);
  } catch (caught) {
    const message = caught instanceof Error ? caught.message : __('Sync failed.', 'docsync-wp');
    link.textContent = originalText;
    setNotice({ type: 'error', message });
    speak(message, 'assertive');
  } finally {
    delete link.dataset.busy;
    link.removeAttribute('aria-busy');
  }
};
