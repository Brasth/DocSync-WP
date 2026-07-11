import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { ConfirmDialog } from '../../shared/ui/confirm-dialog';
import { GoogleSetupTestResult } from './google-setup-test-result';
import { OAuthClientJsonImport } from './oauth-client-json-import';
import type { OAuthClientJsonCredentials } from './oauth-client-json';
import { googleCloudLinks, type SetupCheck } from './google-setup-utils';
import type { GoogleSetupActiveTask, GoogleSetupNextActionConfig } from './google-setup-task-types';

type Props = {
  account: GoogleAccount;
  activeTask: GoogleSetupActiveTask;
  busy: boolean;
  clientId: string;
  clientSecret: string;
  copyMessage: string;
  hasClientSecret: boolean;
  hasSavedOAuthConfiguration: boolean;
  hasUnsavedChanges: boolean;
  nextAction: GoogleSetupNextActionConfig;
  redirectUri: string;
  testChecks: SetupCheck[] | null;
  onClearOAuthConfiguration: () => Promise<boolean>;
  onClientIdChange: (clientId: string) => void;
  onClientSecretChange: (clientSecret: string) => void;
  onCopyValue: (value: string, label: string) => void;
  onImported: (credentials: OAuthClientJsonCredentials) => void;
  onTestSetup: () => void;
};

const renderPrimaryAction = (nextAction: GoogleSetupNextActionConfig): JSX.Element => {
  if (nextAction.href) {
    return (
      <a
        aria-disabled={nextAction.disabled ? 'true' : undefined}
        className="button button-primary docsync-wp-button docsync-wp-button--default"
        href={nextAction.href}
      >
        {nextAction.label}
      </a>
    );
  }

  return (
    <AdminButton disabled={Boolean(nextAction.disabled)} onClick={nextAction.onClick} variant="primary">
      {nextAction.label}
    </AdminButton>
  );
};

