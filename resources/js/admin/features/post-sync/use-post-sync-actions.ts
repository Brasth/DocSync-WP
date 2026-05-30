import { speak } from '@wordpress/a11y';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { detachSource, syncSource, type SourceRecord } from '../../api';
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
      const message = result.source?.syncMessage || __('Google Doc sync queued.', 'docsync-wp');
      setSource(result.source ?? source);
      setNotice({ type: 'info', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Sync failed.', 'docsync-wp');
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
      const message = __('Google Doc detached.', 'docsync-wp');
      setSource(null);
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Detach failed.', 'docsync-wp');
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
    syncNow
  };
};
