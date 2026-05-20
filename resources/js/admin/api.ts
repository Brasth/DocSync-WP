import { getAdminConfig, type AvailablePostType } from './config';

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

export type DriveDocumentsResponse = {
  documents: DriveDocumentSummary[];
  nextPageToken?: string;
  incompleteSearch?: boolean;
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

type ApiFetchOptions = {
  method?: string;
  data?: unknown;
  headers?: Record<string, string>;
};

type WPApiFetch = <T>(options: ApiFetchOptions & { url: string }) => Promise<T>;

declare global {
  interface Window {
    wp?: {
      apiFetch?: WPApiFetch;
    };
  }
}

const endpointUrl = (endpoint: string): string => {
  const config = getAdminConfig();
  const base = config.restUrl.replace(/\/$/, '');
  return `${base}/${endpoint.replace(/^\//, '')}`;
};

const request = async <T>(endpoint: string, options: ApiFetchOptions = {}): Promise<T> => {
  const apiFetch = window.wp?.apiFetch;

  if (!apiFetch) {
    throw new Error('WordPress REST client is not available. Reload the admin page.');
  }

  const config = getAdminConfig();

  try {
    return await apiFetch<T>({
      ...options,
      url: endpointUrl(endpoint),
      headers: {
        ...(options.headers ?? {}),
        'X-WP-Nonce': config.nonce
      }
    });
  } catch (caught) {
    if (caught && typeof caught === 'object' && 'message' in caught && typeof caught.message === 'string') {
      throw new Error(caught.message);
    }

    throw caught;
  }
};

export const getSettings = (): Promise<SettingsResponse> => request<SettingsResponse>('settings');

export const saveSettings = (settings: Partial<SettingsResponse> & { clientSecret?: string }): Promise<SettingsResponse> => {
  return request<SettingsResponse>('settings', {
    method: 'POST',
    data: settings
  });
};

export const getGoogleAccount = (): Promise<GoogleAccount> => request<GoogleAccount>('oauth/google/account');

export const disconnectGoogleAccount = (): Promise<{ disconnected: boolean }> => {
  return request<{ disconnected: boolean }>('oauth/google/account', { method: 'DELETE' });
};

export const getGoogleAuthUrl = (): Promise<{ authUrl: string }> => request<{ authUrl: string }>('oauth/google/url');

export const inspectDocument = (document: string, source: 'url' | 'file_id'): Promise<DocumentMetadata> => {
  return request<DocumentMetadata>('documents/inspect', {
    method: 'POST',
    data: { document, source }
  });
};

export const listDriveDocuments = (filters: DriveDocumentFilters = {}): Promise<DriveDocumentsResponse> => {
  const params = new URLSearchParams({
    page_size: String(filters.pageSize ?? 20)
  });

  if (filters.search) {
    params.set('search', filters.search);
  }

  if (filters.pageToken) {
    params.set('page_token', filters.pageToken);
  }

  return request<DriveDocumentsResponse>(`documents?${params.toString()}`);
};

export const listSources = (filters: SourceFilters = {}): Promise<SourcesResponse> => {
  const params = new URLSearchParams({
    page: String(filters.page ?? 1),
    per_page: String(filters.perPage ?? 100)
  });

  if (filters.postType) {
    params.set('post_type', filters.postType);
  }

  if (filters.search) {
    params.set('search', filters.search);
  }

  if (filters.status) {
    params.set('status', filters.status);
  }

  return request<SourcesResponse>(`sources?${params.toString()}`);
};

export const createSource = (payload: {
  fileId: string;
  target: { mode: 'existing'; postId: number } | { mode: 'new'; postType: string };
  exportFormat?: string;
}): Promise<SyncResult> => {
  return request<SyncResult>('sources', {
    method: 'POST',
    data: payload
  });
};

export const syncSource = (postId: number): Promise<SyncResult> => {
  return request<SyncResult>(`sources/${postId}/sync`, { method: 'POST' });
};

export const syncAllSources = (): Promise<{ results: SyncResult[]; count: number }> => {
  return request<{ results: SyncResult[]; count: number }>('sources/sync-all', { method: 'POST' });
};

export const detachSource = (postId: number): Promise<{ postId: number; deleted: boolean }> => {
  return request<{ postId: number; deleted: boolean }>(`sources/${postId}`, { method: 'DELETE' });
};
