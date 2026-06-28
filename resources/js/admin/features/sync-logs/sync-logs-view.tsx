import { speak } from '@wordpress/a11y';
import { createElement, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { clearSyncLogEntries, listSyncLogEntries, type SyncLogEntry } from '../../api';
import { getAdminConfig } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice, type AdminNoticeState } from '../../shared/ui/admin-notice';
import { SyncLogEventsTable } from './sync-log-events-table';
import { levelOptions, quickFilters, statusOptions, stepOptions, type SyncLogQuickFilter } from './sync-log-filter-options';
import { SyncLogSummaryStrip } from './sync-log-summary-strip';

const pageSize = 25;
const autoRefreshInterval = 10000;

type LogFilters = {
  level: string;
  postId: string;
  search: string;
  status: string;
  step: string;
};

const positivePage = (value: string | null): number => {
  const page = Number(value ?? '1');

  return Number.isInteger(page) && page > 0 ? page : 1;
};

const getInitialFilters = () => {
  const params = new URLSearchParams(window.location.search);

  return {
    level: params.get('level') ?? '',
    page: positivePage(params.get('log_page')),
    postId: params.get('post_id') ?? '',
    search: params.get('search') ?? '',
    status: params.get('status') ?? '',
    step: params.get('step') ?? ''
  };
};

const updateLocation = (filters: LogFilters, page: number) => {
  const params = new URLSearchParams();
  const postId = filters.postId.trim();
  const search = filters.search.trim();

  params.set('page', 'brasth-document-sync-for-google-docs-logs');

  if (postId) {
    params.set('post_id', postId);
  }

  if (search) {
    params.set('search', search);
  }

  if (filters.level) {
    params.set('level', filters.level);
  }

  if (filters.status) {
    params.set('status', filters.status);
  }

  if (filters.step) {
    params.set('step', filters.step);
  }

  if (page > 1) {
    params.set('log_page', String(page));
  }

  window.history.replaceState(null, '', `admin.php?${params.toString()}`);
};

const parsePostIdFilter = (postId: string): number | null | undefined => {
  const trimmedPostId = postId.trim();

  if (!trimmedPostId) {
    return undefined;
  }

  const parsedPostId = Number(trimmedPostId);

  return Number.isInteger(parsedPostId) && parsedPostId > 0 ? parsedPostId : null;
};

