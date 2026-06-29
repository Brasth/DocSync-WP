export type AvailablePostType = {
  name: string;
  label: string;
};

export type AvailableLayoutPreset = {
  id: string;
  label: string;
  description: string;
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
  defaultLayoutPreset: string;
  availableLayoutPresets: AvailableLayoutPreset[];
  syncInterval: string;
  hasClientId: boolean;
  hasClientSecret: boolean;
  hasRequiredSettings: boolean;
  createSyncedDraftUrl: string;
  docSourceModalStyleUrls: string[];
  driveBrowserScriptUrl: string;
  driveBrowserStyleUrls: string[];
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
  version: '1.1.1',
  currentUserId: 0,
  clientId: '',
  connectionMode: 'self_managed',
  enabledPostTypes: ['post'],
  availablePostTypes: [{ name: 'post', label: 'Post' }],
  defaultExportFormat: 'html_zip',
  defaultLayoutPreset: 'plain_blocks',
  availableLayoutPresets: [
    {
      id: 'plain_blocks',
      label: 'Plain Blocks',
      description: 'Compatibility mode for existing synced posts. Keeps output closest to earlier versions.'
    }
  ],
  syncInterval: 'off',
  hasClientId: false,
  hasClientSecret: false,
  hasRequiredSettings: false,
  createSyncedDraftUrl: 'edit.php',
  docSourceModalStyleUrls: [],
  driveBrowserScriptUrl: '',
  driveBrowserStyleUrls: []
};

export const getAdminConfig = (): DocSyncWPAdminConfig => {
  return window.DocSyncWPAdmin ?? fallbackConfig;
};
