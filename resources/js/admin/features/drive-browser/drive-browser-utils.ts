import type { DocumentMetadata, DriveItemSummary } from '../../api';

export type DriveBrowserBreadcrumb = {
  fileId: string;
  name: string;
};

export const rootDriveBreadcrumb: DriveBrowserBreadcrumb = { fileId: 'root', name: 'My Drive' };
export const driveBrowserPageSize = 20;

export const formatDriveModifiedTime = (value: string): string => {
  if (!value) {
    return 'Unavailable';
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
  return item.itemType === 'folder' ? 'Folder' : 'Google Doc';
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
