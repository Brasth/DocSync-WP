import { createElement } from '@wordpress/element';

import type { GoogleAccount } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';

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
            <AdminButton disabled={busy || !canConnect} onClick={onConnect} variant="primary">
              Reconnect Google
            </AdminButton>
          ) : null}
          <AdminButton disabled={busy} onClick={onDisconnect}>
            Disconnect
          </AdminButton>
          {needsReconnect ? <span className="docsync-wp-inline-warning">Reconnect to grant Drive read-only access before browsing Docs.</span> : null}
        </div>
      ) : (
        <div className="docsync-wp-account">
          <strong>Not connected</strong>
          <span>{canConnect ? 'Connect Google before inspecting or syncing Docs.' : 'Save OAuth client ID and client secret before connecting.'}</span>
          <AdminButton disabled={busy || !canConnect} onClick={onConnect} variant="primary">
            Connect Google
          </AdminButton>
        </div>
      )}
    </section>
  );
};
