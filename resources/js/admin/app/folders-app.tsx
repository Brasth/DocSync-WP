import { createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { DocSourceModal } from '../features/doc-source-modal/doc-source-modal';
import { FolderWatchDetailPage } from '../features/folder-watches/folder-watch-detail-page';
import { FolderWatchesView } from '../features/folder-watches/folder-watches-view';
import { useFolderWatchRoute } from '../features/folder-watches/use-folder-watch-route';
import { useFolderWatches } from '../features/folder-watches/use-folder-watches';
import { AdminShell } from '../shared/ui/admin-shell';
import { SourcesTableSkeleton } from '../features/sources/sources-table';

export const FoldersApp = (): JSX.Element => {
  const app = useFolderWatches();
  const route = useFolderWatchRoute();
  const activeWatch = route.watchId
    ? app.watches.find((watch) => watch.id === route.watchId) ?? null
    : null;

  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load Drive folder watches.', 'brasth-document-sync-for-google-docs'));
      }).catch(() => undefined);
    });
  }, []);

  useEffect(() => {
    if (!route.watchId || app.watches.length === 0 || activeWatch) {
      return;
    }

    app.setNotice({
      type: 'error',
      message: __('That folder watch could not be found.', 'brasth-document-sync-for-google-docs')
    });
    route.closeWatch();
  }, [activeWatch, app.watches.length, route.watchId]);

  const title = activeWatch
    ? activeWatch.folderName
    : __('Drive Folders', 'brasth-document-sync-for-google-docs');

  return (
    <AdminShell
      notice={app.notice}
      status={{
        label: app.watches.length === 1 ? __('folder watch', 'brasth-document-sync-for-google-docs') : __('folder watches', 'brasth-document-sync-for-google-docs'),
        value: app.watches.length
      }}
      title={title}
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
          <div className="docsync-wp-admin-grid__main">
            {route.watchId && activeWatch ? (
              <FolderWatchDetailPage
                busy={app.busy}
                onBack={route.closeWatch}
                onPause={app.onPause}
                onRemove={async (watchId) => {
                  await app.onRemove(watchId);
                  route.closeWatch();
                }}
                onResume={app.onResume}
                onRetry={app.onRetry}
                onSave={app.saveWatch}
                onScan={app.onScan}
                watch={activeWatch}
              />
            ) : (
              <FolderWatchesView
                busy={app.busy}
                onCreateWatch={app.openSourceModal}
                onEdit={(watch) => route.openWatch(watch.id)}
                onPause={app.onPause}
                onRemove={app.onRemove}
                onResume={app.onResume}
                onScan={app.onScan}
                watches={app.watches}
                workspace={app.workspace}
              />
            )}
          </div>
          <DocSourceModal
            initialIntent="folder"
            isOpen={app.sourceModalOpen}
            onClose={app.closeSourceModal}
            onCompleted={() => undefined}
            onFolderWatchCreated={() => {
              void app.refresh();
              app.closeSourceModal();
            }}
            target={app.sourceModalOpen && app.workspace.creatablePostTypes[0] ? { mode: 'new', postType: app.workspace.creatablePostTypes[0] } : null}
          />
        </div>
      )}
    </AdminShell>
  );
};
