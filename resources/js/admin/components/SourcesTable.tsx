import { createElement } from '@wordpress/element';

import type { SourceRecord } from '../api';

type Props = {
  sources: SourceRecord[];
  hasMore: boolean;
  busy: boolean;
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

export const SourcesTable = ({ sources, hasMore, busy, onRefresh, onLoadMore, onSync, onSyncAll }: Props): JSX.Element => {
  return (
    <section className="docsync-wp-card docsync-wp-card--wide">
      <div className="docsync-wp-card__header docsync-wp-card__header--row">
        <div>
          <h2>Sources</h2>
          <p>Linked Google Docs across enabled post types.</p>
        </div>
        <div className="docsync-wp-actions-row">
          <button className="button" disabled={busy} onClick={onRefresh} type="button">Refresh</button>
          <button className="button button-primary" disabled={busy || sources.length === 0} onClick={onSyncAll} type="button">Sync all changed</button>
        </div>
      </div>

      <table className="widefat striped docsync-wp-sources-table">
        <thead>
          <tr>
            <th>Post</th>
            <th>Google Doc</th>
            <th>Status</th>
            <th>Last sync</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          {sources.length === 0 ? (
            <tr><td colSpan={5}>No linked sources yet.</td></tr>
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
