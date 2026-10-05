import { request } from './client';

export type ZipImportResult = {
  postId: number;
  title: string;
  editUrl: string;
};

export const importZip = (file: File, postType: string, layoutPreset: string): Promise<ZipImportResult> => {
  const body = new FormData();

  body.append('file', file);
  body.append('postType', postType);

  if (layoutPreset) {
    body.append('layoutPreset', layoutPreset);
  }

  return request<ZipImportResult>('imports/zip', { method: 'POST', body });
};
