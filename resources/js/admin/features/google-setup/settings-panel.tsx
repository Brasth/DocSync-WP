import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { GoogleSetupCloudSteps } from './google-setup-cloud-steps';
import { GoogleSetupCredentialsStep } from './google-setup-credentials-step';
import { GoogleSetupFirstSyncStep } from './google-setup-first-sync-step';
import { GoogleSetupNextAction, type GoogleSetupNextActionConfig } from './google-setup-next-action';
import { GoogleSetupTargetsStep } from './google-setup-targets-step';
import { GoogleSetupTestResult } from './google-setup-test-result';
import {
  buildSetupChecks,
  samePostTypes,
  type SetupCheck
} from './google-setup-utils';

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
  const credentialStepState = settings.hasRequiredSettings && !hasCredentialChanges ? 'complete' : 'needs-action';
  const syncDefaultsStepState = hasSyncDefaultChanges ? 'needs-action' : 'ready';
  const firstSyncStepState = canCreateDraft ? 'ready' : 'needs-action';

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

  const nextAction: GoogleSetupNextActionConfig = (() => {
    if (!settings.hasRequiredSettings || hasCredentialChanges) {
      return {
        title: __('Save OAuth credentials', 'brasth-document-sync-for-google-docs'),
        description: __('Save the Google OAuth web client ID and secret before connecting Google.', 'brasth-document-sync-for-google-docs'),
        label: __('Save OAuth credentials', 'brasth-document-sync-for-google-docs'),
        disabled: busy || !canSaveCredentials,
        onClick: submit
      };
    }

    if (!account.connected) {
      return {
        title: __('Connect Google', 'brasth-document-sync-for-google-docs'),
        description: __('Authorize this WordPress user to browse and sync readable Google Docs.', 'brasth-document-sync-for-google-docs'),
        label: __('Connect Google', 'brasth-document-sync-for-google-docs'),
        disabled: busy,
        onClick: onConnect
      };
    }

    if (!account.hasRequiredScope) {
      return {
        title: __('Reconnect Google', 'brasth-document-sync-for-google-docs'),
        description: __('Reconnect to grant Drive read-only access required by the current browser and sync flow.', 'brasth-document-sync-for-google-docs'),
        label: __('Reconnect Google', 'brasth-document-sync-for-google-docs'),
        disabled: busy,
        onClick: onConnect
      };
    }

    return {
      title: __('Create first synced draft', 'brasth-document-sync-for-google-docs'),
      description: __('Open the Posts list, choose Add Sync Doc, select a Google Doc, and create the first synced draft.', 'brasth-document-sync-for-google-docs'),
      label: __('Create synced draft', 'brasth-document-sync-for-google-docs'),
      href: createSyncedDraftUrl
    };
  })();

  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <p className="docsync-wp-kicker">{__('Self-managed Google Cloud app', 'brasth-document-sync-for-google-docs')}</p>
        <h2>{__('Google setup wizard', 'brasth-document-sync-for-google-docs')}</h2>
        <p>{__('Complete these saved settings before each WordPress user connects Google.', 'brasth-document-sync-for-google-docs')}</p>
      </div>

      <GoogleSetupNextAction action={nextAction} />

      <div className="docsync-wp-setup-summary">
        <div>
          <strong>
            {sprintf(
              /* translators: 1: completed setup check count, 2: total setup check count. */
              __('%1$d of %2$d setup checks complete', 'brasth-document-sync-for-google-docs'),
              completedChecks,
              setupChecks.length
            )}
          </strong>
          <span>
            {canCreateDraft
              ? __('Ready to create the first synced draft.', 'brasth-document-sync-for-google-docs')
              : settings.hasRequiredSettings
                ? __('OAuth settings saved. Connect Google next.', 'brasth-document-sync-for-google-docs')
                : __('OAuth client setup incomplete.', 'brasth-document-sync-for-google-docs')}
          </span>
        </div>
        <div className="docsync-wp-setup-progress" aria-hidden="true">
          <span style={{ width: `${setupProgress}%` }} />
        </div>
      </div>

      <ol className="docsync-wp-setup-steps">
        <GoogleSetupCloudSteps
          cloudStepState="manual"
          copyMessage={copyMessage}
          initialOpen={!settings.hasRequiredSettings}
          onCopyValue={copyValue}
          redirectUri={redirectUri}
          redirectStepState="manual"
        />

        <GoogleSetupCredentialsStep
          busy={busy}
          clientId={clientId}
          clientSecret={clientSecret}
          hasClientSecret={settings.hasClientSecret}
          onClientIdChange={setClientId}
          onClientSecretChange={setClientSecret}
          onImported={(credentials) => {
            setClientId(credentials.clientId);
            setClientSecret(credentials.clientSecret);
            setTestChecks(null);
          }}
          initialOpen={!settings.hasRequiredSettings || hasCredentialChanges}
          redirectUri={redirectUri}
          stepNumber={3}
          stepState={credentialStepState}
        />

        <GoogleSetupFirstSyncStep
          account={account}
          canCreateDraft={canCreateDraft}
          createSyncedDraftUrl={createSyncedDraftUrl}
          initialOpen={canCreateDraft || (account.connected && !account.hasRequiredScope)}
          stepNumber={4}
          stepState={firstSyncStepState}
        />
      </ol>

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
      </div>

      <div className="docsync-wp-settings-actions">
        <AdminButton disabled={busy} onClick={submit}>
          {__('Save settings', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        <AdminButton disabled={busy} onClick={testSetup}>
          {__('Test setup', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        {hasUnsavedChanges ? <span>{__('Unsaved changes are not tested until saved.', 'brasth-document-sync-for-google-docs')}</span> : null}
      </div>

      {testChecks ? <GoogleSetupTestResult checks={testChecks} /> : null}
    </section>
  );
};
