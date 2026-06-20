import { __ } from '@wordpress/i18n';

import type { SettingsResponse } from '../../api';

export type SetupCheck = {
  id: string;
  label: string;
  description: string;
  complete: boolean;
};

export const googleCloudLinks = [
  { href: 'https://console.cloud.google.com/apis/library/drive.googleapis.com', label: __('Enable Drive API', 'brasth-document-sync-for-google-docs') },
  { href: 'https://console.cloud.google.com/apis/library/docs.googleapis.com', label: __('Enable Docs API', 'brasth-document-sync-for-google-docs') },
  { href: 'https://console.cloud.google.com/apis/credentials/consent', label: __('OAuth consent', 'brasth-document-sync-for-google-docs') },
  { href: 'https://console.cloud.google.com/apis/credentials', label: __('Credentials', 'brasth-document-sync-for-google-docs') }
];

export const samePostTypes = (left: string[], right: string[]): boolean => {
  return [...left].sort().join('|') === [...right].sort().join('|');
};

export const buildSetupChecks = (settings: SettingsResponse): SetupCheck[] => [
  {
    id: 'client-id',
    label: __('OAuth client ID', 'brasth-document-sync-for-google-docs'),
    description: __('Saved from a Google OAuth web application client.', 'brasth-document-sync-for-google-docs'),
    complete: settings.hasClientId
  },
  {
    id: 'client-secret',
    label: __('OAuth client secret', 'brasth-document-sync-for-google-docs'),
    description: __('Stored encrypted in WordPress options.', 'brasth-document-sync-for-google-docs'),
    complete: settings.hasClientSecret
  }
];
