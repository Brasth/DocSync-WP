import { createElement, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SourceRecord } from '../../api';
import type { AvailablePostType } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { EmptyState } from '../../shared/ui/empty-state';
import { SkeletonTableRows, SkeletonText } from '../../shared/ui/skeleton';
import { StatusPill } from '../../shared/ui/status-pill';
import { shouldShowSyncProgress, SyncProgress } from '../../shared/ui/sync-progress';

export type SourceListFilters = {
  search: string;
  postType: string;
  status: string;
};

type Props = {
  sources: SourceRecord[];
  availablePostTypes: AvailablePostType[];
  filters: SourceListFilters;
  hasMore: boolean;
  busy: boolean;
  onFiltersChange: (filters: SourceListFilters) => Promise<void>;
  onRefresh: () => Promise<void>;
  onLoadMore: () => Promise<void>;
  onSync: (postId: number) => Promise<void>;
  onSyncAll: () => Promise<void>;
};

const statusLabel = (source: SourceRecord): string => {
  if (source.syncError) {
    return 'error';
  }

  return source.syncStatus || 'linked';
};

const syncMethodLabel = (source: SourceRecord): string => {
  if (source.lastSyncMethod === 'docs_api_fallback') {
    return __('Large-doc fallback', 'brasth-document-sync-for-google-docs');
  }

  if (source.lastSyncMethod === 'html_zip') {
    return __('HTML ZIP', 'brasth-document-sync-for-google-docs');
  }

  return '';
};

const logsUrl = (postId: number): string => {
  return `admin.php?page=brasth-document-sync-for-google-docs-logs&post_id=${encodeURIComponent(String(postId))}`;
};

const statusOptions = [
  { value: '', label: __('All statuses', 'brasth-document-sync-for-google-docs') },
  { value: 'linked', label: __('Linked', 'brasth-document-sync-for-google-docs') },
  { value: 'syncing', label: __('Syncing', 'brasth-document-sync-for-google-docs') },
  { value: 'synced', label: __('Synced', 'brasth-document-sync-for-google-docs') },
  { value: 'skipped', label: __('Skipped', 'brasth-document-sync-for-google-docs') },
  { value: 'error', label: __('Error', 'brasth-document-sync-for-google-docs') }
];

export const SourcesTableSkeleton = (): JSX.Element => {
  return (
    <section
      aria-busy="true"
      aria-label={__('Loading linked sources', 'brasth-document-sync-for-google-docs')}
      className="docsync-wp-card docsync-wp-card--wide"
    >
      <div className="docsync-wp-card__header docsync-wp-card__header--row">
        <div className="docsync-wp-skeleton-stack">
          <SkeletonText width="94px" />
          <SkeletonText width="320px" />
        </div>
        <div className="docsync-wp-actions-row">
          <SkeletonText className="docsync-wp-skeleton-button" width="82px" />
          <SkeletonText className="docsync-wp-skeleton-button" width="130px" />
        </div>
      </div>

      <div className="docsync-wp-source-filters">
        <SkeletonText className="docsync-wp-skeleton-button" width="100%" />
        <SkeletonText className="docsync-wp-skeleton-button" width="100%" />
        <SkeletonText className="docsync-wp-skeleton-button" width="100%" />
        <div className="docsync-wp-source-filters__actions">
          <SkeletonText className="docsync-wp-skeleton-button" width="110px" />
          <SkeletonText className="docsync-wp-skeleton-button" width="74px" />
        </div>
      </div>

      <div className="docsync-wp-table-scroll">
        <table className="docsync-wp-data-table docsync-wp-sources-table">
          <thead>
            <tr>
              <th>{__('WordPress target', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Google Doc', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Status', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Last sync', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Actions', 'brasth-document-sync-for-google-docs')}</th>
            </tr>
          </thead>
          <tbody>
            <SkeletonTableRows columns={['62%', '58%', '44%', '48%', '72%']} rows={5} />
          </tbody>
        </table>
      </div>
    </section>
  );
};

