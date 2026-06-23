import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../../css/setup-entry.css';
import { SetupApp } from '../app/setup-app';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  createRoot(rootElement).render(
    <Fragment>
      <SetupApp />
    </Fragment>
  );
}
