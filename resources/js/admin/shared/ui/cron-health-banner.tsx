import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { WorkspaceCronHealth } from '../../api';

type Props = {
  health?: WorkspaceCronHealth;
};

export const CronHealthBanner = ({ health }: Props): JSX.Element | null => {
  if (!health?.stalled) {
    return null;
  }

  return (
    <aside className="docsync-wp-cron-health" role="status">
      <strong>{__('Scheduled sync may be stalled', 'brasth-document-sync-for-google-docs')}</strong>
      <p>
        {__('Brasth Document Sync uses WP-Cron, which runs when this site receives traffic. Low-traffic sites or sites with DISABLE_WP_CRON should use a real server cron hitting wp-cron.php.', 'brasth-document-sync-for-google-docs')}
      </p>
      <p>
        <a href="https://docsyncwp.com/user-guide/" rel="noreferrer" target="_blank">
          {__('Read the server-cron guidance', 'brasth-document-sync-for-google-docs')}
        </a>
      </p>
    </aside>
  );
};
