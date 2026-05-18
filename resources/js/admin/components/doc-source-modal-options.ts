export type SourceMode = 'picker' | 'url' | 'file_id';

export const docSourceLabels: Record<SourceMode, string> = {
  picker: 'Choose from Google',
  url: 'Paste Doc URL',
  file_id: 'Paste file ID'
};

export const docSourceHelp: Record<SourceMode, string> = {
  picker: 'Best for least-privilege Drive access. Picker grants this app access to the selected file.',
  url: 'Use a full docs.google.com/document URL that this app can already access.',
  file_id: 'Use the raw Google Drive file ID when you already know it.'
};
