import { createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AccountPanel } from '../features/google-setup/account-panel';
import { SettingsPanel } from '../features/google-setup/settings-panel';
import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { SourcesTable, SourcesTableSkeleton } from '../features/sources/sources-table';
import { SyncLogsView } from '../features/sync-logs/sync-logs-view';
import { AdminShell } from '../shared/ui/admin-shell';
import { useAdminApp, type AdminView as SetupAdminView } from './use-admin-app';

type AdminView = SetupAdminView | 'logs';

const SetupSourcesApp = ({ view }: { view: SetupAdminView }): JSX.Element => {
  const app = useAdminApp(view);

  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load Brasth Document Sync.', 'brasth-document-sync-for-google-docs'));
      }).catch(() => undefined);
    });
  }, [view]);

  const setupReady = Boolean(app.settings?.hasRequiredSettings && app.account.connected && app.account.hasRequiredScope);
  const shellStatus = view === 'sources'
    ? {
      label: app.sources.length === 1 ? __('shown source', 'brasth-document-sync-for-google-docs') : __('shown sources', 'brasth-document-sync-for-google-docs'),
      value: app.sources.length
    }
    : {
      label: __('Google connection', 'brasth-document-sync-for-google-docs'),
      value: setupReady ? __('Ready', 'brasth-document-sync-for-google-docs') : __('Setup', 'brasth-document-sync-for-google-docs'),
      variant: setupReady ? 'ready' as const : 'attention' as const
    };

  return (
    <AdminShell
      notice={app.notice}
      status={shellStatus}
      title={view === 'sources' ? __('Sources', 'brasth-document-sync-for-google-docs') : __('Google Setup', 'brasth-document-sync-for-google-docs')}
      version={app.config.version}
    >
      {!app.settings && view === 'sources' ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          <div className="docsync-wp-admin-grid__main">
            <SourcesTableSkeleton />
          </div>
        </div>
      ) : !app.settings ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          <div className="docsync-wp-admin-grid__main">
            <section aria-busy="true" className="docsync-wp-card" role="status">
              <p>{__('Loading settings...', 'brasth-document-sync-for-google-docs')}</p>
            </section>
          </div>
        </div>
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
              account={app.account}
              busy={app.busy}
              createSyncedDraftUrl={app.config.createSyncedDraftUrl}
              onConnect={app.connectGoogle}
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
            />
          </aside>
        </div>
      )}
    </AdminShell>
  );
};

export const AdminApp = ({ view }: { view: AdminView }): JSX.Element => {
  if (view === 'logs') {
    return <SyncLogsView />;
  }

  return <SetupSourcesApp view={view} />;
};
