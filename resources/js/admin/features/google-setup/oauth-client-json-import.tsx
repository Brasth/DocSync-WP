import { createElement, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';
import { parseOAuthClientJson, type OAuthClientJsonCredentials } from './oauth-client-json';

type Props = {
  busy: boolean;
  redirectUri: string;
  onImported: (credentials: OAuthClientJsonCredentials) => void;
};

type ImportNotice = {
  type: 'success' | 'warning' | 'error';
  message: string;
};

export const OAuthClientJsonImport = ({ busy, redirectUri, onImported }: Props): JSX.Element => {
  const inputRef = useRef<HTMLInputElement | null>(null);
  const [notice, setNotice] = useState<ImportNotice | null>(null);
  const [fileName, setFileName] = useState('');

  const importFile = async (file: File) => {
    if (!file.name.toLowerCase().endsWith('.json') && file.type !== 'application/json') {
      throw new Error(__('Choose the downloaded Google OAuth client JSON file.', 'brasth-document-sync-for-google-docs'));
    }

    if (file.size > 1024 * 1024) {
      throw new Error(__('OAuth JSON file is unexpectedly large.', 'brasth-document-sync-for-google-docs'));
    }

    return parseOAuthClientJson(await file.text());
  };

  return (
    <div className="docsync-wp-oauth-import">
      <div>
        <strong>{__('Import OAuth JSON', 'brasth-document-sync-for-google-docs')}</strong>
        <p>{__('Optional. Fill the credential fields from the Web application JSON downloaded from Google Cloud.', 'brasth-document-sync-for-google-docs')}</p>
      </div>
      <div className="docsync-wp-oauth-import__control">
        <input
          accept="application/json,.json"
          className="docsync-wp-oauth-import__input"
          disabled={busy}
          onChange={(event) => {
            const file = event.currentTarget.files?.[0] ?? null;
            event.currentTarget.value = '';

            if (!file) {
              return;
            }

            setFileName(file.name);
            importFile(file)
              .then((credentials) => {
                const redirectMatches = credentials.redirectUris.includes(redirectUri);
                const message = redirectMatches
                  ? __('OAuth client ID and secret imported.', 'brasth-document-sync-for-google-docs')
                  : __(
                    'OAuth credentials imported. Add the redirect URI from step 2 to this Google OAuth client before connecting.',
                    'brasth-document-sync-for-google-docs'
                  );

                onImported(credentials);
                setNotice({
                  type: redirectMatches ? 'success' : 'warning',
                  message
                });
              })
              .catch((caught) => {
                setNotice({
                  type: 'error',
                  message: caught instanceof Error ? caught.message : __('Could not import this OAuth JSON file.', 'brasth-document-sync-for-google-docs')
                });
              });
          }}
          ref={inputRef}
          type="file"
        />
        <AdminButton disabled={busy} onClick={() => inputRef.current?.click()}>
          {__('Choose JSON', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
        <span className="docsync-wp-oauth-import__filename">
          {fileName || __('No file selected', 'brasth-document-sync-for-google-docs')}
        </span>
      </div>
      {notice ? <p className={`docsync-wp-oauth-import__notice is-${notice.type}`}>{notice.message}</p> : null}
    </div>
  );
};
