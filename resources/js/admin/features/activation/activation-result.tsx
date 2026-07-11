import { createElement, useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SourceRecord } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { StatusPill } from '../../shared/ui/status-pill';
import { isQueuedSync, shouldShowSyncProgress, SyncProgress } from '../../shared/ui/sync-progress';

type Props = {
  busy?: boolean;
  onRetry: () => void | Promise<void>;
  source: SourceRecord;
};

const sourcesUrl = 'admin.php?page=brasth-document-sync-for-google-docs-sources';

const safeErrorMessage = (source: SourceRecord): string => {
  switch (source.syncErrorCode) {
    case 'docsync_wp_google_not_connected':
    case 'docsync_wp_google_token_expired':
      return __('Reconnect your Google account, then retry this source.', 'brasth-document-sync-for-google-docs');
    case 'docsync_wp_download_blocked':
      return __('Google does not allow the connected account to download this Doc. Ask the document owner to allow access, then retry.', 'brasth-document-sync-for-google-docs');
    case 'docsync_wp_docs_api_unavailable':
      return __('A site administrator must enable Google Docs API in the connected Google Cloud project, then retry.', 'brasth-document-sync-for-google-docs');
    case 'docsync_wp_sync_stale':
      return __('The background job stopped updating. Retry from Sources; an administrator may need to verify WP-Cron.', 'brasth-document-sync-for-google-docs');
    default:
      return __('The sync could not complete. Review Sync Activity for safe diagnostics, then retry.', 'brasth-document-sync-for-google-docs');
  }
};

export const ActivationResult = ({ busy = false, onRetry, source }: Props): JSX.Element => {
  const activated = (source.syncStatus === 'synced' || source.syncStatus === 'skipped') && Boolean(source.lastSyncedAt);
  const failed = source.syncStatus === 'error';
  const terminal = activated || failed;
  const resultRef = useRef<HTMLElement | null>(null);
  const titleId = `docsync-wp-activation-result-title-${source.postId}`;

  useEffect(() => {
    if (!terminal) {
      return;
    }

    const timer = window.setTimeout(() => resultRef.current?.focus(), 0);

    return () => window.clearTimeout(timer);
  }, [activated, failed, source.postId]);

  return (
    <section
      aria-labelledby={titleId}
      className={`docsync-wp-activation-result${failed ? ' is-error' : activated ? ' is-success' : ''}`}
      ref={resultRef}
      tabIndex={terminal ? -1 : undefined}
    >
      <div>
        <StatusPill status={failed ? 'error' : activated ? source.syncStatus : 'syncing'} />
        <h2 id={titleId}>
          {failed
            ? __('First source could not sync', 'brasth-document-sync-for-google-docs')
            : activated
              ? __('Your synced draft is ready', 'brasth-document-sync-for-google-docs')
              : __('Creating your synced draft', 'brasth-document-sync-for-google-docs')}
        </h2>
        <p>
          {failed
            ? __('The existing WordPress content was not replaced. Resolve the connection or document access issue, then retry.', 'brasth-document-sync-for-google-docs')
            : activated
              ? __('The first source completed successfully. You can edit the WordPress draft or continue in Sources.', 'brasth-document-sync-for-google-docs')
              : __('The source is queued in the background. You may leave this page and return later.', 'brasth-document-sync-for-google-docs')}
        </p>
        {failed ? <small className="docsync-wp-source-error-text">{safeErrorMessage(source)}</small> : null}
        {shouldShowSyncProgress(source) ? <SyncProgress indeterminate={isQueuedSync(source)} message={source.syncMessage} progress={source.syncProgress} /> : null}
      </div>
      <div className="docsync-wp-actions-row">
        {activated ? <a className="button button-primary" href={source.editUrl}>{__('Open draft', 'brasth-document-sync-for-google-docs')}</a> : null}
        {failed ? <AdminButton disabled={busy} onClick={onRetry} variant="primary">{__('Retry sync', 'brasth-document-sync-for-google-docs')}</AdminButton> : null}
        <a className="button button-secondary" href={sourcesUrl}>{__('View Sources', 'brasth-document-sync-for-google-docs')}</a>
      </div>
    </section>
  );
};
