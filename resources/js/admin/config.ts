export type DocSyncWPAdminConfig = {
  restUrl: string;
  nonce: string;
  pluginUrl: string;
  version: string;
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
  version: '0.1.0'
};

export const getAdminConfig = (): DocSyncWPAdminConfig => {
  return window.DocSyncWPAdmin ?? fallbackConfig;
};
