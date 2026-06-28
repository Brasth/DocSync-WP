import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { AdminButton } from '../../shared/ui/admin-button';

export type GoogleSetupNextActionConfig = {
  description: string;
  disabled?: boolean;
  href?: string;
  label: string;
  onClick?: () => Promise<void>;
  title: string;
};

type Props = {
  action: GoogleSetupNextActionConfig;
};

export const GoogleSetupNextAction = ({ action }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-next-action">
      <div>
        <p className="docsync-wp-kicker">{__('Next action', 'brasth-document-sync-for-google-docs')}</p>
        <h3>{action.title}</h3>
        <p>{action.description}</p>
      </div>
      {action.href ? (
        <a className="button button-primary" href={action.href} aria-disabled={action.disabled ? 'true' : undefined}>
          {action.label}
        </a>
      ) : (
        <AdminButton disabled={Boolean(action.disabled)} onClick={action.onClick} variant="primary">
          {action.label}
        </AdminButton>
      )}
    </div>
  );
};
