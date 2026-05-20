import { createElement, useEffect, useState } from '@wordpress/element';

import type { SourceRecord } from '../api';
import type { AvailablePostType } from '../config';

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

const statusOptions = [
  { value: '', label: 'All statuses' },
  { value: 'linked', label: 'Linked' },
  { value: 'syncing', label: 'Syncing' },
  { value: 'synced', label: 'Synced' },
  { value: 'skipped', label: 'Skipped' },
  { value: 'error', label: 'Error' }
];

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
          <h2>Sources</h2>
          <p>Linked Google Docs across enabled WordPress targets.</p>
        </div>
        <div className="docsync-wp-actions-row">
          <button className="button" disabled={busy} onClick={onRefresh} type="button">Refresh</button>
          <button className="button button-primary" disabled={busy} onClick={onSyncAll} type="button">Sync all changed</button>
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
          <span>Search</span>
          <input
            className="regular-text"
            onChange={(event) => setSearch(event.currentTarget.value)}
            placeholder="Post or Google Doc"
            type="search"
            value={search}
          />
        </label>
        <label>
          <span>Post type</span>
          <select onChange={(event) => setPostType(event.currentTarget.value)} value={postType}>
            <option value="">All enabled</option>
            {availablePostTypes.map((item) => (
              <option key={item.name} value={item.name}>{item.label}</option>
            ))}
          </select>
        </label>
        <label>
          <span>Sync status</span>
          <select onChange={(event) => setStatus(event.currentTarget.value)} value={status}>
            {statusOptions.map((item) => (
              <option key={item.value} value={item.value}>{item.label}</option>
            ))}
          </select>
        </label>
        <div className="docsync-wp-source-filters__actions">
          <button className="button button-primary" disabled={busy} type="submit">Apply filters</button>
          <button className="button" disabled={busy || !hasActiveFilters} onClick={resetFilters} type="button">Reset</button>
        </div>
      </form>

      <div className="docsync-wp-table-scroll">
        <table className="widefat striped docsync-wp-sources-table">
          <thead>
            <tr>
              <th>WordPress target</th>
              <th>Google Doc</th>
              <th>Status</th>
              <th>Last sync</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            {sources.length === 0 ? (
              <tr><td colSpan={5}>{hasActiveFilters ? 'No sources match these filters.' : 'No linked sources yet.'}</td></tr>
            ) : sources.map((source) => (
              <tr key={source.postId}>
                <td>
                  <a href={source.editUrl}>{source.postTitle || `Post ${source.postId}`}</a>
                  <small>{source.postType} · {source.postStatus}</small>
                </td>
                <td>
                  {source.googleDocUrl ? <a href={source.googleDocUrl} rel="noreferrer" target="_blank">{source.googleTitle || source.googleFileId}</a> : source.googleTitle || source.googleFileId}
                </td>
                <td><span className={`docsync-wp-pill is-${statusLabel(source)}`}>{statusLabel(source)}</span>{source.syncError ? <small>{source.syncError}</small> : null}</td>
                <td>{source.lastSyncedAt || 'Never'}</td>
                <td><button className="button" disabled={busy} onClick={() => onSync(source.postId)} type="button">Sync</button></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {hasMore ? (
        <p className="docsync-wp-table-footer">
          <button className="button" disabled={busy} onClick={onLoadMore} type="button">
            Load more sources
          </button>
        </p>
      ) : null}
    </section>
  );
};
