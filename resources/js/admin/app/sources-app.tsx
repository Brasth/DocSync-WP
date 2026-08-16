import { createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { SourcesFolderWatches } from '../features/sources/sources-folder-watches';
import { SourcesTable, SourcesTableSkeleton } from '../features/sources/sources-table';
import { SourceHealthSummary } from '../features/sources/source-health-summary';
import { ActivationGuidance } from '../features/activation/activation-guidance';
import { ActivationResult } from '../features/activation/activation-result';
import { DocSourceModal } from '../features/doc-source-modal/doc-source-modal';
import { AdminShell } from '../shared/ui/admin-shell';
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
    <AdminShell
      notice={app.notice}
      status={{
        label: app.sources.length === 1 ? __('shown source', 'brasth-document-sync-for-google-docs') : __('shown sources', 'brasth-document-sync-for-google-docs'),
        value: app.sources.length
      }}
      title={__('Sources', 'brasth-document-sync-for-google-docs')}
      version={app.config.version}
    >
      {!app.workspace ? (
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
            <ActivationGuidance
              account={app.account}
              busy={app.busy}
              onConnect={app.connectGoogle}
              onCreateSource={app.openSourceModal}
              setupUrl="admin.php?page=brasth-document-sync-for-google-docs"
              workspace={app.workspace}
            />
            <SourceHealthSummary summary={app.workspace.sourceSummary} />
            <SourcesFolderWatches
              busy={app.busy}
              onPause={app.onPauseFolderWatch}
              onRemove={app.onRemoveFolderWatch}
              onResume={app.onResumeFolderWatch}
              onScan={app.onScanFolderWatch}
              watches={app.folderWatches}
            />
            {app.activationSource ? (
              <ActivationResult busy={app.busy} onRetry={app.retryActivationSource} source={app.activationSource} />
            ) : null}
            <SourcesTable
              availablePostTypes={app.workspace.availablePostTypes.filter((postType) => app.workspace?.enabledPostTypes.includes(postType.name))}
              busy={app.busy}
              canCreateSource={app.workspace.siteConnectionReady && app.account.connected && app.account.hasRequiredScope && app.workspace.creatablePostTypes.length > 0}
              filters={app.sourceFilters}
              hasMore={app.hasMoreSources}
              onFiltersChange={app.applySourceFilters}
              onLoadMore={app.loadMoreSources}
              onCreateSource={app.openSourceModal}
              onRefresh={() => app.runAction(app.refresh)}
              onSync={app.syncOne}
              onSyncAll={app.syncAll}
              sources={app.sources}
            />
          </div>
          <DocSourceModal
            isOpen={app.sourceModalOpen}
            onClose={app.closeSourceModal}
            onCompleted={app.handleSourceCreated}
            onFolderWatchCreated={() => {
              void app.refresh();
            }}
            target={app.sourceModalOpen && app.workspace.creatablePostTypes[0] ? { mode: 'new', postType: app.workspace.creatablePostTypes[0] } : null}
          />
        </div>
      )}
    </AdminShell>
  );
};
