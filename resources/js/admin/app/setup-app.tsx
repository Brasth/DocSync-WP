import { createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AccountPanel } from '../features/google-setup/account-panel';
import { SettingsPanel } from '../features/google-setup/settings-panel';
import { AdminNotice } from '../shared/ui/admin-notice';
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

  return (
    <main className="docsync-wp-admin-shell">
      <header className="docsync-wp-hero">
        <div>
          <p>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</p>
          <h1>{__('Google Setup', 'brasth-document-sync-for-google-docs')}</h1>
          <span>{__('Version', 'brasth-document-sync-for-google-docs')} {app.config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          <strong>{app.settings?.hasRequiredSettings ? __('Ready', 'brasth-document-sync-for-google-docs') : __('Setup', 'brasth-document-sync-for-google-docs')}</strong>
          <span>{__('Google connection', 'brasth-document-sync-for-google-docs')}</span>
        </div>
      </header>

      <AdminNotice notice={app.notice} />

      {!app.settings ? (
        <section aria-busy="true" className="docsync-wp-card" role="status">
          <p>{__('Loading settings...', 'brasth-document-sync-for-google-docs')}</p>
        </section>
      ) : (
        <div className="docsync-wp-admin-grid">
          <div className="docsync-wp-admin-grid__main">
            <SettingsPanel
              busy={app.busy}
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
              onConnect={app.connectGoogle}
              onDisconnect={app.disconnectGoogle}
            />
            <section className="docsync-wp-card">
              <h2>{__('Connection mode', 'brasth-document-sync-for-google-docs')}</h2>
              <ul>
                <li>{__('Current mode: self-managed Google Cloud app.', 'brasth-document-sync-for-google-docs')}</li>
                <li>{__('Each WordPress user connects their own Google account.', 'brasth-document-sync-for-google-docs')}</li>
                <li>{__('Managed connector support can be added later without proxying document content.', 'brasth-document-sync-for-google-docs')}</li>
              </ul>
            </section>
          </aside>
        </div>
      )}
    </main>
  );
};
