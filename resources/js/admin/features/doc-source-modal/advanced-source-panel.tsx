import { TextControl } from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { docSourceHelp, type SourceMode } from './doc-source-modal-options';

type Props = {
  sourceMode: SourceMode;
  documentInput: string;
  onInputChange: (value: string) => void;
};

export const AdvancedSourcePanel = ({ sourceMode, documentInput, onInputChange }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-advanced-source">
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
