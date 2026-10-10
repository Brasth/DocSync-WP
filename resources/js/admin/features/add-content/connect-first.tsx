/**
 * Connect-first gate shown in place of the Google Docs picker when the current user has
 * no usable Google connection. Only the read-only scope is requested here; the token
 * stays on the server and the browser only follows Google's consent URL.
 */
import { createElement, createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { JourneyGoogleAccount } from '../../api/journey-types';
import { getAdminConfig } from '../../config';
import { Icon, InlineNotice } from './add-content-dialog';
import { currentReturnTo, isSourcesPage } from './use-add-content';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';

type Props = {
  account: JourneyGoogleAccount | null;
  connecting: boolean;
  error: string;
  onConnect: () => void;
};

export const ConnectFirst = ({ account, connecting, error, onConnect }: Props): JSX.Element => {
  const config = getAdminConfig();
  const siteReady = config.hasRequiredSettings;
  const outdated = Boolean(account?.connected && !account.hasRequiredScope);
  const returnHint = currentReturnTo() === 'setup'
    ? __('You\'ll come straight back to setup to pick a Doc.', TEXT_DOMAIN)
    : isSourcesPage()
      ? __('You\'ll come straight back here to pick a Doc.', TEXT_DOMAIN)
      : __('You\'ll come back to Sources to pick a Doc.', TEXT_DOMAIN);
  const cards = [
    { title: __('Read-only', TEXT_DOMAIN), text: __('We list and read Docs. Never edit or delete.', TEXT_DOMAIN) },
    { title: __('Only your Docs', TEXT_DOMAIN), text: __('Other editors connect their own accounts.', TEXT_DOMAIN) },
    { title: __('Disconnect any time', TEXT_DOMAIN), text: __('From Document Sync › Settings.', TEXT_DOMAIN) }
  ];

  return (
    <div className="dj-connect">
      <div className="dj-connect__intro">
        <span aria-hidden="true" className="dj-connect__icon"><Icon name="person" size={22} /></span>
        <div>
          <h3>
            {outdated
              ? __('Reconnect your Google account', TEXT_DOMAIN)
              : __('First, connect your Google account', TEXT_DOMAIN)}
          </h3>
          {siteReady ? (
            <p>
              {outdated
                ? __('Your Google connection is missing the Drive read access Document Sync needs. Reconnect to see the Docs you can open. Takes about 20 seconds.', TEXT_DOMAIN)
                : createInterpolateElement(
                  __('Document Sync is set up on this site, but it needs <strong>your</strong> Google account to see the Docs you can open. Takes about 20 seconds.', TEXT_DOMAIN),
                  { strong: <strong /> }
                )}
            </p>
          ) : (
            <p>{__('Document Sync is not connected to Google on this site yet. An administrator needs to finish setup before anyone can pick Docs.', TEXT_DOMAIN)}</p>
          )}
        </div>
      </div>

      <div className="dj-connect__cards">
        {cards.map((card) => (
          <div className="dj-connect__card" key={card.title}>
            <Icon name="check" size={12} />
            <div>
              <strong>{card.title}</strong>
              <span>{card.text}</span>
            </div>
          </div>
        ))}
      </div>

      {error ? <InlineNotice tone="error">{error}</InlineNotice> : null}

      <div className="dj-connect__action">
        <button
          className="dj-button dj-button--primary"
          disabled={connecting || !siteReady}
          onClick={onConnect}
          type="button"
        >
          {connecting ? __('Opening Google…', TEXT_DOMAIN) : __('Connect with Google', TEXT_DOMAIN)}
        </button>
        <span>{siteReady ? returnHint : __('Ask a site administrator to complete Document Sync setup.', TEXT_DOMAIN)}</span>
      </div>

      <details className="dj-connect__details">
        <summary>{__('Google says the app isn\'t verified?', TEXT_DOMAIN)}</summary>
        <p>{__('This site uses its own Google Cloud project. If Google shows an unverified-app screen, choose Advanced, then continue to the site name. Document Sync only asks to read your Drive files; it never edits or deletes them.', TEXT_DOMAIN)}</p>
      </details>
    </div>
  );
};
