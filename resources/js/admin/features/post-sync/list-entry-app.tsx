import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { type SourceRecord, type SyncResult } from '../../api';
import { DocSourceModal, type DocSourceTarget } from '../doc-source-modal/doc-source-modal';
import { AdminButton } from '../../shared/ui/admin-button';
import { BackgroundSyncPoller } from './background-sync-poller';
import { syncRowAction } from './post-list-row-action-sync';
import { refreshPostListTable, reloadPostListPage, updateListRowSource } from './post-sync-dom';
import { SyncToastStack, type SyncToast } from './sync-toast-stack';

type TrackedSync = {
  id: string;
  created: boolean;
  postId: number;
};

export const ListEntryApp = ({ postType }: { postType: string }): JSX.Element => {
  const [modalTarget, setModalTarget] = useState<DocSourceTarget | null>(null);
  const [toasts, setToasts] = useState<SyncToast[]>([]);
  const [trackedSyncs, setTrackedSyncs] = useState<TrackedSync[]>([]);

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
        await syncRowAction(link, postId, showToast);
        return;
      }

      setModalTarget({ mode: 'existing', postId, postType: rowPostType });
    };

    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, [postType]);

  const onCompleted = (result: SyncResult) => {
    updateListRowSource(result.source ?? null);

    if (result.queued || result.status === 'queued') {
      trackBackgroundSync(result);
      return;
    }

    const message = sprintf(__('Sync %s.', 'docsync-wp'), result.status);
    showToast({
      id: `sync-${result.postId}-${Date.now()}`,
      message,
      title: __('DocSync WP', 'docsync-wp'),
      tone: 'success'
    });
    speak(message);
  };

  const dismissToast = (id: string) => {
    setToasts((current) => current.filter((toast) => toast.id !== id));
  };

  const showToast = (toast: Omit<SyncToast, 'onDismiss'>) => {
    setToasts((current) => [
      ...current.filter((currentToast) => currentToast.id !== toast.id),
      {
        ...toast,
        onDismiss: () => dismissToast(toast.id)
      }
    ]);
  };

  const stopTracking = (syncId: string) => {
    setTrackedSyncs((current) => current.filter((sync) => sync.id !== syncId));
  };

  const trackBackgroundSync = (result: SyncResult) => {
    const syncId = `sync-${result.postId}-${Date.now()}`;
    const message = result.created
      ? __('Draft created. Syncing Google Doc in the background.', 'docsync-wp')
      : __('Google Doc sync queued.', 'docsync-wp');

    setTrackedSyncs((current) => [...current, {
      id: syncId,
      created: Boolean(result.created),
      postId: result.postId
    }]);
    showToast({
      busy: true,
      id: syncId,
      message,
      title: __('Sync queued', 'docsync-wp'),
      tone: 'info'
    });
    speak(message);
  };

  const handleTerminalStatus = async (sync: TrackedSync, source: SourceRecord) => {
    const status = source.syncStatus || 'synced';
    const isError = status === 'error';
    const refreshed = sync.created ? await refreshPostListTable() : true;
    const message = isError
      ? source.syncError || __('Google Doc sync failed.', 'docsync-wp')
      : sprintf(__('Google Doc sync %s.', 'docsync-wp'), status);

    updateListRowSource(source);
    stopTracking(sync.id);
    showToast({
      actionLabel: refreshed ? undefined : __('Reload', 'docsync-wp'),
      id: sync.id,
      message: refreshed ? message : `${message} ${__('Reload to see the updated list.', 'docsync-wp')}`,
      onAction: refreshed ? undefined : reloadPostListPage,
      title: isError ? __('Sync failed', 'docsync-wp') : __('Sync complete', 'docsync-wp'),
      tone: isError ? 'error' : 'success'
    });
    speak(message, isError ? 'assertive' : 'polite');
  };

  const handlePollingError = (sync: TrackedSync, message: string) => {
    stopTracking(sync.id);
    showToast({
      actionLabel: __('Reload', 'docsync-wp'),
      id: sync.id,
      message,
      onAction: reloadPostListPage,
      title: __('Sync status unavailable', 'docsync-wp'),
      tone: 'error'
    });
    speak(message, 'assertive');
  };

  const handlePollingTimeout = (sync: TrackedSync) => {
    const message = __('Still syncing. The source status remains visible in the list.', 'docsync-wp');

    stopTracking(sync.id);
    showToast({
      actionLabel: __('Reload', 'docsync-wp'),
      id: sync.id,
      message,
      onAction: reloadPostListPage,
      title: __('Still syncing', 'docsync-wp'),
      tone: 'warning'
    });
    speak(message);
  };

  return (
    <Fragment>
      <AdminButton className="docsync-wp-add-sync-doc" onClick={() => setModalTarget({ mode: 'new', postType })} variant="primary">
        {__('Add Sync Doc', 'docsync-wp')}
      </AdminButton>
      <SyncToastStack toasts={toasts} />
      {trackedSyncs.map((sync) => (
        <BackgroundSyncPoller
          key={sync.id}
          onError={(message) => handlePollingError(sync, message)}
          onStatus={(source) => updateListRowSource(source)}
          onTerminal={(source) => handleTerminalStatus(sync, source).catch(() => undefined)}
          onTimeout={() => handlePollingTimeout(sync)}
          postId={sync.postId}
        />
      ))}
      <DocSourceModal
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={onCompleted}
        target={modalTarget}
      />
    </Fragment>
  );
};
