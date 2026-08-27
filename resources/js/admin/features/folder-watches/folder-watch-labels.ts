import { __ } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';

export const foldersScreenUrl = 'admin.php?page=brasth-document-sync-for-google-docs-folders';

export const sourcesForWatchUrl = (watchId: string): string => {
  return `admin.php?page=brasth-document-sync-for-google-docs-sources&folder_watch_id=${encodeURIComponent(watchId)}`;
};

export const folderWatchDetailUrl = (watchId: string): string => {
  return `${foldersScreenUrl}&watch=${encodeURIComponent(watchId)}`;
};

export const intervalLabel = (interval: string): string => {
  if (interval === 'hourly') {
    return __('Hourly', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'twicedaily') {
    return __('Twice daily', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'daily') {
    return __('Daily', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'weekly') {
    return __('Weekly', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'off') {
    return __('Off', 'brasth-document-sync-for-google-docs');
  }

  return __('Use site default', 'brasth-document-sync-for-google-docs');
};

export const watchScheduleLabel = (watch: FolderWatchRecord): string => {
  return intervalLabel(watch.effectiveInterval || watch.syncInterval);
};

export const watchStatusLabel = (status: string): string => {
  return status;
};
