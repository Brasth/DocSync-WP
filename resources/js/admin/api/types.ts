import type { AvailableLayoutPreset, AvailablePostType } from '../config';

export type SettingsResponse = {
  clientId: string;
  scopeMode: string;
  enabledPostTypes: string[];
  defaultPostStatus: string;
  defaultExportFormat: string;
  defaultLayoutPreset: string;
  syncInterval: string;
  connectionMode: string;
  elementorSyncEnabled: boolean;
  telemetryEnabled: boolean;
  telemetryPromptDismissed: boolean;
  hasClientId: boolean;
  hasClientSecret: boolean;
  hasRequiredSettings: boolean;
  availablePostTypes: AvailablePostType[];
  availableLayoutPresets: AvailableLayoutPreset[];
  availableElementorLayoutPresets: AvailableLayoutPreset[];
};

export type WorkspaceSourceSummary = {
  total: number;
  attention: number;
  syncing: number;
  healthy: number;
  activated: boolean;
  truncated: boolean;
};

export type WorkspaceFolderWatchSummary = {
  importing: number;
  watching: number;
  attention: number;
  imported: number;
  truncated: boolean;
};

export type WorkspaceCronHealth = {
  lastRunAt: string;
  stalled: boolean;
};

export type WorkspaceResponse = {
  canManageSettings: boolean;
  siteConnectionReady: boolean;
  availablePostTypes: AvailablePostType[];
  enabledPostTypes: string[];
  creatablePostTypes: string[];
  defaultPostStatus: string;
  defaultExportFormat: string;
  defaultLayoutPreset: string;
  availableLayoutPresets: AvailableLayoutPreset[];
  elementorSyncEnabled: boolean;
  elementorAvailable: boolean;
  availableElementorLayoutPresets: AvailableLayoutPreset[];
  sourceSummary: WorkspaceSourceSummary;
  folderWatches?: WorkspaceFolderWatchSummary;
  cronHealth?: WorkspaceCronHealth;
};

export type GoogleAccount = {
  connected: boolean;
  googleAccountEmail?: string;
  scope?: string;
  connectedAt?: string;
  expiresAt?: number;
  hasRequiredScope: boolean;
  requiredScope?: string;
};

export type DocumentMetadata = {
  fileId: string;
  name: string;
  mimeType: string;
  modifiedTime: string;
  version: string;
  webViewLink: string;
  syncCompatibility?: SyncCompatibility;
};

export type SyncCompatibility = {
  canDownload: boolean | null;
  sizeBytes: number | null;
  quotaBytesUsed: number | null;
  warningCode: 'large_doc_possible' | 'download_blocked' | null;
  warningMessage: string;
};

export type DriveDocumentSummary = DocumentMetadata;

export type DriveItemType = 'folder' | 'document';

export type DriveItemSummary = {
  fileId: string;
  name: string;
  mimeType: string;
  itemType: DriveItemType;
  modifiedTime: string;
  webViewLink: string;
  iconLink?: string;
  version?: string;
  selectable: boolean;
  folderPath?: string;
  syncCompatibility?: SyncCompatibility;
};

export type SharedDriveSummary = {
  driveId: string;
  name: string;
};

export type DriveItemsResponse = {
  items: DriveItemSummary[];
  nextPageToken?: string;
  incompleteSearch?: boolean;
  folderId: string;
  driveId: string;
};

export type SharedDrivesResponse = {
  drives: SharedDriveSummary[];
  nextPageToken?: string;
};

export type DriveDocumentsResponse = {
  documents: DriveDocumentSummary[];
  nextPageToken?: string;
  incompleteSearch?: boolean;
};

export type DriveItemFilters = {
  driveId?: string;
  folderId?: string;
  search?: string;
  pageToken?: string;
  pageSize?: number;
};

export type SharedDriveFilters = {
  pageToken?: string;
  pageSize?: number;
};

export type DriveDocumentFilters = {
  search?: string;
  pageToken?: string;
  pageSize?: number;
};

