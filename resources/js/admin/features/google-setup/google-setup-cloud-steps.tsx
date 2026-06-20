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
            <p>{__('Use one Google Cloud project for Drive API, Docs API, OAuth consent, and OAuth web credentials.', 'brasth-document-sync-for-google-docs')}</p>
          </div>
        </div>
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
            <h3>{__('Add OAuth URLs', 'brasth-document-sync-for-google-docs')}</h3>
            <p>{__('Paste and save this callback in the Google OAuth web client used by Brasth Document Sync.', 'brasth-document-sync-for-google-docs')}</p>
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
