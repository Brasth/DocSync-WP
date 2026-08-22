import { createElement, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { FolderWatchRecord, WorkspaceResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { CronHealthBanner } from '../../shared/ui/cron-health-banner';
import { FolderWatchTable } from './folder-watch-table';

type Props = {
  busy: boolean;
  watches: FolderWatchRecord[];
  workspace: WorkspaceResponse;
  onCreateWatch: () => void;
  onEdit: (watch: FolderWatchRecord) => void;
  onPause: (watchId: string) => Promise<void>;
  onRemove: (watchId: string) => Promise<void>;
  onResume: (watchId: string) => Promise<void>;
  onScan: (watchId: string) => Promise<void>;
};

const statusOptions = [
  { value: '', label: __('All statuses', 'brasth-document-sync-for-google-docs') },
  { value: 'watching', label: __('Synced', 'brasth-document-sync-for-google-docs') },
  { value: 'importing', label: __('Syncing', 'brasth-document-sync-for-google-docs') },
  { value: 'error', label: __('Error', 'brasth-document-sync-for-google-docs') },
  { value: 'paused', label: __('Paused', 'brasth-document-sync-for-google-docs') }
];

export const FolderWatchesView = ({
  busy,
  watches,
  workspace,
  onCreateWatch,
  onEdit,
  onPause,
  onRemove,
  onResume,
  onScan
}: Props): JSX.Element => {
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const watching = watches.filter((watch) => watch.status === 'watching').length;
  const attention = watches.filter((watch) => watch.status === 'error' || watch.status === 'paused').length;

  const filteredWatches = useMemo(() => {
    const needle = search.trim().toLowerCase();

    return watches.filter((watch) => {
      if (statusFilter && watch.status !== statusFilter) {
        return false;
      }

      if (!needle) {
        return true;
      }

      return watch.folderName.toLowerCase().includes(needle)
        || (watch.ownerDisplayName || '').toLowerCase().includes(needle);
    });
  }, [search, statusFilter, watches]);

  return (
    <div className="docsync-wp-folder-watches-page">
      <CronHealthBanner health={workspace.cronHealth} />
      <div className="docsync-wp-source-health-summary" aria-label={__('Folder watch health', 'brasth-document-sync-for-google-docs')}>
        <div>
          <strong className="docsync-wp-tabular">{watches.length}</strong>
          <span>{__('Watches', 'brasth-document-sync-for-google-docs')}</span>
        </div>
        <div>
          <strong className="docsync-wp-tabular">{watching}</strong>
          <span>{__('Watching', 'brasth-document-sync-for-google-docs')}</span>
        </div>
        <div>
          <strong className="docsync-wp-tabular">{workspace.folderWatches?.importing ?? 0}</strong>
          <span>{__('Importing', 'brasth-document-sync-for-google-docs')}</span>
        </div>
        <div>
          <strong className="docsync-wp-tabular">{attention}</strong>
          <span>{__('Needs attention', 'brasth-document-sync-for-google-docs')}</span>
        </div>
      </div>
      {watches.length > 0 ? (
        <div className="docsync-wp-actions-row docsync-wp-folder-watches-page__toolbar">
          <AdminButton disabled={busy} onClick={onCreateWatch} variant="primary">
            {__('Watch another folder', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      ) : null}
      {watches.length > 0 ? (
        <div className="docsync-wp-source-filters docsync-wp-folder-watches-page__filters">
          <input
            aria-label={__('Search folder watches', 'brasth-document-sync-for-google-docs')}
            className="docsync-wp-folder-watches-page__search"
            disabled={busy}
            onChange={(event) => setSearch(event.currentTarget.value)}
            placeholder={__('Search folders or owners…', 'brasth-document-sync-for-google-docs')}
            type="search"
            value={search}
          />
          <select
            aria-label={__('Filter by status', 'brasth-document-sync-for-google-docs')}
            disabled={busy}
            onChange={(event) => setStatusFilter(event.currentTarget.value)}
            value={statusFilter}
          >
            {statusOptions.map((option) => (
              <option key={option.value || 'all'} value={option.value}>{option.label}</option>
            ))}
          </select>
        </div>
      ) : null}
      <FolderWatchTable
        busy={busy}
        onCreateWatch={onCreateWatch}
        onEdit={onEdit}
        onPause={onPause}
        onRemove={onRemove}
        onResume={onResume}
        onScan={onScan}
        watches={filteredWatches}
      />
    </div>
  );
};
