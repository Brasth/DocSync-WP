import { speak } from '@wordpress/a11y';
import { createElement, useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { clearSyncLogEntries, listSyncLogEntries, type SyncLogEntry } from '../../api';
import { getAdminConfig } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminShell } from '../../shared/ui/admin-shell';
import { type AdminNoticeState } from '../../shared/ui/admin-notice';
import { SyncLogEventsTable } from './sync-log-events-table';
import { levelOptions, statusOptions, stepOptions } from './sync-log-filter-options';
import { SyncLogManageDialog } from './sync-log-manage-dialog';
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

type LogView = {
  id: 'all' | 'needs-attention' | 'large-doc-fallback' | 'stalled-cron';
  label: string;
  filters: Pick<LogFilters, 'level' | 'search' | 'status' | 'step'>;
};

const logViews: LogView[] = [
  {
    id: 'all',
    label: __('All events', 'brasth-document-sync-for-google-docs'),
    filters: { level: '', search: '', status: '', step: '' }
  },
  {
    id: 'needs-attention',
    label: __('Needs attention', 'brasth-document-sync-for-google-docs'),
    filters: { level: 'error', search: '', status: '', step: '' }
  },
  {
    id: 'stalled-cron',
    label: __('Stalled / WP-Cron', 'brasth-document-sync-for-google-docs'),
    filters: { level: '', search: 'docsync_wp_sync_stalled', status: '', step: '' }
  },
  {
    id: 'large-doc-fallback',
    label: __('Large doc fallback', 'brasth-document-sync-for-google-docs'),
    filters: { level: '', search: '', status: '', step: 'large_doc_fallback' }
  }
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
  const [manageLogsOpen, setManageLogsOpen] = useState(false);
  const autoRefreshRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const hasActiveFilters = Boolean(postId.trim() || search.trim() || level || status || step);
  const parsedPostId = parsePostIdFilter(postId);
  const canClearSourceLogs = typeof parsedPostId === 'number';
  const canManageLogs = hasLoaded && (entries.length > 0 || hasActiveFilters);
  const showPagination = !hasLoaded || entries.length > 0 || page > 1 || hasMore;
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

  const applyLogView = async (logView: LogView) => {
    const nextFilters = {
      level: logView.filters.level,
      postId,
      search: logView.filters.search,
      status: logView.filters.status,
      step: logView.filters.step
    };

    setLevel(nextFilters.level);
    setSearch(nextFilters.search);
    setStatus(nextFilters.status);
    setStep(nextFilters.step);
    await loadEntries(1, nextFilters);
  };

  const isLogViewActive = (logView: LogView): boolean => {
    return logView.filters.level === level
      && logView.filters.search === search.trim()
      && logView.filters.status === status
      && logView.filters.step === step;
  };

  const clearLogs = async (scope: 'source' | 'all') => {
    if (scope === 'source' && parsedPostId === null) {
      const message = __('Enter a valid source post ID before clearing source logs.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
      return;
    }

    const isSourceClear = scope === 'source' && typeof parsedPostId === 'number';

    setBusy(true);
    setNotice(null);

    try {
      const response = await clearSyncLogEntries(isSourceClear ? parsedPostId : undefined);
      await loadEntries(1, currentFilters(), true);
      const message = response.cleared === 0
        ? __('No stored log events found to clear.', 'brasth-document-sync-for-google-docs')
        : sprintf(
          __('Cleared %d stored log events.', 'brasth-document-sync-for-google-docs'),
          response.cleared
        );
      setNotice({ type: response.cleared === 0 ? 'info' : 'success', message });
      setManageLogsOpen(false);
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
    <AdminShell
      notice={notice}
      status={{
        label: autoRefresh ? __('auto-refresh on', 'brasth-document-sync-for-google-docs') : __('visible events', 'brasth-document-sync-for-google-docs'),
        value: autoRefresh ? __('Live', 'brasth-document-sync-for-google-docs') : entries.length
      }}
      title={__('Sync Activity', 'brasth-document-sync-for-google-docs')}
      version={config.version}
    >
      <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
        <section className="docsync-wp-card docsync-wp-card--wide">
          <div className="docsync-wp-card__header docsync-wp-card__header--row">
            <div>
              <h2>{__('Activity console', 'brasth-document-sync-for-google-docs')}</h2>
              <p>{__('Search source events, switch troubleshooting views, and inspect recovery hints.', 'brasth-document-sync-for-google-docs')}</p>
            </div>
            {canManageLogs ? (
              <AdminButton disabled={busy} onClick={() => setManageLogsOpen(true)} size="small">
                {__('Manage logs', 'brasth-document-sync-for-google-docs')}
              </AdminButton>
            ) : null}
          </div>

          <form className="docsync-wp-log-console" onSubmit={(event) => {
            event.preventDefault();
            void applyFilters();
          }}>
            <div className="docsync-wp-log-command-bar">
              <label className="docsync-wp-log-search">
                <span>{__('Search sync activity', 'brasth-document-sync-for-google-docs')}</span>
                <input
                  className="regular-text"
                  onChange={(event) => setSearch(event.currentTarget.value)}
                  placeholder={__('Title, Google Doc, message, code, step, status, or post ID', 'brasth-document-sync-for-google-docs')}
                  type="search"
                  value={search}
                />
              </label>
              <div className="docsync-wp-log-command-actions">
                <AdminButton disabled={busy} type="submit" variant="primary">{__('Search', 'brasth-document-sync-for-google-docs')}</AdminButton>
                <AdminButton disabled={busy || !hasActiveFilters} onClick={resetFilters}>{__('Reset', 'brasth-document-sync-for-google-docs')}</AdminButton>
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

            <div className="docsync-wp-log-viewbar" aria-label={__('Sync activity views', 'brasth-document-sync-for-google-docs')}>
              <span>{__('View', 'brasth-document-sync-for-google-docs')}</span>
              {logViews.map((logView) => (
                <button
                  aria-pressed={isLogViewActive(logView)}
                  className="button button-secondary"
                  disabled={busy}
                  key={logView.id}
                  onClick={() => void applyLogView(logView)}
                  type="button"
                >
                  {logView.label}
                </button>
              ))}
              <button
                aria-expanded={showAdvancedFilters}
                className="button-link docsync-wp-log-advanced-toggle"
                onClick={() => setShowAdvancedFilters(!showAdvancedFilters)}
                type="button"
              >
                {postId.trim()
                  ? __('Advanced filters active', 'brasth-document-sync-for-google-docs')
                  : __('Advanced filters', 'brasth-document-sync-for-google-docs')}
              </button>
            </div>

            <div className="docsync-wp-log-advanced-panel" hidden={!showAdvancedFilters}>
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
              <AdminButton disabled={busy} type="submit">{__('Apply advanced filters', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </div>
          </form>

          <SyncLogSummaryStrip entries={entries} />

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

          {showPagination ? (
            <div className="docsync-wp-table-footer docsync-wp-logs-pagination">
              <AdminButton disabled={busy || page <= 1} onClick={() => loadEntries(page - 1)}>{__('Previous', 'brasth-document-sync-for-google-docs')}</AdminButton>
              <span>{sprintf(__('Page %d', 'brasth-document-sync-for-google-docs'), page)}</span>
              <AdminButton disabled={busy || !hasMore} onClick={() => loadEntries(page + 1)} variant="primary">{__('Next', 'brasth-document-sync-for-google-docs')}</AdminButton>
            </div>
          ) : null}
        </section>
      </div>

      <SyncLogManageDialog
        busy={busy}
        canClearSourceLogs={canClearSourceLogs}
        open={manageLogsOpen}
        parsedPostId={parsedPostId}
        postId={postId}
        onClear={clearLogs}
        onOpenChange={setManageLogsOpen}
      />
    </AdminShell>
  );
};
