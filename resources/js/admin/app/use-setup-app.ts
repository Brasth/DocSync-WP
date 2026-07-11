import { speak } from '@wordpress/a11y';
import { useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
  clearOAuthConfiguration,
  disconnectGoogleAccount,
  getGoogleAccount,
  getGoogleAuthUrl,
  getSettings,
  getWorkspace,
  saveSettings,
  syncSource,
  type GoogleAccount,
  type SettingsResponse,
  type SourceRecord,
  type SyncResult,
  type WorkspaceResponse
} from '../api';
import { getAdminConfig } from '../config';
import type { AdminNoticeState } from '../shared/ui/admin-notice';

const emptyAccount: GoogleAccount = { connected: false, hasRequiredScope: false };

export const useSetupApp = () => {
  const config = useMemo(() => getAdminConfig(), []);
  const redirectUri = useMemo(() => `${config.restUrl.replace(/\/$/, '')}/oauth/google/callback`, [config.restUrl]);
  const [settings, setSettings] = useState<SettingsResponse | null>(null);
  const [account, setAccount] = useState<GoogleAccount>(emptyAccount);
  const [workspace, setWorkspace] = useState<WorkspaceResponse | null>(null);
  const [activationSource, setActivationSource] = useState<SourceRecord | null>(null);
  const [sourceModalOpen, setSourceModalOpen] = useState(false);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);
  const sourceModalTrigger = useRef<HTMLElement | null>(null);
  const restoreModalFocus = useRef(true);

  const refresh = async () => {
    const [settingsResponse, accountResponse, workspaceResponse] = await Promise.all([
      getSettings(),
      getGoogleAccount(),
      getWorkspace()
    ]);

    setSettings(settingsResponse);
    setAccount(accountResponse);
    setWorkspace(workspaceResponse);
  };

  const runAction = async (action: () => Promise<void>) => {
    setBusy(true);
    setNotice(null);

    try {
      await action();
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Action failed.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const connectGoogle = async () => {
    if (!settings?.hasRequiredSettings) {
      const message = __('Save OAuth client ID and client secret before connecting Google.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
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
      const message = __('Google account disconnected.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'success', message });
      speak(message);
    });
  };

  const clearSavedOAuthConfiguration = async () => {
    let clearedSuccessfully = false;

    await runAction(async () => {
      const saved = await clearOAuthConfiguration();
      const message = __('Saved OAuth configuration cleared. All plugin users must reconnect after new credentials are saved.', 'brasth-document-sync-for-google-docs');

      setSettings(saved);
      setAccount(emptyAccount);
      setNotice({ type: 'success', message });
      speak(message);
      clearedSuccessfully = true;
    });

    return clearedSuccessfully;
  };

  const persistSettings = async (nextSettings: Partial<SettingsResponse> & { clientSecret?: string }) => {
    let savedSuccessfully = false;

    await runAction(async () => {
      const saved = await saveSettings(nextSettings);
      const message = __('Settings saved.', 'brasth-document-sync-for-google-docs');
      setSettings(saved);
      setNotice({ type: 'success', message });
      speak(message);
      savedSuccessfully = true;
    });

    return savedSuccessfully;
  };

  const handleSourceCreated = (result: SyncResult) => {
    if (result.source) {
      setActivationSource(result.source);
      restoreModalFocus.current = !['synced', 'skipped', 'error'].includes(result.source.syncStatus);
    }
  };

  const handleActivationSourceStatus = (source: SourceRecord) => {
    setActivationSource(source);
  };

  const handleActivationSourceTerminal = (source: SourceRecord) => {
    setActivationSource(source);
    void getWorkspace().then(setWorkspace).catch((caught) => {
      const message = caught instanceof Error ? caught.message : __('Sync completed, but activation status could not refresh.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'warning', message });
    });
  };

  const handleActivationSourceTimeout = () => {
    const message = __('This sync is taking longer than expected. WordPress content remains unchanged until sync completes. Check Sources later; low-traffic sites may need an administrator to verify WP-Cron.', 'brasth-document-sync-for-google-docs');
    setNotice({ type: 'warning', message });
    speak(message);
  };

  const handleActivationPollingError = (message: string) => {
    setNotice({ type: 'warning', message });
    speak(message);
  };

  const retryActivationSource = async () => {
    if (!activationSource) {
      return;
    }

    await runAction(async () => {
      const result = await syncSource(activationSource.postId, 'background');
      const source = result.source ?? activationSource;
      const message = source.syncMessage || __('Source sync queued.', 'brasth-document-sync-for-google-docs');
      setActivationSource(source);
      setNotice({ type: 'info', message });
      speak(message);
    });
  };

  const openSourceModal = () => {
    sourceModalTrigger.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    restoreModalFocus.current = true;
    setSourceModalOpen(true);
  };

  const closeSourceModal = () => {
    setSourceModalOpen(false);

    if (restoreModalFocus.current) {
      window.setTimeout(() => sourceModalTrigger.current?.focus(), 0);
    }

    restoreModalFocus.current = true;
  };

  return {
    account,
    activationSource,
    busy,
    clearSavedOAuthConfiguration,
    config,
    connectGoogle,
    disconnectGoogle,
    notice,
    closeSourceModal,
    openSourceModal,
    handleActivationSourceStatus,
    handleActivationSourceTerminal,
    handleActivationSourceTimeout,
    handleActivationPollingError,
    handleSourceCreated,
    persistSettings,
    retryActivationSource,
    redirectUri,
    refresh,
    runAction,
    settings,
    sourceModalOpen,
    workspace
  };
};
