import { __ } from '@wordpress/i18n';

export type SyncLogQuickFilter = {
  id: 'errors' | 'warnings' | 'large-doc-fallback' | 'stalled-cron';
  label: string;
  filters: {
    level?: string;
    search?: string;
    status?: string;
    step?: string;
  };
};

export const levelOptions = [
  { value: '', label: __('All levels', 'brasth-document-sync-for-google-docs') },
  { value: 'info', label: __('Info', 'brasth-document-sync-for-google-docs') },
  { value: 'warning', label: __('Warning', 'brasth-document-sync-for-google-docs') },
  { value: 'error', label: __('Error', 'brasth-document-sync-for-google-docs') }
];

export const statusOptions = [
  { value: '', label: __('All statuses', 'brasth-document-sync-for-google-docs') },
  { value: 'linked', label: __('Linked', 'brasth-document-sync-for-google-docs') },
  { value: 'syncing', label: __('Syncing', 'brasth-document-sync-for-google-docs') },
  { value: 'synced', label: __('Synced', 'brasth-document-sync-for-google-docs') },
  { value: 'skipped', label: __('Skipped', 'brasth-document-sync-for-google-docs') },
  { value: 'error', label: __('Error', 'brasth-document-sync-for-google-docs') }
];

export const stepOptions = [
  { value: '', label: __('All steps', 'brasth-document-sync-for-google-docs') },
  { value: 'queued', label: __('Queued', 'brasth-document-sync-for-google-docs') },
  { value: 'checking_google', label: __('Checking Google', 'brasth-document-sync-for-google-docs') },
  { value: 'exporting', label: __('Exporting', 'brasth-document-sync-for-google-docs') },
  { value: 'large_doc_fallback', label: __('Large-doc fallback', 'brasth-document-sync-for-google-docs') },
  { value: 'importing', label: __('Importing', 'brasth-document-sync-for-google-docs') },
  { value: 'large_doc_partial_import', label: __('Partial fallback import', 'brasth-document-sync-for-google-docs') },
  { value: 'converting', label: __('Converting', 'brasth-document-sync-for-google-docs') },
  { value: 'updating_post', label: __('Updating post', 'brasth-document-sync-for-google-docs') },
  { value: 'complete', label: __('Complete', 'brasth-document-sync-for-google-docs') }
];

export const quickFilters: SyncLogQuickFilter[] = [
  {
    id: 'errors',
    label: __('Errors', 'brasth-document-sync-for-google-docs'),
    filters: { level: 'error' }
  },
  {
    id: 'warnings',
    label: __('Warnings', 'brasth-document-sync-for-google-docs'),
    filters: { level: 'warning' }
  },
  {
    id: 'large-doc-fallback',
    label: __('Large doc fallback', 'brasth-document-sync-for-google-docs'),
    filters: { step: 'large_doc_fallback' }
  },
  {
    id: 'stalled-cron',
    label: __('Stalled / WP-Cron', 'brasth-document-sync-for-google-docs'),
    filters: { search: 'docsync_wp_sync_stalled' }
  }
];
