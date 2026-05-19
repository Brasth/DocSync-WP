import { createElement } from '@wordpress/element';

import { DocSourceTabs } from './DocSourceTabs';
import { docSourceHelp, type SourceMode } from './doc-source-modal-options';

type Props = {
  sourceMode: Exclude<SourceMode, 'picker'>;
  documentInput: string;
  onInputChange: (value: string) => void;
  onModeChange: (mode: Exclude<SourceMode, 'picker'>) => void;
};

export const DocSourceAdvancedPanel = ({ sourceMode, documentInput, onInputChange, onModeChange }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-advanced-source">
      <DocSourceTabs
        modes={['url', 'file_id']}
        onChange={(mode) => onModeChange(mode as Exclude<SourceMode, 'picker'>)}
        sourceMode={sourceMode}
      />
      <p className="description">{docSourceHelp[sourceMode]}</p>
      <label className="docsync-wp-field">
        <span>{sourceMode === 'url' ? 'Google Docs URL' : 'Google Drive file ID'}</span>
        <input
          className="regular-text"
          onChange={(event) => onInputChange(event.currentTarget.value)}
          placeholder={sourceMode === 'url' ? 'https://docs.google.com/document/d/...' : '1AbC...'}
          type="text"
          value={documentInput}
        />
      </label>
    </div>
  );
};
