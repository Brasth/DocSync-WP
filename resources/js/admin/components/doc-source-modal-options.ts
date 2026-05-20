export type SourceMode = 'url' | 'file_id';

export const docSourceLabels: Record<SourceMode, string> = {
  url: 'Paste Doc URL',
  file_id: 'Paste file ID'
};

export const docSourceHelp: Record<SourceMode, string> = {
  url: 'Use a full docs.google.com/document URL that your connected Google account can open.',
  file_id: 'Use the raw Google Drive file ID when your connected Google account can open it.'
};
