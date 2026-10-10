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
  /**
   * False when the response omitted elementorSyncEnabled. The defaults form
   * must not write a replacement value in that case.
   */
  elementorPreferencePresent: boolean;
  telemetryEnabled: boolean;
  telemetryPromptDismissed: boolean;
  hasClientId: boolean;
  hasClientSecret: boolean;
  hasRequiredSettings: boolean;
  availablePostTypes: AvailablePostType[];
  availableLayoutPresets: AvailableLayoutPreset[];
  availableElementorLayoutPresets: AvailableLayoutPreset[];
  oauthCredentialsSavedAt: string | null;
  oauthCredentialsSavedDateLabel: string | null;
  currentUserDisplayName: string;
};

/** Writable POST /settings keys. Readonly identity and capability fields stay off this type. */
export type SettingsUpdate = {
  clientId?: string;
  clientSecret?: string;
  scopeMode?: string;
  enabledPostTypes?: string[];
  defaultPostStatus?: string;
  defaultExportFormat?: string;
  defaultLayoutPreset?: string;
  syncInterval?: string;
  connectionMode?: string;
  elementorSyncEnabled?: boolean;
  telemetryEnabled?: boolean;
  telemetryPromptDismissed?: boolean;
};

export type SettingsConnectionState = 'connected' | 'not_connected' | 'reconnect_required';

export type SettingsConnectionUser = {
  userId: number;
  displayName: string;
  state: SettingsConnectionState;
};

export type SettingsConnectionsSummary = {
  connected: number;
  notConnected: number;
  reconnectRequired: number;
};

export type SettingsConnectionsResponse = {
  summary: SettingsConnectionsSummary;
  users: SettingsConnectionUser[];
  page: number;
  perPage: number;
  total: number;
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
  wpCronDisabled?: boolean;
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

const isRecord = (value: unknown): value is Record<string, unknown> => {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
};

const asString = (value: unknown, fallback = ''): string => {
  return typeof value === 'string' ? value : fallback;
};

const asBoolean = (value: unknown, fallback = false): boolean => {
  return typeof value === 'boolean' ? value : fallback;
};

const asNullableString = (value: unknown): string | null => {
  return typeof value === 'string' && value.trim() !== '' ? value : null;
};

const asCount = (value: unknown): number => {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return 0;
  }

  return Math.floor(value);
};

const asStringList = (value: unknown, fallback: string[]): string[] => {
  if (!Array.isArray(value)) {
    return fallback;
  }

  return value.filter((item): item is string => typeof item === 'string');
};

const asPostTypes = (value: unknown): AvailablePostType[] => {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.flatMap((item) => {
    if (!isRecord(item) || typeof item.name !== 'string' || typeof item.label !== 'string') {
      return [];
    }

    return [{ name: item.name, label: item.label }];
  });
};

const asLayoutPresets = (value: unknown): AvailableLayoutPreset[] => {
  if (!Array.isArray(value)) {
    return [];
  }

  return value.flatMap((item) => {
    if (!isRecord(item) || typeof item.id !== 'string' || typeof item.label !== 'string') {
      return [];
    }

    return [{
      id: item.id,
      label: item.label,
      description: asString(item.description)
    }];
  });
};

const CONNECTION_STATES: readonly SettingsConnectionState[] = ['connected', 'not_connected', 'reconnect_required'];

const isConnectionState = (value: unknown): value is SettingsConnectionState => {
  return typeof value === 'string' && CONNECTION_STATES.some((state) => state === value);
};

/**
 * Fill optional setup fields so an older settings payload still renders.
 * Omitted Elementor preference is remembered so a later save does not invent false.
 */
export const normalizeSettingsResponse = (raw: unknown): SettingsResponse => {
  const record = isRecord(raw) ? raw : {};
  const elementorPreferencePresent = Object.prototype.hasOwnProperty.call(record, 'elementorSyncEnabled')
    && typeof record.elementorSyncEnabled === 'boolean';

  return {
    clientId: asString(record.clientId),
    scopeMode: asString(record.scopeMode),
    enabledPostTypes: asStringList(record.enabledPostTypes, ['post']),
    defaultPostStatus: asString(record.defaultPostStatus, 'draft'),
    defaultExportFormat: asString(record.defaultExportFormat, 'html_zip'),
    defaultLayoutPreset: asString(record.defaultLayoutPreset),
    syncInterval: asString(record.syncInterval, 'off'),
    connectionMode: asString(record.connectionMode),
    elementorSyncEnabled: elementorPreferencePresent ? record.elementorSyncEnabled === true : false,
    elementorPreferencePresent,
    telemetryEnabled: asBoolean(record.telemetryEnabled),
    telemetryPromptDismissed: asBoolean(record.telemetryPromptDismissed),
    hasClientId: asBoolean(record.hasClientId),
    hasClientSecret: asBoolean(record.hasClientSecret),
    hasRequiredSettings: asBoolean(record.hasRequiredSettings),
    availablePostTypes: asPostTypes(record.availablePostTypes),
    availableLayoutPresets: asLayoutPresets(record.availableLayoutPresets),
    availableElementorLayoutPresets: asLayoutPresets(record.availableElementorLayoutPresets),
    oauthCredentialsSavedAt: asNullableString(record.oauthCredentialsSavedAt),
    oauthCredentialsSavedDateLabel: asNullableString(record.oauthCredentialsSavedDateLabel),
    currentUserDisplayName: asString(record.currentUserDisplayName).trim()
  };
};

/** Coerce the team connections payload. Drops tokens, emails, and unknown states. */
export const normalizeSettingsConnections = (raw: unknown): SettingsConnectionsResponse => {
  const record = isRecord(raw) ? raw : {};
  const summary = isRecord(record.summary) ? record.summary : {};
  const users = (Array.isArray(record.users) ? record.users : []).flatMap((item): SettingsConnectionUser[] => {
    if (!isRecord(item) || !isConnectionState(item.state)) {
      return [];
    }

    const userId = asCount(item.userId);

    if (userId < 1) {
      return [];
    }

    return [{
      userId,
      displayName: asString(item.displayName).trim(),
      state: item.state
    }];
  }).sort((left, right) => {
    if (left.displayName === right.displayName) {
      return left.userId - right.userId;
    }

    return left.displayName < right.displayName ? -1 : 1;
  });

  return {
    summary: {
      connected: asCount(summary.connected),
      notConnected: asCount(summary.notConnected),
      reconnectRequired: asCount(summary.reconnectRequired)
    },
    users,
    page: Math.max(1, asCount(record.page) || 1),
    perPage: Math.min(50, Math.max(1, asCount(record.perPage) || 20)),
    total: asCount(record.total)
  };
};
