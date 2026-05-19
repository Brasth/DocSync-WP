import { createElement, Fragment } from '@wordpress/element';

import { googleCloudLinks } from './google-setup-utils';

type Props = {
  redirectUri: string;
  copyMessage: string;
  onCopyRedirectUri: () => void;
};

export const GoogleSetupCloudSteps = ({ redirectUri, copyMessage, onCopyRedirectUri }: Props): JSX.Element => {
  return (
    <>
      <li>
        <div className="docsync-wp-step-heading">
          <span>1</span>
          <div>
            <h3>Create the Google Cloud pieces</h3>
            <p>Use one Google Cloud project for Drive, Picker, OAuth consent, and credentials.</p>
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
            <h3>Add the authorized redirect URI</h3>
            <p>Paste this exact URI into the Google OAuth web client.</p>
          </div>
        </div>
        <div className="docsync-wp-copy-row">
          <input className="regular-text code" readOnly type="text" value={redirectUri} />
          <button className="button" onClick={onCopyRedirectUri} type="button">
            Copy
          </button>
        </div>
        {copyMessage ? <p className="description">{copyMessage}</p> : null}
      </li>
    </>
  );
};
