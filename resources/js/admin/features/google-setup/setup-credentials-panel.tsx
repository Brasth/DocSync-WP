import { createElement, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SettingsResponse, SettingsUpdate } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { OAuthClientJsonImport } from './oauth-client-json-import';

const domain = 'brasth-document-sync-for-google-docs';
type Props = {
  settings: SettingsResponse;
  busy: boolean;
  redirectUri: string;
  editing: boolean;
  onCancel: () => void;
  onDirtyChange: (dirty: boolean) => void;
  onSave: (update: SettingsUpdate) => Promise<boolean>;
};

export const SetupCredentialsPanel = ({ settings, busy, redirectUri, editing, onCancel, onDirtyChange, onSave }: Props): JSX.Element => {
  const [clientId, setClientId] = useState(settings.clientId);
  const [secret, setSecret] = useState('');
  const [copyMessage, setCopyMessage] = useState('');
  const callbackRef = useRef<HTMLInputElement>(null);
  const dirty = clientId !== settings.clientId || secret !== '';
  const valid = clientId.trim() !== '' && (secret.trim() !== '' || settings.hasClientSecret);

  useEffect(() => {
    setClientId(settings.clientId);
    setSecret('');
  }, [settings.clientId, settings.hasClientSecret]);
  useEffect(() => {
    onDirtyChange(dirty);
    return () => onDirtyChange(false);
  }, [dirty, onDirtyChange]);

  const copy = async () => {
    try {
      if (!navigator.clipboard) { throw new Error('Clipboard unavailable'); }
      await navigator.clipboard.writeText(redirectUri);
      setCopyMessage(__('Redirect URI copied.', domain));
    } catch {
      callbackRef.current?.focus();
      callbackRef.current?.select();
      setCopyMessage(__('Select and copy the redirect URI from the field.', domain));
    }
  };
  const submit = async () => {
    if (!valid || !dirty || busy) { return; }
    const saved = await onSave({ clientId: clientId.trim(), ...(secret.trim() ? { clientSecret: secret.trim() } : {}) });
    if (saved) { setSecret(''); onDirtyChange(false); }
  };

  return (
    <section aria-labelledby="docsync-wp-credentials-title" className="docsync-wp-setup-card">
      <header className="docsync-wp-setup-card__header">
        <h2 id="docsync-wp-credentials-title">{editing ? __('Change Google OAuth client', domain) : __('Paste your Google OAuth client', domain)}</h2>
        <p>{__('From a Web application client in Google Cloud with the Drive and Docs APIs enabled.', domain)}{' '}<a href="https://docsyncwp.com/user-guide/" target="_blank" rel="noreferrer">{__('Open the setup guide', domain)} ↗</a></p>
      </header>
      <form onSubmit={(event) => { event.preventDefault(); void submit(); }}>
        <div className="docsync-wp-setup-fields">
          <label htmlFor="docsync-wp-client-id">{__('Client ID', domain)}<input autoComplete="off" disabled={busy} id="docsync-wp-client-id" onChange={(event) => setClientId(event.currentTarget.value)} placeholder="1234…apps.googleusercontent.com" value={clientId} /></label>
          <label htmlFor="docsync-wp-client-secret">{__('Client secret', domain)}<input autoComplete="new-password" disabled={busy} id="docsync-wp-client-secret" onChange={(event) => setSecret(event.currentTarget.value)} placeholder={settings.hasClientSecret ? __('Secret saved — leave blank to keep it', domain) : 'GOCSPX-…'} type="password" value={secret} /></label>
        </div>
        <div className="docsync-wp-setup-callback">
          <label htmlFor="docsync-wp-callback">{__('Authorized redirect URI', domain)}</label>
          <div><input id="docsync-wp-callback" readOnly ref={callbackRef} value={redirectUri} /><AdminButton onClick={() => void copy()} type="button"><svg aria-hidden="true" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="1.6"><rect x="8" y="8" width="12" height="12" rx="1"/><path d="M15 8V4H4v11h4"/></svg>{__('Copy', domain)}</AdminButton></div>
          <p role="status">{copyMessage || __('Add this to Authorized redirect URIs in Google Cloud.', domain)}</p>
        </div>
        <div className="docsync-wp-setup-form-footer">
          <OAuthClientJsonImport compact busy={busy} onImported={(credentials) => { setClientId(credentials.clientId); setSecret(credentials.clientSecret); }} redirectUri={redirectUri} />
          <div className="docsync-wp-setup-actions">
            {editing ? <AdminButton disabled={busy} onClick={onCancel} type="button">{__('Cancel', domain)}</AdminButton> : null}
            <AdminButton disabled={!valid || !dirty || busy} type="submit" variant="primary">{busy ? __('Saving…', domain) : __('Save and continue', domain) + ' →'}</AdminButton>
          </div>
        </div>
      </form>
    </section>
  );
};
