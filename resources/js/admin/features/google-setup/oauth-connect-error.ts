import { __, sprintf } from '@wordpress/i18n';

export type OAuthConnectErrorCode =
  | 'oauth_invalid_credentials'
  | 'oauth_redirect_mismatch'
  | 'oauth_access_denied'
  | 'oauth_insufficient_scope'
  | 'oauth_token_expired'
  | 'oauth_network'
  | 'oauth_unknown';

export type OAuthConnectErrorView = {
  code: OAuthConnectErrorCode;
  message: string;
  detail?: string;
  showRetry: boolean;
  showReconnect: boolean;
  focusField?: 'clientId' | 'clientSecret' | 'redirectUri';
  showRedirectUriCopy?: boolean;
};

const QUERY_ARG = 'docsync_oauth_error';
const DETAIL_ARG = 'docsync_oauth_detail';

export const readOAuthConnectErrorFromLocation = (): OAuthConnectErrorView | null => {
  const params = new URLSearchParams(window.location.search);
  const raw = params.get(QUERY_ARG);

  if (!raw) {
    return null;
  }

  const detail = params.get(DETAIL_ARG) ?? undefined;
  const view = buildOAuthConnectErrorView(raw as OAuthConnectErrorCode, detail);

  params.delete(QUERY_ARG);
  params.delete(DETAIL_ARG);

  const nextSearch = params.toString();
  const nextUrl = `${window.location.pathname}${nextSearch ? `?${nextSearch}` : ''}${window.location.hash}`;
  window.history.replaceState({}, '', nextUrl);

  return view;
};

export const buildOAuthConnectErrorView = (code: OAuthConnectErrorCode, detail?: string): OAuthConnectErrorView => {
  switch (code) {
    case 'oauth_invalid_credentials':
      return {
        code,
        message: __('Google rejected the client ID or secret. Check both fields match Google Cloud.', 'brasth-document-sync-for-google-docs'),
        showRetry: false,
        showReconnect: false,
        focusField: 'clientSecret'
      };
    case 'oauth_redirect_mismatch':
      return {
        code,
        message: __('Redirect URI does not match. Add exactly the URI shown below in Google Cloud → Credentials.', 'brasth-document-sync-for-google-docs'),
        showRetry: true,
        showReconnect: false,
        focusField: 'redirectUri',
        showRedirectUriCopy: true
      };
    case 'oauth_access_denied':
      return {
        code,
        message: __('Google connect was cancelled. Try again when ready.', 'brasth-document-sync-for-google-docs'),
        showRetry: true,
        showReconnect: false
      };
    case 'oauth_insufficient_scope':
      return {
        code,
        message: __('This Google account needs Drive read access for Docs.', 'brasth-document-sync-for-google-docs'),
        showRetry: true,
        showReconnect: true
      };
    case 'oauth_token_expired':
      return {
        code,
        message: __('Google access expired. Reconnect this user.', 'brasth-document-sync-for-google-docs'),
        showRetry: false,
        showReconnect: true
      };
    case 'oauth_network':
      return {
        code,
        message: __('Google is unreachable right now. Try again in a few minutes.', 'brasth-document-sync-for-google-docs'),
        showRetry: true,
        showReconnect: false
      };
    case 'oauth_unknown':
    default:
      return {
        code: 'oauth_unknown',
        message: detail
          ? sprintf(__('Connect failed (%s).', 'brasth-document-sync-for-google-docs'), detail)
          : __('Connect failed.', 'brasth-document-sync-for-google-docs'),
        detail,
        showRetry: true,
        showReconnect: false
      };
  }
};

export const copySupportErrorDetails = async (view: OAuthConnectErrorView): Promise<string> => {
  const payload = JSON.stringify({
    code: view.code,
    detail: view.detail ?? null,
    url: window.location.href.split('?')[0]
  });

  if (navigator.clipboard) {
    try {
      await navigator.clipboard.writeText(payload);
      return __('Error details copied.', 'brasth-document-sync-for-google-docs');
    } catch {
      return __('Copy the error details manually.', 'brasth-document-sync-for-google-docs');
    }
  }

  return __('Copy the error details manually.', 'brasth-document-sync-for-google-docs');
};
