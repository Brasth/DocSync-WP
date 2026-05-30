import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type Props = {
  className?: string;
  message?: string;
  progress?: number;
  updatedAt?: string;
};

export const shouldShowSyncProgress = <T extends { syncStatus?: string }>(source: T | null | undefined): source is T => {
  return source?.syncStatus === 'syncing';
};

export const normalizeSyncProgress = (progress: number | null | undefined): number => {
  if (typeof progress !== 'number' || Number.isNaN(progress)) {
    return 0;
  }

  return Math.max(0, Math.min(100, Math.round(progress)));
};

export const SyncProgress = ({ className = '', message = '', progress, updatedAt = '' }: Props): JSX.Element => {
  const percent = normalizeSyncProgress(progress);
  const label = sprintf(__('Sync progress: %d%%', 'docsync-wp'), percent);
  const progressDetails = message ? `${percent}% - ${message}` : `${percent}%`;
  const details = updatedAt
    ? sprintf(__('%1$s. Last update: %2$s', 'docsync-wp'), progressDetails, updatedAt)
    : progressDetails;

  return (
    <div className={`docsync-wp-sync-progress-wrap ${className}`.trim()}>
      <div
        aria-label={label}
        aria-valuemax={100}
        aria-valuemin={0}
        aria-valuenow={percent}
        className="docsync-wp-sync-progress"
        role="progressbar"
      >
        <span style={{ width: `${percent}%` }} />
      </div>
      <small>{details}</small>
    </div>
  );
};
