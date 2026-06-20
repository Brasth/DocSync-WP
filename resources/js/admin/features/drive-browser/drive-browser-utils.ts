import { __ } from '@wordpress/i18n';

import type { DocumentMetadata, DriveItemSummary } from '../../api';

export type DriveBrowserBreadcrumb = {
  fileId: string;
  name: string;
};

export const rootDriveBreadcrumb: DriveBrowserBreadcrumb = { fileId: 'root', name: __('My Drive', 'brasth-document-sync-for-google-docs') };
export const driveBrowserPageSize = 50;

export const formatDriveModifiedTime = (value: string): string => {
  if (!value) {
    return __('Unavailable', 'brasth-document-sync-for-google-docs');
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short'
  }).format(date);
};

export const driveItemTypeLabel = (item: DriveItemSummary): string => {
  return item.itemType === 'folder' ? __('Folder', 'brasth-document-sync-for-google-docs') : __('Google Doc', 'brasth-document-sync-for-google-docs');
};

export const driveItemToDocumentMetadata = (item: DriveItemSummary): DocumentMetadata | null => {
  if (item.itemType !== 'document' || !item.selectable) {
    return null;
  }

  return {
    fileId: item.fileId,
    name: item.name,
    mimeType: item.mimeType,
    modifiedTime: item.modifiedTime,
    version: item.version ?? '',
    webViewLink: item.webViewLink
  };
};
