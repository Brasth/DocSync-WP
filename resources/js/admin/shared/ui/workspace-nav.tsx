import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { getAdminConfig } from '../../config';

export type WorkspaceScreen = 'sources' | 'folders' | 'activity' | 'setup';

const screens: { id: WorkspaceScreen; page: string; label: () => string; adminOnly?: boolean }[] = [
  { id: 'sources', page: 'brasth-document-sync-for-google-docs-sources', label: () => __('Sources', 'brasth-document-sync-for-google-docs') },
  { id: 'folders', page: 'brasth-document-sync-for-google-docs-folders', label: () => __('Drive Folders', 'brasth-document-sync-for-google-docs') },
  { id: 'activity', page: 'brasth-document-sync-for-google-docs-logs', label: () => __('Activity', 'brasth-document-sync-for-google-docs') },
  { id: 'setup', page: 'brasth-document-sync-for-google-docs', label: () => __('Setup', 'brasth-document-sync-for-google-docs'), adminOnly: true }
];

/** Tab-style links between plugin screens. Plain links: each screen stays its own bundle and URL. */
export const WorkspaceNav = ({ current }: { current: WorkspaceScreen }): JSX.Element => {
  const config = getAdminConfig();

  return (
    <nav aria-label={__('Brasth Document Sync sections', 'brasth-document-sync-for-google-docs')} className="docsync-wp-workspace-nav">
      <ul>
        {screens
          .filter((screen) => !screen.adminOnly || config.canManageSettings)
          .map((screen) => (
            <li key={screen.id}>
              <a
                aria-current={screen.id === current ? 'page' : undefined}
                className={screen.id === current ? 'is-current' : undefined}
                href={`admin.php?page=${screen.page}`}
              >
                {screen.label()}
              </a>
            </li>
          ))}
      </ul>
    </nav>
  );
};
