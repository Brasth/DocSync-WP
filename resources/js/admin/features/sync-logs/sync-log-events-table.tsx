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

export const SyncLogEventsTable = ({ entries }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-table-scroll">
      <table className="widefat striped docsync-wp-logs-table">
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
          {entries.length === 0 ? (
            <tr>
              <td colSpan={7}>{__('No sync events match these filters.', 'brasth-document-sync-for-google-docs')}</td>
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
