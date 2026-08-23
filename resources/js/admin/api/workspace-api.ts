import { request } from './client';
import type { WorkspaceCronHealth, WorkspaceFolderWatchSummary, WorkspaceResponse, WorkspaceSourceSummary } from './types';

const normalizeCount = (value: unknown): number => {
  return typeof value === 'number' && Number.isFinite(value) ? Math.max(0, Math.floor(value)) : 0;
};

const normalizeSourceSummary = (summary: WorkspaceSourceSummary | undefined): WorkspaceSourceSummary => ({
  total: normalizeCount(summary?.total),
  attention: normalizeCount(summary?.attention),
  syncing: normalizeCount(summary?.syncing),
  healthy: normalizeCount(summary?.healthy),
  activated: summary?.activated === true,
  truncated: summary?.truncated === true
});

const normalizeFolderWatchSummary = (
  summary: WorkspaceFolderWatchSummary | undefined
): WorkspaceFolderWatchSummary => ({
  importing: normalizeCount(summary?.importing),
  watching: normalizeCount(summary?.watching),
  attention: normalizeCount(summary?.attention),
  imported: normalizeCount(summary?.imported),
  truncated: summary?.truncated === true
});

const normalizeCronHealth = (health: WorkspaceCronHealth | undefined): WorkspaceCronHealth => ({
  lastRunAt: typeof health?.lastRunAt === 'string' ? health.lastRunAt : '',
  stalled: health?.stalled === true
});

const normalizeWorkspaceResponse = (response: WorkspaceResponse): WorkspaceResponse => ({
  canManageSettings: response.canManageSettings === true,
  siteConnectionReady: response.siteConnectionReady === true,
  availablePostTypes: Array.isArray(response.availablePostTypes) ? response.availablePostTypes : [],
  enabledPostTypes: Array.isArray(response.enabledPostTypes)
    ? response.enabledPostTypes.filter((postType): postType is string => typeof postType === 'string')
    : [],
  creatablePostTypes: Array.isArray(response.creatablePostTypes)
    ? response.creatablePostTypes.filter((postType): postType is string => typeof postType === 'string')
    : [],
  defaultPostStatus: typeof response.defaultPostStatus === 'string' ? response.defaultPostStatus : 'draft',
  defaultExportFormat: typeof response.defaultExportFormat === 'string' ? response.defaultExportFormat : 'html_zip',
  defaultLayoutPreset: typeof response.defaultLayoutPreset === 'string' ? response.defaultLayoutPreset : 'plain_blocks',
  availableLayoutPresets: Array.isArray(response.availableLayoutPresets) ? response.availableLayoutPresets : [],
  elementorSyncEnabled: response.elementorSyncEnabled === true,
  elementorAvailable: response.elementorAvailable === true,
  availableElementorLayoutPresets: Array.isArray(response.availableElementorLayoutPresets)
    ? response.availableElementorLayoutPresets
    : [],
  sourceSummary: normalizeSourceSummary(response.sourceSummary),
  folderWatches: normalizeFolderWatchSummary(response.folderWatches),
  cronHealth: normalizeCronHealth(response.cronHealth)
});

export const getWorkspace = async (): Promise<WorkspaceResponse> => {
  const response = await request<WorkspaceResponse>('workspace');

  return normalizeWorkspaceResponse(response);
};
