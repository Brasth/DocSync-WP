import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SettingsResponse } from '../../api';
import { GoogleSetupCloudSteps } from './google-setup-cloud-steps';
import { GoogleSetupTargetsStep } from './google-setup-targets-step';
import { GoogleSetupTestResult } from './google-setup-test-result';
import {
  buildSetupChecks,
  samePostTypes,
  type SetupCheck
} from './google-setup-utils';
import { OAuthClientJsonImport } from './oauth-client-json-import';
import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  settings: SettingsResponse;
  busy: boolean;
  redirectUri: string;
  onSave: (settings: Partial<SettingsResponse> & { clientSecret?: string }) => Promise<void>;
};

export const SettingsPanel = ({ settings, busy, redirectUri, onSave }: Props): JSX.Element => {
  const [clientId, setClientId] = useState(settings.clientId);
  const [clientSecret, setClientSecret] = useState('');
  const [enabledPostTypes, setEnabledPostTypes] = useState(settings.enabledPostTypes);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);
  const [elementorSyncEnabled, setElementorSyncEnabled] = useState(settings.elementorSyncEnabled);
  const [copyMessage, setCopyMessage] = useState('');
  const [testChecks, setTestChecks] = useState<SetupCheck[] | null>(null);
  const setupChecks = useMemo(() => buildSetupChecks(settings), [settings]);
  const completedChecks = setupChecks.filter((check) => check.complete).length;
  const setupProgress = Math.round((completedChecks / setupChecks.length) * 100);
  const hasUnsavedChanges =
    clientId !== settings.clientId ||
    clientSecret.trim() !== '' ||
    syncInterval !== settings.syncInterval ||
    elementorSyncEnabled !== settings.elementorSyncEnabled ||
    !samePostTypes(enabledPostTypes, settings.enabledPostTypes);

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
    setTestChecks(buildSetupChecks(settings));
  };

  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <p className="docsync-wp-kicker">{__('Self-managed Google Cloud app', 'brasth-document-sync-for-google-docs')}</p>
        <h2>{__('Google setup wizard', 'brasth-document-sync-for-google-docs')}</h2>
        <p>{__('Complete these saved settings before each WordPress user connects Google.', 'brasth-document-sync-for-google-docs')}</p>
      </div>

      <div className="docsync-wp-setup-summary">
        <div>
          <strong>
            {sprintf(
              __('%1$d of %2$d setup checks complete', 'brasth-document-sync-for-google-docs'),
              completedChecks,
              setupChecks.length
            )}
          </strong>
          <span>
            {settings.hasRequiredSettings
              ? __('OAuth connection ready.', 'brasth-document-sync-for-google-docs')
              : __('OAuth client setup incomplete.', 'brasth-document-sync-for-google-docs')}
          </span>
        </div>
        <div className="docsync-wp-setup-progress" aria-hidden="true">
          <span style={{ width: `${setupProgress}%` }} />
        </div>
      </div>

      <ol className="docsync-wp-setup-steps">
        <GoogleSetupCloudSteps
          copyMessage={copyMessage}
          onCopyValue={copyValue}
          redirectUri={redirectUri}
        />

        <li>
          <div className="docsync-wp-step-heading">
            <span>3</span>
            <div>
              <h3>{__('Save OAuth credentials', 'brasth-document-sync-for-google-docs')}</h3>
              <p>{__("The custom document browser uses these server-side credentials and the connected user's Drive read-only grant.", 'brasth-document-sync-for-google-docs')}</p>
            </div>
          </div>
          <OAuthClientJsonImport
            busy={busy}
            onImported={(credentials) => {
              setClientId(credentials.clientId);
              setClientSecret(credentials.clientSecret);
              setTestChecks(null);
            }}
            redirectUri={redirectUri}
          />
          <div className="docsync-wp-settings-grid">
            <label>
              <span>{__('OAuth client ID', 'brasth-document-sync-for-google-docs')}</span>
              <input className="regular-text" onChange={(event) => setClientId(event.currentTarget.value)} type="text" value={clientId} />
            </label>
            <label>
              <span>{__('OAuth client secret', 'brasth-document-sync-for-google-docs')}</span>
              <input
                className="regular-text"
                onChange={(event) => setClientSecret(event.currentTarget.value)}
                placeholder={settings.hasClientSecret ? __('Saved. Enter a new secret to replace.', 'brasth-document-sync-for-google-docs') : ''}
                type="password"
                value={clientSecret}
              />
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

        <li>
          <div className="docsync-wp-step-heading">
            <span>4</span>
            <div>
              <h3>{__('Elementor sync support', 'brasth-document-sync-for-google-docs')}</h3>
              <p>{__('When Elementor is active, allow synced posts that are already built with Elementor to receive native Elementor layouts.', 'brasth-document-sync-for-google-docs')}</p>
            </div>
          </div>
          <label className="docsync-wp-checkbox-row">
            <input
              checked={elementorSyncEnabled}
              onChange={(event) => setElementorSyncEnabled(event.currentTarget.checked)}
              type="checkbox"
            />
            <span>{__('Enable Elementor sync support', 'brasth-document-sync-for-google-docs')}</span>
          </label>
        </li>
      </ol>

      <div className="docsync-wp-settings-actions">
        <AdminButton disabled={busy} onClick={submit} variant="primary">
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
