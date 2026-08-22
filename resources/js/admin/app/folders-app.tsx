import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { DocSourceModal } from '../features/doc-source-modal/doc-source-modal';
import { FolderWatchDetailDrawer } from '../features/folder-watches/folder-watch-detail-drawer';
import { FolderWatchesView } from '../features/folder-watches/folder-watches-view';
import { useFolderWatches } from '../features/folder-watches/use-folder-watches';
import type { FolderWatchRecord } from '../api';
import { AdminShell } from '../shared/ui/admin-shell';
import { SourcesTableSkeleton } from '../features/sources/sources-table';

export const FoldersApp = (): JSX.Element => {
  const app = useFolderWatches();
  const [editing, setEditing] = useState<FolderWatchRecord | null>(null);

  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load Drive folder watches.', 'brasth-document-sync-for-google-docs'));
      }).catch(() => undefined);
    });
  }, []);

  return (
    <AdminShell
      notice={app.notice}
      status={{
        label: app.watches.length === 1 ? __('folder watch', 'brasth-document-sync-for-google-docs') : __('folder watches', 'brasth-document-sync-for-google-docs'),
        value: app.watches.length
      }}
      title={__('Drive Folders', 'brasth-document-sync-for-google-docs')}
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
            <FolderWatchesView
              busy={app.busy}
              onCreateWatch={app.openSourceModal}
              onEdit={setEditing}
              onPause={app.onPause}
              onRemove={app.onRemove}
              onResume={app.onResume}
              onScan={app.onScan}
              watches={app.watches}
              workspace={app.workspace}
            />
          </div>
          <FolderWatchDetailDrawer
            busy={app.busy}
            onClose={() => setEditing(null)}
            onRetry={app.onRetry}
            onSave={async (watchId, payload) => {
              await app.onUpdate(watchId, payload);
              setEditing(null);
            }}
            watch={editing}
          />
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
