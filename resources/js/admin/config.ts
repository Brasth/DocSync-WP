export type AvailablePostType = {
  name: string;
  label: string;
};

export type DocSyncWPAdminConfig = {
  restUrl: string;
  nonce: string;
  pluginUrl: string;
  version: string;
  currentUserId: number;
  clientId: string;
  pickerApiKey: string;
  pickerAppId: string;
  connectionMode: string;
  enabledPostTypes: string[];
  availablePostTypes: AvailablePostType[];
  defaultExportFormat: string;
  syncInterval: string;
  hasClientId: boolean;
  hasClientSecret: boolean;
  hasPickerApiKey: boolean;
  hasPickerAppId: boolean;
  hasPickerSettings: boolean;
  hasRequiredSettings: boolean;
};

declare global {
  interface Window {
    DocSyncWPAdmin?: DocSyncWPAdminConfig;
  }
}

const fallbackConfig: DocSyncWPAdminConfig = {
  restUrl: '',
  nonce: '',
  pluginUrl: '',
  version: '0.1.0',
  currentUserId: 0,
  clientId: '',
  pickerApiKey: '',
  pickerAppId: '',
  connectionMode: 'self_managed',
  enabledPostTypes: ['post'],
  availablePostTypes: [{ name: 'post', label: 'Post' }],
  defaultExportFormat: 'markdown',
  syncInterval: 'off',
  hasClientId: false,
  hasClientSecret: false,
  hasPickerApiKey: false,
  hasPickerAppId: false,
  hasPickerSettings: false,
  hasRequiredSettings: false
};

export const getAdminConfig = (): DocSyncWPAdminConfig => {
  return window.DocSyncWPAdmin ?? fallbackConfig;
};
