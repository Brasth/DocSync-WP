import { addQueryArgs } from '@wordpress/url';

import { request } from './client';
import type { FolderDocumentInventory, FolderWatchRecord } from './types';

export type CreateFolderWatchPayload = {
  folderId: string;
  driveId?: string;
  includeSubfolders?: boolean;
  confirmRoot?: boolean;
  postType: string;
  postStatus?: 'draft' | 'publish';
  syncInterval?: 'site' | 'off' | 'hourly' | 'twicedaily' | 'daily' | 'weekly';
  layoutPreset?: string;
  elementorSync?: boolean;
  elementorPreset?: string;
  excludeFileIds?: string[];
};

export type UpdateFolderWatchPayload = {
  syncInterval?: 'site' | 'off' | 'hourly' | 'twicedaily' | 'daily' | 'weekly';
  postStatus?: 'draft' | 'publish';
  layoutPreset?: string;
  elementorSync?: boolean;
  elementorPreset?: string;
  includeSubfolders?: boolean;
  excludedFileIds?: string[];
};

export const listFolderDocuments = (
  folderId: string,
  filters: { driveId?: string; includeSubfolders?: boolean } = {}
): Promise<FolderDocumentInventory> => {
  return request<FolderDocumentInventory>(addQueryArgs(`drive/folders/${encodeURIComponent(folderId)}/documents`, {
    drive_id: filters.driveId || undefined,
    include_subfolders: filters.includeSubfolders ? true : undefined
  }));
};

export const listFolderWatches = (): Promise<{ folders: FolderWatchRecord[] }> => {
  return request<{ folders: FolderWatchRecord[] }>('folders');
};

export const createFolderWatch = (payload: CreateFolderWatchPayload): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>('folders', {
    method: 'POST',
    data: payload
  });
};

export const getFolderWatch = (watchId: string): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>(`folders/${watchId}`);
};

export const updateFolderWatch = (watchId: string, payload: UpdateFolderWatchPayload): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>(`folders/${watchId}`, {
    method: 'PATCH',
    data: payload
  });
};

export const scanFolderWatch = (watchId: string): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>(`folders/${watchId}/scan`, {
    method: 'POST'
  });
};

export const pauseFolderWatch = (watchId: string): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>(`folders/${watchId}/pause`, {
    method: 'POST'
  });
};

export const resumeFolderWatch = (watchId: string): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>(`folders/${watchId}/resume`, {
    method: 'POST'
  });
};

export const retryFolderWatch = (watchId: string): Promise<FolderWatchRecord> => {
  return request<FolderWatchRecord>(`folders/${watchId}/retry`, {
    method: 'POST'
  });
};

export const deleteFolderWatch = (watchId: string): Promise<{ deleted: boolean }> => {
  return request<{ deleted: boolean }>(`folders/${watchId}`, {
    method: 'DELETE'
  });
};
