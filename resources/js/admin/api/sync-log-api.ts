import { addQueryArgs } from '@wordpress/url';

import { request } from './client';
import type { ClearSyncLogResponse, SyncLogFilters, SyncLogResponse } from './types';

export const listSyncLogEntries = (filters: SyncLogFilters = {}): Promise<SyncLogResponse> => {
  return request<SyncLogResponse>(addQueryArgs('sync-log', {
    level: filters.level || undefined,
    page: filters.page ?? 1,
    per_page: filters.perPage ?? 50,
    post_id: filters.postId || undefined,
    search: filters.search || undefined,
    status: filters.status || undefined,
    step: filters.step || undefined
  }));
};

export const clearSyncLogEntries = (postId?: number): Promise<ClearSyncLogResponse> => {
  return request<ClearSyncLogResponse>(addQueryArgs('sync-log', {
    post_id: postId || undefined
  }), { method: 'DELETE' });
};
