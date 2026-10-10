import { Fragment, createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { buildSetupJourneyRail } from './setup-wizard-steps';
import type { SetupJourneyRailState, SetupJourneyStepId } from './setup-journey-state';

type Props = {
  onEditCredentials?: () => void;
  accountEmail?: string;
  onDisconnect?: () => void;
  rail: Record<SetupJourneyStepId, SetupJourneyRailState>;
};

export const GoogleSetupProgressRail = ({ rail, onEditCredentials, accountEmail, onDisconnect }: Props): JSX.Element => {
  const steps = buildSetupJourneyRail(rail);

  return (
    <nav aria-label={__('Setup progress', 'brasth-document-sync-for-google-docs')} className="docsync-wp-setup-rail">
      <h2>{__('Connect Google', 'brasth-document-sync-for-google-docs')}</h2>
      <ol className="docsync-wp-setup-journey-steps">
        {steps.map((step, index) => (
          <li
            aria-current={step.state === 'current' || step.state === 'attention' ? 'step' : undefined}
            className={`is-${step.state}`}
            key={step.id}
          >
            <span className="docsync-wp-setup-journey-steps__index">{step.state === 'complete' ? '✓' : index + 1}</span>
            <span className="docsync-wp-setup-journey-steps__label">{step.label}</span>
            <span className="docsync-wp-setup-journey-steps__state">{step.stateLabel}</span>
            <small>{step.id === 'credentials' && step.state === 'complete' ? <>{__('Saved', 'brasth-document-sync-for-google-docs')} · <button type="button" onClick={onEditCredentials}>{__('change', 'brasth-document-sync-for-google-docs')}</button></> : step.id === 'account' && step.state === 'complete' && accountEmail ? <>{accountEmail}{onDisconnect ? <> · <button type="button" onClick={onDisconnect}>{__('disconnect', 'brasth-document-sync-for-google-docs')}</button></> : null}</> : step.description}</small>
          </li>
        ))}
      </ol>
      <p className="docsync-wp-setup-rail__help">{rail.credentials === 'current' ? __('Only administrators see this step. Takes about 5 minutes.', 'brasth-document-sync-for-google-docs') : rail.source === 'current' ? __('You can skip this and come back from the Sources tab any time.', 'brasth-document-sync-for-google-docs') : __('Editors connect their own Google account before importing their first Doc.', 'brasth-document-sync-for-google-docs')}</p>
    </nav>
  );
};
