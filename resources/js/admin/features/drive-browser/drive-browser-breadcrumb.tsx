import { createElement } from '@wordpress/element';

import { AdminButton } from '../../shared/ui/admin-button';
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
      {breadcrumbs.map((breadcrumb, index) => {
        const isCurrent = breadcrumb.fileId === folderId;

        return (
          <span key={`${driveId || 'my-drive'}-${breadcrumb.fileId}`}>
            {index > 0 ? <span aria-hidden="true">/</span> : null}
            <AdminButton
              className={isCurrent ? 'is-current' : ''}
              disabled={busy || loading || isCurrent}
              onClick={() => onOpen(breadcrumb, index)}
              variant="link"
            >
              <span aria-current={isCurrent ? 'page' : undefined}>{breadcrumb.name}</span>
            </AdminButton>
          </span>
        );
      })}
    </nav>
  );
};
