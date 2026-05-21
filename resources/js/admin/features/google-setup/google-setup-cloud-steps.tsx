import { createElement, Fragment } from '@wordpress/element';

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
            <h3>Create the Google Cloud pieces</h3>
            <p>Use one Google Cloud project for Drive API, OAuth consent, and OAuth web credentials.</p>
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
            <h3>Add OAuth URLs</h3>
            <p>Paste and save this callback in the Google OAuth web client used by DocSync WP.</p>
          </div>
        </div>
        <label className="docsync-wp-copy-field">
          <span>Authorized redirect URI in the OAuth client</span>
          <div className="docsync-wp-copy-row">
            <input className="regular-text code" readOnly type="text" value={redirectUri} />
            <AdminButton onClick={() => onCopyValue(redirectUri, 'Redirect URI')}>
              Copy
            </AdminButton>
          </div>
        </label>
        {copyMessage ? <p className="description">{copyMessage}</p> : null}
      </li>
    </>
  );
};
