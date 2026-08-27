import { createElement, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord, WorkspaceResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { CronHealthBanner } from '../../shared/ui/cron-health-banner';
import { FolderWatchTable } from './folder-watch-table';
import { folderWatchDetailUrl } from './folder-watch-labels';
import {
  type FolderWatchHealthFilter,
  attentionReason,
  filterFolderWatches,
  primaryWatchAction,
  watchNeedsAttention
} from './folder-watch-ops';
import { FolderWatchReviewPrompt } from './folder-watch-review-prompt';

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
  { value: '', label: __('All folders', 'brasth-document-sync-for-google-docs') },
  { value: 'attention', label: __('Needs attention', 'brasth-document-sync-for-google-docs') },
  { value: 'importing', label: __('Importing', 'brasth-document-sync-for-google-docs') },
  { value: 'watching', label: __('Watching', 'brasth-document-sync-for-google-docs') },
  { value: 'error', label: __('Error', 'brasth-document-sync-for-google-docs') },
  { value: 'paused', label: __('Paused', 'brasth-document-sync-for-google-docs') }
];

const reasonLabel = (watch: FolderWatchRecord): string => {
  const reason = attentionReason(watch);

  if (reason === 'failed') {
    return sprintf(
      /* translators: %d: number of failed Google Docs. */
      __('%d Doc failed', 'brasth-document-sync-for-google-docs'),
      watch.failed.length
    );
  }

  if (reason === 'error') {
    return watch.lastError || __('Folder scan failed', 'brasth-document-sync-for-google-docs');
  }

  if (reason === 'importing') {
    return sprintf(
      /* translators: %d: number of Docs still importing. */
      __('%d Doc still importing', 'brasth-document-sync-for-google-docs'),
      watch.pendingCount
    );
  }

  if (reason === 'paused') {
    return __('Watch is paused', 'brasth-document-sync-for-google-docs');
  }

  return '';
};

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
  const [healthFilter, setHealthFilter] = useState<FolderWatchHealthFilter>('');
  const watchingCount = watches.filter((watch) => watch.status === 'watching').length;
  const importingCount = watches.filter((watch) => watch.status === 'importing').length;
  const attentionCount = watches.filter((watch) => watchNeedsAttention(watch)).length;
  const attentionWatches = filterFolderWatches(watches, '', 'attention');

  const filteredWatches = useMemo(() => {
    return filterFolderWatches(watches, search, healthFilter);
  }, [healthFilter, search, watches]);

  const tiles: Array<{ id: FolderWatchHealthFilter; label: string; count: number }> = [
    { id: '', label: __('Folders', 'brasth-document-sync-for-google-docs'), count: watches.length },
    { id: 'watching', label: __('Watching', 'brasth-document-sync-for-google-docs'), count: watchingCount },
    { id: 'importing', label: __('Importing', 'brasth-document-sync-for-google-docs'), count: importingCount },
    { id: 'attention', label: __('Needs attention', 'brasth-document-sync-for-google-docs'), count: attentionCount }
  ];

  const runPrimary = (watch: FolderWatchRecord) => {
    const action = primaryWatchAction(watch);

    if (action === 'resume') {
      void onResume(watch.id);
      return;
    }

    if (action === 'scan') {
      void onScan(watch.id);
      return;
    }

    onEdit(watch);
  };

  return (
    <div className="docsync-wp-folder-watches-page">
      <CronHealthBanner health={workspace.cronHealth} />
      <FolderWatchReviewPrompt watches={watches} />
      {watches.length > 0 ? (
        <div className="docsync-wp-source-health-summary" role="group" aria-label={__('Folder watch health', 'brasth-document-sync-for-google-docs')}>
          {tiles.map((tile) => (
            <button
              aria-pressed={healthFilter === tile.id}
              className={`docsync-wp-source-health-summary__tile${healthFilter === tile.id ? ' is-active' : ''}`}
              key={tile.id || 'all'}
              onClick={() => setHealthFilter(healthFilter === tile.id ? '' : tile.id)}
              type="button"
            >
              <strong className="docsync-wp-tabular">{tile.count}</strong>
              <span>{tile.label}</span>
            </button>
          ))}
        </div>
      ) : null}
      {attentionWatches.length > 0 && healthFilter !== 'watching' ? (
        <section className="docsync-wp-folder-attention" aria-label={__('Folders that need attention', 'brasth-document-sync-for-google-docs')}>
          <header className="docsync-wp-folder-attention__header">
            <h2>{__('Needs attention', 'brasth-document-sync-for-google-docs')}</h2>
            <p>{__('Fix failed Docs, stalled imports, and paused client folders first.', 'brasth-document-sync-for-google-docs')}</p>
          </header>
          <ul className="docsync-wp-folder-attention__list">
            {attentionWatches.slice(0, 5).map((watch) => (
              <li className="docsync-wp-folder-attention__item" key={watch.id}>
                <div>
                  <a href={folderWatchDetailUrl(watch.id)}>
                    <strong>{watch.folderName}</strong>
                  </a>
                  <p>{reasonLabel(watch)}</p>
                </div>
                <AdminButton disabled={busy} onClick={() => runPrimary(watch)} size="small" variant="primary">
                  {primaryWatchAction(watch) === 'resume'
                    ? __('Resume', 'brasth-document-sync-for-google-docs')
                    : primaryWatchAction(watch) === 'scan'
                      ? __('Scan now', 'brasth-document-sync-for-google-docs')
                      : primaryWatchAction(watch) === 'fix'
                        ? __('Fix failures', 'brasth-document-sync-for-google-docs')
                        : __('Manage', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              </li>
            ))}
          </ul>
        </section>
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
            onChange={(event) => setHealthFilter(event.currentTarget.value as FolderWatchHealthFilter)}
            value={healthFilter}
          >
            {statusOptions.map((option) => (
              <option key={option.value || 'all'} value={option.value}>{option.label}</option>
            ))}
          </select>
          <AdminButton disabled={busy} onClick={onCreateWatch} variant="primary">
            {__('Watch a client folder', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
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
