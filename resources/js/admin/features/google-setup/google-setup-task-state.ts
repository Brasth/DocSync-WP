import { __ } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse } from '../../api';
import type { SetupStepState } from './setup-step-state';
import type {
  GoogleSetupActiveTask,
  GoogleSetupChecklistItem,
  GoogleSetupNextActionConfig
} from './google-setup-task-types';

type NextActionArgs = {
  account: GoogleAccount;
  busy: boolean;
  canSaveCredentials: boolean;
  canCreateSource: boolean;
  activated: boolean;
  hasCredentialChanges: boolean;
  settings: SettingsResponse;
  onConnect: () => Promise<void>;
  onCreateSource: () => void;
  onSaveCredentials: () => Promise<void>;
};

type ChecklistArgs = {
  account: GoogleAccount;
  canCreateDraft: boolean;
  activated: boolean;
  credentialStepState: SetupStepState;
  firstSyncStepState: SetupStepState;
  settings: SettingsResponse;
};

export const setupCredentialStepState = (settings: SettingsResponse, hasCredentialChanges: boolean): SetupStepState => {
  return settings.hasRequiredSettings && !hasCredentialChanges ? 'complete' : 'needs-action';
};

export const setupFirstSyncStepState = (canCreateDraft: boolean): SetupStepState => {
  return canCreateDraft ? 'ready' : 'needs-action';
};

export const activeGoogleSetupTask = (
  settings: SettingsResponse,
  account: GoogleAccount,
  hasCredentialChanges: boolean
): GoogleSetupActiveTask => {
  if (!settings.hasRequiredSettings || hasCredentialChanges) {
    return 'credentials';
  }

  if (!account.connected) {
    return 'connect';
  }

  if (!account.hasRequiredScope) {
    return 'reconnect';
  }

  return 'draft';
};

export const buildGoogleSetupNextAction = ({
  account,
  activated,
  busy,
  canSaveCredentials,
  canCreateSource,
  hasCredentialChanges,
  settings,
  onConnect,
  onCreateSource,
  onSaveCredentials
}: NextActionArgs): GoogleSetupNextActionConfig => {
  if (!settings.hasRequiredSettings || hasCredentialChanges) {
    return {
      title: __('Save OAuth credentials', 'brasth-document-sync-for-google-docs'),
      description: __('Save the Google OAuth web client ID and secret before connecting Google.', 'brasth-document-sync-for-google-docs'),
      label: __('Save OAuth credentials', 'brasth-document-sync-for-google-docs'),
      disabled: busy || !canSaveCredentials,
      onClick: onSaveCredentials
    };
  }

  if (!account.connected) {
    return {
      title: __('Connect Google', 'brasth-document-sync-for-google-docs'),
      description: __('Authorize this WordPress user to browse and sync readable Google Docs.', 'brasth-document-sync-for-google-docs'),
      label: __('Connect Google', 'brasth-document-sync-for-google-docs'),
      disabled: busy,
      onClick: onConnect
    };
  }

  if (!account.hasRequiredScope) {
    return {
      title: __('Reconnect Google', 'brasth-document-sync-for-google-docs'),
      description: __('Reconnect to grant Drive read-only access required by the current browser and sync flow.', 'brasth-document-sync-for-google-docs'),
      label: __('Reconnect Google', 'brasth-document-sync-for-google-docs'),
      disabled: busy,
      onClick: onConnect
    };
  }

  if (activated) {
    return {
      title: __('Publishing workspace active', 'brasth-document-sync-for-google-docs'),
      description: __('At least one source has completed successfully. Use Sources for daily publishing work.', 'brasth-document-sync-for-google-docs'),
      label: __('View Sources', 'brasth-document-sync-for-google-docs'),
      href: 'admin.php?page=brasth-document-sync-for-google-docs-sources'
    };
  }

  return {
    title: __('Create first synced draft', 'brasth-document-sync-for-google-docs'),
    description: canCreateSource
      ? __('Choose an accessible Google Doc and create a WordPress draft without leaving Setup.', 'brasth-document-sync-for-google-docs')
      : __('No enabled WordPress target is available for this user. Adjust post-type permissions before creating a source.', 'brasth-document-sync-for-google-docs'),
    label: __('Choose source', 'brasth-document-sync-for-google-docs'),
    disabled: busy || !canCreateSource,
    onClick: async () => onCreateSource()
  };
};

export const buildGoogleSetupChecklistItems = ({
  account,
  activated,
  canCreateDraft,
  credentialStepState,
  firstSyncStepState,
  settings
}: ChecklistArgs): GoogleSetupChecklistItem[] => [
  {
    id: 'credentials',
    label: __('Site connection', 'brasth-document-sync-for-google-docs'),
    description: settings.hasRequiredSettings
      ? __('Administrator responsibility complete: OAuth web client saved.', 'brasth-document-sync-for-google-docs')
      : __('Administrator responsibility: save the site OAuth web client.', 'brasth-document-sync-for-google-docs'),
    state: credentialStepState
  },
  {
    id: 'google-account',
    label: account.connected && !account.hasRequiredScope
      ? __('Reconnect Google', 'brasth-document-sync-for-google-docs')
      : __('Your Google account', 'brasth-document-sync-for-google-docs'),
    description: account.connected && account.hasRequiredScope
      ? __('Your responsibility complete: Drive read-only access granted.', 'brasth-document-sync-for-google-docs')
      : __('Your responsibility: authorize this WordPress user.', 'brasth-document-sync-for-google-docs'),
    state: account.connected && account.hasRequiredScope ? 'complete' : 'needs-action'
  },
  {
    id: 'first-draft',
    label: __('First publishing source', 'brasth-document-sync-for-google-docs'),
    description: activated
      ? __('Publishing responsibility complete: a source synced successfully.', 'brasth-document-sync-for-google-docs')
      : canCreateDraft
      ? __('Publishing responsibility: choose a Google Doc.', 'brasth-document-sync-for-google-docs')
      : __('Publishing responsibility unlocks after Google is connected.', 'brasth-document-sync-for-google-docs'),
    state: activated ? 'complete' : firstSyncStepState
  }
];
