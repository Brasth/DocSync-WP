import { createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AccountPanel } from '../features/google-setup/account-panel';
import { ActivationResult } from '../features/activation/activation-result';
import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { DocSourceModal } from '../features/doc-source-modal/doc-source-modal';
import { GoogleSetupSyncDefaultsPanel } from '../features/google-setup/google-setup-sync-defaults-panel';
import { SettingsPanel } from '../features/google-setup/settings-panel';
import { TelemetryConsentPanel } from '../features/google-setup/telemetry-consent-panel';
import { AdminShell } from '../shared/ui/admin-shell';
import { useSetupApp } from './use-setup-app';

export const SetupApp = (): JSX.Element => {
  const app = useSetupApp();

  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load Brasth Document Sync.', 'brasth-document-sync-for-google-docs'));
      }).catch(() => undefined);
    });
  }, []);

  const setupReady = Boolean(app.workspace?.siteConnectionReady && app.account.connected && app.account.hasRequiredScope);
  const activated = Boolean(app.workspace?.sourceSummary.activated);
  const showTelemetryConsent = Boolean(
    setupReady && app.settings && !app.settings.telemetryEnabled && !app.settings.telemetryPromptDismissed
  );

  return (
    <AdminShell
      notice={app.notice}
      status={{
        label: __('Google connection', 'brasth-document-sync-for-google-docs'),
        value: setupReady ? __('Ready', 'brasth-document-sync-for-google-docs') : __('Setup', 'brasth-document-sync-for-google-docs'),
        variant: setupReady ? 'ready' : 'attention'
      }}
      title={__('Google Setup', 'brasth-document-sync-for-google-docs')}
      version={app.config.version}
    >
      {!app.settings || !app.workspace ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          <div className="docsync-wp-admin-grid__main">
            <section aria-busy="true" className="docsync-wp-card" role="status">
              <p>{__('Loading settings...', 'brasth-document-sync-for-google-docs')}</p>
            </section>
          </div>
        </div>
      ) : setupReady ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--ready">
          <div className="docsync-wp-admin-grid__main">
            <SettingsPanel
              account={app.account}
              activated={activated}
              busy={app.busy}
              canCreateSource={app.workspace.creatablePostTypes.length > 0}
              layoutMode="ready"
              onClearOAuthConfiguration={app.clearSavedOAuthConfiguration}
              onConnect={app.connectGoogle}
              onCreateSource={app.openSourceModal}
              onSave={app.persistSettings}
              redirectUri={app.redirectUri}
              settings={app.settings}
            />
          </div>
          <aside className="docsync-wp-admin-grid__side">
            <AccountPanel
              account={app.account}
              busy={app.busy}
              canConnect={app.settings.hasRequiredSettings}
              createSyncedDraftUrl={app.config.createSyncedDraftUrl}
              onConnect={app.connectGoogle}
              onDisconnect={app.disconnectGoogle}
              primaryActions={false}
            />
            {showTelemetryConsent ? (
              <TelemetryConsentPanel
                busy={app.busy}
                onAccept={() => app.persistSettings({
                  telemetryEnabled: true,
                  telemetryPromptDismissed: true
                })}
                onDismiss={() => app.persistSettings({ telemetryPromptDismissed: true })}
              />
            ) : null}
            <GoogleSetupSyncDefaultsPanel
              busy={app.busy}
              onSave={app.persistSettings}
              settings={app.settings}
            />
          </aside>
        </div>
      ) : (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single docsync-wp-admin-grid--focus">
          <div className="docsync-wp-admin-grid__main">
            <SettingsPanel
              account={app.account}
              activated={activated}
              busy={app.busy}
              canCreateSource={app.workspace.creatablePostTypes.length > 0}
              layoutMode="focus"
              onClearOAuthConfiguration={app.clearSavedOAuthConfiguration}
              onConnect={app.connectGoogle}
              onCreateSource={app.openSourceModal}
              onSave={app.persistSettings}
              redirectUri={app.redirectUri}
              settings={app.settings}
            />
          </div>
        </div>
      )}
      {app.activationSource ? (
        <ActivationResult busy={app.busy} onRetry={app.retryActivationSource} source={app.activationSource} />
      ) : null}
      {app.activationSource && !['synced', 'skipped', 'error'].includes(app.activationSource.syncStatus) ? (
        <BackgroundSyncPoller
          onError={app.handleActivationPollingError}
          onStatus={app.handleActivationSourceStatus}
          onTerminal={app.handleActivationSourceTerminal}
          onTimeout={app.handleActivationSourceTimeout}
          postId={app.activationSource.postId}
        />
      ) : null}
      <DocSourceModal
        isOpen={app.sourceModalOpen}
        onClose={app.closeSourceModal}
        onCompleted={app.handleSourceCreated}
        onFolderWatchCreated={() => {
          void app.refresh();
        }}
        target={app.sourceModalOpen && app.workspace?.creatablePostTypes[0] ? { mode: 'new', postType: app.workspace.creatablePostTypes[0] } : null}
      />
    </AdminShell>
  );
};
