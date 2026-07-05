import { __ } from '@wordpress/i18n';

export type SourceMode = 'url' | 'file_id';
export type DocSourceUiMode = 'browse' | SourceMode;
export type DocSourceOutputType = 'blocks' | 'elementor';

export const docSourceLabels: Record<DocSourceUiMode, string> = {
  browse: __('Browse Google Docs', 'brasth-document-sync-for-google-docs'),
  url: __('Paste Doc URL', 'brasth-document-sync-for-google-docs'),
  file_id: __('Paste file ID', 'brasth-document-sync-for-google-docs')
};

export const docSourceHelp: Record<SourceMode, string> = {
  url: __('Use a full docs.google.com/document URL that your connected Google account can open.', 'brasth-document-sync-for-google-docs'),
  file_id: __('Use the raw Google Drive file ID when your connected Google account can open it.', 'brasth-document-sync-for-google-docs')
};
