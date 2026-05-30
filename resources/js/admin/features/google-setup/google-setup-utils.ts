import type { SettingsResponse } from '../../api';

export type SetupCheck = {
  id: string;
  label: string;
  description: string;
  complete: boolean;
};

export const googleCloudLinks = [
  { href: 'https://console.cloud.google.com/apis/library/drive.googleapis.com', label: 'Enable Drive API' },
  { href: 'https://console.cloud.google.com/apis/library/docs.googleapis.com', label: 'Enable Docs API' },
  { href: 'https://console.cloud.google.com/apis/credentials/consent', label: 'OAuth consent' },
  { href: 'https://console.cloud.google.com/apis/credentials', label: 'Credentials' }
];

export const samePostTypes = (left: string[], right: string[]): boolean => {
  return [...left].sort().join('|') === [...right].sort().join('|');
};

export const buildSetupChecks = (settings: SettingsResponse): SetupCheck[] => [
  {
    id: 'client-id',
    label: 'OAuth client ID',
    description: 'Saved from a Google OAuth web application client.',
    complete: settings.hasClientId
  },
  {
    id: 'client-secret',
    label: 'OAuth client secret',
    description: 'Stored encrypted in WordPress options.',
    complete: settings.hasClientSecret
  }
];