export const SyncLogsView = (): JSX.Element => {
  const config = useMemo(() => getAdminConfig(), []);
  const initialFilters = useMemo(getInitialFilters, []);
  const [entries, setEntries] = useState<SyncLogEntry[]>([]);
  const [postId, setPostId] = useState(initialFilters.postId);
  const [search, setSearch] = useState(initialFilters.search);
  const [level, setLevel] = useState(initialFilters.level);
  const [status, setStatus] = useState(initialFilters.status);
  const [step, setStep] = useState(initialFilters.step);
  const [page, setPage] = useState(initialFilters.page);
  const [hasMore, setHasMore] = useState(false);
  const [busy, setBusy] = useState(false);
  const [hasLoaded, setHasLoaded] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(false);
  const [showAdvancedFilters, setShowAdvancedFilters] = useState(Boolean(initialFilters.postId));
  const autoRefreshRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const hasActiveFilters = Boolean(postId.trim() || search.trim() || level || status || step);
  const currentFilters = (): LogFilters => ({
    level,
    postId,
    search,
    status,
    step
  });

  const loadEntries = async (nextPage = 1, nextFilters: LogFilters = currentFilters(), silent = false) => {
    const trimmedPostId = nextFilters.postId.trim();
    const trimmedSearch = nextFilters.search.trim();
    const parsedPostId = parsePostIdFilter(trimmedPostId);

    if (parsedPostId === null) {
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
        level: nextFilters.level,
        page: nextPage,
        perPage: pageSize,
        postId: parsedPostId,
        search: trimmedSearch,
        status: nextFilters.status,
        step: nextFilters.step
      });

      setEntries(response.entries);
      setHasMore(Boolean(response.has_more ?? response.hasMore));
      setPage(nextPage);
      updateLocation({ ...nextFilters, postId: trimmedPostId, search: trimmedSearch }, nextPage);
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
    void loadEntries(initialFilters.page, initialFilters);
  }, []);

  useEffect(() => {
    if (autoRefreshRef.current) {
      clearInterval(autoRefreshRef.current);
      autoRefreshRef.current = null;
    }

    if (autoRefresh) {
      autoRefreshRef.current = setInterval(() => {
        void loadEntries(page, currentFilters(), true);
      }, autoRefreshInterval);
    }

    return () => {
      if (autoRefreshRef.current) {
        clearInterval(autoRefreshRef.current);
        autoRefreshRef.current = null;
      }
    };
  }, [autoRefresh, page, postId, search, level, status, step]);

  const applyFilters = async () => {
    await loadEntries(1, currentFilters());
  };

  const resetFilters = async () => {
    setPostId('');
    setSearch('');
    setLevel('');
    setStatus('');
    setStep('');
    setShowAdvancedFilters(false);
    await loadEntries(1, {
      level: '',
      postId: '',
      search: '',
      status: '',
      step: ''
    });
  };

  const applyQuickFilter = async (quickFilter: SyncLogQuickFilter) => {
    const nextFilters = {
      level: quickFilter.filters.level ?? '',
      postId,
      search: quickFilter.filters.search ?? '',
      status: quickFilter.filters.status ?? '',
      step: quickFilter.filters.step ?? ''
    };

    setLevel(nextFilters.level);
    setSearch(nextFilters.search);
    setStatus(nextFilters.status);
    setStep(nextFilters.step);
    await loadEntries(1, nextFilters);
  };

  const isQuickFilterActive = (quickFilter: SyncLogQuickFilter): boolean => {
    return (quickFilter.filters.level ?? '') === level
      && (quickFilter.filters.search ?? '') === search.trim()
      && (quickFilter.filters.status ?? '') === status
      && (quickFilter.filters.step ?? '') === step;
  };

  const clearLogs = async (scope: 'source' | 'all') => {
    const parsedPostId = parsePostIdFilter(postId);

    if (scope === 'source' && parsedPostId === null) {
      const message = __('Enter a valid source post ID before clearing source logs.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
      return;
    }

    const isSourceClear = scope === 'source' && typeof parsedPostId === 'number';
    const confirmed = window.confirm(
      isSourceClear
        ? __('Clear stored log events for this source? Linked Docs, sync status, credentials, and content will not be deleted.', 'brasth-document-sync-for-google-docs')
        : __('Clear all stored log events for sources you can edit? Linked Docs, sync status, credentials, and content will not be deleted.', 'brasth-document-sync-for-google-docs')
    );

    if (!confirmed) {
      return;
    }

    setBusy(true);
    setNotice(null);

    try {
      const response = await clearSyncLogEntries(isSourceClear ? parsedPostId : undefined);
      await loadEntries(1, currentFilters(), true);
      const message = sprintf(
        __('Cleared %d stored log events.', 'brasth-document-sync-for-google-docs'),
        response.cleared
      );
      setNotice({ type: 'success', message });
      speak(message);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not clear sync logs.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
      setHasLoaded(true);
    }
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
            <label className="docsync-wp-log-filters__search">
              <span>{__('Search logs', 'brasth-document-sync-for-google-docs')}</span>
              <input
                className="regular-text"
                onChange={(event) => setSearch(event.currentTarget.value)}
                placeholder={__('Post title, Google Doc, message, code, step, status, or post ID', 'brasth-document-sync-for-google-docs')}
                type="search"
                value={search}
              />
            </label>
            <label>
              <span>{__('Level', 'brasth-document-sync-for-google-docs')}</span>
              <select onChange={(event) => setLevel(event.currentTarget.value)} value={level}>
                {levelOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <label>
              <span>{__('Status', 'brasth-document-sync-for-google-docs')}</span>
              <select onChange={(event) => setStatus(event.currentTarget.value)} value={status}>
                {statusOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <label>
              <span>{__('Step', 'brasth-document-sync-for-google-docs')}</span>
              <select onChange={(event) => setStep(event.currentTarget.value)} value={step}>
                {stepOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
              </select>
            </label>
            <div className="docsync-wp-log-filters__actions">
              <AdminButton disabled={busy} type="submit" variant="primary">{__('Apply filters', 'brasth-document-sync-for-google-docs')}</AdminButton>
              <AdminButton disabled={busy || !hasActiveFilters} onClick={resetFilters}>{__('Reset', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </div>
            <div className="docsync-wp-log-quick-filters" aria-label={__('Quick log filters', 'brasth-document-sync-for-google-docs')}>
              {quickFilters.map((quickFilter) => (
                <button
                  aria-pressed={isQuickFilterActive(quickFilter)}
                  className="button button-secondary"
                  disabled={busy}
                  key={quickFilter.id}
                  onClick={() => void applyQuickFilter(quickFilter)}
                  type="button"
                >
                  {quickFilter.label}
                </button>
              ))}
            </div>
            <div className="docsync-wp-log-advanced">
              <button
                aria-expanded={showAdvancedFilters}
                className="button-link"
                onClick={() => setShowAdvancedFilters(!showAdvancedFilters)}
                type="button"
              >
                {postId.trim()
                  ? __('Source filter active', 'brasth-document-sync-for-google-docs')
                  : __('Narrow by source post ID', 'brasth-document-sync-for-google-docs')}
              </button>
              {showAdvancedFilters ? (
                <label>
                  <span>{__('Source post ID', 'brasth-document-sync-for-google-docs')}</span>
                  <input
                    className="small-text"
                    inputMode="numeric"
                    onChange={(event) => setPostId(event.currentTarget.value)}
                    type="number"
                    value={postId}
                  />
                </label>
              ) : null}
            </div>
          </form>

          <SyncLogSummaryStrip entries={entries} />

          <div className="docsync-wp-log-clear-actions">
            <span>{__('Clears stored events only. Source links, sync status, credentials, progress, and synced content stay intact.', 'brasth-document-sync-for-google-docs')}</span>
            <div>
              {postId.trim() ? (
                <AdminButton disabled={busy} onClick={() => clearLogs('source')} variant="delete">
                  {__('Clear source logs', 'brasth-document-sync-for-google-docs')}
                </AdminButton>
              ) : null}
              <AdminButton disabled={busy} onClick={() => clearLogs('all')} variant="delete">
                {__('Clear all logs', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            </div>
          </div>

          <SyncLogEventsTable
            busy={busy}
            entries={entries}
            hasActiveFilters={hasActiveFilters}
            hasLoaded={hasLoaded}
            level={level}
            postId={postId}
            search={search}
            status={status}
            step={step}
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
