import { addQueryArgs } from '@wordpress/url';

import { request } from './client';
import type { SourceContentResponse, SourceFilters, SourceRecord, SourcesResponse, SyncResult } from './types';

export type SyncMode = 'inline' | 'background';

export const listSources = (filters: SourceFilters = {}): Promise<SourcesResponse> => {
  return request<SourcesResponse>(addQueryArgs('sources', {
    page: filters.page ?? 1,
    per_page: filters.perPage ?? 100,
    post_type: filters.postType || undefined,
    search: filters.search || undefined,
    status: filters.status || undefined
  }));
};

export const createSource = (payload: {
  fileId: string;
  target: { mode: 'existing'; postId: number } | { mode: 'new'; postType: string };
  exportFormat?: string;
  syncMode?: SyncMode;
}): Promise<SyncResult> => {
  return request<SyncResult>('sources', {
    method: 'POST',
    data: payload
  });
};

export const getSource = (postId: number): Promise<SourceRecord> => {
  return request<SourceRecord>(`sources/${postId}`);
};

export const getSourceContent = (postId: number): Promise<SourceContentResponse> => {
  return request<SourceContentResponse>(`sources/${postId}/content`);
};

export const syncSource = (postId: number, syncMode: SyncMode = 'background'): Promise<SyncResult> => {
  return request<SyncResult>(`sources/${postId}/sync`, {
    method: 'POST',
    data: { syncMode }
  });
};

export const syncAllSources = (): Promise<{ results: SyncResult[]; count: number; hasMore?: boolean }> => {
  return request<{ results: SyncResult[]; count: number; hasMore?: boolean }>('sources/sync-all', { method: 'POST' });
};

export const detachSource = (postId: number): Promise<{ postId: number; deleted: boolean }> => {
  return request<{ postId: number; deleted: boolean }>(`sources/${postId}`, { method: 'DELETE' });
};
