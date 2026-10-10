import { Fragment, createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { FolderWatchRecord, GoogleAccount, SettingsResponse, SettingsUpdate, SourceRecord, WorkspaceResponse } from '../../api';
import type { AvailablePostType } from '../../config';
import type { OAuthConnectErrorView } from './oauth-connect-error';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { SetupAccountTaskPanel } from './setup-account-task-panel';
import { SetupCredentialsPanel } from './setup-credentials-panel';
import { resolveSetupJourney, resolveSetupJourneyView, type SetupJourneyOverride } from './setup-journey-state';
import { SetupMaintenancePanel } from './setup-maintenance-panel';
import { SetupNavigationGuard, useSetupLeaveGuard } from './setup-navigation-guard';
import { SetupSourceTaskPanel } from './setup-source-task-panel';
import { SetupTaskLayout } from './setup-task-layout';

export type SettingsPanelLayoutMode = 'focus' | 'ready';
type Props = {
  account: GoogleAccount;
  settings: SettingsResponse;
  busy: boolean;
  availablePostTypes?: AvailablePostType[];
  canCreateSource?: boolean;
  creatablePostTypes?: string[];
  activated?: boolean;
  layoutMode?: SettingsPanelLayoutMode;
  redirectUri: string;
  onClearOAuthConfiguration: () => Promise<boolean>;
  onConnect: () => Promise<void>;
  onDisconnect?: () => Promise<void>;
  onCreateSource?: (intent?: 'folder' | 'document') => void;
  onSave: (settings: SettingsUpdate) => Promise<boolean>;
  onTargetPostTypeChange?: (postType: string) => void;
  onRetrySource?: () => Promise<void>;
  oauthConnectError?: OAuthConnectErrorView | null;
  showTargetPicker?: boolean;
  targetPostType?: string;
  workspace?: WorkspaceResponse | null;
  source?: SourceRecord | null;
  watch?: FolderWatchRecord | null;
};

export const SettingsPanel = ({ account, settings, busy, availablePostTypes = [], canCreateSource = false, creatablePostTypes = [], activated = false, redirectUri, onClearOAuthConfiguration, onConnect, onDisconnect, onCreateSource, onSave, onTargetPostTypeChange, onRetrySource, oauthConnectError = null, showTargetPicker = true, targetPostType = '', workspace = null, source = null, watch = null }: Props): JSX.Element => {
  const [disconnectOpen, setDisconnectOpen] = useState(false);
  const [override, setOverride] = useState<SetupJourneyOverride>('derived');
  const guard = useSetupLeaveGuard();
  const taskRef = useRef<HTMLDivElement>(null);
  const resolution = resolveSetupJourney({ hasRequiredSettings: settings.hasRequiredSettings, accountConnected: account.connected, accountHasRequiredScope: account.hasRequiredScope, source, watch, workspace: workspace || { sourceSummary: { activated } } });
  const view = resolveSetupJourneyView(resolution, override, { forceCredentials: oauthConnectError?.code === 'oauth_invalid_credentials' });
  const previousPhase = useRef(`${view.phase}:${view.editingCredentials}`);
  const changeView = (next: SetupJourneyOverride) => guard.requestLeave(() => setOverride(next));
  const saveCredentials = useCallback(async (next: SettingsUpdate) => {
    const saved = await onSave(next);
    if (saved) { guard.setDirty(false); setOverride('derived'); }
    return saved;
  }, [onSave, guard.setDirty]);

  useEffect(() => {
    const next = `${view.phase}:${view.editingCredentials}`;
    if (next === previousPhase.current) { return; }
    previousPhase.current = next;
    const heading = taskRef.current?.querySelector<HTMLElement>('h2');
    heading?.setAttribute('tabindex', '-1');
    heading?.focus({ preventScroll: true });
  }, [view.phase, view.editingCredentials]);

  return (
    <>
      {view.phase === 'maintenance' ? <div ref={taskRef}><SetupMaintenancePanel account={account} busy={busy} onClearOAuthConfiguration={onClearOAuthConfiguration} onConnect={onConnect} onDirtyChange={guard.setDirty} onDisconnect={onDisconnect} onEditCredentials={() => changeView('edit-credentials')} onSave={onSave} pendingActivation={view.pendingActivation} settings={settings} />{view.pendingActivation ? <button className="docsync-wp-setup-return" onClick={() => changeView('derived')} type="button">{__('Return to first import', 'brasth-document-sync-for-google-docs')}</button> : null}</div> : <SetupTaskLayout accountEmail={account.googleAccountEmail} onDisconnect={onDisconnect ? () => setDisconnectOpen(true) : undefined} onEditCredentials={() => changeView('edit-credentials')} rail={view.rail} taskRef={taskRef}>
        {view.phase === 'credentials' ? <SetupCredentialsPanel busy={busy} editing={view.editingCredentials} onCancel={() => changeView('derived')} onDirtyChange={guard.setDirty} onSave={saveCredentials} redirectUri={redirectUri} settings={settings} /> : view.phase === 'account' ? <SetupAccountTaskPanel account={account} busy={busy} displayName={settings.currentUserDisplayName} oauthConnectError={oauthConnectError} onConnect={onConnect} onReviewCredentials={() => changeView('edit-credentials')} redirectUri={redirectUri} /> : <SetupSourceTaskPanel availablePostTypes={availablePostTypes} basis={resolution.basis} busy={busy} canChoose={canCreateSource} creatablePostTypes={creatablePostTypes} onChoose={onCreateSource} onOpenMaintenance={() => changeView('maintenance')} onRetrySource={onRetrySource} onTargetPostTypeChange={onTargetPostTypeChange} phase={view.phase} settings={settings} showTargetPicker={showTargetPicker} source={source} targetPostType={targetPostType} watch={watch} workspaceAttention={workspace?.sourceSummary.attention ?? 0} workspaceImporting={workspace?.folderWatches?.importing ?? 0} workspaceSyncing={workspace?.sourceSummary.syncing ?? 0} workspaceWatchAttention={workspace?.folderWatches?.attention ?? 0} />}
      </SetupTaskLayout>}
      <ConfirmDialog open={disconnectOpen} busy={busy} onOpenChange={setDisconnectOpen} title={__('Disconnect your Google account?', 'brasth-document-sync-for-google-docs')} description={__('Imported WordPress content is retained. Reconnect before importing or syncing more Docs.', 'brasth-document-sync-for-google-docs')} confirmLabel={__('Disconnect Google', 'brasth-document-sync-for-google-docs')} variant="danger" onConfirm={async () => { await onDisconnect?.(); setDisconnectOpen(false); }} />
      <SetupNavigationGuard dirty={guard.dirty} onLeave={guard.confirmLeave} onStay={guard.stay} open={guard.open} />
    </>
  );
};
