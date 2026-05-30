import { createElement, Fragment, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AccountPanel } from '../features/google-setup/account-panel';
import { SettingsPanel } from '../features/google-setup/settings-panel';
import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { SourcesTable } from '../features/sources/sources-table';
import { SyncLogsView } from '../features/sync-logs/sync-logs-view';
import { AdminNotice } from '../shared/ui/admin-notice';
import { useAdminApp, type AdminView as SetupAdminView } from './use-admin-app';

type AdminView = SetupAdminView | 'logs';

const SetupSourcesApp = ({ view }: { view: SetupAdminView }): JSX.Element => {
  const app = useAdminApp(view);

  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load DocSync WP.', 'docsync-wp'));
      }).catch(() => undefined);
    });
  }, [view]);

  return (
    <main className="docsync-wp-admin-shell">
      <header className="docsync-wp-hero">
        <div>
          <p>{__('DocSync WP', 'docsync-wp')}</p>
          <h1>{view === 'sources' ? __('Sources', 'docsync-wp') : __('Google Setup', 'docsync-wp')}</h1>
          <span>{__('Version', 'docsync-wp')} {app.config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          {view === 'sources' ? (
            <>
              <strong>{app.sources.length}</strong>
              <span>{app.sources.length === 1 ? __('shown source', 'docsync-wp') : __('shown sources', 'docsync-wp')}</span>
            </>
          ) : (
            <>
              <strong>{app.settings?.hasRequiredSettings ? __('Ready', 'docsync-wp') : __('Setup', 'docsync-wp')}</strong>
              <span>{__('Google connection', 'docsync-wp')}</span>
            </>
          )}
        </div>
      </header>

      <AdminNotice notice={app.notice} />

      {!app.settings ? (
        <section className="docsync-wp-card">
          <p>{__('Loading settings...', 'docsync-wp')}</p>
        </section>
      ) : view === 'sources' ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          {app.trackedSourceIds.map((postId) => (
            <BackgroundSyncPoller
              key={postId}
              onError={(message) => app.handleSourcePollingError(postId, message)}
              onStatus={app.handleSourceStatus}
              onTerminal={app.handleSourceTerminal}
              onTimeout={() => app.handleSourcePollingTimeout(postId)}
              postId={postId}
            />
          ))}
          <div className="docsync-wp-admin-grid__main">
            <SourcesTable
              availablePostTypes={app.settings.availablePostTypes.filter((postType) => app.settings?.enabledPostTypes.includes(postType.name))}
              busy={app.busy}
              filters={app.sourceFilters}
              hasMore={app.hasMoreSources}
              onFiltersChange={app.applySourceFilters}
              onLoadMore={app.loadMoreSources}
              onRefresh={() => app.runAction(app.refresh)}
              onSync={app.syncOne}
              onSyncAll={app.syncAll}
              sources={app.sources}
            />
          </div>
        </div>
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
              <h2>{__('Connection mode', 'docsync-wp')}</h2>
              <ul>
                <li>{__('Current mode: self-managed Google Cloud app.', 'docsync-wp')}</li>
                <li>{__('Each WordPress user connects their own Google account.', 'docsync-wp')}</li>
                <li>{__('Managed connector support can be added later without proxying document content.', 'docsync-wp')}</li>
              </ul>
            </section>
          </aside>
        </div>
      )}
    </main>
  );
};

export const AdminApp = ({ view }: { view: AdminView }): JSX.Element => {
  if (view === 'logs') {
    return <SyncLogsView />;
  }

  return <SetupSourcesApp view={view} />;
};
