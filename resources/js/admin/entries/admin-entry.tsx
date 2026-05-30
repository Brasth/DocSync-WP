import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../../css/admin-entry.css';
import { AdminApp } from '../app/admin-app';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  const requestedView = rootElement.dataset.view ?? '';
  const view = requestedView === 'sources' || requestedView === 'logs' ? requestedView : 'setup';

  createRoot(rootElement).render(
    <Fragment>
      <AdminApp view={view} />
    </Fragment>
  );
}
