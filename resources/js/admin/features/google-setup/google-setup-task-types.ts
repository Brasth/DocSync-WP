import type { SetupStepState } from './setup-step-state';

export type GoogleSetupActiveTask = 'credentials' | 'connect' | 'reconnect' | 'draft';

export type SetupSourceIntent = 'folder' | 'document';

export type GoogleSetupNextActionConfig = {
  description: string;
  disabled?: boolean;
  href?: string;
  label: string;
  onClick?: () => Promise<void>;
  secondaryHref?: string;
  secondaryLabel?: string;
  onSecondaryClick?: () => void;
  title: string;
};

export type GoogleSetupChecklistItem = {
  description: string;
  id: GoogleSetupActiveTask | 'google-account' | 'first-draft';
  label: string;
  state: SetupStepState;
};