export const SourcesTable = ({
  sources,
  availablePostTypes,
  filters,
  hasMore,
  busy,
  onFiltersChange,
  onRefresh,
  onLoadMore,
  onSync,
  onSyncAll
}: Props): JSX.Element => {
  const [search, setSearch] = useState(filters.search);
  const [postType, setPostType] = useState(filters.postType);
  const [status, setStatus] = useState(filters.status);
  const hasActiveFilters = Boolean(filters.search || filters.postType || filters.status);

  useEffect(() => {
    setSearch(filters.search);
    setPostType(filters.postType);
    setStatus(filters.status);
  }, [filters]);

  const applyFilters = async () => {
    await onFiltersChange({
      search: search.trim(),
      postType,
      status
    });
  };

  const resetFilters = async () => {
    setSearch('');
    setPostType('');
    setStatus('');
    await onFiltersChange({ search: '', postType: '', status: '' });
  };

  return (
    <section className="docsync-wp-card docsync-wp-card--wide">
      <div className="docsync-wp-card__header docsync-wp-card__header--row">
        <div>
          <h2>{__('Sources', 'brasth-document-sync-for-google-docs')}</h2>
          <p>{__('Linked Google Docs across enabled WordPress targets.', 'brasth-document-sync-for-google-docs')}</p>
        </div>
        <div className="docsync-wp-actions-row">
          <AdminButton disabled={busy} onClick={onRefresh}>{__('Refresh', 'brasth-document-sync-for-google-docs')}</AdminButton>
          <AdminButton disabled={busy} onClick={onSyncAll} variant="primary">{__('Sync all changed', 'brasth-document-sync-for-google-docs')}</AdminButton>
        </div>
      </div>

      <form
        className="docsync-wp-source-filters"
        onSubmit={(event) => {
          event.preventDefault();
          void applyFilters();
        }}
      >
        <label>
          <span>{__('Search', 'brasth-document-sync-for-google-docs')}</span>
          <input
            className="regular-text"
            onChange={(event) => setSearch(event.currentTarget.value)}
            placeholder={__('Post or Google Doc', 'brasth-document-sync-for-google-docs')}
            type="search"
            value={search}
          />
        </label>
        <label>
          <span>{__('Post type', 'brasth-document-sync-for-google-docs')}</span>
          <select onChange={(event) => setPostType(event.currentTarget.value)} value={postType}>
            <option value="">{__('All enabled', 'brasth-document-sync-for-google-docs')}</option>
            {availablePostTypes.map((item) => (
              <option key={item.name} value={item.name}>{item.label}</option>
            ))}
          </select>
        </label>
        <label>
          <span>{__('Sync status', 'brasth-document-sync-for-google-docs')}</span>
          <select onChange={(event) => setStatus(event.currentTarget.value)} value={status}>
            {statusOptions.map((item) => (
              <option key={item.value} value={item.value}>{item.label}</option>
            ))}
          </select>
        </label>
        <div className="docsync-wp-source-filters__actions">
          <AdminButton disabled={busy} type="submit" variant="primary">{__('Apply filters', 'brasth-document-sync-for-google-docs')}</AdminButton>
          <AdminButton disabled={busy || !hasActiveFilters} onClick={resetFilters}>{__('Reset', 'brasth-document-sync-for-google-docs')}</AdminButton>
        </div>
      </form>

      <div className="docsync-wp-table-scroll">
        <table aria-busy={busy && sources.length === 0} className="widefat striped docsync-wp-sources-table">
          <thead>
            <tr>
              <th>{__('WordPress target', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Google Doc', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Status', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Last sync', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Actions', 'brasth-document-sync-for-google-docs')}</th>
            </tr>
          </thead>
          <tbody>
            {busy && sources.length === 0 ? (
              <SkeletonTableRows columns={['62%', '58%', '44%', '48%', '72%']} rows={5} />
            ) : sources.length === 0 ? (
              <tr>
                <td colSpan={5}>
                  <EmptyState
                    className="docsync-wp-table-empty-state"
                    description={hasActiveFilters
                      ? __('Adjust the filters or reset to see all linked sources.', 'brasth-document-sync-for-google-docs')
                      : __('Link a Google Doc from the post editor to get started.', 'brasth-document-sync-for-google-docs')}
                    title={hasActiveFilters
                      ? __('No sources match these filters.', 'brasth-document-sync-for-google-docs')
                      : __('No linked sources yet.', 'brasth-document-sync-for-google-docs')}
                  />
                </td>
              </tr>
            ) : sources.map((source) => (
              <tr key={source.postId}>
                <td>
                  <a href={source.editUrl}>{source.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), source.postId)}</a>
                  <span className="docsync-wp-row-tag">{source.postType}</span>
                  <span className="docsync-wp-row-tag">{source.postStatus}</span>
                </td>
                <td>
                  {source.googleDocUrl ? <a href={source.googleDocUrl} rel="noreferrer" target="_blank">{source.googleTitle || source.googleFileId}</a> : source.googleTitle || source.googleFileId}
                  {source.googleFileId ? <small>{source.googleFileId}</small> : null}
                </td>
                <td>
                  <div className="docsync-wp-source-status-cell">
                    <StatusPill status={statusLabel(source)} />
                    {shouldShowSyncProgress(source) ? (
                      <div className="docsync-wp-source-sync-block">
                        <SyncProgress message={source.syncMessage} progress={source.syncProgress} />
                      </div>
                    ) : null}
                    {source.syncError ? <small>{source.syncError}</small> : null}
                  </div>
                </td>
                <td>
                  {source.lastSyncedAt || __('Never', 'brasth-document-sync-for-google-docs')}
                  {syncMethodLabel(source) ? <span className="docsync-wp-row-tag">{syncMethodLabel(source)}</span> : null}
                </td>
                <td>
                  <div className="docsync-wp-source-actions">
                    <AdminButton disabled={busy} onClick={() => onSync(source.postId)} variant="primary">{__('Sync', 'brasth-document-sync-for-google-docs')}</AdminButton>
                    <a className="button button-secondary docsync-wp-view-logs-link" href={logsUrl(source.postId)}>
                      <span aria-hidden="true" className="dashicons dashicons-list-view" />
                      {__('View logs', 'brasth-document-sync-for-google-docs')}
                    </a>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {hasMore ? (
        <p className="docsync-wp-table-footer">
          <AdminButton disabled={busy} onClick={onLoadMore}>
            {__('Load more sources', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </p>
      ) : null}
    </section>
  );
};
