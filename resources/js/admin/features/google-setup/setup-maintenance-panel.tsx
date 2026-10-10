import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse, SettingsUpdate } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { buildSetupChecks } from './google-setup-utils';
import { SetupConnectionsDialog } from './setup-connections-dialog';
import { SetupDefaultsForm } from './setup-defaults-form';
import { abbreviateClientId } from './setup-journey-state';
import { TelemetryConsentPanel } from './telemetry-consent-panel';

const domain = 'brasth-document-sync-for-google-docs';
type Props = {
  settings: SettingsResponse;
  account: GoogleAccount;
  busy: boolean;
  pendingActivation: boolean;
  onSave: (update: SettingsUpdate) => Promise<boolean>;
  onDirtyChange: (dirty: boolean) => void;
  onEditCredentials: () => void;
  onConnect: () => Promise<void>;
  onDisconnect?: () => Promise<void>;
  onClearOAuthConfiguration: () => Promise<boolean>;
};

export const SetupMaintenancePanel = ({ settings, account, busy, pendingActivation, onSave, onDirtyChange, onEditCredentials, onConnect, onDisconnect, onClearOAuthConfiguration }: Props): JSX.Element => {
  const [confirm, setConfirm] = useState<'disconnect' | 'clear' | null>(null);
  const [checksVisible, setChecksVisible] = useState(false);
  const ready = account.connected && account.hasRequiredScope;
  const action = async () => {
    if (confirm === 'disconnect' && onDisconnect) { await onDisconnect(); }
    if (confirm === 'clear') { await onClearOAuthConfiguration(); }
    setConfirm(null);
  };

  return (
    <div className="docsync-wp-setup-maintenance">
      {pendingActivation ? <div className="docsync-wp-setup-pending" role="status"><strong>{__('First import is still pending.', domain)}</strong> {__('You can set defaults now. Import a Doc to complete setup.', domain)}</div> : <div className="docsync-wp-setup-complete" role="status"><span aria-hidden="true">✓</span><p><strong>{__('You’re set up.', domain)}</strong> {__('Google is connected and your first Doc has imported. Everything below can be changed later.', domain)}</p><a className="button docsync-wp-button button-primary" href="admin.php?page=brasth-document-sync-for-google-docs-sources">{__('Go to Sources', domain)} →</a></div>}
      <div className="docsync-wp-setup-maintenance__cards">
      <section aria-labelledby="docsync-wp-maintenance-title" className="docsync-wp-setup-card">
        <header className="docsync-wp-setup-card__header"><h2 id="docsync-wp-maintenance-title">{__('Google', domain)}</h2></header>
        <div className="docsync-wp-maintenance-row"><div><strong>{__('OAuth client', domain)}</strong><p><code>{abbreviateClientId(settings.clientId)}</code> · {settings.oauthCredentialsSavedDateLabel ? `${__('saved', domain)} ${settings.oauthCredentialsSavedDateLabel}` : __('saved date unavailable', domain)}</p></div><div className="docsync-wp-setup-row-actions"><span className="docsync-wp-setup-tick" aria-label={__('Saved', domain)}>✓</span><AdminButton disabled={busy} onClick={onEditCredentials}>{__('Change', domain)}</AdminButton></div></div>
        <div className="docsync-wp-maintenance-row"><div><strong>{__('Your account', domain)}</strong><p>{account.googleAccountEmail || __('Your personal Google connection', domain)}{ready ? ` · ${__('Drive read-only', domain)}` : ''}</p>{!ready ? <span className="docsync-wp-connection-badge">{account.connected ? __('Reconnect required', domain) : __('Not connected', domain)}</span> : null}</div><div className="docsync-wp-setup-row-actions">{ready ? <span className="docsync-wp-setup-tick" aria-label={__('Connected', domain)}>✓</span> : null}{account.connected && onDisconnect ? <AdminButton className="docsync-wp-setup-disconnect" disabled={busy} onClick={() => setConfirm('disconnect')}>{__('Disconnect', domain)}</AdminButton> : <AdminButton disabled={busy} onClick={() => void onConnect()}>{__('Connect Google', domain)}</AdminButton>}</div></div>
        <SetupConnectionsDialog />
        <details className="docsync-wp-setup-troubleshooting"><summary>{__('Advanced', domain)}</summary><p>{__('Check saved credentials and the current user’s local connection. These checks do not contact Google.', domain)}</p><div className="docsync-wp-setup-actions"><AdminButton disabled={busy} onClick={() => setChecksVisible(true)}>{__('Check setup', domain)}</AdminButton><AdminButton disabled={busy} onClick={() => setConfirm('clear')}>{__('Clear OAuth configuration', domain)}</AdminButton></div>{checksVisible ? <ul>{buildSetupChecks(settings, account).map((check) => <li key={check.id}><strong>{check.complete ? '✓ ' : '! '}{check.label}</strong><span> — {check.description}</span></li>)}</ul> : null}</details>
      </section>
      <section aria-labelledby="docsync-wp-defaults-title" className="docsync-wp-setup-card"><header className="docsync-wp-setup-card__header"><h2 id="docsync-wp-defaults-title">{__('Sync defaults', domain)}</h2></header><SetupDefaultsForm busy={busy} onDirtyChange={onDirtyChange} onSave={onSave} settings={settings} /></section>
      </div>
      {!settings.telemetryEnabled && !settings.telemetryPromptDismissed ? <TelemetryConsentPanel busy={busy} onAccept={() => onSave({ telemetryEnabled: true, telemetryPromptDismissed: true })} onDismiss={() => onSave({ telemetryPromptDismissed: true })} /> : null}
      <ConfirmDialog busy={busy} confirmLabel={confirm === 'clear' ? __('Clear configuration', domain) : __('Disconnect Google', domain)} description={confirm === 'clear' ? __('Clear the shared OAuth credentials and plugin Google connections. Everyone must reconnect after new credentials are saved. WordPress posts and imported content are retained.', domain) : __('Disconnect your Google account from this WordPress user. Imported WordPress content is retained.', domain)} onConfirm={action} onOpenChange={(open) => { if (!open) { setConfirm(null); } }} open={confirm !== null} title={confirm === 'clear' ? __('Clear OAuth configuration?', domain) : __('Disconnect your Google account?', domain)} variant="danger" />
    </div>
  );
};
