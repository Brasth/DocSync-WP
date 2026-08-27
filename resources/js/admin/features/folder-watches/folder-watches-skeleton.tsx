import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { SkeletonTableRows, SkeletonText } from '../../shared/ui/skeleton';

export const FolderWatchesSkeleton = (): JSX.Element => {
  return (
    <section
      aria-busy="true"
      aria-label={__('Loading folder watches', 'brasth-document-sync-for-google-docs')}
      className="docsync-wp-card docsync-wp-card--wide"
    >
      <div className="docsync-wp-card__header docsync-wp-card__header--row">
        <div className="docsync-wp-skeleton-stack">
          <SkeletonText width="120px" />
          <SkeletonText width="280px" />
        </div>
      </div>
      <div className="docsync-wp-table-scroll">
        <table className="docsync-wp-data-table">
          <tbody>
            <SkeletonTableRows columns={6} rows={4} />
          </tbody>
        </table>
      </div>
    </section>
  );
};
