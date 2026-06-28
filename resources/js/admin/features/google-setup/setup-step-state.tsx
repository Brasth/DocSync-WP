import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export type SetupStepState = 'manual' | 'complete' | 'needs-action' | 'ready';

const stepStateLabel = (state: SetupStepState): string => {
  switch (state) {
    case 'complete':
      return __('Complete', 'brasth-document-sync-for-google-docs');
    case 'needs-action':
      return __('Needs action', 'brasth-document-sync-for-google-docs');
    case 'ready':
      return __('Ready', 'brasth-document-sync-for-google-docs');
    case 'manual':
    default:
      return __('Manual', 'brasth-document-sync-for-google-docs');
  }
};

export const SetupStepStateBadge = ({ state }: { state: SetupStepState }): JSX.Element => {
  return (
    <span className={`docsync-wp-step-state is-${state}`}>
      {stepStateLabel(state)}
    </span>
  );
};
