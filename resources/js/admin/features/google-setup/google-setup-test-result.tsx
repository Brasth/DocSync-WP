import { createElement } from '@wordpress/element';

import type { SetupCheck } from './google-setup-utils';

type Props = {
  checks: SetupCheck[];
};

export const GoogleSetupTestResult = ({ checks }: Props): JSX.Element => {
  const isComplete = checks.every((check) => check.complete);

  return (
    <div className={`docsync-wp-setup-test ${isComplete ? 'is-complete' : 'is-warning'}`} role="status">
      <strong>{isComplete ? 'Setup ready for Google connect.' : 'Setup still needs attention.'}</strong>
      <ul>
        {checks.map((check) => (
          <li className={check.complete ? 'is-complete' : 'is-missing'} key={check.id}>
            <span>{check.complete ? 'Complete' : 'Missing'}</span>
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
