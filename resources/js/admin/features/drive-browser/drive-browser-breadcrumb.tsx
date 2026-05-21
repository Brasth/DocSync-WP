import { createElement } from '@wordpress/element';

import type { DriveBrowserBreadcrumb } from './drive-browser-utils';

type Props = {
  breadcrumbs: DriveBrowserBreadcrumb[];
  busy: boolean;
  driveId: string;
  folderId: string;
  loading: boolean;
  onOpen: (breadcrumb: DriveBrowserBreadcrumb, index: number) => Promise<void>;
};

export const DriveBrowserBreadcrumbNav = ({
  breadcrumbs,
  busy,
  driveId,
  folderId,
  loading,
  onOpen
}: Props): JSX.Element => {
  return (
    <nav aria-label="Google Drive folder path" className="docsync-wp-drive-browser__breadcrumb">
      <ol>
        {breadcrumbs.map((breadcrumb, index) => {
          const isCurrent = breadcrumb.fileId === folderId;

          return (
            <li key={`${driveId || 'my-drive'}-${breadcrumb.fileId}`}>
              {index > 0 ? (
                <span aria-hidden="true" className="docsync-wp-drive-browser__breadcrumb-separator">/</span>
              ) : null}
              {isCurrent ? (
                <span aria-current="page" className="docsync-wp-drive-browser__breadcrumb-current">
                  {breadcrumb.name}
                </span>
              ) : (
                <button
                  className="docsync-wp-drive-browser__breadcrumb-button"
                  disabled={busy || loading}
                  onClick={() => onOpen(breadcrumb, index)}
                  type="button"
                >
                  {breadcrumb.name}
                </button>
              )}
            </li>
          );
        })}
      </ol>
    </nav>
  );
};
