import { addQueryArgs } from '@wordpress/url';

import { request } from './client';
import type {
  DocumentMetadata,
  DriveDocumentFilters,
  DriveDocumentsResponse,
  DriveItemFilters,
  DriveItemsResponse,
  SharedDriveFilters,
  SharedDrivesResponse
} from './types';

export const inspectDocument = (document: string, source: 'url' | 'file_id'): Promise<DocumentMetadata> => {
  return request<DocumentMetadata>('documents/inspect', {
    method: 'POST',
    data: { document, source }
  });
};

export const listDriveItems = (filters: DriveItemFilters = {}): Promise<DriveItemsResponse> => {
  return request<DriveItemsResponse>(addQueryArgs('drive/items', {
    drive_id: filters.driveId || undefined,
    folder_id: filters.folderId || 'root',
    page_size: filters.pageSize ?? 20,
    page_token: filters.pageToken || undefined,
    search: filters.search || undefined
  }));
};

export const listSharedDrives = (filters: SharedDriveFilters = {}): Promise<SharedDrivesResponse> => {
  return request<SharedDrivesResponse>(addQueryArgs('drive/shared-drives', {
    page_size: filters.pageSize ?? 50,
    page_token: filters.pageToken || undefined
  }));
};

export const listDriveDocuments = (filters: DriveDocumentFilters = {}): Promise<DriveDocumentsResponse> => {
  return request<DriveDocumentsResponse>(addQueryArgs('documents', {
    page_size: filters.pageSize ?? 20,
    page_token: filters.pageToken || undefined,
    search: filters.search || undefined
  }));
};
