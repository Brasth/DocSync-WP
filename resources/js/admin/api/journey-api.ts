/**
 * Typed Journey 2 REST clients (contracts sections 3 to 7). Every function maps to
 * exactly one route; uploads use XMLHttpRequest for byte progress and cancellation.
 */
import { addQueryArgs } from '@wordpress/url';

import { request, requestWithProgress, restEndpointUrl, type UploadProgress } from './client';
import type {
  AddImportFilesResponse,
  CancelImportSessionResponse,
  CommitImportSessionRequest,
  ContentListQuery,
  ContentListResponse,
  CreateMatchJobRequest,
  ImportFile,
  ImportFileOptionsPatch,
  ImportPreview,
  ImportSession,
  ImportSessionSummary,
  JourneyDriveItemsQuery,
  JourneyDriveItemsResponse,
  JourneyGoogleAccount,
  MatchCommitPair,
  MatchCommitResponse,
  MatchCompare,
  MatchCreateDocResponse,
  MatchJob,
  OAuthContinuationRequest,
  OAuthContinuationResponse,
  SourceBatchItem,
  SourceBatchResponse,
  StoreRenderedAssetResponse
} from './journey-types';
import type { SyncResult } from './types';

const sessionPath = (sessionId: string): string => `imports/sessions/${encodeURIComponent(sessionId)}`;
const filePath = (sessionId: string, fileId: string): string => `${sessionPath(sessionId)}/files/${encodeURIComponent(fileId)}`;
const jobPath = (jobId: string): string => `matching/jobs/${encodeURIComponent(jobId)}`;

/** Random idempotency key matching `^[A-Za-z0-9-]{16,64}$`. */
export const createIdempotencyKey = (): string => {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID();
  }

  const bytes = new Uint8Array(16);
  crypto.getRandomValues(bytes);

  return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
};

/* Import sessions */

export const createImportSession = (): Promise<ImportSession> => {
  return request<ImportSession>('imports/sessions', { method: 'POST', data: {} });
};

export const listImportSessions = async (): Promise<ImportSessionSummary[]> => {
  const response = await request<{ sessions: ImportSessionSummary[] }>('imports/sessions');

  return Array.isArray(response.sessions) ? response.sessions : [];
};

export const getImportSession = (sessionId: string): Promise<ImportSession> => {
  return request<ImportSession>(sessionPath(sessionId));
};

export const cancelImportSession = (sessionId: string): Promise<CancelImportSessionResponse> => {
  return request<CancelImportSessionResponse>(sessionPath(sessionId), { method: 'DELETE' });
};

export const uploadImportFiles = (
  sessionId: string,
  files: File[],
  options: { signal?: AbortSignal; onProgress?: (progress: UploadProgress) => void } = {}
): Promise<AddImportFilesResponse> => {
  const body = new FormData();

  files.forEach((file) => body.append('files[]', file, file.name));

  return requestWithProgress<AddImportFilesResponse>(`${sessionPath(sessionId)}/files`, {
    method: 'POST',
    body,
    signal: options.signal,
    onProgress: options.onProgress
  });
};

export const updateImportFileOptions = async (
  sessionId: string,
  fileId: string,
  options: ImportFileOptionsPatch
): Promise<ImportFile> => {
  const response = await request<{ file: ImportFile }>(`${filePath(sessionId, fileId)}/options`, {
    method: 'PATCH',
    data: options
  });

  return response.file;
};

export const getImportFilePreview = (sessionId: string, fileId: string): Promise<ImportPreview> => {
  return request<ImportPreview>(`${filePath(sessionId, fileId)}/preview`);
};

/** Private asset stream URL (section 3.8); carries `_wpnonce` for image and PDF.js loads. */
export const importAssetUrl = (sessionId: string, fileId: string, assetId: string): string => {
  return restEndpointUrl(`${filePath(sessionId, fileId)}/assets/${encodeURIComponent(assetId)}`, true);
};

export const storeRenderedImportAsset = (
  sessionId: string,
  fileId: string,
  assetId: string,
  png: Blob,
  signal?: AbortSignal
): Promise<StoreRenderedAssetResponse> => {
  return requestWithProgress<StoreRenderedAssetResponse>(`${filePath(sessionId, fileId)}/assets/${encodeURIComponent(assetId)}`, {
    method: 'POST',
    body: png,
    contentType: 'image/png',
    signal
  });
};

