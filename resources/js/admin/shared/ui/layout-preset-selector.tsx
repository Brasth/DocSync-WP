import { createElement, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { AvailableLayoutPreset } from '../../config';

type Props = {
  availableLayoutPresets: AvailableLayoutPreset[];
  defaultLayoutPreset: string;
  disabled?: boolean;
  value: string;
  onChange: (value: string) => void;
};

const defaultPresetLabel = (availableLayoutPresets: AvailableLayoutPreset[], defaultLayoutPreset: string): string => {
  const preset = availableLayoutPresets.find((item) => item.id === defaultLayoutPreset);

  return preset?.label || defaultLayoutPreset;
};

export const LayoutPresetSelector = ({
  availableLayoutPresets,
  defaultLayoutPreset,
  disabled = false,
  value,
  onChange
}: Props): JSX.Element => {
  const defaultLabel = useMemo(
    () => defaultPresetLabel(availableLayoutPresets, defaultLayoutPreset),
    [availableLayoutPresets, defaultLayoutPreset]
  );

  return (
    <label className="docsync-wp-field docsync-wp-layout-preset-selector">
      <span>{__('Layout preset', 'brasth-document-sync-for-google-docs')}</span>
      <select
        disabled={disabled}
        onChange={(event) => onChange(event.currentTarget.value)}
        value={value}
      >
        <option value="">
          {sprintf(__('Use site default (%s)', 'brasth-document-sync-for-google-docs'), defaultLabel)}
        </option>
        {availableLayoutPresets.map((preset) => (
          <option key={preset.id} value={preset.id}>{preset.label}</option>
        ))}
      </select>
      <small className="docsync-wp-field-help">
        {__('Applies to block editor sync. Elementor sync uses Elementor layout.', 'brasth-document-sync-for-google-docs')}
      </small>
    </label>
  );
};
