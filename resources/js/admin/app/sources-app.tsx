import { createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { SourcesTable, SourcesTableSkeleton } from '../features/sources/sources-table';
import { AdminNotice } from '../shared/ui/admin-notice';
import { useSourcesApp } from './use-sources-app';

export const SourcesApp = (): JSX.Element => {
  const app = useSourcesApp();

  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load Brasth Document Sync sources.', 'brasth-document-sync-for-google-docs'));
      }).catch(() => undefined);
    });
  }, []);

  return (
    <main className="docsync-wp-admin-shell">
      <header className="docsync-wp-hero">
        <div>
          <p>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</p>
          <h1>{__('Sources', 'brasth-document-sync-for-google-docs')}</h1>
          <span>{__('Version', 'brasth-document-sync-for-google-docs')} {app.config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          <strong>{app.sources.length}</strong>
          <span>{app.sources.length === 1 ? __('shown source', 'brasth-document-sync-for-google-docs') : __('shown sources', 'brasth-document-sync-for-google-docs')}</span>
        </div>
      </header>

      <AdminNotice notice={app.notice} />

      {!app.settings ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          <div className="docsync-wp-admin-grid__main">
            <SourcesTableSkeleton />
          </div>
        </div>
      ) : (
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
      )}
    </main>
  );
};
