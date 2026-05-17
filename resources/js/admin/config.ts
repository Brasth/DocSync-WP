export type DocSyncWPAdminConfig = {
  restUrl: string;
  nonce: string;
  pluginUrl: string;
  version: string;
  currentUserId: number;
  enabledPostTypes: string[];
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
  enabledPostTypes: ['post'],
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
