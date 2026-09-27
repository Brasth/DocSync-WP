import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { SetupStepStateBadge } from './setup-step-state';
import { activeWizardStepId, type SetupWizardStep } from './setup-wizard-steps';
import type { GoogleSetupActiveTask } from './google-setup-task-types';

type Props = {
  activeTask: GoogleSetupActiveTask;
  activated: boolean;
  completedSteps: number;
  wizardSteps: SetupWizardStep[];
};

export const GoogleSetupProgressRail = ({
  activeTask,
  activated,
  completedSteps,
  wizardSteps
}: Props): JSX.Element => {
  const currentStepId = activeWizardStepId(activeTask, activated);
  const setupProgress = Math.round((completedSteps / wizardSteps.length) * 100);

  return (
    <aside className="docsync-wp-setup-rail" aria-label={__('Setup progress', 'brasth-document-sync-for-google-docs')}>
      <p className="docsync-wp-kicker">{__('Setup', 'brasth-document-sync-for-google-docs')}</p>
      <strong>
        {sprintf(
          /* translators: 1: completed step count, 2: total step count. */
          __('%1$d of %2$d steps complete', 'brasth-document-sync-for-google-docs'),
          completedSteps,
          wizardSteps.length
        )}
      </strong>
      <div className="docsync-wp-setup-progress" aria-hidden="true">
        <span style={{ width: `${setupProgress}%` }} />
      </div>
      <ol className="docsync-wp-setup-wizard-steps">
        {wizardSteps.map((item) => (
          <li className={item.id === currentStepId ? 'is-active' : item.state === 'complete' ? 'is-complete' : ''} key={item.id}>
            <div>
              <span>{item.label}</span>
              <small>{item.description}</small>
            </div>
            <SetupStepStateBadge state={item.state} />
          </li>
        ))}
      </ol>
    </aside>
  );
};
