import '../../../css/drive-browser-entry.css';
import { DriveBrowserPanel } from '../features/drive-browser/drive-browser-panel';

window.DocSyncWPDriveBrowserBundle = {
  DriveBrowserPanel
};

window.dispatchEvent(new CustomEvent('docsync-wp-drive-browser-ready'));
