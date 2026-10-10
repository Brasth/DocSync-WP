import { Fragment, createElement, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { FolderWatchPoller } from '../features/activation/folder-watch-poller';
import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { DocSourceModal } from '../features/doc-source-modal/doc-source-modal';
import { SettingsPanel } from '../features/google-setup/settings-panel';
import { AdminShell } from '../shared/ui/admin-shell';
import { useSetupApp } from './use-setup-app';

export const SetupApp = (): JSX.Element => {
  const app = useSetupApp();
  useEffect(() => {
    app.refresh().catch((caught) => {
      app.runAction(async () => { throw caught instanceof Error ? caught : new Error(__('Could not load Document Sync.', 'brasth-document-sync-for-google-docs')); }).catch(() => undefined);
    });
  }, []);
  return (
    <AdminShell notice={app.notice} title={__('Settings', 'brasth-document-sync-for-google-docs')} variant="setup" version={app.config.version}>
      {!app.settings || !app.workspace ? <section aria-busy="true" className="docsync-wp-setup-card" role="status"><p>{__('Loading settings…', 'brasth-document-sync-for-google-docs')}</p></section> : <>
        <SettingsPanel account={app.account} activated={app.workspace.sourceSummary.activated} availablePostTypes={app.workspace.availablePostTypes} busy={app.busy} canCreateSource={app.workspace.creatablePostTypes.length > 0} creatablePostTypes={app.workspace.creatablePostTypes} oauthConnectError={app.oauthConnectError} onClearOAuthConfiguration={app.clearSavedOAuthConfiguration} onConnect={app.connectGoogle} onCreateSource={app.openSourceModal} onDisconnect={app.disconnectGoogle} onRetrySource={app.retryActivationSource} onSave={app.persistSettings} onTargetPostTypeChange={app.setTargetPostType} redirectUri={app.redirectUri} settings={app.settings} source={app.activationSource} targetPostType={app.targetPostType} watch={app.activationWatch} workspace={app.workspace} />
      </>}
      {app.activationSource && !['synced', 'skipped', 'error'].includes(app.activationSource.syncStatus) ? <BackgroundSyncPoller onError={app.handleActivationPollingError} onStatus={app.handleActivationSourceStatus} onTerminal={app.handleActivationSourceTerminal} onTimeout={app.handleActivationSourceTimeout} postId={app.activationSource.postId} /> : null}
      {app.activationWatch && (app.activationWatch.status === 'importing' || app.activationWatch.pendingCount > 0) ? <FolderWatchPoller onError={app.handleActivationPollingError} onStatus={app.handleActivationWatchStatus} onTimeout={app.handleActivationSourceTimeout} watchId={app.activationWatch.id} /> : null}
      <DocSourceModal key={app.sourceIntent} initialIntent={app.sourceIntent} isOpen={app.sourceModalOpen} onClose={app.closeSourceModal} onCompleted={app.handleSourceCreated} onFolderWatchCreated={app.handleFolderWatchCreated} target={app.sourceModalOpen && app.targetPostType ? { mode: 'new', postType: app.targetPostType } : null} />
    </AdminShell>
  );
};
