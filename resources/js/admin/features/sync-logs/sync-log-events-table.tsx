import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SyncLogEntry } from '../../api';
import { StatusPill } from '../../shared/ui/status-pill';

type Props = {
  entries: SyncLogEntry[];
};

const eventContext = (entry: SyncLogEntry): string => {
  const context = entry.context ?? {};
  const details = [];

  if (typeof context.hasLock === 'boolean') {
    details.push(sprintf(__('lock: %s', 'docsync-wp'), context.hasLock ? __('yes', 'docsync-wp') : __('no', 'docsync-wp')));
  }

  if (typeof context.hasCronEvent === 'boolean') {
    details.push(sprintf(__('cron: %s', 'docsync-wp'), context.hasCronEvent ? __('yes', 'docsync-wp') : __('no', 'docsync-wp')));
  }

  if (context.lastHeartbeat) {
    details.push(sprintf(__('last heartbeat: %s', 'docsync-wp'), context.lastHeartbeat));
  }

  if (context.lastStep) {
    details.push(sprintf(__('last step: %s', 'docsync-wp'), context.lastStep));
  }

  return details.join(' | ');
};

export const SyncLogEventsTable = ({ entries }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-table-scroll">
      <table className="widefat striped docsync-wp-logs-table">
        <thead>
          <tr>
            <th>{__('Time', 'docsync-wp')}</th>
            <th>{__('Level', 'docsync-wp')}</th>
            <th>{__('WordPress target', 'docsync-wp')}</th>
            <th>{__('Google Doc', 'docsync-wp')}</th>
            <th>{__('Status', 'docsync-wp')}</th>
            <th>{__('Message', 'docsync-wp')}</th>
            <th>{__('Error code', 'docsync-wp')}</th>
          </tr>
        </thead>
        <tbody>
          {entries.length === 0 ? (
            <tr>
              <td colSpan={7}>{__('No sync events match these filters.', 'docsync-wp')}</td>
            </tr>
          ) : entries.map((entry) => {
            const context = eventContext(entry);

            return (
              <tr key={entry.eventId}>
                <td>{entry.timestamp}</td>
                <td><StatusPill status={entry.level} /></td>
                <td>
                  {entry.postTitle || sprintf(__('Post %d', 'docsync-wp'), entry.postId)}
                  <small>{sprintf(__('ID %d', 'docsync-wp'), entry.postId)}</small>
                </td>
                <td>{entry.googleTitle || __('Untitled Google Doc', 'docsync-wp')}</td>
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
