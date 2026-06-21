import { createElement, Fragment, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SyncLogEntry } from '../../api';
import { EmptyState } from '../../shared/ui/empty-state';
import { SkeletonTableRows } from '../../shared/ui/skeleton';
import { StatusPill } from '../../shared/ui/status-pill';

type Props = {
  busy: boolean;
  entries: SyncLogEntry[];
  hasActiveFilters: boolean;
  hasLoaded: boolean;
  level: string;
  postId: string;
};

type EmptyCopy = {
  description: string;
  title: string;
};

const eventContext = (entry: SyncLogEntry): { label: string; value: string }[] => {
  const context = entry.context ?? {};
  const details: { label: string; value: string }[] = [];

  if (typeof context.hasLock === 'boolean') {
    details.push({
      label: __('Lock', 'brasth-document-sync-for-google-docs'),
      value: context.hasLock ? __('Yes', 'brasth-document-sync-for-google-docs') : __('No', 'brasth-document-sync-for-google-docs')
    });
  }

  if (typeof context.hasCronEvent === 'boolean') {
    details.push({
      label: __('Cron event', 'brasth-document-sync-for-google-docs'),
      value: context.hasCronEvent ? __('Yes', 'brasth-document-sync-for-google-docs') : __('No', 'brasth-document-sync-for-google-docs')
    });
  }

  if (context.lastHeartbeat) {
    details.push({ label: __('Last heartbeat', 'brasth-document-sync-for-google-docs'), value: context.lastHeartbeat });
  }

  if (context.lastStep) {
    details.push({ label: __('Last step', 'brasth-document-sync-for-google-docs'), value: context.lastStep });
  }

  return details;
};

const formatRelativeTime = (timestamp: string): string => {
  if (!timestamp) {
    return '';
  }

  const date = new Date(timestamp);

  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const now = Date.now();
  const diffMs = now - date.getTime();
  const diffMin = Math.floor(diffMs / 60000);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffMin < 1) {
    return __('just now', 'brasth-document-sync-for-google-docs');
  }

  if (diffMin < 60) {
    return sprintf(__('%d min ago', 'brasth-document-sync-for-google-docs'), diffMin);
  }

  if (diffHr < 24) {
    return sprintf(__('%d hr ago', 'brasth-document-sync-for-google-docs'), diffHr);
  }

  if (diffDay < 7) {
    return sprintf(__('%d days ago', 'brasth-document-sync-for-google-docs'), diffDay);
  }

  return '';
};

const getEmptyCopy = (postId: string, level: string, hasActiveFilters: boolean): EmptyCopy => {
  const filteredPostId = postId.trim();

  if (filteredPostId && level) {
    return {
      title: __('No sync events for this source and level.', 'brasth-document-sync-for-google-docs'),
      description: __('Choose another level or clear filters to inspect all stored events for this source.', 'brasth-document-sync-for-google-docs')
    };
  }

  if (filteredPostId) {
    return {
      title: __('No sync events for this source yet.', 'brasth-document-sync-for-google-docs'),
      description: __('This post may not be linked, may not have synced yet, or its older events may have been pruned.', 'brasth-document-sync-for-google-docs')
    };
  }

  if (level) {
    return {
      title: __('No sync events for this level.', 'brasth-document-sync-for-google-docs'),
      description: __('Choose another level or clear filters to view all stored events.', 'brasth-document-sync-for-google-docs')
    };
  }

  if (hasActiveFilters) {
    return {
      title: __('No sync events match these filters.', 'brasth-document-sync-for-google-docs'),
      description: __('Adjust the filters to inspect another set of stored events.', 'brasth-document-sync-for-google-docs')
    };
  }

  return {
    title: __('No sync events recorded yet.', 'brasth-document-sync-for-google-docs'),
    description: __('Manual and scheduled sync events appear here after a source runs.', 'brasth-document-sync-for-google-docs')
  };
};

