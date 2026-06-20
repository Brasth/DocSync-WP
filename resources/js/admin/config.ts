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
  connectionMode: string;
  enabledPostTypes: string[];
  availablePostTypes: AvailablePostType[];
  defaultExportFormat: string;
  syncInterval: string;
  hasClientId: boolean;
  hasClientSecret: boolean;
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
  version: '1.0.3',
  currentUserId: 0,
  clientId: '',
  connectionMode: 'self_managed',
  enabledPostTypes: ['post'],
  availablePostTypes: [{ name: 'post', label: 'Post' }],
  defaultExportFormat: 'html_zip',
  syncInterval: 'off',
  hasClientId: false,
  hasClientSecret: false,
  hasRequiredSettings: false
};

export const getAdminConfig = (): DocSyncWPAdminConfig => {
  return window.DocSyncWPAdmin ?? fallbackConfig;
};
