import { createElement, useEffect, useRef } from '@wordpress/element';

import type { DocumentMetadata, DriveItemSummary } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { driveItemTypeLabel, formatDriveModifiedTime } from './drive-browser-utils';

type Props = {
  busy: boolean;
  items: DriveItemSummary[];
  loading: boolean;
  selectedDocument: DocumentMetadata | null;
  hasMore: boolean;
  onActivate: (item: DriveItemSummary) => Promise<void>;
  onLoadMore: () => Promise<void>;
};

export const DriveBrowserTable = ({ busy, items, loading, selectedDocument, hasMore, onActivate, onLoadMore }: Props): JSX.Element => {
  const scrollRoot = useRef<HTMLDivElement | null>(null);
  const sentinel = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    const root = scrollRoot.current;
    const target = sentinel.current;

    if (!root || !target || !hasMore || busy || loading) {
      return undefined;
    }

    const observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)) {
        onLoadMore().catch(() => undefined);
      }
    }, {
      root,
      rootMargin: '160px 0px'
    });

    observer.observe(target);

    return () => observer.disconnect();
  }, [busy, hasMore, loading, onLoadMore]);

  return (
    <div className="docsync-wp-drive-browser__table-wrap" ref={scrollRoot}>
      <table className="widefat striped docsync-wp-drive-browser__table">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Modified</th>
            <th scope="col">Type</th>
            <th scope="col">Action</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const selected = item.itemType === 'document' && selectedDocument?.fileId === item.fileId;

            return (
              <tr className={selected ? 'is-selected' : ''} key={item.fileId}>
                <td className="docsync-wp-drive-browser__name-cell">
                  <button
                    aria-label={item.itemType === 'folder' ? `Open folder ${item.name}` : `Select Google Doc ${item.name}`}
                    aria-pressed={item.itemType === 'document' ? selected : undefined}
                    className="docsync-wp-drive-browser__row-button"
                    disabled={busy || loading}
                    onClick={() => onActivate(item).catch(() => undefined)}
                    type="button"
                  >
                    <span
                      aria-hidden="true"
                      className={`dashicons ${item.itemType === 'folder' ? 'dashicons-category' : 'dashicons-media-document'}`}
                    />
                    <span>{item.name}</span>
                  </button>
                </td>
                <td>{formatDriveModifiedTime(item.modifiedTime)}</td>
                <td>{driveItemTypeLabel(item)}</td>
                <td>
                  <AdminButton
                    className="button-small"
                    disabled={busy || loading}
                    onClick={() => onActivate(item)}
                    variant={selected ? 'primary' : 'secondary'}
                  >
                    {item.itemType === 'folder' ? 'Open' : selected ? 'Selected' : 'Select'}
                  </AdminButton>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
      {hasMore ? (
        <div
          aria-hidden={!loading}
          className="docsync-wp-drive-browser__sentinel"
          ref={sentinel}
        >
          {loading ? 'Loading more...' : ''}
        </div>
      ) : null}
    </div>
  );
};
