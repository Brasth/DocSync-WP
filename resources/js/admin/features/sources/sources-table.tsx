import { createElement, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SourceRecord } from '../../api';
import { getAdminConfig, type AvailablePostType } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { EmptyState } from '../../shared/ui/empty-state';
import { SkeletonTableRows, SkeletonText } from '../../shared/ui/skeleton';
import { StatusPill } from '../../shared/ui/status-pill';
import { isQueuedSync, shouldShowSyncProgress, SyncProgress } from '../../shared/ui/sync-progress';
import { formatLocalDateTime, formatSyncTime } from '../../shared/format-time';
import { recoveryForErrorCode } from './sync-error-recovery';
import { SourceActivityDrawer } from './source-activity-drawer';

export type SourceListFilters = {
  search: string;
  postType: string;
  status: string;
  folderWatchId: string;
};

type Props = {
  sources: SourceRecord[];
  availablePostTypes: AvailablePostType[];
  folderWatchNames?: Record<string, string>;
  filters: SourceListFilters;
  hasMore: boolean;
  busy: boolean;
  onFiltersChange: (filters: SourceListFilters) => Promise<void>;
  onRefresh: () => Promise<void>;
  onLoadMore: () => Promise<void>;
  onSync: (postId: number) => Promise<void>;
  onSyncAll: () => Promise<void>;
  onSyncSelected?: (postIds: number[]) => Promise<void>;
  onCreateSource?: (intent?: 'folder' | 'document') => void;
  canCreateSource?: boolean;
  emptyStateExtra?: JSX.Element | null;
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

const setupUrl = 'admin.php?page=brasth-document-sync-for-google-docs';
const maxBulkSync = 20;
const searchDebounceMs = 300;

const postStatusLabels: Record<string, string> = {
  publish: __('Published', 'brasth-document-sync-for-google-docs'),
  draft: __('Draft', 'brasth-document-sync-for-google-docs'),
  pending: __('Pending review', 'brasth-document-sync-for-google-docs'),
  private: __('Private', 'brasth-document-sync-for-google-docs'),
  future: __('Scheduled', 'brasth-document-sync-for-google-docs')
};

const statusOptions = [
  { value: '', label: __('All statuses', 'brasth-document-sync-for-google-docs') },
  { value: 'linked', label: __('Linked', 'brasth-document-sync-for-google-docs') },
  { value: 'syncing', label: __('Syncing', 'brasth-document-sync-for-google-docs') },
  { value: 'synced', label: __('Synced', 'brasth-document-sync-for-google-docs') },
  { value: 'skipped', label: __('Up to date', 'brasth-document-sync-for-google-docs') },
  { value: 'update_available', label: __('Update available', 'brasth-document-sync-for-google-docs') },
  { value: 'attention', label: __('Needs attention', 'brasth-document-sync-for-google-docs') },
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
  folderWatchNames = {},
  filters,
  hasMore,
  busy,
  onFiltersChange,
  onRefresh,
  onLoadMore,
  onSync,
  onSyncAll,
  onSyncSelected,
  onCreateSource = () => undefined,
  canCreateSource = false,
  emptyStateExtra = null
}: Props): JSX.Element => {
  const [search, setSearch] = useState(filters.search);
  const [selected, setSelected] = useState<number[]>([]);
  const [activitySource, setActivitySource] = useState<SourceRecord | null>(null);
  const filtersRef = useRef(filters);
  const submittedSearchRef = useRef(filters.search);
  const hasActiveFilters = Boolean(filters.search || filters.postType || filters.status || filters.folderWatchId);
  const postTypeLabels = Object.fromEntries(availablePostTypes.map((item) => [item.name, item.label]));

  filtersRef.current = filters;

  useEffect(() => {
    // Ignore the echo of our own debounced request so typing during a refetch is never overwritten.
    if (filters.search !== submittedSearchRef.current) {
      submittedSearchRef.current = filters.search;
      setSearch(filters.search);
    }
  }, [filters.search]);

  useEffect(() => {
    setSelected((current) => current.filter((id) => sources.some((source) => source.postId === id)));
  }, [sources]);

  useEffect(() => {
    const next = search.trim();

    if (next === filtersRef.current.search) {
      return undefined;
    }

    const timer = window.setTimeout(() => {
      submittedSearchRef.current = next;
      void onFiltersChange({ ...filtersRef.current, search: next });
    }, searchDebounceMs);

    return () => window.clearTimeout(timer);
  }, [search]);

  const resetFilters = async () => {
    submittedSearchRef.current = '';
    setSearch('');
    await onFiltersChange({ search: '', postType: '', status: '', folderWatchId: '' });
  };

  const toggleSelected = (postId: number) => {
    setSelected((current) => current.includes(postId) ? current.filter((id) => id !== postId) : [...current, postId]);
  };

  const syncSelected = async () => {
    const ids = selected.slice(0, maxBulkSync);

    await onSyncSelected?.(ids);
    setSelected([]);
  };

  const folderWatchLabel = filters.folderWatchId
    ? folderWatchNames[filters.folderWatchId] || filters.folderWatchId
    : '';

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
          void onFiltersChange({ ...filters, search: search.trim() });
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
          <select onChange={(event) => void onFiltersChange({ ...filters, postType: event.currentTarget.value })} value={filters.postType}>
            <option value="">{__('All enabled', 'brasth-document-sync-for-google-docs')}</option>
            {availablePostTypes.map((item) => (
              <option key={item.name} value={item.name}>{item.label}</option>
            ))}
          </select>
        </label>
        <label>
          <span>{__('Sync status', 'brasth-document-sync-for-google-docs')}</span>
          <select onChange={(event) => void onFiltersChange({ ...filters, status: event.currentTarget.value })} value={filters.status}>
            {statusOptions.map((item) => (
              <option key={item.value} value={item.value}>{item.label}</option>
            ))}
          </select>
        </label>
        <div className="docsync-wp-source-filters__actions">
          {onSyncSelected ? (
            <AdminButton disabled={busy || selected.length === 0} onClick={syncSelected}>
              {selected.length > 0
                ? sprintf(__('Sync selected (%d)', 'brasth-document-sync-for-google-docs'), Math.min(selected.length, maxBulkSync))
                : __('Sync selected', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          ) : null}
          <AdminButton disabled={busy || !hasActiveFilters} onClick={resetFilters}>{__('Reset', 'brasth-document-sync-for-google-docs')}</AdminButton>
        </div>
      </form>

      {filters.folderWatchId ? (
        <p className="docsync-wp-source-folder-filter">
          <span className="docsync-wp-folder-watch-chip">
            {sprintf(
              /* translators: %s: folder name. */
              __('From folder: %s', 'brasth-document-sync-for-google-docs'),
              folderWatchLabel
            )}
          </span>
          <AdminButton disabled={busy} onClick={() => void onFiltersChange({ ...filters, folderWatchId: '' })} size="small" variant="link">
            {__('Clear folder filter', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </p>
      ) : null}

      <div className="docsync-wp-table-scroll">
        <table aria-busy={busy && sources.length === 0} className="docsync-wp-data-table docsync-wp-sources-table">
          <thead>
            <tr>
              <th className="docsync-wp-source-select-cell"><span className="screen-reader-text">{__('Select', 'brasth-document-sync-for-google-docs')}</span></th>
              <th>{__('WordPress target', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Google Doc', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Status', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Last sync', 'brasth-document-sync-for-google-docs')}</th>
              <th>{__('Actions', 'brasth-document-sync-for-google-docs')}</th>
            </tr>
          </thead>
          <tbody>
            {busy && sources.length === 0 ? (
              <SkeletonTableRows columns={['24px', '62%', '58%', '44%', '48%', '72%']} rows={5} />
            ) : sources.length === 0 ? (
              <tr>
                <td colSpan={6}>
                  <EmptyState
                    action={hasActiveFilters ? (
                      <AdminButton disabled={busy} onClick={resetFilters}>
                        {__('Reset filters', 'brasth-document-sync-for-google-docs')}
                      </AdminButton>
                    ) : (
                      canCreateSource ? (
                        <div className="docsync-wp-empty-state__action-row">
                          <AdminButton disabled={busy} onClick={() => onCreateSource('folder')} variant="primary">
                            {__('Watch a client folder', 'brasth-document-sync-for-google-docs')}
                          </AdminButton>
                          <AdminButton disabled={busy} onClick={() => onCreateSource('document')} variant="secondary">
                            {__('Link one Google Doc instead', 'brasth-document-sync-for-google-docs')}
                          </AdminButton>
                          {emptyStateExtra}
                        </div>
                      ) : emptyStateExtra ?? undefined
                    )}
                    className="docsync-wp-table-empty-state"
                    description={hasActiveFilters
                      ? __('Adjust the filters or reset to see all linked sources.', 'brasth-document-sync-for-google-docs')
                      : canCreateSource
                        ? __('No Docs linked yet. Link one Doc or watch a client folder.', 'brasth-document-sync-for-google-docs')
                        : __('Complete the connection responsibility above before creating a source.', 'brasth-document-sync-for-google-docs')}
                    title={hasActiveFilters
                      ? __('No sources match these filters.', 'brasth-document-sync-for-google-docs')
                      : __('No Docs linked yet', 'brasth-document-sync-for-google-docs')}
                    variant="sources"
                  />
                </td>
              </tr>
            ) : sources.map((source) => (
              <tr key={source.postId}>
                <td className="docsync-wp-source-select-cell">
                  <input
                    aria-label={sprintf(__('Select %s', 'brasth-document-sync-for-google-docs'), source.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), source.postId))}
                    checked={selected.includes(source.postId)}
                    disabled={busy || source.syncStatus === 'syncing'}
                    onChange={() => toggleSelected(source.postId)}
                    type="checkbox"
                  />
                </td>
                <td>
                  <div className="docsync-wp-source-target">
                    <a className="docsync-wp-source-target__title" href={source.editUrl}>
                      {source.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), source.postId)}
                    </a>
                    <div className="docsync-wp-source-target__meta">
                      {source.postType ? <span className="docsync-wp-row-tag">{postTypeLabels[source.postType] || source.postType}</span> : null}
                      {source.postStatus ? <span className="docsync-wp-row-tag">{postStatusLabels[source.postStatus] || source.postStatus}</span> : null}
                      {source.folderWatchId ? (
                        <span className="docsync-wp-folder-watch-chip">
                          {sprintf(
                            /* translators: %s: folder name. */
                            __('From folder: %s', 'brasth-document-sync-for-google-docs'),
                            folderWatchNames[source.folderWatchId] || source.folderWatchId
                          )}
                        </span>
                      ) : null}
                    </div>
                  </div>
                </td>
                <td>
                  <div className="docsync-wp-source-doc">
                    {source.googleDocUrl ? (
                      <a className="docsync-wp-source-doc__title" href={source.googleDocUrl} rel="noreferrer" target="_blank">
                        {source.googleTitle || source.googleFileId}
                      </a>
                    ) : (
                      <span className="docsync-wp-source-doc__title">{source.googleTitle || source.googleFileId}</span>
                    )}
                  </div>
                </td>
                <td>
                  <div className="docsync-wp-source-status-cell">
                    <StatusPill status={statusLabel(source)} />
                    {shouldShowSyncProgress(source) ? (
                      <div className="docsync-wp-source-sync-block">
                        <SyncProgress indeterminate={isQueuedSync(source)} message={source.syncMessage} progress={source.syncProgress} />
                      </div>
                    ) : null}
                    {source.syncError ? (
                      <small className="docsync-wp-source-error-text">
                        {source.syncError}
                        {' '}
                        {(() => {
                          const recovery = recoveryForErrorCode(source.syncErrorCode);

                          if (recovery.kind === 'reconnect') {
                            return getAdminConfig().canManageSettings
                              ? <a href={setupUrl}>{recovery.label}</a>
                              : <span>{__('Use Connect Google above', 'brasth-document-sync-for-google-docs')}</span>;
                          }

                          if (recovery.kind === 'open-doc' && source.googleDocUrl) {
                            return <a href={source.googleDocUrl} rel="noreferrer" target="_blank">{recovery.label}</a>;
                          }

                          if (recovery.kind === 'change-doc') {
                            return <a href={source.editUrl}>{recovery.label}</a>;
                          }

                          if (recovery.kind === 'ask-admin') {
                            return <span>{recovery.label}</span>;
                          }

                          return (
                            <AdminButton disabled={busy} onClick={() => onSync(source.postId)} size="small" variant="link">
                              {recovery.label}
                            </AdminButton>
                          );
                        })()}
                      </small>
                    ) : null}
                  </div>
                </td>
                <td>
                  <div className="docsync-wp-source-last-sync">
                    <span title={formatLocalDateTime(source.lastSyncedAt)}>{source.lastSyncedAt ? formatSyncTime(source.lastSyncedAt) : __('Never', 'brasth-document-sync-for-google-docs')}</span>
                    {syncMethodLabel(source) ? <span className="docsync-wp-row-tag">{syncMethodLabel(source)}</span> : null}
                  </div>
                </td>
                <td>
                  <div className="docsync-wp-source-actions">
                    <AdminButton
                      className="docsync-wp-source-sync-button"
                      disabled={busy}
                      onClick={() => onSync(source.postId)}
                      size="small"
                      variant={source.syncStatus === 'update_available' ? 'primary' : 'secondary'}
                    >
                      {source.syncStatus === 'update_available' ? __('Apply update', 'brasth-document-sync-for-google-docs') : __('Sync', 'brasth-document-sync-for-google-docs')}
                    </AdminButton>
                    <AdminButton
                      aria-label={sprintf(
                        __('View activity for %s', 'brasth-document-sync-for-google-docs'),
                        source.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), source.postId)
                      )}
                      onClick={() => setActivitySource(source)}
                      size="small"
                    >
                      <span aria-hidden="true" className="dashicons dashicons-list-view" />
                      <span>{__('Activity', 'brasth-document-sync-for-google-docs')}</span>
                    </AdminButton>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <SourceActivityDrawer
        onClose={() => setActivitySource(null)}
        postId={activitySource?.postId ?? null}
        title={activitySource ? activitySource.postTitle || activitySource.googleTitle || '' : ''}
      />

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
