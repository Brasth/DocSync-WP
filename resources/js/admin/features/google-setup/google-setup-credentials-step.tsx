import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { OAuthClientJsonImport } from './oauth-client-json-import';
import type { OAuthClientJsonCredentials } from './oauth-client-json';
import { SetupStepStateBadge, type SetupStepState } from './setup-step-state';

type Props = {
  busy: boolean;
  clientId: string;
  clientSecret: string;
  hasClientSecret: boolean;
  initialOpen: boolean;
  redirectUri: string;
  stepNumber: number;
  stepState: SetupStepState;
  onClientIdChange: (clientId: string) => void;
  onClientSecretChange: (clientSecret: string) => void;
  onImported: (credentials: OAuthClientJsonCredentials) => void;
};

export const GoogleSetupCredentialsStep = ({
  busy,
  clientId,
  clientSecret,
  hasClientSecret,
  initialOpen,
  redirectUri,
  stepNumber,
  stepState,
  onClientIdChange,
  onClientSecretChange,
  onImported
}: Props): JSX.Element => {
  const [isOpen, setIsOpen] = useState(initialOpen);

  return (
    <li>
      <details className="docsync-wp-setup-disclosure" onToggle={(event) => setIsOpen(event.currentTarget.open)} open={isOpen}>
        <summary className="docsync-wp-step-heading">
          <span className="docsync-wp-step-number">{stepNumber}</span>
          <div>
            <div className="docsync-wp-step-title-row">
              <h3>{__('Save OAuth credentials', 'brasth-document-sync-for-google-docs')}</h3>
              <SetupStepStateBadge state={stepState} />
            </div>
            <p>{__('Use the Web application client from the same Google Cloud project.', 'brasth-document-sync-for-google-docs')}</p>
          </div>
        </summary>
        <div className="docsync-wp-step-body">
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
        </div>
      </details>
    </li>
  );
};
