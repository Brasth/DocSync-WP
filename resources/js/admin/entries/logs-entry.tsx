import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../../css/logs-entry.css';
import { SyncLogsView } from '../features/sync-logs/sync-logs-view';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  createRoot(rootElement).render(
    <Fragment>
      <SyncLogsView />
    </Fragment>
  );
}
