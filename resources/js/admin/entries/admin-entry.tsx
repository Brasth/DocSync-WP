import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../../css/admin-entry.css';
import { AdminApp } from '../app/admin-app';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  const view = rootElement.dataset.view === 'sources' ? 'sources' : 'setup';

  createRoot(rootElement).render(
    <Fragment>
      <AdminApp view={view} />
    </Fragment>
  );
}
