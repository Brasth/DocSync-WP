import { __ } from '@wordpress/i18n';

import type { SetupJourneyRailState, SetupJourneyStepId } from './setup-journey-state';

const textDomain = 'brasth-document-sync-for-google-docs';

export type SetupJourneyRailItem = {
  id: SetupJourneyStepId;
  label: string;
  description: string;
  state: SetupJourneyRailState;
  stateLabel: string;
};

const railStateLabel = (state: SetupJourneyRailState): string => {
  switch (state) {
    case 'complete':
      return __('Complete', textDomain);
    case 'current':
      return __('Current', textDomain);
    case 'attention':
      return __('Needs attention', textDomain);
    case 'upcoming':
    default:
      return __('Not yet', textDomain);
  }
};

export const buildSetupJourneyRail = (
  rail: Record<SetupJourneyStepId, SetupJourneyRailState>
): SetupJourneyRailItem[] => [
  {
    id: 'credentials',
    label: __('Google OAuth client', textDomain),
    description: __('Paste the client ID and secret from Google Cloud.', textDomain),
    state: rail.credentials,
    stateLabel: railStateLabel(rail.credentials)
  },
  {
    id: 'account',
    label: __('Your Google account', textDomain),
    description: __('Each editor connects their own.', textDomain),
    state: rail.account,
    stateLabel: railStateLabel(rail.account)
  },
  {
    id: 'source',
    label: __('First Doc', textDomain),
    description: __('Pick a Doc or watch a folder.', textDomain),
    state: rail.source,
    stateLabel: railStateLabel(rail.source)
  }
];
