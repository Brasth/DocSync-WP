import { createElement, useEffect, useMemo, useState } from '@wordpress/element';

import type { SettingsResponse } from '../api';
import { GoogleSetupCloudSteps } from './google-setup-cloud-steps';
import { GoogleSetupTargetsStep } from './google-setup-targets-step';
import { GoogleSetupTestResult } from './google-setup-test-result';
import {
  buildSetupChecks,
  pickerAppIdHelpUrl,
  samePostTypes,
  type SetupCheck
} from './google-setup-utils';

type Props = {
  settings: SettingsResponse;
  busy: boolean;
  redirectUri: string;
  javascriptOrigin: string;
  onSave: (settings: Partial<SettingsResponse> & { clientSecret?: string }) => Promise<void>;
};

export const SettingsPanel = ({ settings, busy, redirectUri, javascriptOrigin, onSave }: Props): JSX.Element => {
  const [clientId, setClientId] = useState(settings.clientId);
  const [clientSecret, setClientSecret] = useState('');
  const [pickerApiKey, setPickerApiKey] = useState(settings.pickerApiKey);
  const [pickerAppId, setPickerAppId] = useState(settings.pickerAppId);
  const [enabledPostTypes, setEnabledPostTypes] = useState(settings.enabledPostTypes);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);
  const [copyMessage, setCopyMessage] = useState('');
  const [testChecks, setTestChecks] = useState<SetupCheck[] | null>(null);
  const setupChecks = useMemo(() => buildSetupChecks(settings), [settings]);
  const completedChecks = setupChecks.filter((check) => check.complete).length;
  const setupProgress = Math.round((completedChecks / setupChecks.length) * 100);
  const hasUnsavedChanges =
    clientId !== settings.clientId ||
    clientSecret.trim() !== '' ||
    pickerApiKey !== settings.pickerApiKey ||
    pickerAppId !== settings.pickerAppId ||
    syncInterval !== settings.syncInterval ||
    !samePostTypes(enabledPostTypes, settings.enabledPostTypes);

  useEffect(() => {
    setClientId(settings.clientId);
    setClientSecret('');
    setPickerApiKey(settings.pickerApiKey);
    setPickerAppId(settings.pickerAppId);
    setEnabledPostTypes(settings.enabledPostTypes);
    setSyncInterval(settings.syncInterval);
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
      setCopyMessage(`Copy the ${label} from the field.`);
      return;
    }

    try {
      await navigator.clipboard.writeText(value);
      setCopyMessage(`${label} copied.`);
    } catch {
      setCopyMessage(`Copy the ${label} from the field.`);
    }
  };

  const submit = async () => {
    await onSave({
      clientId,
      ...(clientSecret ? { clientSecret } : {}),
      pickerApiKey,
      pickerAppId,
      enabledPostTypes,
      syncInterval,
      connectionMode: settings.connectionMode || 'self_managed',
      defaultExportFormat: settings.defaultExportFormat,
      defaultPostStatus: settings.defaultPostStatus,
      scopeMode: settings.scopeMode
    });
    setClientSecret('');
  };

  const testSetup = () => {
    setTestChecks(buildSetupChecks(settings));
  };

  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <p className="docsync-wp-kicker">Self-managed Google Cloud app</p>
        <h2>Google setup wizard</h2>
        <p>Complete these saved settings before each WordPress user connects Google.</p>
      </div>

      <div className="docsync-wp-setup-summary">
        <div>
          <strong>{completedChecks} of {setupChecks.length} setup checks complete</strong>
          <span>{settings.hasRequiredSettings ? 'OAuth connection ready.' : 'OAuth client setup incomplete.'}</span>
        </div>
        <div className="docsync-wp-setup-progress" aria-hidden="true">
          <span style={{ width: `${setupProgress}%` }} />
        </div>
      </div>

      <ol className="docsync-wp-setup-steps">
        <GoogleSetupCloudSteps
          copyMessage={copyMessage}
          javascriptOrigin={javascriptOrigin}
          onCopyValue={copyValue}
          redirectUri={redirectUri}
        />

        <li>
          <div className="docsync-wp-step-heading">
            <span>3</span>
            <div>
              <h3>Save OAuth and Picker credentials</h3>
              <p>Picker is the default document selection path; URL and file ID entry stay available under advanced linking.</p>
            </div>
          </div>
          <div className="docsync-wp-settings-grid">
            <label>
              <span>OAuth client ID</span>
              <input className="regular-text" onChange={(event) => setClientId(event.currentTarget.value)} type="text" value={clientId} />
            </label>
            <label>
              <span>OAuth client secret</span>
              <input
                className="regular-text"
                onChange={(event) => setClientSecret(event.currentTarget.value)}
                placeholder={settings.hasClientSecret ? 'Saved. Enter a new secret to replace.' : ''}
                type="password"
                value={clientSecret}
              />
            </label>
            <label>
              <span>Picker API key</span>
              <input className="regular-text" onChange={(event) => setPickerApiKey(event.currentTarget.value)} type="text" value={pickerApiKey} />
            </label>
            <label>
              <span>Picker app ID</span>
              <input className="regular-text" onChange={(event) => setPickerAppId(event.currentTarget.value)} type="text" value={pickerAppId} />
              <span className="description">
                Use the Google Cloud project number. <a href={pickerAppIdHelpUrl} rel="noreferrer" target="_blank">Open IAM &amp; Admin settings</a>.
              </span>
            </label>
          </div>
        </li>

        <GoogleSetupTargetsStep
          availablePostTypes={settings.availablePostTypes}
          enabledPostTypes={enabledPostTypes}
          onSyncIntervalChange={setSyncInterval}
          onTogglePostType={togglePostType}
          syncInterval={syncInterval}
        />
      </ol>

      <div className="docsync-wp-settings-actions">
        <button className="button button-primary" disabled={busy} onClick={submit} type="button">
          Save settings
        </button>
        <button className="button" disabled={busy} onClick={testSetup} type="button">
          Test setup
        </button>
        {hasUnsavedChanges ? <span>Unsaved changes are not tested until saved.</span> : null}
      </div>

      {testChecks ? <GoogleSetupTestResult checks={testChecks} /> : null}
    </section>
  );
};
