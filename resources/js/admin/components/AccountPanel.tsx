import { createElement } from '@wordpress/element';

import type { GoogleAccount } from '../api';

type Props = {
  account: GoogleAccount;
  busy: boolean;
  onConnect: () => Promise<void>;
  onDisconnect: () => Promise<void>;
};

export const AccountPanel = ({ account, busy, onConnect, onDisconnect }: Props): JSX.Element => {
  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <h2>Connected account</h2>
        <p>Tokens are stored per WordPress user and encrypted with WordPress salts.</p>
      </div>

      {account.connected ? (
        <div className="docsync-wp-account">
          <strong>{account.googleAccountEmail || 'Google account connected'}</strong>
          <span>{account.scope || 'Drive file scope'}</span>
          <button className="button" disabled={busy} onClick={onDisconnect} type="button">
            Disconnect
          </button>
        </div>
      ) : (
        <div className="docsync-wp-account">
          <strong>Not connected</strong>
          <span>Connect Google before inspecting or syncing Docs.</span>
          <button className="button button-primary" disabled={busy} onClick={onConnect} type="button">
            Connect Google
          </button>
        </div>
      )}
    </section>
  );
};
