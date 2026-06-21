import { speak } from '@wordpress/a11y';
import { createElement, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { listSyncLogEntries, type SyncLogEntry } from '../../api';
import { getAdminConfig } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice, type AdminNoticeState } from '../../shared/ui/admin-notice';
import { SyncLogEventsTable } from './sync-log-events-table';

const pageSize = 25;
const autoRefreshInterval = 10000;

const levelOptions = [
  { value: '', label: __('All levels', 'brasth-document-sync-for-google-docs') },
  { value: 'info', label: __('Info', 'brasth-document-sync-for-google-docs') },
  { value: 'warning', label: __('Warning', 'brasth-document-sync-for-google-docs') },
  { value: 'error', label: __('Error', 'brasth-document-sync-for-google-docs') }
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

  params.set('page', 'brasth-document-sync-for-google-docs-logs');

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
  const [hasLoaded, setHasLoaded] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(false);
  const autoRefreshRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const hasActiveFilters = Boolean(postId.trim() || level);

  const loadEntries = async (nextPage = 1, nextPostId = postId, nextLevel = level, silent = false) => {
    const trimmedPostId = nextPostId.trim();
    const parsedPostId = trimmedPostId ? Number(trimmedPostId) : 0;

    if (trimmedPostId && (!Number.isInteger(parsedPostId) || parsedPostId <= 0)) {
      const message = __('Enter a valid source post ID.', 'brasth-document-sync-for-google-docs');
      setEntries([]);
      setHasMore(false);
      setHasLoaded(true);
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
      return;
    }

    if (!silent) {
      setBusy(true);
      setNotice(null);
    }

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
      if (!silent) {
        const message = caught instanceof Error ? caught.message : __('Could not load sync logs.', 'brasth-document-sync-for-google-docs');
        setEntries([]);
        setHasMore(false);
        setNotice({ type: 'error', message });
        speak(message, 'assertive');
      }
    } finally {
      if (!silent) {
        setBusy(false);
        setHasLoaded(true);
      }
    }
  };

  useEffect(() => {
    void loadEntries(initialFilters.page);
  }, []);

  useEffect(() => {
    if (autoRefreshRef.current) {
      clearInterval(autoRefreshRef.current);
      autoRefreshRef.current = null;
    }

    if (autoRefresh) {
      autoRefreshRef.current = setInterval(() => {
        void loadEntries(page, postId, level, true);
      }, autoRefreshInterval);
    }

    return () => {
      if (autoRefreshRef.current) {
        clearInterval(autoRefreshRef.current);
        autoRefreshRef.current = null;
      }
    };
  }, [autoRefresh, page, postId, level]);

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
          <p>{__('Brasth Document Sync', 'brasth-document-sync-for-google-docs')}</p>
          <h1>{__('Sync Logs', 'brasth-document-sync-for-google-docs')}</h1>
          <span>{__('Version', 'brasth-document-sync-for-google-docs')} {config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          <strong>{entries.length}</strong>
          <span>{entries.length === 1 ? __('event on this page', 'brasth-document-sync-for-google-docs') : __('events on this page', 'brasth-document-sync-for-google-docs')}</span>
        </div>
      </header>

      <AdminNotice notice={notice} />

      <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
        <section className="docsync-wp-card docsync-wp-card--wide">
          <div className="docsync-wp-card__header docsync-wp-card__header--row">
            <div>
              <h2>{__('Logs', 'brasth-document-sync-for-google-docs')}</h2>
              <p>{__('Diagnostic sync events stored per linked source.', 'brasth-document-sync-for-google-docs')}</p>
            </div>
            <div className="docsync-wp-actions-row">
              <div className="docsync-wp-auto-refresh">
                <button
                  aria-label={__('Auto-refresh every 10 seconds', 'brasth-document-sync-for-google-docs')}
                  aria-pressed={autoRefresh}
                  className="docsync-wp-auto-refresh-toggle"
                  onClick={() => setAutoRefresh(!autoRefresh)}
                  type="button"
                />
                {autoRefresh ? <span aria-hidden="true" className="docsync-wp-auto-refresh-indicator" /> : null}
                <span>{__('Auto-refresh', 'brasth-document-sync-for-google-docs')}</span>
              </div>
              <AdminButton disabled={busy} onClick={() => loadEntries(page)}>{__('Refresh', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </div>
          </div>

          <form className="docsync-wp-log-filters" onSubmit={(event) => {
            event.preventDefault();
            void applyFilters();
          }}>
            <label>
              <span>{__('Source post ID', 'brasth-document-sync-for-google-docs')}</span>
              <input className="small-text" inputMode="numeric" onChange={(event) => setPostId(event.currentTarget.value)} type="number" value={postId} />
            </label>
            <label>
              <span>{__('Level', 'brasth-document-sync-for-google-docs')}</span>
              <select onChange={(event) => setLevel(event.currentTarget.value)} value={level}>
                {levelOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <div className="docsync-wp-log-filters__actions">
              <AdminButton disabled={busy} type="submit" variant="primary">{__('Apply filters', 'brasth-document-sync-for-google-docs')}</AdminButton>
              <AdminButton disabled={busy || (!postId && !level)} onClick={resetFilters}>{__('Reset', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </div>
          </form>

          <SyncLogEventsTable
            busy={busy}
            entries={entries}
            hasActiveFilters={hasActiveFilters}
            hasLoaded={hasLoaded}
            level={level}
            postId={postId}
          />

          <div className="docsync-wp-table-footer docsync-wp-logs-pagination">
            <AdminButton disabled={busy || page <= 1} onClick={() => loadEntries(page - 1)}>{__('Previous', 'brasth-document-sync-for-google-docs')}</AdminButton>
            <span>{sprintf(__('Page %d', 'brasth-document-sync-for-google-docs'), page)}</span>
            <AdminButton disabled={busy || !hasMore} onClick={() => loadEntries(page + 1)} variant="primary">{__('Next', 'brasth-document-sync-for-google-docs')}</AdminButton>
          </div>
        </section>
      </div>
    </main>
  );
};