export type SourceRecord = {
  postId: number;
  postType: string;
  postStatus: string;
  postTitle: string;
  editUrl: string;
  googleFileId: string;
  googleDocUrl: string;
  googleTitle: string;
  googleModifiedTime: string;
  googleVersion: string;
  lastHash: string;
  lastSyncedAt: string;
  syncOwnerUserId: number;
  exportFormat: string;
  lastSyncMethod?: 'html_zip' | 'docs_api_fallback' | null;
  layoutPreset?: string | null;
  lastLayoutFingerprint?: string;
  elementorSync?: boolean | null;
  elementorPreset?: string | null;
  syncStatus: 'linked' | 'syncing' | 'synced' | 'skipped' | 'error' | string;
  syncError: string;
  syncProgress: number;
  syncStep: string;
  syncMessage: string;
  syncStartedAt: string;
  syncUpdatedAt: string;
  syncErrorCode: string;
  folderWatchId?: string | null;
  syncInterval?: string;
  effectiveInterval?: string;
  nextSyncAt?: string;
};

export type FolderWatchStatus = 'importing' | 'watching' | 'paused' | 'error';

export type FolderWatchFailedItem = {
  fileId: string;
  name: string;
  code: string;
  message: string;
};

export type FolderWatchRecord = {
  id: string;
  ownerUserId?: number;
  folderId: string;
  driveId: string;
  folderName: string;
  webViewLink: string;
  includeSubfolders: boolean;
  postType: string;
  postStatus: 'draft' | 'publish' | string;
  syncInterval: 'site' | 'off' | 'hourly' | 'twicedaily' | 'daily' | 'weekly' | string;
  layoutPreset: string;
  elementorSync: boolean;
  elementorPreset: string;
  effectiveInterval?: string;
  status: FolderWatchStatus | string;
  pendingCount: number;
  importedCount: number;
  totalCount: number;
  overflow: boolean;
  failed: FolderWatchFailedItem[];
  excludedFileIds?: string[];
  lastScanAt: string;
  nextScanAt?: string;
  ownerDisplayName?: string;
  lastError: string;
  createdAt: string;
};

export type FolderDocumentInventory = {
  documents: DriveItemSummary[];
  folderId: string;
  driveId: string;
  overflow: boolean;
  includeSubfolders: boolean;
  scannedFolderCount: number;
};

export type SyncResult = {
  postId: number;
  status: 'queued' | 'linked' | 'syncing' | 'synced' | 'skipped' | 'error' | string;
  changed: boolean;
  created?: boolean;
  queued?: boolean;
  lastSyncMethod?: 'html_zip' | 'docs_api_fallback' | null;
  source?: SourceRecord | null;
};

export type SourceContentResponse = {
  postId: number;
  content: string;
  source: SourceRecord | null;
};

export type SourcesResponse = {
  sources: SourceRecord[];
  has_more?: boolean;
  hasMore?: boolean;
  page?: number;
  per_page?: number;
  perPage?: number;
};

export type SourceFilters = {
  search?: string;
  postType?: string;
  status?: string;
  folderWatchId?: string;
  page?: number;
  perPage?: number;
};

export type SyncLogLevel = 'info' | 'warning' | 'error';

export type SyncLogEntry = {
  eventId: string;
  timestamp: string;
  level: SyncLogLevel | string;
  postId: number;
  postTitle: string;
  googleTitle: string;
  status: string;
  step: string;
  progress: number;
  message: string;
  errorCode: string;
  syncStartedAt: string;
  syncUpdatedAt: string;
  context?: {
    hasLock?: boolean;
    hasCronEvent?: boolean;
    lastHeartbeat?: string;
    lastStep?: string;
    outputType?: 'gutenberg' | 'elementor' | string;
    layoutPreset?: string;
    elementorMode?: 'preset' | 'legacy' | string;
    elementorPreset?: string;
  };
};

export type SyncLogFilters = {
  postId?: number;
  level?: string;
  search?: string;
  status?: string;
  step?: string;
  page?: number;
  perPage?: number;
};

export type SyncLogResponse = {
  entries: SyncLogEntry[];
  has_more?: boolean;
  hasMore?: boolean;
  page?: number;
  per_page?: number;
  perPage?: number;
};

export type ClearSyncLogResponse = {
  cleared: number;
};
