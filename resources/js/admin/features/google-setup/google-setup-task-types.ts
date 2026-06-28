import type { SetupStepState } from './setup-step-state';

export type GoogleSetupActiveTask = 'credentials' | 'connect' | 'reconnect' | 'draft';

export type GoogleSetupNextActionConfig = {
  description: string;
  disabled?: boolean;
  href?: string;
  label: string;
  onClick?: () => Promise<void>;
  title: string;
};

export type GoogleSetupChecklistItem = {
  description: string;
  id: GoogleSetupActiveTask | 'google-account' | 'first-draft';
  label: string;
  state: SetupStepState;
};
