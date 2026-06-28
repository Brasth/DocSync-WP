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
  createSyncedDraftUrl: string;
  hasCredentialChanges: boolean;
  settings: SettingsResponse;
  onConnect: () => Promise<void>;
  onSaveCredentials: () => Promise<void>;
};

type ChecklistArgs = {
  account: GoogleAccount;
  canCreateDraft: boolean;
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
  busy,
  canSaveCredentials,
  createSyncedDraftUrl,
  hasCredentialChanges,
  settings,
  onConnect,
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

  return {
    title: __('Create first synced draft', 'brasth-document-sync-for-google-docs'),
    description: __('Open the Posts list, choose Add Sync Doc, select a Google Doc, and create the first synced draft.', 'brasth-document-sync-for-google-docs'),
    label: __('Create synced draft', 'brasth-document-sync-for-google-docs'),
    href: createSyncedDraftUrl
  };
};

export const buildGoogleSetupChecklistItems = ({
  account,
  canCreateDraft,
  credentialStepState,
  firstSyncStepState,
  settings
}: ChecklistArgs): GoogleSetupChecklistItem[] => [
  {
    id: 'credentials',
    label: __('OAuth credentials', 'brasth-document-sync-for-google-docs'),
    description: settings.hasRequiredSettings
      ? __('Client ID and secret saved.', 'brasth-document-sync-for-google-docs')
      : __('Save a Google OAuth web client.', 'brasth-document-sync-for-google-docs'),
    state: credentialStepState
  },
  {
    id: 'google-account',
    label: account.connected && !account.hasRequiredScope
      ? __('Reconnect Google', 'brasth-document-sync-for-google-docs')
      : __('Connect Google', 'brasth-document-sync-for-google-docs'),
    description: account.connected && account.hasRequiredScope
      ? __('Drive read-only scope granted.', 'brasth-document-sync-for-google-docs')
      : __('Authorize this WordPress user.', 'brasth-document-sync-for-google-docs'),
    state: account.connected && account.hasRequiredScope ? 'complete' : 'needs-action'
  },
  {
    id: 'first-draft',
    label: __('First synced draft', 'brasth-document-sync-for-google-docs'),
    description: canCreateDraft
      ? __('Ready from the Posts list.', 'brasth-document-sync-for-google-docs')
      : __('Available after Google is connected.', 'brasth-document-sync-for-google-docs'),
    state: firstSyncStepState
  }
];
