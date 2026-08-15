import type { DocumentMetadata } from '../../api';

export const MAX_FOLDER_DRAFTS = 20;

export const uniqueDocuments = (documents: DocumentMetadata[]): DocumentMetadata[] => {
  const seen = new Set<string>();

  return documents.filter((document) => {
    if (seen.has(document.fileId)) {
      return false;
    }

    seen.add(document.fileId);
    return true;
  });
};

export const documentsCanAttach = (
  selectedDocuments: DocumentMetadata[],
  metadata: DocumentMetadata | null
): boolean => {
  const documents = uniqueDocuments(selectedDocuments.length > 0 ? selectedDocuments : metadata ? [metadata] : []);

  return documents.length > 0 && documents.every((document) => document.syncCompatibility?.canDownload !== false);
};

export const isAuthFailureCode = (code: string): boolean => {
  return ['rest_not_logged_in', 'rest_cookie_invalid_nonce', 'rest_forbidden'].includes(code);
};
