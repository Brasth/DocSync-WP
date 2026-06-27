import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount } from '../../api';

type Props = {
  account: GoogleAccount;
  canCreateDraft: boolean;
  createSyncedDraftUrl: string;
  stepNumber: number;
};

export const GoogleSetupFirstSyncStep = ({
  account,
  canCreateDraft,
  createSyncedDraftUrl,
  stepNumber
}: Props): JSX.Element => {
  const needsReconnect = account.connected && !account.hasRequiredScope;
  const description = canCreateDraft
    ? __('Open the Posts list, choose Add Sync Doc, select a Google Doc, and Brasth Document Sync will create the first synced draft.', 'brasth-document-sync-for-google-docs')
    : needsReconnect
      ? __('Reconnect Google with Drive read-only access before selecting Docs.', 'brasth-document-sync-for-google-docs')
      : __('After settings are saved, connect Google in the account panel on this page. Then create a synced draft from the Posts list.', 'brasth-document-sync-for-google-docs');

  return (
    <li>
      <div className="docsync-wp-step-heading">
        <span>{stepNumber}</span>
        <div>
          <h3>{__('Connect Google and create a synced draft', 'brasth-document-sync-for-google-docs')}</h3>
          <p>{description}</p>
        </div>
      </div>
      <div className="docsync-wp-step-actions">
        {canCreateDraft ? (
          <a className="button button-primary" href={createSyncedDraftUrl}>
            {__('Create synced draft', 'brasth-document-sync-for-google-docs')}
          </a>
        ) : null}
      </div>
    </li>
  );
};
