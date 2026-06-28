import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { GoogleAccount } from '../../api';
import { SetupStepStateBadge, type SetupStepState } from './setup-step-state';

type Props = {
  account: GoogleAccount;
  canCreateDraft: boolean;
  createSyncedDraftUrl: string;
  initialOpen: boolean;
  stepNumber: number;
  stepState: SetupStepState;
};

export const GoogleSetupFirstSyncStep = ({
  account,
  canCreateDraft,
  createSyncedDraftUrl,
  initialOpen,
  stepNumber,
  stepState
}: Props): JSX.Element => {
  const [isOpen, setIsOpen] = useState(initialOpen);
  const needsReconnect = account.connected && !account.hasRequiredScope;
  const description = canCreateDraft
    ? __('Open the Posts list, choose Add Sync Doc, select a Google Doc, and Brasth Document Sync will create the first synced draft.', 'brasth-document-sync-for-google-docs')
    : needsReconnect
      ? __('Reconnect Google with Drive read-only access before selecting Docs.', 'brasth-document-sync-for-google-docs')
      : __('After settings are saved, connect Google from the next action on this page. Then create a synced draft from the Posts list.', 'brasth-document-sync-for-google-docs');

  return (
    <li>
      <details className="docsync-wp-setup-disclosure" onToggle={(event) => setIsOpen(event.currentTarget.open)} open={isOpen}>
        <summary className="docsync-wp-step-heading">
          <span className="docsync-wp-step-number">{stepNumber}</span>
          <div>
            <div className="docsync-wp-step-title-row">
              <h3>{__('Connect and sync first draft', 'brasth-document-sync-for-google-docs')}</h3>
              <SetupStepStateBadge state={stepState} />
            </div>
            <p>
              {canCreateDraft
                ? __('Ready from the Posts list.', 'brasth-document-sync-for-google-docs')
                : needsReconnect
                  ? __('Reconnect Google before selecting Docs.', 'brasth-document-sync-for-google-docs')
                  : __('Connect Google after credentials are saved.', 'brasth-document-sync-for-google-docs')}
            </p>
          </div>
        </summary>
        <div className="docsync-wp-step-body">
          <p>{description}</p>
          <div className="docsync-wp-step-actions">
            {canCreateDraft ? (
              <a className="button button-secondary" href={createSyncedDraftUrl}>
                {__('Create synced draft', 'brasth-document-sync-for-google-docs')}
              </a>
            ) : null}
          </div>
        </div>
      </details>
    </li>
  );
};
