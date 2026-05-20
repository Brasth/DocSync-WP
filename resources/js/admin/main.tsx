import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../css/admin.css';
import { App } from './App';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  const view = rootElement.dataset.view === 'sources' ? 'sources' : 'setup';

  createRoot(rootElement).render(
    <Fragment>
      <App view={view} />
    </Fragment>
  );
}
