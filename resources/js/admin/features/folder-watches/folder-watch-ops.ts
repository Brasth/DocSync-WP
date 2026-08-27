export type FolderWatchOpsInput = {
  id: string;
  folderName: string;
  status: string;
  pendingCount: number;
  importedCount: number;
  failed: unknown[];
  lastError: string;
  ownerDisplayName?: string;
};

export type FolderWatchHealthFilter = '' | 'watching' | 'importing' | 'attention' | 'error' | 'paused';

export type FolderWatchPrimaryAction = 'scan' | 'manage' | 'resume' | 'fix';

export const watchNeedsAttention = (watch: FolderWatchOpsInput): boolean => {
  return watch.status === 'error'
    || watch.status === 'paused'
    || watch.status === 'importing'
    || watch.failed.length > 0
    || watch.lastError.trim() !== '';
};

export const attentionRank = (watch: FolderWatchOpsInput): number => {
  if (watch.status === 'error' || watch.failed.length > 0 || watch.lastError.trim() !== '') {
    return 0;
  }

  if (watch.status === 'importing') {
    return 1;
  }

  if (watch.status === 'paused') {
    return 2;
  }

  return 3;
};

export const watchStatusTone = (status: string): string => {
  if (status === 'watching' || status === 'importing' || status === 'paused' || status === 'error') {
    return status;
  }

  return status;
};

export const primaryWatchAction = (watch: FolderWatchOpsInput): FolderWatchPrimaryAction => {
  if (watch.status === 'error' || watch.failed.length > 0 || watch.lastError.trim() !== '') {
    return 'fix';
  }

  if (watch.status === 'paused') {
    return 'resume';
  }

  if (watch.status === 'importing') {
    return 'manage';
  }

  return 'scan';
};

export const filterFolderWatches = (
  watches: FolderWatchOpsInput[],
  search: string,
  healthFilter: FolderWatchHealthFilter
): FolderWatchOpsInput[] => {
  const needle = search.trim().toLowerCase();

  return watches
    .filter((watch) => {
      if (healthFilter === 'attention' && !watchNeedsAttention(watch)) {
        return false;
      }

      if (healthFilter === 'watching' && watch.status !== 'watching') {
        return false;
      }

      if (healthFilter === 'importing' && watch.status !== 'importing') {
        return false;
      }

      if (healthFilter === 'error' && watch.status !== 'error') {
        return false;
      }

      if (healthFilter === 'paused' && watch.status !== 'paused') {
        return false;
      }

      if (!needle) {
        return true;
      }

      return watch.folderName.toLowerCase().includes(needle)
        || (watch.ownerDisplayName || '').toLowerCase().includes(needle);
    })
    .slice()
    .sort((left, right) => {
      if (healthFilter === 'attention' || healthFilter === '') {
        const rankDiff = attentionRank(left) - attentionRank(right);

        if (rankDiff !== 0) {
          return rankDiff;
        }
      }

      return 0;
    });
};
