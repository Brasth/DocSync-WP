import type { FolderWatchRecord, UpdateFolderWatchPayload } from '../../api';
import type { FolderWatchEditDraft } from './folder-watch-edit-form';

export const draftFromWatch = (watch: FolderWatchRecord): FolderWatchEditDraft => ({
  syncInterval: watch.syncInterval,
  postStatus: watch.postStatus === 'publish' ? 'publish' : 'draft',
  includeSubfolders: watch.includeSubfolders,
  layoutPreset: watch.layoutPreset,
  elementorSync: watch.elementorSync,
  elementorPreset: watch.elementorPreset
});

const arraysEqual = (left: string[], right: string[]): boolean => {
  if (left.length !== right.length) {
    return false;
  }

  const sortedLeft = [...left].sort();
  const sortedRight = [...right].sort();

  return sortedLeft.every((value, index) => value === sortedRight[index]);
};

export const buildUpdatePayload = (
  watch: FolderWatchRecord,
  draft: FolderWatchEditDraft,
  excludedFileIds: string[]
): UpdateFolderWatchPayload => {
  const payload: UpdateFolderWatchPayload = {};

  if (draft.syncInterval !== watch.syncInterval) {
    payload.syncInterval = draft.syncInterval as UpdateFolderWatchPayload['syncInterval'];
  }

  const postStatus = draft.postStatus;
  const watchPostStatus = watch.postStatus === 'publish' ? 'publish' : 'draft';

  if (postStatus !== watchPostStatus) {
    payload.postStatus = postStatus;
  }

  if (draft.includeSubfolders !== watch.includeSubfolders) {
    payload.includeSubfolders = draft.includeSubfolders;
  }

  if (draft.layoutPreset !== watch.layoutPreset) {
    payload.layoutPreset = draft.layoutPreset;
  }

  if (draft.elementorSync !== watch.elementorSync) {
    payload.elementorSync = draft.elementorSync;
  }

  if (draft.elementorPreset !== watch.elementorPreset) {
    payload.elementorPreset = draft.elementorPreset;
  }

  const originalExcluded = watch.excludedFileIds ?? [];

  if (!arraysEqual(excludedFileIds, originalExcluded)) {
    payload.excludedFileIds = excludedFileIds;
  }

  return payload;
};
