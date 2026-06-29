import { createElement, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { DocumentMetadata, DriveItemSummary } from '../../api';
import { SkeletonTableRows } from '../../shared/ui/skeleton';
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

const DriveBrowserTableHeader = (): JSX.Element => {
  return (
    <thead>
      <tr>
        <th scope="col">{__('Name', 'brasth-document-sync-for-google-docs')}</th>
        <th scope="col">{__('Modified', 'brasth-document-sync-for-google-docs')}</th>
        <th scope="col">{__('Type', 'brasth-document-sync-for-google-docs')}</th>
      </tr>
    </thead>
  );
};

export const DriveBrowserTableSkeleton = (): JSX.Element => {
  return (
    <div
      aria-busy="true"
      aria-label={__('Loading Drive items...', 'brasth-document-sync-for-google-docs')}
      className="docsync-wp-drive-browser__table-wrap"
    >
      <table className="docsync-wp-drive-browser__table">
        <DriveBrowserTableHeader />
        <tbody>
          <SkeletonTableRows columns={['70%', '50%', '44%']} rows={7} />
        </tbody>
      </table>
    </div>
  );
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
      <table aria-busy={loading} className="docsync-wp-drive-browser__table">
        <DriveBrowserTableHeader />
        <tbody>
          {items.map((item) => {
            const selected = item.itemType === 'document' && selectedDocument?.fileId === item.fileId;
            const compatibility = item.syncCompatibility;
            const disabled = busy || loading || (item.itemType === 'document' && !item.selectable);
            const rowClass = [
              selected ? 'is-selected' : '',
              item.itemType === 'folder' ? 'docsync-wp-drive-row--folder' : '',
              item.itemType === 'document' && !item.selectable ? 'is-disabled' : ''
            ].filter(Boolean).join(' ');

            return (
              <tr className={rowClass} key={item.fileId}>
                <td className="docsync-wp-drive-browser__name-cell">
                  <div className="docsync-wp-drive-browser__row-main">
                    <button
                      aria-label={item.itemType === 'folder'
                        ? sprintf(__('Open folder %s', 'brasth-document-sync-for-google-docs'), item.name)
                        : sprintf(__('Select Google Doc %s', 'brasth-document-sync-for-google-docs'), item.name)}
                      aria-pressed={item.itemType === 'document' ? selected : undefined}
                      className="docsync-wp-drive-browser__row-button"
                      disabled={disabled}
                      onClick={() => onActivate(item).catch(() => undefined)}
                      type="button"
                    >
                      <span
                        aria-hidden="true"
                        className={`dashicons ${item.itemType === 'folder' ? 'dashicons-category' : 'dashicons-media-document'}`}
                      />
                      <span>{item.name}</span>
                    </button>
                    <span className="docsync-wp-drive-browser__row-indicator">
                      {selected ? (
                        <span aria-hidden="true" className="dashicons dashicons-yes" />
                      ) : item.itemType === 'document' && !item.selectable ? (
                        <span aria-hidden="true" className="dashicons dashicons-lock" title={__('Blocked', 'brasth-document-sync-for-google-docs')} />
                      ) : null}
                    </span>
                  </div>
                  {compatibility?.warningMessage ? (
                    <small className={`docsync-wp-drive-browser__compat-warning is-${compatibility.warningCode ?? 'info'}`}>
                      <span aria-hidden="true" className="dashicons dashicons-warning" />
                      <span>{compatibility.warningMessage}</span>
                    </small>
                  ) : null}
                </td>
                <td>{formatDriveModifiedTime(item.modifiedTime)}</td>
                <td>{driveItemTypeLabel(item)}</td>
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
          {loading ? __('Loading more...', 'brasth-document-sync-for-google-docs') : ''}
        </div>
      ) : null}
    </div>
  );
};
