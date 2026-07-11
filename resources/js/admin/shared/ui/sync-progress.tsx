import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type Props = {
  className?: string;
  indeterminate?: boolean;
  message?: string;
  progress?: number;
  showDetails?: boolean;
  updatedAt?: string;
};

export const shouldShowSyncProgress = <T extends { syncStatus?: string }>(source: T | null | undefined): source is T => {
  return source?.syncStatus === 'syncing';
};

export const isQueuedSync = <T extends { syncStatus?: string; syncStep?: string }>(source: T | null | undefined): boolean => {
  return source?.syncStatus === 'syncing' && source.syncStep === 'queued';
};

export const normalizeSyncProgress = (progress: number | null | undefined): number => {
  if (typeof progress !== 'number' || Number.isNaN(progress)) {
    return 0;
  }

  return Math.max(0, Math.min(100, Math.round(progress)));
};

export const SyncProgress = ({ className = '', indeterminate = false, message = '', progress, showDetails = true, updatedAt = '' }: Props): JSX.Element => {
  const percent = normalizeSyncProgress(progress);
  const label = indeterminate
    ? __('Waiting for the background worker.', 'brasth-document-sync-for-google-docs')
    : sprintf(__('Sync progress: %d%%', 'brasth-document-sync-for-google-docs'), percent);
  const progressDetails = indeterminate ? message || __('Waiting for the background worker.', 'brasth-document-sync-for-google-docs') : message ? `${percent}% - ${message}` : `${percent}%`;
  const details = updatedAt
    ? sprintf(__('%1$s. Last update: %2$s', 'brasth-document-sync-for-google-docs'), progressDetails, updatedAt)
    : progressDetails;

  return (
    <div className={`docsync-wp-sync-progress-wrap ${className}`.trim()}>
      <div
        aria-label={label}
        aria-valuemax={100}
        aria-valuemin={0}
        aria-valuenow={indeterminate ? undefined : percent}
        className={`docsync-wp-sync-progress${indeterminate ? ' is-indeterminate' : ''}`}
        role="progressbar"
      >
        <span style={{ width: `${percent}%` }} />
      </div>
      {showDetails ? <small>{details}</small> : null}
    </div>
  );
};