export const GoogleSetupActiveTaskPanel = ({
  account,
  activeTask,
  busy,
  clientId,
  clientSecret,
  copyMessage,
  hasClientSecret,
  hasSavedOAuthConfiguration,
  hasUnsavedChanges,
  nextAction,
  redirectUri,
  testChecks,
  onClearOAuthConfiguration,
  onClientIdChange,
  onClientSecretChange,
  onCopyValue,
  onImported,
  onTestSetup
}: Props): JSX.Element => {
  const [clearOAuthOpen, setClearOAuthOpen] = useState(false);

  const clearOAuthConfiguration = async () => {
    const cleared = await onClearOAuthConfiguration();

    if (cleared) {
      setClearOAuthOpen(false);
    }
  };

  const renderBody = (): JSX.Element => {
    if (activeTask === 'credentials') {
      return (
        <div className="docsync-wp-setup-task-stack">
          <label className="docsync-wp-copy-field">
            <span>{__('Authorized redirect URI in the OAuth client', 'brasth-document-sync-for-google-docs')}</span>
            <div className="docsync-wp-copy-row">
              <input className="regular-text code" readOnly type="text" value={redirectUri} />
              <AdminButton onClick={() => onCopyValue(redirectUri, __('Redirect URI', 'brasth-document-sync-for-google-docs'))} size="small">
                {__('Copy', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            </div>
          </label>
          {copyMessage ? <p className="description">{copyMessage}</p> : null}

          <details className="docsync-wp-setup-help-disclosure">
            <summary>{__('Need Google Cloud help?', 'brasth-document-sync-for-google-docs')}</summary>
            <div>
              <ul className="docsync-wp-step-notes">
                <li>{__('Enable both Google Drive API and Google Docs API in the same Google Cloud project.', 'brasth-document-sync-for-google-docs')}</li>
                <li>{__('Create an OAuth web application client and add the redirect URI shown above.', 'brasth-document-sync-for-google-docs')}</li>
                <li>{__('If the OAuth app is in Google test mode, add each WordPress user as a test user before they connect.', 'brasth-document-sync-for-google-docs')}</li>
              </ul>
              <div className="docsync-wp-cloud-links">
                {googleCloudLinks.map((link) => (
                  <a href={link.href} key={link.href} rel="noreferrer" target="_blank">
                    {link.label}
                  </a>
                ))}
              </div>
            </div>
          </details>

          <OAuthClientJsonImport busy={busy} onImported={onImported} redirectUri={redirectUri} />

          <div className="docsync-wp-settings-grid">
            <label>
              <span>{__('OAuth client ID', 'brasth-document-sync-for-google-docs')}</span>
              <input className="regular-text" onChange={(event) => onClientIdChange(event.currentTarget.value)} type="text" value={clientId} />
            </label>
            <label>
              <span>{__('OAuth client secret', 'brasth-document-sync-for-google-docs')}</span>
              <input
                className="regular-text"
                onChange={(event) => onClientSecretChange(event.currentTarget.value)}
                placeholder={hasClientSecret ? __('Saved. Enter a new secret to replace.', 'brasth-document-sync-for-google-docs') : ''}
                type="password"
                value={clientSecret}
              />
            </label>
          </div>
        </div>
      );
    }

    if (activeTask === 'connect') {
      return (
        <div className="docsync-wp-setup-task-message">
          <strong>{__('OAuth credentials are saved.', 'brasth-document-sync-for-google-docs')}</strong>
          <p>{__('Connect this WordPress user to Google before browsing or syncing readable Docs.', 'brasth-document-sync-for-google-docs')}</p>
        </div>
      );
    }

    if (activeTask === 'reconnect') {
      return (
        <div className="docsync-wp-setup-task-message">
          <strong>{account.googleAccountEmail || __('Google account connected', 'brasth-document-sync-for-google-docs')}</strong>
          <p>{__('Reconnect Google to grant Drive read-only access required by the current browser and sync flow.', 'brasth-document-sync-for-google-docs')}</p>
        </div>
      );
    }

    return (
      <div className="docsync-wp-setup-task-message">
        <strong>{__('Setup ready for source selection.', 'brasth-document-sync-for-google-docs')}</strong>
        <p>{__('Open the Posts list, choose Add Sync Doc, select a Google Doc, and create the first synced draft.', 'brasth-document-sync-for-google-docs')}</p>
      </div>
    );
  };

  return (
    <section className="docsync-wp-card docsync-wp-setup-task-panel" aria-labelledby="docsync-wp-active-setup-task">
      <div className="docsync-wp-card__header">
        <p className="docsync-wp-kicker">{__('Next action', 'brasth-document-sync-for-google-docs')}</p>
        <h2 id="docsync-wp-active-setup-task">{nextAction.title}</h2>
        <p>{nextAction.description}</p>
      </div>

      {renderBody()}

      <div className="docsync-wp-setup-task-actions">
        {renderPrimaryAction(nextAction)}
        <AdminButton disabled={busy} onClick={onTestSetup}>
          {__('Test setup', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        {hasUnsavedChanges ? <span>{__('Unsaved changes are not tested until saved.', 'brasth-document-sync-for-google-docs')}</span> : null}
      </div>

      {testChecks ? <GoogleSetupTestResult checks={testChecks} /> : null}

      {hasSavedOAuthConfiguration ? (
        <details className="docsync-wp-setup-advanced-disclosure">
          <summary>{__('Advanced', 'brasth-document-sync-for-google-docs')}</summary>
          <div className="docsync-wp-oauth-danger-zone">
            <div className="docsync-wp-oauth-danger-zone__content">
              <h3>{__('Clear saved OAuth configuration', 'brasth-document-sync-for-google-docs')}</h3>
              <p>
                {__(
                  'Removes the saved client credentials, disconnects all plugin users locally, and cancels queued syncs. Sources and synced content stay intact.',
                  'brasth-document-sync-for-google-docs'
                )}
              </p>
            </div>
            <AdminButton disabled={busy} onClick={() => setClearOAuthOpen(true)} variant="delete">
              {__('Clear configuration', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        </details>
      ) : null}

      <ConfirmDialog
        busy={busy}
        confirmLabel={__('Clear configuration', 'brasth-document-sync-for-google-docs')}
        description={__('This removes the saved OAuth client ID and secret, disconnects every plugin user locally, and cancels queued syncs. Linked sources, posts, revisions, media, and logs are retained.', 'brasth-document-sync-for-google-docs')}
        open={clearOAuthOpen}
        title={__('Clear saved OAuth configuration?', 'brasth-document-sync-for-google-docs')}
        variant="danger"
        onConfirm={clearOAuthConfiguration}
        onOpenChange={setClearOAuthOpen}
      />
    </section>
  );
};
