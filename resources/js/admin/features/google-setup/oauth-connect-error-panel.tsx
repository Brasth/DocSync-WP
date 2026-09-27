import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { copySupportErrorDetails, type OAuthConnectErrorView } from './oauth-connect-error';

type Props = {
  error: OAuthConnectErrorView;
  onReconnect?: () => void | Promise<void>;
  onRetry?: () => void | Promise<void>;
  redirectUri?: string;
  onCopyRedirectUri?: (value: string) => void;
};

export const OAuthConnectErrorPanel = ({
  error,
  onCopyRedirectUri,
  onReconnect,
  onRetry,
  redirectUri = ''
}: Props): JSX.Element => {
  const [copyMessage, setCopyMessage] = useState('');

  const copyError = async () => {
    setCopyMessage(await copySupportErrorDetails(error));
  };

  return (
    <div className="docsync-wp-oauth-error-panel" role="alert">
      <strong>{__('Google connect failed', 'brasth-document-sync-for-google-docs')}</strong>
      <p>{error.message}</p>
      {error.showRedirectUriCopy && redirectUri ? (
        <div className="docsync-wp-copy-row">
          <input className="regular-text code" readOnly type="text" value={redirectUri} />
          <AdminButton onClick={() => onCopyRedirectUri?.(redirectUri)} size="small">
            {__('Copy URI', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      ) : null}
      <div className="docsync-wp-actions-row">
        {error.showReconnect && onReconnect ? (
          <AdminButton onClick={onReconnect} variant="primary">
            {__('Reconnect', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        ) : null}
        {error.showRetry && onRetry ? (
          <AdminButton onClick={onRetry} variant="primary">
            {__('Retry', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        ) : null}
        {error.code === 'oauth_unknown' ? (
          <AdminButton onClick={copyError} variant="secondary">
            {__('Copy error', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        ) : null}
      </div>
      {copyMessage ? <p className="description">{copyMessage}</p> : null}
    </div>
  );
};
