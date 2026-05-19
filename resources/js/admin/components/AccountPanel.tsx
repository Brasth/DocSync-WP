import { createElement } from '@wordpress/element';

import type { GoogleAccount } from '../api';

type Props = {
  account: GoogleAccount;
  busy: boolean;
  canConnect: boolean;
  pickerReady: boolean;
  onConnect: () => Promise<void>;
  onDisconnect: () => Promise<void>;
};

export const AccountPanel = ({ account, busy, canConnect, pickerReady, onConnect, onDisconnect }: Props): JSX.Element => {
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
          {!pickerReady ? <span className="docsync-wp-inline-warning">Finish Picker setup before choosing Docs.</span> : null}
        </div>
      ) : (
        <div className="docsync-wp-account">
          <strong>Not connected</strong>
          <span>{canConnect ? 'Connect Google before inspecting or syncing Docs.' : 'Save OAuth client ID and client secret before connecting.'}</span>
          <button className="button button-primary" disabled={busy || !canConnect} onClick={onConnect} type="button">
            Connect Google
          </button>
          {canConnect && !pickerReady ? <span className="docsync-wp-inline-warning">Picker setup is still incomplete.</span> : null}
        </div>
      )}
    </section>
  );
};
