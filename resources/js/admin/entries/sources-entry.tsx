import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../../css/sources-entry.css';
import { SourcesApp } from '../app/sources-app';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  createRoot(rootElement).render(
    <Fragment>
      <SourcesApp />
    </Fragment>
  );
}
