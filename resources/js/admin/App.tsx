import { createElement, Fragment, useEffect, useMemo, useState } from '@wordpress/element';

import {
  disconnectGoogleAccount,
  getGoogleAccount,
  getGoogleAuthUrl,
  getSettings,
  listSources,
  saveSettings,
  syncAllSources,
  syncSource,
  type GoogleAccount,
  type SettingsResponse,
  type SourceRecord
} from './api';
import { AccountPanel } from './components/AccountPanel';
import { SettingsPanel } from './components/SettingsPanel';
import { SourcesTable, type SourceListFilters } from './components/SourcesTable';
import { getAdminConfig } from './config';

type Notice = {
  type: 'success' | 'error';
  message: string;
};

const emptyAccount: GoogleAccount = { connected: false };
const sourcePageSize = 100;
const defaultSourceFilters: SourceListFilters = { search: '', postType: '', status: '' };

type AdminView = 'setup' | 'sources';

export const App = ({ view }: { view: AdminView }): JSX.Element => {
  const config = useMemo(() => getAdminConfig(), []);
  const redirectUri = useMemo(() => `${config.restUrl.replace(/\/$/, '')}/oauth/google/callback`, [config.restUrl]);
  const javascriptOrigin = useMemo(() => window.location.origin, []);
  const [settings, setSettings] = useState<SettingsResponse | null>(null);
  const [account, setAccount] = useState<GoogleAccount>(emptyAccount);
  const [sources, setSources] = useState<SourceRecord[]>([]);
  const [sourcePage, setSourcePage] = useState(1);
  const [sourceFilters, setSourceFilters] = useState<SourceListFilters>(defaultSourceFilters);
  const [hasMoreSources, setHasMoreSources] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);

  const refreshSetup = async () => {
    const [settingsResponse, accountResponse] = await Promise.all([
      getSettings(),
      getGoogleAccount()
    ]);

    setSettings(settingsResponse);
    setAccount(accountResponse);
  };

  const refreshSources = async (filters = sourceFilters, page = 1, append = false) => {
    const [settingsResponse, sourcesResponse] = await Promise.all([
      getSettings(),
      listSources({
        ...filters,
        page,
        perPage: sourcePageSize
      })
    ]);

    setSettings(settingsResponse);
    setSourceFilters(filters);
    setSources((current) => append ? [...current, ...sourcesResponse.sources] : sourcesResponse.sources);
    setSourcePage(page);
    setHasMoreSources(Boolean(sourcesResponse.has_more ?? sourcesResponse.hasMore));
  };

  const refresh = async () => {
    if (view === 'sources') {
      await refreshSources(sourceFilters, 1);
      return;
    }

    await refreshSetup();
  };

  const loadMoreSources = async () => {
    await runAction(async () => {
      const nextPage = sourcePage + 1;
      await refreshSources(sourceFilters, nextPage, true);
    });
  };

  useEffect(() => {
    refresh().catch((caught) => {
      setNotice({ type: 'error', message: caught instanceof Error ? caught.message : 'Could not load DocSync WP.' });
    });
  }, [view]);

  const runAction = async (action: () => Promise<void>) => {
    setBusy(true);
    setNotice(null);

    try {
      await action();
    } catch (caught) {
      setNotice({ type: 'error', message: caught instanceof Error ? caught.message : 'Action failed.' });
    } finally {
      setBusy(false);
    }
  };

  const connectGoogle = async () => {
    if (!settings?.hasRequiredSettings) {
      setNotice({ type: 'error', message: 'Save OAuth client ID and client secret before connecting Google.' });
      return;
    }

    await runAction(async () => {
      const response = await getGoogleAuthUrl();
      window.location.assign(response.authUrl);
    });
  };

  const disconnectGoogle = async () => {
    await runAction(async () => {
      await disconnectGoogleAccount();
      await refresh();
      setNotice({ type: 'success', message: 'Google account disconnected.' });
    });
  };

  const persistSettings = async (nextSettings: Partial<SettingsResponse> & { clientSecret?: string }) => {
    await runAction(async () => {
      const saved = await saveSettings(nextSettings);
      setSettings(saved);
      setNotice({ type: 'success', message: 'Settings saved.' });
    });
  };

  const syncOne = async (postId: number) => {
    await runAction(async () => {
      const result = await syncSource(postId);
      await refresh();
      setNotice({ type: 'success', message: `Source ${postId} sync ${result.status}.` });
    });
  };

  const syncAll = async () => {
    await runAction(async () => {
      const result = await syncAllSources();
      await refresh();
      setNotice({ type: 'success', message: `Sync attempted for ${result.count} source(s).` });
    });
  };

  const applySourceFilters = async (filters: SourceListFilters) => {
    await runAction(async () => {
      await refreshSources(filters, 1);
    });
  };

  return (
    <main className="docsync-wp-admin-shell">
      <header className="docsync-wp-hero">
        <div>
          <p>DocSync WP</p>
          <h1>{view === 'sources' ? 'Sources' : 'Google Setup'}</h1>
          <span>Version {config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          {view === 'sources' ? (
            <>
              <strong>{sources.length}</strong>
              <span>shown source{sources.length === 1 ? '' : 's'}</span>
            </>
          ) : (
            <>
              <strong>{settings?.hasRequiredSettings ? 'Ready' : 'Setup'}</strong>
              <span>Google connection</span>
            </>
          )}
        </div>
      </header>

      {notice ? <div className={`notice notice-${notice.type}`}><p>{notice.message}</p></div> : null}

      {!settings ? (
        <section className="docsync-wp-card"><p>Loading settings...</p></section>
      ) : (
        view === 'sources' ? (
          <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
            <div className="docsync-wp-admin-grid__main">
              <SourcesTable
                availablePostTypes={settings.availablePostTypes.filter((postType) => settings.enabledPostTypes.includes(postType.name))}
                busy={busy}
                filters={sourceFilters}
                hasMore={hasMoreSources}
                onFiltersChange={applySourceFilters}
                onLoadMore={loadMoreSources}
                onRefresh={() => runAction(refresh)}
                onSync={syncOne}
                onSyncAll={syncAll}
                sources={sources}
              />
            </div>
          </div>
        ) : (
          <div className="docsync-wp-admin-grid">
            <div className="docsync-wp-admin-grid__main">
              <SettingsPanel
                busy={busy}
                javascriptOrigin={javascriptOrigin}
                onSave={persistSettings}
                redirectUri={redirectUri}
                settings={settings}
              />
            </div>
            <aside className="docsync-wp-admin-grid__side">
              <AccountPanel
                account={account}
                busy={busy}
                canConnect={settings.hasRequiredSettings}
                onConnect={connectGoogle}
                onDisconnect={disconnectGoogle}
                pickerReady={settings.hasPickerSettings}
              />
              <section className="docsync-wp-card">
                <h2>Connection mode</h2>
                <ul>
                  <li>Current mode: self-managed Google Cloud app.</li>
                  <li>Each WordPress user connects their own Google account.</li>
                  <li>Managed connector support can be added later without proxying document content.</li>
                </ul>
              </section>
            </aside>
          </div>
        )
      )}
    </main>
  );
};
