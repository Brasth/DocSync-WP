import { createElement } from '@wordpress/element';
import type { ReactNode, Ref } from 'react';

import { GoogleSetupProgressRail } from './google-setup-progress-rail';
import type { SetupJourneyRailState, SetupJourneyStepId } from './setup-journey-state';

type Props = {
  children: ReactNode;
  onEditCredentials?: () => void;
  accountEmail?: string;
  onDisconnect?: () => void;
  rail: Record<SetupJourneyStepId, SetupJourneyRailState>;
  taskRef?: Ref<HTMLDivElement>;
};

export const SetupTaskLayout = ({ children, rail, taskRef, onEditCredentials, accountEmail, onDisconnect }: Props): JSX.Element => (
  <div className="docsync-wp-setup-journey">
    <GoogleSetupProgressRail rail={rail} onEditCredentials={onEditCredentials} accountEmail={accountEmail} onDisconnect={onDisconnect} />
    <div className="docsync-wp-setup-journey__task" ref={taskRef}>
      {children}
    </div>
  </div>
);
