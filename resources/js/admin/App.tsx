import { createElement, useEffect, useMemo, useState } from '@wordpress/element';

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
import { SourcesTable } from './components/SourcesTable';
import { getAdminConfig } from './config';

type Notice = {
  type: 'success' | 'error';
  message: string;
};

const emptyAccount: GoogleAccount = { connected: false };
const sourcePageSize = 100;

export const App = (): JSX.Element => {
  const config = useMemo(() => getAdminConfig(), []);
  const [settings, setSettings] = useState<SettingsResponse | null>(null);
  const [account, setAccount] = useState<GoogleAccount>(emptyAccount);
  const [sources, setSources] = useState<SourceRecord[]>([]);
  const [sourcePage, setSourcePage] = useState(1);
  const [hasMoreSources, setHasMoreSources] = useState(false);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);

  const refresh = async () => {
    const [settingsResponse, accountResponse, sourcesResponse] = await Promise.all([
      getSettings(),
      getGoogleAccount(),
      listSources(undefined, 1, sourcePageSize)
    ]);

    setSettings(settingsResponse);
    setAccount(accountResponse);
    setSources(sourcesResponse.sources);
    setSourcePage(1);
    setHasMoreSources(Boolean(sourcesResponse.has_more ?? sourcesResponse.hasMore));
  };

  const loadMoreSources = async () => {
    await runAction(async () => {
      const nextPage = sourcePage + 1;
      const response = await listSources(undefined, nextPage, sourcePageSize);

      setSources((current) => [...current, ...response.sources]);
      setSourcePage(nextPage);
      setHasMoreSources(Boolean(response.has_more ?? response.hasMore));
    });
  };

  useEffect(() => {
    refresh().catch((caught) => {
      setNotice({ type: 'error', message: caught instanceof Error ? caught.message : 'Could not load DocSync WP.' });
    });
  }, []);

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
      setNotice({ type: 'success', message: `Post ${postId} sync ${result.status}.` });
    });
  };

  const syncAll = async () => {
    await runAction(async () => {
      const result = await syncAllSources();
      await refresh();
      setNotice({ type: 'success', message: `Sync attempted for ${result.count} source(s).` });
    });
  };

  return (
    <main className="docsync-wp-admin-shell">
      <header className="docsync-wp-hero">
        <div>
          <p>DocSync WP</p>
          <h1>Google Docs Sync Control</h1>
          <span>Version {config.version}</span>
        </div>
        <div className="docsync-wp-hero__status">
          <strong>{sources.length}</strong>
          <span>linked source{sources.length === 1 ? '' : 's'}</span>
        </div>
      </header>

      {notice ? <div className={`notice notice-${notice.type}`}><p>{notice.message}</p></div> : null}

      {!settings ? (
        <section className="docsync-wp-card"><p>Loading settings...</p></section>
      ) : (
        <div className="docsync-wp-admin-grid">
          <div className="docsync-wp-admin-grid__main">
            <SourcesTable
              busy={busy}
              hasMore={hasMoreSources}
              onLoadMore={loadMoreSources}
              onRefresh={() => runAction(refresh)}
              onSync={syncOne}
              onSyncAll={syncAll}
              sources={sources}
            />
            <SettingsPanel busy={busy} onSave={persistSettings} settings={settings} />
          </div>
          <aside className="docsync-wp-admin-grid__side">
            <AccountPanel account={account} busy={busy} onConnect={connectGoogle} onDisconnect={disconnectGoogle} />
            <section className="docsync-wp-card">
              <h2>Setup notes</h2>
              <ul>
                <li>Redirect URI: <code>{config.restUrl}/oauth/google/callback</code></li>
                <li>Required APIs: Google Drive API and Google Picker API.</li>
                <li>Pasted Docs must already be accessible to the connected app.</li>
              </ul>
            </section>
          </aside>
        </div>
      )}
    </main>
  );
};
