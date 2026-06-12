import { speak } from '@wordpress/a11y';
import { __, sprintf } from '@wordpress/i18n';

import { syncSource, type SyncResult } from '../../api';
import { updateListRowSource } from './post-sync-dom';
import type { SyncToast } from './sync-toast-stack';

export const syncRowAction = async (
  link: HTMLElement,
  postId: number,
  showToast: (toast: Omit<SyncToast, 'onDismiss'>) => void,
  onQueued: (result: SyncResult) => void
) => {
  if (link.dataset.busy === 'true') {
    return;
  }

  const originalText = link.textContent || __('Sync Doc', 'brasth-document-sync-for-google-docs');

  try {
    link.dataset.busy = 'true';
    link.setAttribute('aria-busy', 'true');
    link.textContent = __('Syncing...', 'brasth-document-sync-for-google-docs');
    const result = await syncSource(postId, 'background');
    const message = sprintf(__('Source %d sync %s.', 'brasth-document-sync-for-google-docs'), postId, result.status);

    updateListRowSource(result.source ?? null);

    if (result.queued || result.status === 'queued') {
      onQueued(result);
      link.textContent = originalText;
      return;
    }

    if (!result.source) {
      link.textContent = originalText;
    }

    showToast({
      id: `sync-${postId}-${Date.now()}`,
      message,
      title: __('Brasth Document Sync', 'brasth-document-sync-for-google-docs'),
      tone: 'success'
    });
    speak(message);
  } catch (caught) {
    const message = caught instanceof Error ? caught.message : __('Sync failed.', 'brasth-document-sync-for-google-docs');

    link.textContent = originalText;
    showToast({
      id: `sync-${postId}-${Date.now()}`,
      message,
      title: __('Sync failed', 'brasth-document-sync-for-google-docs'),
      tone: 'error'
    });
    speak(message, 'assertive');
  } finally {
    delete link.dataset.busy;
    link.removeAttribute('aria-busy');
  }
};
