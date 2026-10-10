import { Fragment, createElement, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse, SettingsUpdate } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { SetupConnectionsDialog } from './setup-connections-dialog';
import { SetupDefaultsForm } from './setup-defaults-form';
import { abbreviateClientId } from './setup-journey-state';
import { SetupSettingsTabs, type SettingsTab } from './setup-settings-tabs';
import { SetupSyncHealthPanel } from './setup-sync-health-panel';
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
  onRequestLeave?: (action: () => void) => void;
};

type Tab = SettingsTab;
const initialTab = (): Tab => new URLSearchParams(window.location.search).get('tab') === 'health' ? 'health' : 'general';

export const SetupMaintenancePanel = ({ settings, account, busy, pendingActivation, onSave, onDirtyChange, onEditCredentials, onConnect, onDisconnect, onClearOAuthConfiguration, onRequestLeave = (action) => action() }: Props): JSX.Element => {
  const [confirm, setConfirm] = useState<'disconnect' | 'clear' | null>(null);
  const [tab, setTab] = useState<Tab>(initialTab);
  const [typed, setTyped] = useState('');
  const [clearing, setClearing] = useState(false);
  const googleCard = useRef<HTMLElement>(null);
  const openConnections = useRef(false);
  useEffect(() => {
    if (tab === 'general' && openConnections.current) {
      openConnections.current = false;
      // Open the existing editor dialog after the General card mounts.
      googleCard.current?.querySelector<HTMLButtonElement>('.docsync-wp-editor-connections button')?.click();
    }
  }, [tab]);
  const ready = account.connected && account.hasRequiredScope;
  const closeConfirm = () => { setConfirm(null); setTyped(''); };
  const action = async () => {
    if (confirm === 'disconnect') {
      if (onDisconnect) { await onDisconnect(); }
      closeConfirm();
      return;
    }
    setClearing(true);
    try {
      if (await onClearOAuthConfiguration()) { closeConfirm(); }
    } catch {
      // The shell reports the failure; keep the dialog and typed text for a retry.
    } finally {
      setClearing(false);
    }
  };
  const selectTab = (next: Tab) => {
    if (next === tab) { return; }
    onRequestLeave(() => {
      setTab(next);
      const url = new URL(window.location.href);
      if (next === 'health') { url.searchParams.set('tab', 'health'); } else { url.searchParams.delete('tab'); }
      window.history.replaceState(null, '', url.toString());
    });
  };

  return (
    <div className="docsync-wp-setup-maintenance">
      <SetupSettingsTabs onSelect={selectTab} tab={tab} />
      {tab === 'health' ? <SetupSyncHealthPanel busy={busy} onEditCredentials={onEditCredentials} onOpenConnections={() => { openConnections.current = true; selectTab('general'); }} onReconnect={onConnect} /> : <Fragment>
      {pendingActivation ? <div className="docsync-wp-setup-pending" role="status"><strong>{__('First import is still pending.', domain)}</strong> {__('You can set defaults now. Import a Doc to complete setup.', domain)}</div> : <div className="docsync-wp-setup-complete" role="status"><span aria-hidden="true">✓</span><p><strong>{__('You’re set up.', domain)}</strong> {__('Google is connected and your first Doc has imported. Everything below can be changed later.', domain)}</p><a className="button docsync-wp-button button-primary" href="admin.php?page=brasth-document-sync-for-google-docs-sources">{__('Go to Sources', domain)} →</a></div>}
      <div className="docsync-wp-setup-maintenance__cards">
      <section aria-labelledby="docsync-wp-maintenance-title" className="docsync-wp-setup-card" ref={googleCard}>
        <header className="docsync-wp-setup-card__header"><h2 id="docsync-wp-maintenance-title">{__('Google', domain)}</h2></header>
        <div className="docsync-wp-maintenance-row"><div><strong>{__('OAuth client', domain)}</strong><p><code>{abbreviateClientId(settings.clientId)}</code> · {settings.oauthCredentialsSavedDateLabel ? `${__('saved', domain)} ${settings.oauthCredentialsSavedDateLabel}` : __('saved date unavailable', domain)}</p></div><div className="docsync-wp-setup-row-actions"><span className="docsync-wp-setup-tick" aria-label={__('Saved', domain)}>✓</span><AdminButton disabled={busy} onClick={onEditCredentials}>{__('Change', domain)}</AdminButton></div></div>
        <div className="docsync-wp-maintenance-row"><div><strong>{__('Your account', domain)}</strong><p>{account.googleAccountEmail || __('Your personal Google connection', domain)}{ready ? ` · ${__('Drive read-only', domain)}` : ''}</p>{!ready ? <span className="docsync-wp-connection-badge">{account.connected ? __('Reconnect required', domain) : __('Not connected', domain)}</span> : null}</div><div className="docsync-wp-setup-row-actions">{ready ? <span className="docsync-wp-setup-tick" aria-label={__('Connected', domain)}>✓</span> : null}{account.connected && onDisconnect ? <AdminButton className="docsync-wp-setup-disconnect" disabled={busy} onClick={() => setConfirm('disconnect')}>{__('Disconnect', domain)}</AdminButton> : <AdminButton disabled={busy} onClick={() => void onConnect()}>{__('Connect Google', domain)}</AdminButton>}</div></div>
        <SetupConnectionsDialog />
        <details className="docsync-wp-setup-troubleshooting"><summary>{__('Advanced', domain)}</summary><p>{__('Open Sync health to run local checks on saved credentials and connections. These checks do not contact Google.', domain)}</p><div className="docsync-wp-setup-actions"><AdminButton disabled={busy} onClick={() => selectTab('health')}>{__('Check setup', domain)}</AdminButton><AdminButton disabled={busy} onClick={() => setConfirm('clear')}>{__('Clear OAuth configuration', domain)}</AdminButton></div></details>
      </section>
      <section aria-labelledby="docsync-wp-defaults-title" className="docsync-wp-setup-card"><header className="docsync-wp-setup-card__header"><h2 id="docsync-wp-defaults-title">{__('Sync defaults', domain)}</h2></header><SetupDefaultsForm busy={busy} onDirtyChange={onDirtyChange} onSave={onSave} settings={settings} /></section>
      </div>
      {!settings.telemetryEnabled && !settings.telemetryPromptDismissed ? <TelemetryConsentPanel busy={busy} onAccept={() => onSave({ telemetryEnabled: true, telemetryPromptDismissed: true })} onDismiss={() => onSave({ telemetryPromptDismissed: true })} /> : null}
      </Fragment>}
      <ConfirmDialog busy={busy || clearing} confirmDisabled={confirm === 'clear' && typed !== 'clear'} confirmLabel={confirm === 'clear' ? __('Clear configuration', domain) : __('Disconnect Google', domain)} description={confirm === 'clear' ? __('Clear the shared OAuth credentials and plugin Google connections. Everyone must reconnect after new credentials are saved. WordPress posts and imported content are retained.', domain) : __('Disconnect your Google account from this WordPress user. Imported WordPress content is retained.', domain)} onConfirm={action} onOpenChange={(open) => { if (!open) { closeConfirm(); } }} open={confirm !== null} title={confirm === 'clear' ? __('Clear OAuth configuration?', domain) : __('Disconnect your Google account?', domain)} variant="danger">
        {confirm === 'clear' ? <label className="docsync-wp-clear-confirm"><span>{__('Type clear to confirm', domain)}</span><input autoComplete="off" onChange={(event) => setTyped(event.currentTarget.value)} type="text" value={typed} /></label> : null}
      </ConfirmDialog>
    </div>
  );
};
