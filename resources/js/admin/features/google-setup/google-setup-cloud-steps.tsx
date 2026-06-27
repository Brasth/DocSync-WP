import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { googleCloudLinks } from './google-setup-utils';
import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  redirectUri: string;
  copyMessage: string;
  onCopyValue: (value: string, label: string) => void;
};

export const GoogleSetupCloudSteps = ({ redirectUri, copyMessage, onCopyValue }: Props): JSX.Element => {
  return (
    <>
      <li>
        <div className="docsync-wp-step-heading">
          <span>1</span>
          <div>
            <h3>{__('Create the Google Cloud pieces', 'brasth-document-sync-for-google-docs')}</h3>
            <p>{__('Use one Google Cloud project for the Drive API, Docs API, OAuth consent screen, and OAuth web credentials.', 'brasth-document-sync-for-google-docs')}</p>
          </div>
        </div>
        <ul className="docsync-wp-step-notes">
          <li>{__('Enable both Google Drive API and Google Docs API in that same project.', 'brasth-document-sync-for-google-docs')}</li>
          <li>{__('If the OAuth app is in Google test mode, add each WordPress user as a test user before they connect.', 'brasth-document-sync-for-google-docs')}</li>
          <li>{__('Brasth Document Sync requests drive.readonly so the connected account can browse and sync readable Docs.', 'brasth-document-sync-for-google-docs')}</li>
        </ul>
        <div className="docsync-wp-cloud-links">
          {googleCloudLinks.map((link) => (
            <a href={link.href} key={link.href} rel="noreferrer" target="_blank">
              {link.label}
            </a>
          ))}
        </div>
      </li>

      <li>
        <div className="docsync-wp-step-heading">
          <span>2</span>
          <div>
            <h3>{__('Add the exact redirect URI', 'brasth-document-sync-for-google-docs')}</h3>
            <p>{__('Paste this callback into Authorized redirect URIs for the Google OAuth web client. It must match exactly, including protocol, domain, path, and trailing slash state.', 'brasth-document-sync-for-google-docs')}</p>
          </div>
        </div>
        <label className="docsync-wp-copy-field">
          <span>{__('Authorized redirect URI in the OAuth client', 'brasth-document-sync-for-google-docs')}</span>
          <div className="docsync-wp-copy-row">
            <input className="regular-text code" readOnly type="text" value={redirectUri} />
            <AdminButton onClick={() => onCopyValue(redirectUri, __('Redirect URI', 'brasth-document-sync-for-google-docs'))}>
              {__('Copy', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          </div>
        </label>
        {copyMessage ? <p className="description">{copyMessage}</p> : null}
      </li>
    </>
  );
};
