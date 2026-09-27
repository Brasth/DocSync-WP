import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse } from '../../api';
import type { AvailablePostType } from '../../config';
import { GoogleSetupActiveTaskPanel } from './google-setup-active-task-panel';
import { GoogleSetupProgressRail } from './google-setup-progress-rail';
import type { OAuthClientJsonCredentials } from './oauth-client-json';
import { buildSetupChecks, type SetupCheck } from './google-setup-utils';
import {
  activeGoogleSetupTask,
  buildGoogleSetupNextAction,
  setupCredentialStepState
} from './google-setup-task-state';
import { buildSetupWizardSteps } from './setup-wizard-steps';
import { OAuthConnectErrorPanel } from './oauth-connect-error-panel';
import type { OAuthConnectErrorView } from './oauth-connect-error';

export type SettingsPanelLayoutMode = 'focus' | 'ready';

type Props = {
  account: GoogleAccount;
  settings: SettingsResponse;
  busy: boolean;
  availablePostTypes?: AvailablePostType[];
  canCreateSource?: boolean;
  creatablePostTypes?: string[];
  activated?: boolean;
  /** focus = first-run single column; ready = maintenance layout with quieter rail. */
  layoutMode?: SettingsPanelLayoutMode;
  redirectUri: string;
  onClearOAuthConfiguration: () => Promise<boolean>;
  onConnect: () => Promise<void>;
  onCreateSource?: (intent?: 'folder' | 'document') => void;
  onSave: (settings: Partial<SettingsResponse> & { clientSecret?: string }) => Promise<boolean>;
  onTargetPostTypeChange?: (postType: string) => void;
  oauthConnectError?: OAuthConnectErrorView | null;
  showTargetPicker?: boolean;
  targetPostType?: string;
};

export const SettingsPanel = ({
  account,
  settings,
  busy,
  availablePostTypes = [],
  canCreateSource = true,
  creatablePostTypes = [],
  activated = false,
  layoutMode = 'focus',
  redirectUri,
  onClearOAuthConfiguration,
  onConnect,
  onCreateSource = () => undefined,
  onSave,
  onTargetPostTypeChange,
  oauthConnectError = null,
  showTargetPicker = false,
  targetPostType = ''
}: Props): JSX.Element => {
  const [clientId, setClientId] = useState(settings.clientId);
  const [clientSecret, setClientSecret] = useState('');
  const [copyMessage, setCopyMessage] = useState('');
  const [testChecks, setTestChecks] = useState<SetupCheck[] | null>(null);
  const setupChecks = useMemo(() => buildSetupChecks(settings, account), [settings, account]);
  const canCreateDraft = settings.hasRequiredSettings && account.connected && account.hasRequiredScope;
  const hasCredentialChanges = clientId !== settings.clientId || clientSecret.trim() !== '';
  const canSaveCredentials = clientId.trim() !== '' && (clientSecret.trim() !== '' || settings.hasClientSecret);
  const credentialStepState = setupCredentialStepState(settings, hasCredentialChanges);

  useEffect(() => {
    setClientId(settings.clientId);
    setClientSecret('');
    setTestChecks(null);
  }, [settings]);

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
    const saved = await onSave({
      clientId,
      ...(clientSecret ? { clientSecret } : {}),
      connectionMode: settings.connectionMode || 'self_managed',
      defaultExportFormat: settings.defaultExportFormat,
      defaultPostStatus: settings.defaultPostStatus,
      scopeMode: settings.scopeMode
    });

    if (saved) {
      setClientSecret('');
    }
  };

  const testSetup = () => {
    setTestChecks(setupChecks);
  };

  const nextAction = buildGoogleSetupNextAction({
    account,
    activated,
    busy,
    canSaveCredentials,
    canCreateSource,
    hasCredentialChanges,
    settings,
    onConnect,
    onCreateSource,
    onSaveCredentials: submit
  });
  const activeTask = oauthConnectError?.code === 'oauth_invalid_credentials'
    ? 'credentials'
    : activeGoogleSetupTask(settings, account, hasCredentialChanges);
  const wizardSteps = buildSetupWizardSteps({
    account,
    activated,
    canCreateDraft,
    credentialStepState,
    settings
  });
  const completedSteps = wizardSteps.filter((step) => step.state === 'complete').length;

  const importCredentials = (credentials: OAuthClientJsonCredentials) => {
    setClientId(credentials.clientId);
    setClientSecret(credentials.clientSecret);
    setTestChecks(null);
  };

  return (
    <section className={`docsync-wp-setup-workspace docsync-wp-setup-workspace--${layoutMode}`}>
      <GoogleSetupProgressRail
        activeTask={activeTask}
        activated={activated}
        completedSteps={completedSteps}
        wizardSteps={wizardSteps}
      />

      {oauthConnectError ? (
        <OAuthConnectErrorPanel
          error={oauthConnectError}
          onCopyRedirectUri={(value) => void copyValue(value, __('Redirect URI', 'brasth-document-sync-for-google-docs'))}
          onReconnect={onConnect}
          onRetry={onConnect}
          redirectUri={redirectUri}
        />
      ) : null}

      <GoogleSetupActiveTaskPanel
        account={account}
        activeTask={activeTask}
        availablePostTypes={availablePostTypes}
        busy={busy}
        creatablePostTypes={creatablePostTypes}
        clientId={clientId}
        clientSecret={clientSecret}
        copyMessage={copyMessage}
        hasClientSecret={settings.hasClientSecret}
        hasSavedOAuthConfiguration={settings.hasRequiredSettings}
        hasUnsavedChanges={hasCredentialChanges}
        nextAction={nextAction}
        onClearOAuthConfiguration={onClearOAuthConfiguration}
        onClientIdChange={setClientId}
        onClientSecretChange={setClientSecret}
        onCopyValue={copyValue}
        onImported={importCredentials}
        onTargetPostTypeChange={onTargetPostTypeChange}
        oauthConnectError={oauthConnectError}
        onTestSetup={testSetup}
        redirectUri={redirectUri}
        showTargetPicker={showTargetPicker}
        targetPostType={targetPostType}
        testChecks={testChecks}
      />
    </section>
  );
};
