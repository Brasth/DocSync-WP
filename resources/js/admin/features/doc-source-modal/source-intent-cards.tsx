import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

export type SourceIntent = 'document' | 'folder';

type Props = {
  disabled?: boolean;
  value: SourceIntent;
  onChange: (intent: SourceIntent) => void;
};

export const SourceIntentCards = ({ disabled = false, value, onChange }: Props): JSX.Element => {
  return (
    <fieldset className="docsync-wp-source-intent" disabled={disabled}>
      <legend className="screen-reader-text">
        {__('Choose a Google Doc or a Drive folder', 'brasth-document-sync-for-google-docs')}
      </legend>
      <label className={`docsync-wp-source-intent__card${value === 'document' ? ' is-selected' : ''}`}>
        <input
          checked={value === 'document'}
          className="docsync-wp-source-intent__input"
          name="docsync-source-intent"
          onChange={() => onChange('document')}
          type="radio"
          value="document"
        />
        <span className="docsync-wp-source-intent__copy">
          <strong>{__('This Google Doc', 'brasth-document-sync-for-google-docs')}</strong>
          <span>{__('One source. One draft. Follows the site schedule.', 'brasth-document-sync-for-google-docs')}</span>
        </span>
      </label>
      <label className={`docsync-wp-source-intent__card${value === 'folder' ? ' is-selected' : ''}`}>
        <input
          checked={value === 'folder'}
          className="docsync-wp-source-intent__input"
          name="docsync-source-intent"
          onChange={() => onChange('folder')}
          type="radio"
          value="folder"
        />
        <span className="docsync-wp-source-intent__copy">
          <strong>{__('This Drive folder', 'brasth-document-sync-for-google-docs')}</strong>
          <span>{__('Watch the folder. New Docs become drafts.', 'brasth-document-sync-for-google-docs')}</span>
        </span>
      </label>
    </fieldset>
  );
};
