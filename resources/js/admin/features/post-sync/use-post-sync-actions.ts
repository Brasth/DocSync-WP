import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { detachSource, syncSource, updateSource, type SourceRecord } from '../../api';
import type { AdminNoticeState } from '../../shared/ui/admin-notice';

export const usePostSyncActions = (postId: number, initialSource: SourceRecord | null) => {
  const [source, setSource] = useState<SourceRecord | null>(initialSource);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);

  const syncNow = async () => {
    setBusy(true);
    setNotice(null);

    try {
      const result = await syncSource(postId, 'background');
      const message = result.source?.syncMessage || __('Google Doc sync queued.', 'brasth-document-sync-for-google-docs');
      setSource(result.source ?? source);
      setNotice({ type: 'info', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Sync failed.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const detach = async () => {
    setBusy(true);
    setNotice(null);

    try {
      await detachSource(postId);
      const message = __('Google Doc detached.', 'brasth-document-sync-for-google-docs');
      setSource(null);
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Detach failed.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const updateElementorSync = async (elementorSync: boolean) => {
    setBusy(true);
    setNotice(null);

    try {
      const updated = await updateSource(postId, { elementorSync });
      setSource(updated);
      const message = elementorSync
        ? __('This post will sync as an Elementor layout.', 'brasth-document-sync-for-google-docs')
        : __('This post will sync as WordPress blocks.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not update Elementor sync preference.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const updateLayoutPreset = async (layoutPreset: string) => {
    setBusy(true);
    setNotice(null);

    try {
      const updated = await updateSource(postId, { layoutPreset });
      setSource(updated);
      const message = layoutPreset
        ? __('Layout preset updated.', 'brasth-document-sync-for-google-docs')
        : __('This post will use the site default layout preset.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not update layout preset.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const updateSyncInterval = async (syncInterval: string) => {
    setBusy(true);
    setNotice(null);

    try {
      const updated = await updateSource(postId, { syncInterval });
      setSource(updated);
      const message = syncInterval
        ? __('Re-sync schedule updated.', 'brasth-document-sync-for-google-docs')
        : __('This post will use the folder or site schedule.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not update re-sync schedule.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const updateElementorPreset = async (elementorPreset: string) => {
    setBusy(true);
    setNotice(null);

    try {
      const updated = await updateSource(postId, { elementorPreset });
      setSource(updated);
      const message = __('Elementor layout preset updated.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not update Elementor layout preset.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  return {
    busy,
    detach,
    notice,
    setNotice,
    setSource,
    source,
    syncNow,
    updateElementorSync,
    updateElementorPreset,
    updateLayoutPreset,
    updateSyncInterval
  };
};
