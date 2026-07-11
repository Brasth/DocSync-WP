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
  availableElementorLayoutPresets: AvailableLayoutPreset[];
  elementorSyncEnabled: boolean;
  elementorAvailable: boolean;
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
    DocSyncWPAdmin?: Partial<DocSyncWPAdminConfig>;
  }
}

const fallbackConfig: DocSyncWPAdminConfig = {
  restUrl: '',
  nonce: '',
  pluginUrl: '',
  version: '1.1.2',
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
  availableElementorLayoutPresets: [
    {
      id: 'elementor_feature_block',
      label: 'Elementor Feature Block',
      description: 'Builds clean Elementor sections from document headings, text, lists, media, tables, and dividers.'
    }
  ],
  elementorSyncEnabled: false,
  elementorAvailable: false,
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
  return {
    ...fallbackConfig,
    ...(window.DocSyncWPAdmin ?? {})
  };
};
