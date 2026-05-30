import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

type Props = {
  className?: string;
  message?: string;
  progress?: number;
};

export const normalizeSyncProgress = (progress: number | null | undefined): number => {
  if (typeof progress !== 'number' || Number.isNaN(progress)) {
    return 0;
  }

  return Math.max(0, Math.min(100, Math.round(progress)));
};

export const SyncProgress = ({ className = '', message = '', progress }: Props): JSX.Element => {
  const percent = normalizeSyncProgress(progress);
  const label = sprintf(__('Sync progress: %d%%', 'docsync-wp'), percent);
  const details = message ? `${percent}% - ${message}` : `${percent}%`;

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
