import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { DocSourceOutputType } from './doc-source-modal-options';

type Props = {
  disabled?: boolean;
  onChange: (value: DocSourceOutputType) => void;
  value: DocSourceOutputType;
};

const outputOptions: Array<{
  description: string;
  label: string;
  value: DocSourceOutputType;
}> = [
  {
    description: __('Editable in the block editor.', 'brasth-document-sync-for-google-docs'),
    label: __('WordPress Blocks', 'brasth-document-sync-for-google-docs'),
    value: 'blocks'
  },
  {
    description: __('Editable in Elementor.', 'brasth-document-sync-for-google-docs'),
    label: __('Elementor Layout', 'brasth-document-sync-for-google-docs'),
    value: 'elementor'
  }
];

export const OutputTypeChoice = ({ disabled = false, onChange, value }: Props): JSX.Element => (
  <fieldset className="docsync-wp-output-choice">
    <legend>{__('Output type', 'brasth-document-sync-for-google-docs')}</legend>
    <div className="docsync-wp-output-choice__options">
      {outputOptions.map((option) => (
        <label
          className="docsync-wp-output-choice__option"
          data-disabled={disabled ? 'true' : 'false'}
          data-selected={value === option.value ? 'true' : 'false'}
          key={option.value}
        >
          <input
            checked={value === option.value}
            disabled={disabled}
            name="docsync-wp-output-type"
            onChange={() => onChange(option.value)}
            type="radio"
            value={option.value}
          />
          <span>
            <strong>{option.label}</strong>
            <small>{option.description}</small>
          </span>
        </label>
      ))}
    </div>
  </fieldset>
);
