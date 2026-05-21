import { __ } from '@wordpress/i18n';

export type SourceMode = 'url' | 'file_id';

export const docSourceLabels: Record<SourceMode, string> = {
  url: __('Paste Doc URL', 'docsync-wp'),
  file_id: __('Paste file ID', 'docsync-wp')
};

export const docSourceHelp: Record<SourceMode, string> = {
  url: __('Use a full docs.google.com/document URL that your connected Google account can open.', 'docsync-wp'),
  file_id: __('Use the raw Google Drive file ID when your connected Google account can open it.', 'docsync-wp')
};
