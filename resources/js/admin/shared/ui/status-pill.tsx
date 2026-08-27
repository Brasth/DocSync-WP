import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type StatusConfig = {
  label: string;
  variant: string;
};

const statusConfig: Record<string, StatusConfig> = {
  linked: { label: __('Linked', 'brasth-document-sync-for-google-docs'), variant: 'linked' },
  syncing: { label: __('Syncing', 'brasth-document-sync-for-google-docs'), variant: 'syncing' },
  synced: { label: __('Synced', 'brasth-document-sync-for-google-docs'), variant: 'synced' },
  skipped: { label: __('Skipped', 'brasth-document-sync-for-google-docs'), variant: 'skipped' },
  error: { label: __('Error', 'brasth-document-sync-for-google-docs'), variant: 'error' },
  info: { label: __('Info', 'brasth-document-sync-for-google-docs'), variant: 'info' },
  warning: { label: __('Warning', 'brasth-document-sync-for-google-docs'), variant: 'warning' },
  queued: { label: __('Queued', 'brasth-document-sync-for-google-docs'), variant: 'syncing' },
  new: { label: __('New', 'brasth-document-sync-for-google-docs'), variant: 'synced' },
  unchanged: { label: __('Unchanged', 'brasth-document-sync-for-google-docs'), variant: 'skipped' },
  changed: { label: __('Changed', 'brasth-document-sync-for-google-docs'), variant: 'info' },
  created: { label: __('Created', 'brasth-document-sync-for-google-docs'), variant: 'synced' },
  watching: { label: __('Watching', 'brasth-document-sync-for-google-docs'), variant: 'synced' },
  importing: { label: __('Importing', 'brasth-document-sync-for-google-docs'), variant: 'syncing' },
  paused: { label: __('Paused', 'brasth-document-sync-for-google-docs'), variant: 'warning' }
};

type Props = {
  status: string;
};

export const StatusPill = ({ status }: Props): JSX.Element => {
  const config = statusConfig[status] ?? { label: status, variant: 'unknown' };

  return (
    <span className={`docsync-wp-pill docsync-wp-pill--${config.variant}`}>
      <span aria-hidden="true" className="docsync-wp-pill__dot" />
      {config.label}
    </span>
  );
};
