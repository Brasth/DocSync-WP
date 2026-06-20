import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SetupCheck } from './google-setup-utils';

type Props = {
  checks: SetupCheck[];
};

export const GoogleSetupTestResult = ({ checks }: Props): JSX.Element => {
  const isComplete = checks.every((check) => check.complete);

  return (
    <div className={`docsync-wp-setup-test ${isComplete ? 'is-complete' : 'is-warning'}`} role="status">
      <strong>
        {isComplete
          ? __('Setup ready for Google connect.', 'brasth-document-sync-for-google-docs')
          : __('Setup still needs attention.', 'brasth-document-sync-for-google-docs')}
      </strong>
      <ul>
        {checks.map((check) => (
          <li className={check.complete ? 'is-complete' : 'is-missing'} key={check.id}>
            <span>{check.complete ? __('Complete', 'brasth-document-sync-for-google-docs') : __('Missing', 'brasth-document-sync-for-google-docs')}</span>
            <div>
              <strong>{check.label}</strong>
              <p>{check.description}</p>
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
};
