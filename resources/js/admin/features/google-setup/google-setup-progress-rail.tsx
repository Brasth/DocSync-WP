import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { SetupStepStateBadge } from './setup-step-state';
import type { SetupCheck } from './google-setup-utils';
import type { GoogleSetupActiveTask, GoogleSetupChecklistItem } from './google-setup-task-types';

type Props = {
  activeTask: GoogleSetupActiveTask;
  checklistItems: GoogleSetupChecklistItem[];
  completedChecks: number;
  setupChecks: SetupCheck[];
  setupProgress: number;
};

const isItemActive = (item: GoogleSetupChecklistItem, activeTask: GoogleSetupActiveTask): boolean => {
  return item.id === activeTask
    || (item.id === 'google-account' && (activeTask === 'connect' || activeTask === 'reconnect'))
    || (item.id === 'first-draft' && activeTask === 'draft');
};

export const GoogleSetupProgressRail = ({
  activeTask,
  checklistItems,
  completedChecks,
  setupChecks,
  setupProgress
}: Props): JSX.Element => {
  return (
    <aside className="docsync-wp-setup-rail" aria-label={__('Setup progress', 'brasth-document-sync-for-google-docs')}>
      <p className="docsync-wp-kicker">{__('Setup checklist', 'brasth-document-sync-for-google-docs')}</p>
      <strong>
        {sprintf(
          /* translators: 1: completed setup check count, 2: total setup check count. */
          __('%1$d of %2$d checks complete', 'brasth-document-sync-for-google-docs'),
          completedChecks,
          setupChecks.length
        )}
      </strong>
      <div className="docsync-wp-setup-progress" aria-hidden="true">
        <span style={{ width: `${setupProgress}%` }} />
      </div>
      <ol>
        {checklistItems.map((item) => (
          <li className={isItemActive(item, activeTask) ? 'is-active' : ''} key={item.id}>
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
