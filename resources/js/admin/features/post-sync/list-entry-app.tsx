import { speak } from '@wordpress/a11y';
import { createElement, Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { type SourceRecord, type SyncResult } from '../../api';
import type { ImportCommitResult } from '../../api/journey-types';
import type { AddContentView } from '../add-content/use-add-content';
import { DocSourceModal, type DocSourceTarget } from '../doc-source-modal/doc-source-modal';
import { AdminButton } from '../../shared/ui/admin-button';
import { isQueuedSync } from '../../shared/ui/sync-progress';
import { BackgroundSyncPoller } from './background-sync-poller';
import { syncRowAction } from './post-list-row-action-sync';
import { refreshPostListTable, reloadPostListPage, updateListRowSource } from './post-sync-dom';
import { SyncToastStack, type SyncToast } from './sync-toast-stack';

type TrackedSync = {
  id: string;
  created: boolean;
  postId: number;
};

/** The server marks the mount with the user's upload_files capability. */
const listCanUpload = (): boolean => document.getElementById('docsync-wp-list-sync-root')?.dataset.canUpload === 'true';

export const ListEntryApp = ({ postType }: { postType: string }): JSX.Element => {
  const canUpload = listCanUpload();
  const [modalTarget, setModalTarget] = useState<DocSourceTarget | null>(null);
  const [modalView, setModalView] = useState<AddContentView>('google');
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
        await syncRowAction(link, postId, showToast, trackBackgroundSync);
        return;
      }

      setModalView('google');
      setModalTarget({
        mode: 'existing',
        postId,
        postType: rowPostType,
        elementorSync: link.dataset.defaultElementorSync === 'true'
      });
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

    // Linking an existing post is attach-only: the post keeps its content until it syncs.
    const message = result.status === 'linked'
      ? __('Google Doc linked. This post keeps its content until its next sync.', 'brasth-document-sync-for-google-docs')
      : sprintf(__('Sync %s.', 'brasth-document-sync-for-google-docs'), result.status);
    showToast({
      id: `sync-${result.postId}-${Date.now()}`,
      message,
      title: __('Brasth Document Sync', 'brasth-document-sync-for-google-docs'),
      tone: 'success'
    });
    speak(message);
  };

  const openAddContent = (view: AddContentView) => {
    setModalView(canUpload ? view : 'google');
    setModalTarget({ mode: 'new', postType });
  };

  /* Uploads commit drafts only; the list re-renders so the new drafts appear in place. */
  const onImported = async (result: ImportCommitResult) => {
    const created = result.files.filter((file) => file.status === 'created').length;
    const failed = result.files.filter((file) => file.status === 'failed').length;

    if (created === 0 && failed === 0) {
      return;
    }

    const refreshed = created > 0 ? await refreshPostListTable() : true;
    const message = failed > 0
      ? sprintf(
        /* translators: 1: drafts created, 2: files that failed. */
        __('%1$d drafts created, %2$d files failed. Open Add content to see why.', 'brasth-document-sync-for-google-docs'),
        created,
        failed
      )
      : sprintf(
        /* translators: %d: drafts created from uploaded files. */
        _n('%d draft created from your upload.', '%d drafts created from your uploads.', created, 'brasth-document-sync-for-google-docs'),
        created
      );

    showToast({
      actionLabel: refreshed ? undefined : __('Reload', 'brasth-document-sync-for-google-docs'),
      id: `import-${result.idempotencyKey}`,
      message: refreshed ? message : `${message} ${__('Reload to see the updated list.', 'brasth-document-sync-for-google-docs')}`,
      onAction: refreshed ? undefined : reloadPostListPage,
      title: __('Brasth Document Sync', 'brasth-document-sync-for-google-docs'),
      tone: failed > 0 ? 'warning' : 'success'
    });
    speak(message, failed > 0 ? 'assertive' : 'polite');
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
    const message = __('Waiting for the background worker.', 'brasth-document-sync-for-google-docs');

    setTrackedSyncs((current) => [...current, {
      id: syncId,
      created: Boolean(result.created),
      postId: result.postId
    }]);
    showToast({
      busy: true,
      id: syncId,
      indeterminate: true,
      message,
      progress: result.source?.syncProgress,
      title: __('Sync queued', 'brasth-document-sync-for-google-docs'),
      tone: 'info'
    });
    speak(message);
  };

  const handleTerminalStatus = async (sync: TrackedSync, source: SourceRecord) => {
    const status = source.syncStatus || 'synced';
    const isError = status === 'error';
    const refreshed = sync.created ? await refreshPostListTable() : true;
    const message = isError
      ? source.syncError || __('Google Doc sync failed.', 'brasth-document-sync-for-google-docs')
      : sprintf(__('Google Doc sync %s.', 'brasth-document-sync-for-google-docs'), status);

    updateListRowSource(source);
    stopTracking(sync.id);
    showToast({
      actionLabel: refreshed ? undefined : __('Reload', 'brasth-document-sync-for-google-docs'),
      id: sync.id,
      message: refreshed ? message : `${message} ${__('Reload to see the updated list.', 'brasth-document-sync-for-google-docs')}`,
      onAction: refreshed ? undefined : reloadPostListPage,
      title: isError ? __('Sync failed', 'brasth-document-sync-for-google-docs') : __('Sync complete', 'brasth-document-sync-for-google-docs'),
      tone: isError ? 'error' : 'success'
    });
    speak(message, isError ? 'assertive' : 'polite');
  };

  const handleProgressStatus = (sync: TrackedSync, source: SourceRecord) => {
    const queued = isQueuedSync(source);

    updateListRowSource(source);
    showToast({
      busy: true,
      id: sync.id,
      indeterminate: queued,
      message: queued ? __('Waiting for the background worker.', 'brasth-document-sync-for-google-docs') : source.syncMessage || __('Google Doc sync is running.', 'brasth-document-sync-for-google-docs'),
      progress: source.syncProgress,
      title: queued ? __('Sync queued', 'brasth-document-sync-for-google-docs') : __('Syncing Google Doc', 'brasth-document-sync-for-google-docs'),
      tone: 'info'
    });
  };

  const handlePollingError = (sync: TrackedSync, message: string) => {
    stopTracking(sync.id);
    showToast({
      actionLabel: __('Reload', 'brasth-document-sync-for-google-docs'),
      id: sync.id,
      message,
      onAction: reloadPostListPage,
      title: __('Sync status unavailable', 'brasth-document-sync-for-google-docs'),
      tone: 'error'
    });
    speak(message, 'assertive');
  };

  const handlePollingTimeout = (sync: TrackedSync) => {
    const message = __('Still syncing. The source status remains visible in the list.', 'brasth-document-sync-for-google-docs');

    stopTracking(sync.id);
    showToast({
      actionLabel: __('Reload', 'brasth-document-sync-for-google-docs'),
      id: sync.id,
      message,
      onAction: reloadPostListPage,
      title: __('Still syncing', 'brasth-document-sync-for-google-docs'),
      tone: 'warning'
    });
    speak(message);
  };

  return (
    <Fragment>
      <AdminButton className="docsync-wp-add-sync-doc" onClick={() => openAddContent('google')} variant="primary">
        {__('Add content', 'brasth-document-sync-for-google-docs')}
      </AdminButton>
      {canUpload ? (
        <AdminButton className="docsync-wp-add-sync-doc" onClick={() => openAddContent('upload')} variant="secondary">
          {__('Upload files', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
      ) : null}
      <SyncToastStack toasts={toasts} />
      {trackedSyncs.map((sync) => (
        <BackgroundSyncPoller
          key={sync.id}
          onError={(message) => handlePollingError(sync, message)}
          onStatus={(source) => handleProgressStatus(sync, source)}
          onTerminal={(source) => handleTerminalStatus(sync, source).catch(() => undefined)}
          onTimeout={() => handlePollingTimeout(sync)}
          postId={sync.postId}
        />
      ))}
      <DocSourceModal
        initialView={modalView}
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={onCompleted}
        onImported={(result) => {
          void onImported(result);
        }}
        target={modalTarget}
      />
    </Fragment>
  );
};
