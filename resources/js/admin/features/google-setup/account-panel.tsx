import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

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
  const disconnect = async () => {
    if (!window.confirm(__('Disconnect this Google account from DocSync WP? Existing linked posts stay linked, but this user cannot browse or sync Google Docs until they reconnect.', 'docsync-wp'))) {
      return;
    }

    await onDisconnect();
  };

  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <h2>{__('Connected account', 'docsync-wp')}</h2>
        <p>{__('Tokens are stored per WordPress user and encrypted with WordPress salts.', 'docsync-wp')}</p>
      </div>

      {account.connected ? (
        <div className="docsync-wp-account">
          <strong>{account.googleAccountEmail || __('Google account connected', 'docsync-wp')}</strong>
          <span>{account.scope || __('Drive read-only scope', 'docsync-wp')}</span>
          {needsReconnect ? (
            <AdminButton disabled={busy || !canConnect} onClick={onConnect} variant="primary">
              {__('Reconnect Google', 'docsync-wp')}
            </AdminButton>
          ) : null}
          <AdminButton disabled={busy} onClick={disconnect} variant="delete">
            {__('Disconnect', 'docsync-wp')}
          </AdminButton>
          {needsReconnect ? <span className="docsync-wp-inline-warning">{__('Reconnect to grant Drive read-only access before browsing Docs.', 'docsync-wp')}</span> : null}
        </div>
      ) : (
        <div className="docsync-wp-account">
          <strong>{__('Not connected', 'docsync-wp')}</strong>
          <span>
            {canConnect
              ? __('Connect Google before inspecting or syncing Docs. DocSync WP will send OAuth requests to Google, request Drive read-only access, and use Google Drive and Docs APIs to list, inspect, export, and sync Docs this account can read.', 'docsync-wp')
              : __('Save OAuth client ID and client secret before connecting. The Google Cloud project must have Drive API and Docs API enabled.', 'docsync-wp')}
          </span>
          <AdminButton disabled={busy || !canConnect} onClick={onConnect} variant="primary">
            {__('Connect Google', 'docsync-wp')}
          </AdminButton>
        </div>
      )}
    </section>
  );
};
