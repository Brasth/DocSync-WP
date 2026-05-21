import type { AvailablePostType } from '../config';

export type SettingsResponse = {
  clientId: string;
  pickerApiKey: string;
  pickerAppId: string;
  scopeMode: string;
  enabledPostTypes: string[];
  defaultPostStatus: string;
  defaultExportFormat: string;
  syncInterval: string;
  connectionMode: string;
  hasClientId: boolean;
  hasClientSecret: boolean;
  hasPickerApiKey: boolean;
  hasPickerAppId: boolean;
  hasPickerSettings: boolean;
  hasRequiredSettings: boolean;
  availablePostTypes: AvailablePostType[];
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
  syncStatus: string;
  syncError: string;
};

export type SyncResult = {
  postId: number;
  status: string;
  changed: boolean;
  created?: boolean;
  source?: SourceRecord | null;
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
  page?: number;
  perPage?: number;
};