export const commitImportSession = (sessionId: string, body: CommitImportSessionRequest): Promise<ImportSession> => {
  return request<ImportSession>(`${sessionPath(sessionId)}/commit`, { method: 'POST', data: body });
};

/* Matching jobs */

export const createMatchJob = (body: CreateMatchJobRequest): Promise<MatchJob> => {
  return request<MatchJob>('matching/jobs', { method: 'POST', data: body });
};

export const getMatchJob = (jobId: string): Promise<MatchJob> => request<MatchJob>(jobPath(jobId));

export const compareMatch = (jobId: string, postId: number, fileId: string): Promise<MatchCompare> => {
  return request<MatchCompare>(addQueryArgs(`${jobPath(jobId)}/compare`, { postId, fileId }));
};

export const commitMatchJob = (jobId: string, idempotencyKey: string, pairs: MatchCommitPair[]): Promise<MatchCommitResponse> => {
  return request<MatchCommitResponse>(`${jobPath(jobId)}/commit`, {
    method: 'POST',
    data: { idempotencyKey, pairs }
  });
};

export const createMatchDoc = (jobId: string, idempotencyKey: string, postId: number, folderId = ''): Promise<MatchCreateDocResponse> => {
  return request<MatchCreateDocResponse>(`${jobPath(jobId)}/create-doc`, {
    method: 'POST',
    data: { idempotencyKey, postId, folderId }
  });
};

/* Drive and OAuth */

/** `/drive/items` with the additive camelCase filters; legacy snake_case folder and paging keys stay. */
export const searchJourneyDriveItems = (query: JourneyDriveItemsQuery): Promise<JourneyDriveItemsResponse> => {
  return request<JourneyDriveItemsResponse>(addQueryArgs('drive/items', {
    location: query.location,
    folder_id: query.folderId || undefined,
    drive_id: query.driveId || undefined,
    search: query.search || undefined,
    globalSearch: query.globalSearch ? 'true' : undefined,
    owner: query.owner && query.owner !== 'any' ? query.owner : undefined,
    linked: query.linked && query.linked !== 'any' ? query.linked : undefined,
    page_token: query.pageToken || undefined,
    page_size: query.pageSize ?? 25
  }));
};

export const getJourneyGoogleAccount = (): Promise<JourneyGoogleAccount> => {
  return request<JourneyGoogleAccount>('oauth/google/account');
};

export const getContinuationAuthUrl = (continuation: OAuthContinuationRequest): Promise<OAuthContinuationResponse> => {
  return request<OAuthContinuationResponse>(addQueryArgs('oauth/google/url', {
    scopeSet: continuation.scopeSet ?? 'readonly',
    returnTo: continuation.returnTo,
    resumeKind: continuation.resumeKind || undefined,
    resumeId: continuation.resumeId || undefined
  }));
};

/* Sources batch and combined content */

export const createSourceBatch = (idempotencyKey: string, items: SourceBatchItem[]): Promise<SourceBatchResponse> => {
  return request<SourceBatchResponse>('sources/batch', {
    method: 'POST',
    data: { idempotencyKey, items }
  });
};

/** POST /sources for one existing post with `syncMode: attach_only` (section 7.1); never writes content. */
export const attachExistingSource = (payload: {
  fileId: string;
  postId: number;
  transferOwnership?: boolean;
  elementorSync?: boolean;
  layoutPreset?: string;
  elementorPreset?: string;
}): Promise<SyncResult> => {
  return request<SyncResult>('sources', {
    method: 'POST',
    data: {
      fileId: payload.fileId,
      target: { mode: 'existing', postId: payload.postId },
      syncMode: 'attach_only',
      elementorSync: payload.elementorSync,
      layoutPreset: payload.layoutPreset,
      elementorPreset: payload.elementorPreset,
      transferOwnership: payload.transferOwnership || undefined
    }
  });
};

export const listContent = (query: ContentListQuery = {}): Promise<ContentListResponse> => {
  if (typeof query.postId === 'number') {
    return request<ContentListResponse>(addQueryArgs('content', { postId: query.postId }));
  }

  return request<ContentListResponse>(addQueryArgs('content', {
    page: query.page ?? 1,
    perPage: query.perPage ?? 20,
    kind: query.kind && query.kind !== 'all' ? query.kind : undefined,
    postType: query.postType || undefined,
    search: query.search || undefined,
    orderBy: query.orderBy && query.orderBy !== 'modified' ? query.orderBy : undefined,
    order: query.order && query.order !== 'desc' ? query.order : undefined
  }));
};
