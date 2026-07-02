import { createElement, useMemo } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { AvailableLayoutPreset } from '../../config';

type Props = {
  availableLayoutPresets: AvailableLayoutPreset[];
  defaultLayoutPreset: string;
  defaultOptionLabel?: string;
  disabled?: boolean;
  helpText?: string;
  label?: string;
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
  defaultOptionLabel,
  disabled = false,
  helpText = __('Choose how this Google Doc becomes block editor content. Elementor presets are separate.', 'brasth-document-sync-for-google-docs'),
  label = __('Layout preset', 'brasth-document-sync-for-google-docs'),
  value,
  onChange
}: Props): JSX.Element => {
  const defaultLabel = useMemo(
    () => defaultPresetLabel(availableLayoutPresets, defaultLayoutPreset),
    [availableLayoutPresets, defaultLayoutPreset]
  );

  return (
    <label className="docsync-wp-field docsync-wp-layout-preset-selector">
      <span>{label}</span>
      <select
        disabled={disabled}
        onChange={(event) => onChange(event.currentTarget.value)}
        value={value}
      >
        <option value="">
          {defaultOptionLabel || sprintf(__('Use site default (%s)', 'brasth-document-sync-for-google-docs'), defaultLabel)}
        </option>
        {availableLayoutPresets.map((preset) => (
          <option key={preset.id} value={preset.id}>{preset.label}</option>
        ))}
      </select>
      <small className="docsync-wp-field-help">
        {helpText}
      </small>
    </label>
  );
};
