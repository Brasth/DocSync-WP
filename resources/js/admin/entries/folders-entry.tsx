import {
  createElement,
  Fragment,
  createRoot
} from '@wordpress/element';

import '../../../css/folders-entry.css';
import { FoldersApp } from '../app/folders-app';

const rootElement = document.getElementById('docsync-wp-admin-root');

if (rootElement) {
  createRoot(rootElement).render(
    <Fragment>
      <FoldersApp />
    </Fragment>
  );
}
