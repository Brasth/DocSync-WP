import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SyncLogEntry } from '../../api';
import { EmptyState } from '../../shared/ui/empty-state';
import { SkeletonTableRows } from '../../shared/ui/skeleton';
import { StatusPill } from '../../shared/ui/status-pill';
import { syncLogRecoveryHint } from './sync-log-recovery-hints';

type Props = {
  busy: boolean;
  entries: SyncLogEntry[];
  hasActiveFilters: boolean;
  hasLoaded: boolean;
  level: string;
  postId: string;
  search: string;
  status: string;
  step: string;
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

  if (context.outputType) {
    details.push({
      label: __('Output', 'brasth-document-sync-for-google-docs'),
      value: context.outputType === 'elementor'
        ? __('Elementor', 'brasth-document-sync-for-google-docs')
        : __('WordPress Blocks', 'brasth-document-sync-for-google-docs')
    });
  }

  if (context.layoutPreset) {
    details.push({ label: __('Gutenberg preset', 'brasth-document-sync-for-google-docs'), value: context.layoutPreset });
  }

  if (context.elementorMode) {
    details.push({
      label: __('Elementor mode', 'brasth-document-sync-for-google-docs'),
      value: context.elementorMode === 'legacy'
        ? __('Legacy converter', 'brasth-document-sync-for-google-docs')
        : __('Preset', 'brasth-document-sync-for-google-docs')
    });
  }

  if (context.elementorPreset) {
    details.push({ label: __('Elementor preset', 'brasth-document-sync-for-google-docs'), value: context.elementorPreset });
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

const getEmptyCopy = (postId: string, level: string, search: string, status: string, step: string, hasActiveFilters: boolean): EmptyCopy => {
  const filteredPostId = postId.trim();

  if (filteredPostId && (level || search || status || step)) {
    return {
      title: __('No sync events for this source and filters.', 'brasth-document-sync-for-google-docs'),
      description: __('Adjust filters or clear them to inspect all stored events for this source.', 'brasth-document-sync-for-google-docs')
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
    description: __('Manual and scheduled sync events appear here after a linked Doc syncs. Check Sources to sync or inspect linked Docs.', 'brasth-document-sync-for-google-docs')
  };
};

const LogRow = ({ entry, level }: { entry: SyncLogEntry; level: string }): JSX.Element => {
  const contextDetails = eventContext(entry);
  const hasContext = contextDetails.length > 0;
  const hint = syncLogRecoveryHint(entry);
  const relativeTime = formatRelativeTime(entry.timestamp);
  const rowClass = level === 'error' ? 'docsync-wp-log-row--error' : level === 'warning' ? 'docsync-wp-log-row--warning' : '';
  const showProgress = typeof entry.progress === 'number' && entry.progress > 0 && entry.progress < 100;

  return (
    <tr className={rowClass}>
      <td className="docsync-wp-log-source-cell">
        <strong>{entry.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), entry.postId)}</strong>
        <small>{entry.googleTitle || __('Untitled Google Doc', 'brasth-document-sync-for-google-docs')}</small>
        <small>{sprintf(__('Source ID %d', 'brasth-document-sync-for-google-docs'), entry.postId)}</small>
      </td>
      <td className="docsync-wp-log-event-cell">
        <div className="docsync-wp-log-event-meta">
          <StatusPill status={entry.level} />
          <span>{entry.status}</span>
          {entry.step ? <span>{entry.step}</span> : null}
        </div>
        {showProgress ? (
          <span className="docsync-wp-log-mini-progress" aria-label={sprintf(__('Progress: %d%%', 'brasth-document-sync-for-google-docs'), entry.progress)}>
            <span style={{ width: `${entry.progress}%` }} />
          </span>
        ) : null}
        <p>{entry.message}</p>
        {entry.errorCode ? <span className="docsync-wp-log-error-code">{entry.errorCode}</span> : null}
      </td>
      <td className="docsync-wp-log-recovery-cell">
        {hint ? (
          <p className="docsync-wp-log-recovery-hint">{hint}</p>
        ) : (
          <span>{__('No action needed', 'brasth-document-sync-for-google-docs')}</span>
        )}
      </td>
      <td className="docsync-wp-log-details-cell">
        {hasContext ? (
          <details className="docsync-wp-log-context-details">
            <summary>{__('Details', 'brasth-document-sync-for-google-docs')}</summary>
            <dl className="docsync-wp-log-context-grid">
              {contextDetails.map((item) => (
                <div className="docsync-wp-log-context-item" key={item.label}>
                  <dt>{item.label}</dt>
                  <dd>{item.value}</dd>
                </div>
              ))}
            </dl>
          </details>
        ) : (
          <span aria-hidden="true" className="docsync-wp-log-details-empty">-</span>
        )}
      </td>
      <td className="docsync-wp-log-time-cell">
        <span title={relativeTime}>{entry.timestamp}</span>
        {relativeTime ? <small>{relativeTime}</small> : null}
      </td>
    </tr>
  );
};

export const SyncLogEventsTable = ({ busy, entries, hasActiveFilters, hasLoaded, level, postId, search, status, step }: Props): JSX.Element => {
  const emptyCopy = getEmptyCopy(postId, level, search, status, step, hasActiveFilters);

  if (hasLoaded && !busy && entries.length === 0) {
    return (
      <EmptyState
        action={(
          <a className="button button-secondary docsync-wp-button docsync-wp-button--default" href="admin.php?page=brasth-document-sync-for-google-docs-sources">
            {__('View Sources', 'brasth-document-sync-for-google-docs')}
          </a>
        )}
        className="docsync-wp-log-empty-panel"
        description={emptyCopy.description}
        title={emptyCopy.title}
        variant="logs"
      />
    );
  }

  return (
    <div className="docsync-wp-table-scroll">
      <table aria-busy={!hasLoaded || (busy && entries.length === 0)} className="docsync-wp-data-table docsync-wp-logs-table">
        <thead>
          <tr>
            <th>{__('Source', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Event', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Recovery', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Details', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Time', 'brasth-document-sync-for-google-docs')}</th>
          </tr>
        </thead>
        <tbody>
          {!hasLoaded || (busy && entries.length === 0) ? (
            <SkeletonTableRows columns={['58%', '46%', '66%', '52%', '78%']} rows={5} />
          ) : entries.map((entry) => (
            <LogRow entry={entry} key={entry.eventId} level={entry.level} />
          ))}
        </tbody>
      </table>
    </div>
  );
};
