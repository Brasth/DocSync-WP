import { createElement, Fragment, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';

type Props = {
  account: GoogleAccount;
  busy: boolean;
  canConnect: boolean;
  createSyncedDraftUrl: string;
  onConnect: () => Promise<void>;
  onDisconnect: () => Promise<void>;
};

export const AccountPanel = ({
  account,
  busy,
  canConnect,
  createSyncedDraftUrl,
  onConnect,
  onDisconnect
}: Props): JSX.Element => {
  const [disconnectOpen, setDisconnectOpen] = useState(false);
  const needsReconnect = account.connected && !account.hasRequiredScope;
  const canCreateDraft = account.connected && account.hasRequiredScope && canConnect;
  const disconnect = async () => {
    await onDisconnect();
    setDisconnectOpen(false);
  };

  return (
    <>
      <section className="docsync-wp-card">
        <div className="docsync-wp-card__header">
          <h2>{__('Connected account', 'brasth-document-sync-for-google-docs')}</h2>
          <p>{__('Tokens are stored per WordPress user and encrypted with WordPress salts.', 'brasth-document-sync-for-google-docs')}</p>
        </div>

        {account.connected ? (
          <div className="docsync-wp-account">
            <strong>{account.googleAccountEmail || __('Google account connected', 'brasth-document-sync-for-google-docs')}</strong>
            <span>{account.scope || __('Drive read-only scope', 'brasth-document-sync-for-google-docs')}</span>
            {needsReconnect ? (
              <AdminButton disabled={busy || !canConnect} onClick={onConnect}>
                {__('Reconnect Google', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
            {canCreateDraft ? (
              <a className="button button-secondary docsync-wp-button docsync-wp-button--default" href={createSyncedDraftUrl}>
                {__('Create synced draft', 'brasth-document-sync-for-google-docs')}
              </a>
            ) : null}
            <AdminButton disabled={busy} onClick={() => setDisconnectOpen(true)} variant="delete">
              {__('Disconnect', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
            {needsReconnect ? <span className="docsync-wp-inline-warning">{__('Reconnect to grant Drive read-only access before browsing Docs.', 'brasth-document-sync-for-google-docs')}</span> : null}
          </div>
        ) : (
          <div className="docsync-wp-account">
            <strong>{__('Not connected', 'brasth-document-sync-for-google-docs')}</strong>
            <span>
              {canConnect
                ? __('Connect Google before inspecting or syncing Docs. Brasth Document Sync will send OAuth requests to Google, request Drive read-only access, and use Google Drive and Docs APIs to list, inspect, export, and sync Docs this account can read.', 'brasth-document-sync-for-google-docs')
                : __('Save OAuth client ID and client secret before connecting. The Google Cloud project must have Drive API and Docs API enabled.', 'brasth-document-sync-for-google-docs')}
            </span>
            <AdminButton disabled={busy || !canConnect} onClick={onConnect}>
              {__('Connect Google', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        )}
      </section>

      <ConfirmDialog
        busy={busy}
        confirmLabel={__('Disconnect', 'brasth-document-sync-for-google-docs')}
        description={__('Existing linked posts stay linked, but this user cannot browse or sync Google Docs until they reconnect.', 'brasth-document-sync-for-google-docs')}
        open={disconnectOpen}
        title={__('Disconnect Google account?', 'brasth-document-sync-for-google-docs')}
        variant="danger"
        onConfirm={disconnect}
        onOpenChange={setDisconnectOpen}
      />
    </>
  );
};
