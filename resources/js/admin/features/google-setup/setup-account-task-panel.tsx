import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { OAuthConnectErrorPanel } from './oauth-connect-error-panel';
import type { OAuthConnectErrorView } from './oauth-connect-error';

const textDomain = 'brasth-document-sync-for-google-docs';
const unverifiedAppUrl = 'https://support.google.com/cloud/answer/7454865';

type Props = {
  account: GoogleAccount;
  busy: boolean;
  displayName: string;
  oauthConnectError: OAuthConnectErrorView | null;
  redirectUri: string;
  onConnect: () => Promise<void>;
  onReviewCredentials: () => void;
};

export const SetupAccountTaskPanel = ({
  account,
  busy,
  displayName,
  oauthConnectError,
  redirectUri,
  onConnect,
  onReviewCredentials
}: Props): JSX.Element => {
  const outdated = account.connected && !account.hasRequiredScope;
  const title = outdated
    ? __('Reconnect Google', textDomain)
    : __('Connect your Google account', textDomain);
  const actionLabel = outdated
    ? __('Reconnect Google', textDomain)
    : __('Connect with Google', textDomain);

  return (
    <section aria-labelledby="docsync-wp-setup-account-title" className="docsync-wp-setup-card">
      <header className="docsync-wp-setup-card__header">
        <h2 id="docsync-wp-setup-account-title">{title}</h2>
        <p>{__('Google will ask you to sign in and approve read-only access to Drive. Document Sync can list and read Docs you can open; it never edits or deletes them.', textDomain)}</p>
      </header>

      {oauthConnectError ? (
        <OAuthConnectErrorPanel
          error={oauthConnectError}
          redirectUri={redirectUri}
          onReconnect={onConnect}
          onRetry={onConnect}
        />
      ) : null}

      {oauthConnectError?.code === 'oauth_invalid_credentials' ? (
        <AdminButton onClick={onReviewCredentials} type="button">
          {__('Review OAuth credentials', textDomain)}
        </AdminButton>
      ) : null}

      <div className="docsync-wp-setup-account-status">
        <span className="docsync-wp-setup-person" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6"><circle cx="12" cy="8" r="3"/><path d="M5 20v-2a7 7 0 0 1 14 0v2"/></svg></span>
        <div><strong>{outdated ? __('Reconnect required', textDomain) : __('Not connected', textDomain)}</strong><p>{__('Signed in to WordPress as', textDomain)} <strong>{displayName || __('current user', textDomain)}</strong>. {__('Tokens are stored encrypted, per WordPress user.', textDomain)}</p></div>
        <AdminButton disabled={busy} onClick={() => void onConnect()} type="button" variant="primary">{actionLabel}</AdminButton>
      </div>
      <div className="docsync-wp-setup-account-facts">
        <div><strong>{__("What's requested", textDomain)}</strong><p>{__('Drive read-only · list and export Docs', textDomain)}</p></div>
        <div><strong>{__("What's stored", textDomain)}</strong><p>{__('Encrypted access and refresh tokens, per user.', textDomain)}</p></div>
        <div><strong>{__("What's never sent", textDomain)}</strong><p>{__('Google tokens and Doc content never go to Brasth.', textDomain)}</p></div>
      </div>

      <details open className="docsync-wp-setup-troubleshooting">
        <summary>{__('Google says “This app isn’t verified”?', textDomain)}</summary>
        <div className="docsync-wp-setup-warning-guide">
          <div className="docsync-wp-setup-warning-preview" aria-label={__('Example Google warning', textDomain)}><span aria-hidden="true">!</span><strong>{__('This app isn’t verified', textDomain)}</strong><p>{__('Only proceed if you know and trust the developer of the Google Cloud app.', textDomain)}</p><small>{__('Example warning', textDomain)}</small></div>
          <div><p>{__('Google may show this warning for your own Google Cloud app before it is verified.', textDomain)}</p><ol><li>{__('Confirm the client belongs to your administrator’s Google Cloud project.', textDomain)}</li><li>{__('The redirect URI must match this site exactly, with Drive and Docs APIs enabled.', textDomain)}</li><li>{__('Still blocked? Ask your administrator to add your Google address as a test user.', textDomain)}</li></ol><p>{__('Publishing the consent screen alone does not remove verification warnings.', textDomain)}{' '}<a href={unverifiedAppUrl} rel="noreferrer" target="_blank">{__('Google’s guidance', textDomain)} ↗</a></p></div>
        </div>
      </details>
    </section>
  );
};
