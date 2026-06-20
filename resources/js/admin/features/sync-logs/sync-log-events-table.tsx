import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SyncLogEntry } from '../../api';
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

const eventContext = (entry: SyncLogEntry): string => {
  const context = entry.context ?? {};
  const details = [];

  if (typeof context.hasLock === 'boolean') {
    details.push(sprintf(__('lock: %s', 'brasth-document-sync-for-google-docs'), context.hasLock ? __('yes', 'brasth-document-sync-for-google-docs') : __('no', 'brasth-document-sync-for-google-docs')));
  }

  if (typeof context.hasCronEvent === 'boolean') {
    details.push(sprintf(__('cron: %s', 'brasth-document-sync-for-google-docs'), context.hasCronEvent ? __('yes', 'brasth-document-sync-for-google-docs') : __('no', 'brasth-document-sync-for-google-docs')));
  }

  if (context.lastHeartbeat) {
    details.push(sprintf(__('last heartbeat: %s', 'brasth-document-sync-for-google-docs'), context.lastHeartbeat));
  }

  if (context.lastStep) {
    details.push(sprintf(__('last step: %s', 'brasth-document-sync-for-google-docs'), context.lastStep));
  }

  return details.join(' | ');
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

export const SyncLogEventsTable = ({ busy, entries, hasActiveFilters, hasLoaded, level, postId }: Props): JSX.Element => {
  const emptyCopy = getEmptyCopy(postId, level, hasActiveFilters);

  return (
    <div className="docsync-wp-table-scroll">
      <table aria-busy={!hasLoaded || (busy && entries.length === 0)} className="widefat striped docsync-wp-logs-table">
        <thead>
          <tr>
            <th>{__('Time', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Level', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('WordPress target', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Google Doc', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Status', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Message', 'brasth-document-sync-for-google-docs')}</th>
            <th>{__('Error code', 'brasth-document-sync-for-google-docs')}</th>
          </tr>
        </thead>
        <tbody>
          {!hasLoaded || (busy && entries.length === 0) ? (
            <SkeletonTableRows columns={['58%', '46%', '62%', '54%', '48%', '72%', '46%']} rows={5} />
          ) : entries.length === 0 ? (
            <tr>
              <td colSpan={7}>
                <div className="docsync-wp-table-empty-state">
                  <strong>{emptyCopy.title}</strong>
                  <span>{emptyCopy.description}</span>
                </div>
              </td>
            </tr>
          ) : entries.map((entry) => {
            const context = eventContext(entry);

            return (
              <tr key={entry.eventId}>
                <td>{entry.timestamp}</td>
                <td><StatusPill status={entry.level} /></td>
                <td>
                  {entry.postTitle || sprintf(__('Post %d', 'brasth-document-sync-for-google-docs'), entry.postId)}
                  <small>{sprintf(__('ID %d', 'brasth-document-sync-for-google-docs'), entry.postId)}</small>
                </td>
                <td>{entry.googleTitle || __('Untitled Google Doc', 'brasth-document-sync-for-google-docs')}</td>
                <td>{entry.status}<small>{entry.step} | {entry.progress}%</small></td>
                <td>{entry.message}{context ? <small>{context}</small> : null}</td>
                <td>{entry.errorCode || '-'}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};
