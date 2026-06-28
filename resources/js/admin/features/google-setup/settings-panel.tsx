import { createElement, Fragment, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { GoogleSetupActiveTaskPanel } from './google-setup-active-task-panel';
import { GoogleSetupProgressRail } from './google-setup-progress-rail';
import { GoogleSetupTargetsStep } from './google-setup-targets-step';
import type { OAuthClientJsonCredentials } from './oauth-client-json';
import { buildSetupChecks, samePostTypes, type SetupCheck } from './google-setup-utils';
import {
  activeGoogleSetupTask,
  buildGoogleSetupChecklistItems,
  buildGoogleSetupNextAction,
  setupCredentialStepState,
  setupFirstSyncStepState
} from './google-setup-task-state';

type Props = {
  account: GoogleAccount;
  settings: SettingsResponse;
  busy: boolean;
  createSyncedDraftUrl: string;
  redirectUri: string;
  onConnect: () => Promise<void>;
  onSave: (settings: Partial<SettingsResponse> & { clientSecret?: string }) => Promise<void>;
};

export const SettingsPanel = ({
  account,
  settings,
  busy,
  createSyncedDraftUrl,
  redirectUri,
  onConnect,
  onSave
}: Props): JSX.Element => {
  const [clientId, setClientId] = useState(settings.clientId);
  const [clientSecret, setClientSecret] = useState('');
  const [enabledPostTypes, setEnabledPostTypes] = useState(settings.enabledPostTypes);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);
  const [elementorSyncEnabled, setElementorSyncEnabled] = useState(settings.elementorSyncEnabled);
  const [copyMessage, setCopyMessage] = useState('');
  const [testChecks, setTestChecks] = useState<SetupCheck[] | null>(null);
  const setupChecks = useMemo(() => buildSetupChecks(settings, account), [settings, account]);
  const completedChecks = setupChecks.filter((check) => check.complete).length;
  const setupProgress = Math.round((completedChecks / setupChecks.length) * 100);
  const canCreateDraft = settings.hasRequiredSettings && account.connected && account.hasRequiredScope;
  const hasCredentialChanges = clientId !== settings.clientId || clientSecret.trim() !== '';
  const canSaveCredentials = clientId.trim() !== '' && (clientSecret.trim() !== '' || settings.hasClientSecret);
  const hasSyncDefaultChanges =
    syncInterval !== settings.syncInterval ||
    elementorSyncEnabled !== settings.elementorSyncEnabled ||
    !samePostTypes(enabledPostTypes, settings.enabledPostTypes);
  const hasUnsavedChanges =
    hasCredentialChanges ||
    hasSyncDefaultChanges;
  const credentialStepState = setupCredentialStepState(settings, hasCredentialChanges);
  const syncDefaultsStepState = hasSyncDefaultChanges ? 'needs-action' : 'ready';
  const firstSyncStepState = setupFirstSyncStepState(canCreateDraft);

  useEffect(() => {
    setClientId(settings.clientId);
    setClientSecret('');
    setEnabledPostTypes(settings.enabledPostTypes);
    setSyncInterval(settings.syncInterval);
    setElementorSyncEnabled(settings.elementorSyncEnabled);
    setTestChecks(null);
  }, [settings]);

  const togglePostType = (postType: string) => {
    setEnabledPostTypes((current) => {
      if (postType === 'post') {
        return current.includes('post') ? current : ['post', ...current];
      }

      if (current.includes(postType)) {
        return current.filter((item) => item !== postType);
      }

      return [...current, postType];
    });
  };

  const copyValue = async (value: string, label: string) => {
    setCopyMessage('');

    if (!navigator.clipboard) {
      setCopyMessage(sprintf(__('Copy the %s from the field.', 'brasth-document-sync-for-google-docs'), label));
      return;
    }

    try {
      await navigator.clipboard.writeText(value);
      setCopyMessage(sprintf(__('%s copied.', 'brasth-document-sync-for-google-docs'), label));
    } catch {
      setCopyMessage(sprintf(__('Copy the %s from the field.', 'brasth-document-sync-for-google-docs'), label));
    }
  };

  const submit = async () => {
    await onSave({
      clientId,
      ...(clientSecret ? { clientSecret } : {}),
      enabledPostTypes,
      syncInterval,
      elementorSyncEnabled,
      connectionMode: settings.connectionMode || 'self_managed',
      defaultExportFormat: settings.defaultExportFormat,
      defaultPostStatus: settings.defaultPostStatus,
      scopeMode: settings.scopeMode
    });
    setClientSecret('');
  };

  const testSetup = () => {
    setTestChecks(buildSetupChecks(settings, account));
  };

  const nextAction = buildGoogleSetupNextAction({
    account,
    busy,
    canSaveCredentials,
    createSyncedDraftUrl,
    hasCredentialChanges,
    settings,
    onConnect,
    onSaveCredentials: submit
  });
  const activeTask = activeGoogleSetupTask(settings, account, hasCredentialChanges);
  const checklistItems = buildGoogleSetupChecklistItems({
    account,
    canCreateDraft,
    credentialStepState,
    firstSyncStepState,
    settings
  });

  const importCredentials = (credentials: OAuthClientJsonCredentials) => {
    setClientId(credentials.clientId);
    setClientSecret(credentials.clientSecret);
    setTestChecks(null);
  };

  return (
    <>
      <section className="docsync-wp-setup-workspace">
        <GoogleSetupProgressRail
          activeTask={activeTask}
          checklistItems={checklistItems}
          completedChecks={completedChecks}
          setupChecks={setupChecks}
          setupProgress={setupProgress}
        />

        <GoogleSetupActiveTaskPanel
          account={account}
          activeTask={activeTask}
          busy={busy}
          clientId={clientId}
          clientSecret={clientSecret}
          copyMessage={copyMessage}
          hasClientSecret={settings.hasClientSecret}
          hasUnsavedChanges={hasUnsavedChanges}
          nextAction={nextAction}
          onClientIdChange={setClientId}
          onClientSecretChange={setClientSecret}
          onCopyValue={copyValue}
          onImported={importCredentials}
          onTestSetup={testSetup}
          redirectUri={redirectUri}
          testChecks={testChecks}
        />
      </section>

      <div className="docsync-wp-setup-secondary">
        <GoogleSetupTargetsStep
          availablePostTypes={settings.availablePostTypes}
          elementorSyncEnabled={elementorSyncEnabled}
          enabledPostTypes={enabledPostTypes}
          onElementorSyncChange={setElementorSyncEnabled}
          onSyncIntervalChange={setSyncInterval}
          onTogglePostType={togglePostType}
          initialOpen={hasSyncDefaultChanges}
          stepState={syncDefaultsStepState}
          syncInterval={syncInterval}
        />
        {hasSyncDefaultChanges ? (
          <div className="docsync-wp-setup-secondary-actions">
            <AdminButton disabled={busy} onClick={submit}>
              {__('Save sync defaults', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        ) : null}
      </div>
    </>
  );
};
