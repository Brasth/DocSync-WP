import { request } from './client';
import {
  normalizeSettingsConnections,
  normalizeSettingsResponse,
  type GoogleAccount,
  type SettingsConnectionsResponse,
  type SettingsResponse,
  type SettingsUpdate
} from './types';

export const getSettings = async (): Promise<SettingsResponse> => {
  return normalizeSettingsResponse(await request<unknown>('settings'));
};

export const saveSettings = async (settings: SettingsUpdate): Promise<SettingsResponse> => {
  return normalizeSettingsResponse(await request<unknown>('settings', {
    method: 'POST',
    data: settings
  }));
};

export const clearOAuthConfiguration = async (): Promise<SettingsResponse> => {
  return normalizeSettingsResponse(await request<unknown>('settings/oauth-configuration', { method: 'DELETE' }));
};

export const getSettingsConnections = async (page = 1, perPage = 20): Promise<SettingsConnectionsResponse> => {
  const safePage = Number.isFinite(page) && page > 0 ? Math.floor(page) : 1;
  const safePerPage = Math.min(50, Math.max(1, Number.isFinite(perPage) ? Math.floor(perPage) : 20));
  const params = new URLSearchParams({
    page: String(safePage),
    perPage: String(safePerPage)
  });

  return normalizeSettingsConnections(await request<unknown>(`settings/connections?${params.toString()}`));
};

export const getGoogleAccount = (): Promise<GoogleAccount> => request<GoogleAccount>('oauth/google/account');

export const disconnectGoogleAccount = (): Promise<{ disconnected: boolean }> => {
  return request<{ disconnected: boolean }>('oauth/google/account', { method: 'DELETE' });
};

export const getGoogleAuthUrl = (): Promise<{ authUrl: string }> => request<{ authUrl: string }>('oauth/google/url');
