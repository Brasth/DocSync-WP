import { __ } from '@wordpress/i18n';

import type { SyncLogEntry } from '../../api';

const googleErrorCodes = [
  'docsync_wp_docs_api_unavailable',
  'docsync_wp_docs_api_access_denied',
  'docsync_wp_docs_api_transient_failure',
  'docsync_wp_access_denied',
  'docsync_wp_google_credentials_missing',
  'docsync_wp_google_reconnect_required',
  'docsync_wp_google_transient_failure'
];

export const syncLogRecoveryHint = (entry: SyncLogEntry): string => {
  const context = entry.context ?? {};

  if (entry.errorCode === 'docsync_wp_sync_stalled') {
    return __('Retry sync. If it stalls again, configure a real server cron for wp-cron.php and check PHP error logs.', 'brasth-document-sync-for-google-docs');
  }

  if (entry.step === 'large_doc_fallback' || entry.errorCode === 'docsync_wp_export_too_large') {
    return __('This is expected for Docs that Google will not export as HTML ZIP. Let the fallback finish before retrying.', 'brasth-document-sync-for-google-docs');
  }

  if (entry.step === 'large_doc_partial_import') {
    return __('The fallback is writing an empty draft in chunks. Keep the browser open until sync reaches complete or error.', 'brasth-document-sync-for-google-docs');
  }

  if (entry.status === 'syncing' && context.hasLock === false && context.hasCronEvent === false) {
    return __('No active lock or scheduled cron event was found. Retry sync and check WP-Cron if the source stays syncing.', 'brasth-document-sync-for-google-docs');
  }

  if (entry.errorCode === 'docsync_wp_drive_download_blocked') {
    return __('Google says this Doc cannot be downloaded. Check the Doc owner, sharing, and download permissions.', 'brasth-document-sync-for-google-docs');
  }

  if (googleErrorCodes.includes(entry.errorCode)) {
    return __('Reconnect Google if needed, confirm Drive API and Docs API are enabled, then retry sync.', 'brasth-document-sync-for-google-docs');
  }

  return '';
};
