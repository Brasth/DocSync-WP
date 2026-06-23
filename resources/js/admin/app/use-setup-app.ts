import { speak } from '@wordpress/a11y';
import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
  disconnectGoogleAccount,
  getGoogleAccount,
  getGoogleAuthUrl,
  getSettings,
  saveSettings,
  type GoogleAccount,
  type SettingsResponse
} from '../api';
import { getAdminConfig } from '../config';
import type { AdminNoticeState } from '../shared/ui/admin-notice';

const emptyAccount: GoogleAccount = { connected: false, hasRequiredScope: false };

export const useSetupApp = () => {
  const config = useMemo(() => getAdminConfig(), []);
  const redirectUri = useMemo(() => `${config.restUrl.replace(/\/$/, '')}/oauth/google/callback`, [config.restUrl]);
  const [settings, setSettings] = useState<SettingsResponse | null>(null);
  const [account, setAccount] = useState<GoogleAccount>(emptyAccount);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);

  const refresh = async () => {
    const [settingsResponse, accountResponse] = await Promise.all([
      getSettings(),
      getGoogleAccount()
    ]);

    setSettings(settingsResponse);
    setAccount(accountResponse);
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

  const persistSettings = async (nextSettings: Partial<SettingsResponse> & { clientSecret?: string }) => {
    await runAction(async () => {
      const saved = await saveSettings(nextSettings);
      const message = __('Settings saved.', 'brasth-document-sync-for-google-docs');
      setSettings(saved);
      setNotice({ type: 'success', message });
      speak(message);
    });
  };

  return {
    account,
    busy,
    config,
    connectGoogle,
    disconnectGoogle,
    notice,
    persistSettings,
    redirectUri,
    refresh,
    runAction,
    settings
  };
};
