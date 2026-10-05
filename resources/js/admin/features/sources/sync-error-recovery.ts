import { __ } from '@wordpress/i18n';

export type RecoveryKind = 'reconnect' | 'open-doc' | 'change-doc' | 'retry' | 'ask-admin';

export type RecoveryAction = {
  kind: RecoveryKind;
  label: string;
};

const reconnectCodes = new Set([
  'docsync_wp_google_reconnect_required',
  'docsync_wp_google_not_connected',
  'docsync_wp_not_connected'
]);
const accessCodes = new Set([
  'docsync_wp_access_denied',
  'docsync_wp_docs_api_access_denied',
  'docsync_wp_drive_download_blocked'
]);
const changeDocCodes = new Set([
  'docsync_wp_source_not_found',
  'docsync_wp_non_google_doc',
  'docsync_wp_invalid_document_id'
]);
const transientCodes = new Set([
  'docsync_wp_google_transient_failure',
  'docsync_wp_docs_api_transient_failure',
  'docsync_wp_bad_google_response'
]);

/** One recovery action for a sync error code. Unknown codes fall back to retry. */
export const recoveryForErrorCode = (code: string): RecoveryAction => {
  if (reconnectCodes.has(code)) {
    return { kind: 'reconnect', label: __('Reconnect Google', 'brasth-document-sync-for-google-docs') };
  }

  if (accessCodes.has(code)) {
    return { kind: 'open-doc', label: __('Check Doc sharing', 'brasth-document-sync-for-google-docs') };
  }

  if (changeDocCodes.has(code)) {
    return { kind: 'change-doc', label: __('Change Doc in editor', 'brasth-document-sync-for-google-docs') };
  }

  if (code === 'docsync_wp_google_credentials_missing') {
    return { kind: 'ask-admin', label: __('Ask an administrator', 'brasth-document-sync-for-google-docs') };
  }

  if (transientCodes.has(code)) {
    return { kind: 'retry', label: __('Retry sync', 'brasth-document-sync-for-google-docs') };
  }

  return { kind: 'retry', label: __('Retry sync', 'brasth-document-sync-for-google-docs') };
};
