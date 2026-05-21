import { createElement, useState } from '@wordpress/element';

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
  const [notice, setNotice] = useState<ImportNotice | null>(null);

  const importFile = async (file: File) => {
    if (!file.name.toLowerCase().endsWith('.json') && file.type !== 'application/json') {
      throw new Error('Choose the downloaded Google OAuth client JSON file.');
    }

    if (file.size > 1024 * 1024) {
      throw new Error('OAuth JSON file is unexpectedly large.');
    }

    return parseOAuthClientJson(await file.text());
  };

  return (
    <div className="docsync-wp-oauth-import">
      <div>
        <strong>Import OAuth JSON</strong>
        <p>Choose the Web application client JSON downloaded from Google Cloud. The file stays in this browser and only fills the fields below.</p>
      </div>
      <label className="docsync-wp-oauth-import__file">
        <span>OAuth client JSON file</span>
        <input
          accept="application/json,.json"
          disabled={busy}
          onChange={(event) => {
            const file = event.currentTarget.files?.[0] ?? null;
            event.currentTarget.value = '';

            if (!file) {
              return;
            }

            importFile(file)
              .then((credentials) => {
                onImported(credentials);
                setNotice({
                  type: credentials.redirectUris.includes(redirectUri) ? 'success' : 'warning',
                  message: credentials.redirectUris.includes(redirectUri)
                    ? 'OAuth client ID and secret imported.'
                    : 'OAuth credentials imported. Add the redirect URI from step 2 to this Google OAuth client before connecting.'
                });
              })
              .catch((caught) => {
                setNotice({
                  type: 'error',
                  message: caught instanceof Error ? caught.message : 'Could not import this OAuth JSON file.'
                });
              });
          }}
          type="file"
        />
      </label>
      {notice ? <p className={`docsync-wp-oauth-import__notice is-${notice.type}`}>{notice.message}</p> : null}
    </div>
  );
};
