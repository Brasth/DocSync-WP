import { addQueryArgs } from '@wordpress/url';

import { request } from './client';
import type { SyncLogFilters, SyncLogResponse } from './types';

export const listSyncLogEntries = (filters: SyncLogFilters = {}): Promise<SyncLogResponse> => {
  return request<SyncLogResponse>(addQueryArgs('sync-log', {
    level: filters.level || undefined,
    page: filters.page ?? 1,
    per_page: filters.perPage ?? 50,
    post_id: filters.postId || undefined
  }));
};
