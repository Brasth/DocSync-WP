import { speak } from '@wordpress/a11y';
import { createElement, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { listSyncLogEntries, type SyncLogEntry } from '../../api';
import { getAdminConfig } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice, type AdminNoticeState } from '../../shared/ui/admin-notice';
import { SyncLogEventsTable } from './sync-log-events-table';

const pageSize = 25;

const levelOptions = [
  { value: '', label: __('All levels', 'docsync-wp') },
  { value: 'info', label: __('Info', 'docsync-wp') },
  { value: 'warning', label: __('Warning', 'docsync-wp') },
  { value: 'error', label: __('Error', 'docsync-wp') }
];

const positivePage = (value: string | null): number => {
  const page = Number(value ?? '1');

  return Number.isInteger(page) && page > 0 ? page : 1;
};

const getInitialFilters = () => {
  const params = new URLSearchParams(window.location.search);

  return {
    level: params.get('level') ?? '',
    page: positivePage(params.get('log_page')),
    postId: params.get('post_id') ?? ''
  };
};

const updateLocation = (postId: string, level: string, page: number) => {
  const params = new URLSearchParams();

  params.set('page', 'docsync-wp-logs');

  if (postId.trim()) {
    params.set('post_id', postId.trim());
  }

  if (level) {
    params.set('level', level);
  }

  if (page > 1) {
    params.set('log_page', String(page));
  }

  window.history.replaceState(null, '', `admin.php?${params.toString()}`);
};

export const SyncLogsView = (): JSX.Element => {
  const config = useMemo(() => getAdminConfig(), []);
  const initialFilters = useMemo(getInitialFilters, []);
  const [entries, setEntries] = useState<SyncLogEntry[]>([]);
  const [postId, setPostId] = useState(initialFilters.postId);
  const [level, setLevel] = useState(initialFilters.level);
  const [page, setPage] = useState(initialFilters.page);
  const [hasMore, setHasMore] = useState(false);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);

  const loadEntries = async (nextPage = 1, nextPostId = postId, nextLevel = level) => {
    const trimmedPostId = nextPostId.trim();
    const parsedPostId = trimmedPostId ? Number(trimmedPostId) : 0;

    if (trimmedPostId && (!Number.isInteger(parsedPostId) || parsedPostId <= 0)) {
      const message = __('Enter a valid source post ID.', 'docsync-wp');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
      return;
    }

    setBusy(true);
    setNotice(null);

    try {
      const response = await listSyncLogEntries({
        level: nextLevel,
        page: nextPage,
        perPage: pageSize,
        postId: parsedPostId || undefined
      });

      setEntries(response.entries);
      setHasMore(Boolean(response.has_more ?? response.hasMore));
      setPage(nextPage);
      updateLocation(trimmedPostId, nextLevel, nextPage);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not load sync logs.', 'docsync-wp');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  useEffect(() => {
    void loadEntries(initialFilters.page);
  }, []);

  const applyFilters = async () => {
    await loadEntries(1);
  };

  const resetFilters = async () => {
    setPostId('');
    setLevel('');
    await loadEntries(1, '', '');
  };

  return (
    <main className="docsync-wp-admin-shell">
      <header className="docsync-wp-hero">
        <div>
          <p>{__('DocSync WP', 'docsync-wp')}</p>
          <h1>{__('Sync Logs', 'docsync-wp')}</h1>
          <span>{__('Version', 'docsync-wp')} {config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          <strong>{entries.length}</strong>
          <span>{entries.length === 1 ? __('shown event', 'docsync-wp') : __('shown events', 'docsync-wp')}</span>
        </div>
      </header>

      <AdminNotice notice={notice} />

      <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
        <section className="docsync-wp-card docsync-wp-card--wide">
          <div className="docsync-wp-card__header docsync-wp-card__header--row">
            <div>
              <h2>{__('Logs', 'docsync-wp')}</h2>
              <p>{__('Diagnostic sync events stored per linked source.', 'docsync-wp')}</p>
            </div>
            <AdminButton disabled={busy} onClick={() => loadEntries(page)}>{__('Refresh', 'docsync-wp')}</AdminButton>
          </div>

          <form className="docsync-wp-log-filters" onSubmit={(event) => {
            event.preventDefault();
            void applyFilters();
          }}>
            <label>
              <span>{__('Source post ID', 'docsync-wp')}</span>
              <input className="small-text" inputMode="numeric" onChange={(event) => setPostId(event.currentTarget.value)} type="number" value={postId} />
            </label>
            <label>
              <span>{__('Level', 'docsync-wp')}</span>
              <select onChange={(event) => setLevel(event.currentTarget.value)} value={level}>
                {levelOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <div className="docsync-wp-log-filters__actions">
              <AdminButton disabled={busy} type="submit" variant="primary">{__('Apply filters', 'docsync-wp')}</AdminButton>
              <AdminButton disabled={busy || (!postId && !level)} onClick={resetFilters}>{__('Reset', 'docsync-wp')}</AdminButton>
            </div>
          </form>

          <SyncLogEventsTable entries={entries} />

          <div className="docsync-wp-table-footer docsync-wp-logs-pagination">
            <AdminButton disabled={busy || page <= 1} onClick={() => loadEntries(page - 1)}>{__('Previous', 'docsync-wp')}</AdminButton>
            <span>{sprintf(__('Page %d', 'docsync-wp'), page)}</span>
            <AdminButton disabled={busy || !hasMore} onClick={() => loadEntries(page + 1)}>{__('Next', 'docsync-wp')}</AdminButton>
          </div>
        </section>
      </div>
    </main>
  );
};
