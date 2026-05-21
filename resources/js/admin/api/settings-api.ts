import { request } from './client';
import type { GoogleAccount, SettingsResponse } from './types';

export const getSettings = (): Promise<SettingsResponse> => request<SettingsResponse>('settings');

export const saveSettings = (settings: Partial<SettingsResponse> & { clientSecret?: string }): Promise<SettingsResponse> => {
  return request<SettingsResponse>('settings', {
    method: 'POST',
    data: settings
  });
};

export const getGoogleAccount = (): Promise<GoogleAccount> => request<GoogleAccount>('oauth/google/account');

export const disconnectGoogleAccount = (): Promise<{ disconnected: boolean }> => {
  return request<{ disconnected: boolean }>('oauth/google/account', { method: 'DELETE' });
};

export const getGoogleAuthUrl = (): Promise<{ authUrl: string }> => request<{ authUrl: string }>('oauth/google/url');