const LogRow = ({ entry, level }: { entry: SyncLogEntry; level: string }): JSX.Element => {
  const [expanded, setExpanded] = useState(false);
  const contextDetails = eventContext(entry);
  const hasContext = contextDetails.length > 0;
  const relativeTime = formatRelativeTime(entry.timestamp);
  const rowClass = level === 'error' ? 'docsync-wp-log-row--error' : level === 'warning' ? 'docsync-wp-log-row--warning' : '';
  const showProgress = typeof entry.progress === 'number' && entry.progress > 0 && entry.progress < 100;

  return (
    <>
      <tr className={rowClass}>
        <td>
          <span title={relativeTime}>{entry.timestamp}</span>
          {relativeTime ? <small>{relativeTime}</small> : null}
        </td>
        <td><StatusPill status={entry.level} /></td>
        <td>
          {entry.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), entry.postId)}
          <small>{sprintf(__('ID %d', 'brasth-document-sync-for-google-docs'), entry.postId)}</small>
        </td>
        <td>{entry.googleTitle || __('Untitled Google Doc', 'brasth-document-sync-for-google-docs')}</td>
        <td>
          {entry.status}
          {showProgress ? (
            <span className="docsync-wp-log-mini-progress" aria-label={sprintf(__('Progress: %d%%', 'brasth-document-sync-for-google-docs'), entry.progress)}>
              <span style={{ width: `${entry.progress}%` }} />
            </span>
          ) : null}
          {entry.step ? <small>{entry.step}</small> : null}
        </td>
        <td>
          {hasContext ? (
            <button
              aria-expanded={expanded}
              aria-label={expanded ? __('Hide context', 'brasth-document-sync-for-google-docs') : __('Show context', 'brasth-document-sync-for-google-docs')}
              className="docsync-wp-log-row-toggle"
              onClick={() => setExpanded(!expanded)}
              type="button"
            >
              <span aria-hidden="true" className={`dashicons ${expanded ? 'dashicons-arrow-down' : 'dashicons-arrow-right'}`} />
            </button>
          ) : null}
          {entry.message}
          {entry.errorCode ? <span className="docsync-wp-log-error-code">{entry.errorCode}</span> : null}
        </td>
      </tr>
      {expanded && hasContext ? (
        <tr className="docsync-wp-log-context-row">
          <td colSpan={6}>
            <dl className="docsync-wp-log-context-grid">
              {contextDetails.map((item) => (
                <div className="docsync-wp-log-context-item" key={item.label}>
                  <dt>{item.label}</dt>
                  <dd>{item.value}</dd>
                </div>
              ))}
            </dl>
          </td>
        </tr>
      ) : null}
    </>
  );
};

export const SyncLogEventsTable = ({ busy, entries, hasActiveFilters, hasLoaded, level, postId }: Props): JSX.Element => {
  const emptyCopy = getEmptyCopy(postId, level, hasActiveFilters);

  return (
    <div className="docsync-wp-table-scroll">
      <table aria-busy={!hasLoaded || (busy && entries.length === 0)} className="docsync-wp-data-table docsync-wp-logs-table">
        <thead>
          <tr>
            <th>{__('Time', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Level', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('WordPress target', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Google Doc', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Status', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Message', 'brasth-document-sync-for-google-docs')}</th>
          </tr>
        </thead>
        <tbody>
          {!hasLoaded || (busy && entries.length === 0) ? (
            <SkeletonTableRows columns={['58%', '46%', '62%', '54%', '48%', '72%']} rows={5} />
          ) : entries.length === 0 ? (
            <tr>
              <td colSpan={6}>
                <EmptyState
                  className="docsync-wp-table-empty-state"
                  description={emptyCopy.description}
                  title={emptyCopy.title}
                />
              </td>
            </tr>
          ) : entries.map((entry) => (
            <LogRow entry={entry} key={entry.eventId} level={entry.level} />
          ))}
        </tbody>
      </table>
    </div>
  );
};
