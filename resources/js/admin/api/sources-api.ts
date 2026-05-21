import { addQueryArgs } from '@wordpress/url';

import { request } from './client';
import type { SourceFilters, SourcesResponse, SyncResult } from './types';

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
