import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { OAuthClientJsonImport } from './oauth-client-json-import';
import type { OAuthClientJsonCredentials } from './oauth-client-json';

type Props = {
  busy: boolean;
  clientId: string;
  clientSecret: string;
  hasClientSecret: boolean;
  redirectUri: string;
  stepNumber: number;
  onClientIdChange: (clientId: string) => void;
  onClientSecretChange: (clientSecret: string) => void;
  onImported: (credentials: OAuthClientJsonCredentials) => void;
};

export const GoogleSetupCredentialsStep = ({
  busy,
  clientId,
  clientSecret,
  hasClientSecret,
  redirectUri,
  stepNumber,
  onClientIdChange,
  onClientSecretChange,
  onImported
}: Props): JSX.Element => {
  return (
    <li>
      <div className="docsync-wp-step-heading">
        <span>{stepNumber}</span>
        <div>
          <h3>{__('Import or enter OAuth credentials', 'brasth-document-sync-for-google-docs')}</h3>
          <p>{__("Use the Web application client from the same Google Cloud project. The custom Drive browser uses these server-side credentials and the connected user's Drive read-only grant.", 'brasth-document-sync-for-google-docs')}</p>
        </div>
      </div>
      <OAuthClientJsonImport
        busy={busy}
        onImported={onImported}
        redirectUri={redirectUri}
      />
      <div className="docsync-wp-settings-grid">
        <label>
          <span>{__('OAuth client ID', 'brasth-document-sync-for-google-docs')}</span>
          <input className="regular-text" onChange={(event) => onClientIdChange(event.currentTarget.value)} type="text" value={clientId} />
        </label>
        <label>
          <span>{__('OAuth client secret', 'brasth-document-sync-for-google-docs')}</span>
          <input
            className="regular-text"
            onChange={(event) => onClientSecretChange(event.currentTarget.value)}
            placeholder={hasClientSecret ? __('Saved. Enter a new secret to replace.', 'brasth-document-sync-for-google-docs') : ''}
            type="password"
            value={clientSecret}
          />
        </label>
      </div>
    </li>
  );
};
