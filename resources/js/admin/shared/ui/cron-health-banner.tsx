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
      <strong>{__('Scheduled sync may not run on this site', 'brasth-document-sync-for-google-docs')}</strong>
      <p>
        {__('Low-traffic WordPress or DISABLE_WP_CRON needs a real server cron. Brasth Document Sync relies on WP-Cron ticks to finish background sync.', 'brasth-document-sync-for-google-docs')}
      </p>
      <p>
        <a href="https://docsyncwp.com/user-guide/" rel="noreferrer" target="_blank">
          {__('How to fix', 'brasth-document-sync-for-google-docs')}
        </a>
      </p>
    </aside>
  );
};
