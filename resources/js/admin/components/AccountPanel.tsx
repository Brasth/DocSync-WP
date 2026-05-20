import { createElement } from '@wordpress/element';

import type { GoogleAccount } from '../api';

type Props = {
  account: GoogleAccount;
  busy: boolean;
  canConnect: boolean;
  onConnect: () => Promise<void>;
  onDisconnect: () => Promise<void>;
};

export const AccountPanel = ({ account, busy, canConnect, onConnect, onDisconnect }: Props): JSX.Element => {
  const needsReconnect = account.connected && !account.hasRequiredScope;

  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <h2>Connected account</h2>
        <p>Tokens are stored per WordPress user and encrypted with WordPress salts.</p>
      </div>

      {account.connected ? (
        <div className="docsync-wp-account">
          <strong>{account.googleAccountEmail || 'Google account connected'}</strong>
          <span>{account.scope || 'Drive read-only scope'}</span>
          {needsReconnect ? (
            <button className="button button-primary" disabled={busy || !canConnect} onClick={onConnect} type="button">
              Reconnect Google
            </button>
          ) : null}
          <button className="button" disabled={busy} onClick={onDisconnect} type="button">
            Disconnect
          </button>
          {needsReconnect ? <span className="docsync-wp-inline-warning">Reconnect to grant Drive read-only access before browsing Docs.</span> : null}
        </div>
      ) : (
        <div className="docsync-wp-account">
          <strong>Not connected</strong>
          <span>{canConnect ? 'Connect Google before inspecting or syncing Docs.' : 'Save OAuth client ID and client secret before connecting.'}</span>
          <button className="button button-primary" disabled={busy || !canConnect} onClick={onConnect} type="button">
            Connect Google
          </button>
        </div>
      )}
    </section>
  );
};
