import { TextControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { docSourceHelp, type SourceMode } from './doc-source-modal-options';
import { SourceModeTabs } from './source-mode-tabs';

type Props = {
  sourceMode: SourceMode;
  documentInput: string;
  onInputChange: (value: string) => void;
  onModeChange: (mode: SourceMode) => void;
};

export const AdvancedSourcePanel = ({ sourceMode, documentInput, onInputChange, onModeChange }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-advanced-source">
      <SourceModeTabs
        modes={['url', 'file_id']}
        onChange={onModeChange}
        sourceMode={sourceMode}
      />
      <p className="description">{docSourceHelp[sourceMode]}</p>
      <TextControl
        className="docsync-wp-field"
        label={sourceMode === 'url' ? __('Google Docs URL', 'docsync-wp') : __('Google Drive file ID', 'docsync-wp')}
        onChange={onInputChange}
        placeholder={sourceMode === 'url' ? 'https://docs.google.com/document/d/...' : '1AbC...'}
        value={documentInput}
      />
    </div>
  );
};
