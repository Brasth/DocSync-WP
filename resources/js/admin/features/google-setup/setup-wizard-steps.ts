import { __ } from '@wordpress/i18n';

import type { GoogleAccount, SettingsResponse } from '../../api';
import type { GoogleSetupActiveTask } from './google-setup-task-types';
import type { SetupStepState } from './setup-step-state';

export type SetupWizardStepId = 'site-app' | 'connect-google' | 'pick-source' | 'first-sync';

export type SetupWizardStep = {
  id: SetupWizardStepId;
  label: string;
  description: string;
  state: SetupStepState;
};

type Args = {
  account: GoogleAccount;
  activated: boolean;
  canCreateDraft: boolean;
  settings: SettingsResponse;
  credentialStepState: SetupStepState;
};

export const buildSetupWizardSteps = ({
  account,
  activated,
  canCreateDraft,
  settings,
  credentialStepState
}: Args): SetupWizardStep[] => {
  const googleComplete = account.connected && account.hasRequiredScope;

  let pickSourceState: SetupStepState = 'needs-action';

  if (activated) {
    pickSourceState = 'complete';
  } else if (googleComplete && canCreateDraft) {
    pickSourceState = 'ready';
  }

  const firstSyncState: SetupStepState = activated ? 'complete' : googleComplete && canCreateDraft ? 'ready' : 'needs-action';

  return [
    {
      id: 'site-app',
      label: __('Site Google app', 'brasth-document-sync-for-google-docs'),
      description: __('Create a Google Cloud OAuth client for this site. DocSync does not host Google for you.', 'brasth-document-sync-for-google-docs'),
      state: credentialStepState
    },
    {
      id: 'connect-google',
      label: __('Connect your Google', 'brasth-document-sync-for-google-docs'),
      description: __('Each WordPress user who syncs connects their own Google account.', 'brasth-document-sync-for-google-docs'),
      state: googleComplete ? 'complete' : settings.hasRequiredSettings ? 'needs-action' : 'manual'
    },
    {
      id: 'pick-source',
      label: __('Pick folder or Doc', 'brasth-document-sync-for-google-docs'),
      description: canCreateDraft
        ? __('Choose the client folder that holds the Docs, or link one Google Doc.', 'brasth-document-sync-for-google-docs')
        : __('Paste a Doc URL or pick from Drive after Google is connected.', 'brasth-document-sync-for-google-docs'),
      state: pickSourceState
    },
    {
      id: 'first-sync',
      label: __('First sync', 'brasth-document-sync-for-google-docs'),
      description: activated
        ? __('At least one Doc or folder watch completed successfully.', 'brasth-document-sync-for-google-docs')
        : __('Run the first sync to finish activation.', 'brasth-document-sync-for-google-docs'),
      state: firstSyncState
    }
  ];
};

export const activeWizardStepId = (activeTask: GoogleSetupActiveTask, activated: boolean): SetupWizardStepId => {
  if (activated) {
    return 'first-sync';
  }

  switch (activeTask) {
    case 'credentials':
      return 'site-app';
    case 'connect':
    case 'reconnect':
      return 'connect-google';
    case 'draft':
    default:
      return 'pick-source';
  }
};
