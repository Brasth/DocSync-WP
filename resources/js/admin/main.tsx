import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../css/admin.css';
import { App } from './App';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  createRoot(rootElement).render(
    <Fragment>
      <App />
    </Fragment>
  );
}
