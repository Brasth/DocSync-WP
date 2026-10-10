import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SettingsResponse, SettingsUpdate } from '../../api';
import { getAdminConfig } from '../../config';
import { AdminButton } from '../../shared/ui/admin-button';
import { samePostTypes } from './google-setup-utils';

const textDomain = 'brasth-document-sync-for-google-docs';
const scheduleOptions = [
  { value: 'off', label: __('Off', textDomain) },
  { value: 'hourly', label: __('Hourly', textDomain) },
  { value: 'twicedaily', label: __('Twice daily', textDomain) },
  { value: 'daily', label: __('Daily', textDomain) },
  { value: 'weekly', label: __('Weekly', textDomain) }
];

type Props = {
  busy: boolean;
  settings: SettingsResponse;
  onDirtyChange: (dirty: boolean) => void;
  onSave: (settings: SettingsUpdate) => Promise<boolean>;
};

const withCurrentOption = (options: { value: string; label: string }[], current: string) => {
  if (current === '' || options.some((option) => option.value === current)) {
    return options;
  }

  return [{ value: current, label: current }, ...options];
};

export const SetupDefaultsForm = ({ busy, settings, onDirtyChange, onSave }: Props): JSX.Element => {
  const [enabledPostTypes, setEnabledPostTypes] = useState(settings.enabledPostTypes);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);
  const [defaultLayoutPreset, setDefaultLayoutPreset] = useState(settings.defaultLayoutPreset);
  const [elementorSyncEnabled, setElementorSyncEnabled] = useState(settings.elementorSyncEnabled);
  const [elementorTouched, setElementorTouched] = useState(false);
  const [telemetryEnabled, setTelemetryEnabled] = useState(settings.telemetryEnabled);
  const schedules = withCurrentOption(scheduleOptions, settings.syncInterval);
  const layouts = withCurrentOption(
    settings.availableLayoutPresets.map((preset) => ({ value: preset.id, label: preset.label })),
    settings.defaultLayoutPreset
  );
  const elementorDirty = settings.elementorPreferencePresent
    ? elementorSyncEnabled !== settings.elementorSyncEnabled
    : elementorTouched && elementorSyncEnabled;
  const dirty = syncInterval !== settings.syncInterval
    || defaultLayoutPreset !== settings.defaultLayoutPreset
    || telemetryEnabled !== settings.telemetryEnabled
    || elementorDirty
    || !samePostTypes(enabledPostTypes, settings.enabledPostTypes);

  const savedPostTypesKey = settings.enabledPostTypes.join('|');
  useEffect(() => { setEnabledPostTypes(savedPostTypesKey.split('|').filter(Boolean)); }, [savedPostTypesKey]);
  useEffect(() => { setSyncInterval(settings.syncInterval); }, [settings.syncInterval]);
  useEffect(() => { setDefaultLayoutPreset(settings.defaultLayoutPreset); }, [settings.defaultLayoutPreset]);
  useEffect(() => {
    setElementorSyncEnabled(settings.elementorSyncEnabled);
    setElementorTouched(false);
  }, [settings.elementorSyncEnabled, settings.elementorPreferencePresent]);
  useEffect(() => { setTelemetryEnabled(settings.telemetryEnabled); }, [settings.telemetryEnabled]);

  useEffect(() => {
    onDirtyChange(dirty);
    return () => onDirtyChange(false);
  }, [dirty, onDirtyChange]);

  const togglePostType = (postType: string) => {
    if (postType === 'post') {
      return;
    }

    setEnabledPostTypes((current) => {
      if (current.includes(postType)) {
        return current.filter((item) => item !== postType);
      }

      return [...current, postType];
    });
  };

  const submit = async () => {
    if (!dirty || busy) {
      return;
    }

    const nextPostTypes = enabledPostTypes.includes('post') ? enabledPostTypes : ['post', ...enabledPostTypes];
    const payload: SettingsUpdate = {
      defaultLayoutPreset,
      enabledPostTypes: nextPostTypes,
      syncInterval,
      telemetryEnabled
    };

    if (settings.elementorPreferencePresent || (elementorTouched && elementorSyncEnabled)) {
      payload.elementorSyncEnabled = elementorSyncEnabled;
    }

    await onSave(payload);
  };

  return (
    <form
      className="docsync-wp-setup-defaults"
      onSubmit={(event) => {
        event.preventDefault();
        void submit();
      }}
    >
      <label htmlFor="docsync-wp-setup-schedule">
        <span>{__('Scheduled sync', textDomain)}</span>
        <select disabled={busy} id="docsync-wp-setup-schedule" onChange={(event) => setSyncInterval(event.currentTarget.value)} value={syncInterval}>
          {schedules.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>

      <p className="docsync-wp-setup-help">{__('Runs through WP-Cron. Individual sources and folders can override this.', textDomain)}</p>
      <label htmlFor="docsync-wp-setup-layout">
        <span>{__('Default layout', textDomain)}</span>
        <select disabled={busy} id="docsync-wp-setup-layout" onChange={(event) => setDefaultLayoutPreset(event.currentTarget.value)} value={defaultLayoutPreset}>
          {defaultLayoutPreset === '' ? <option value="">{__('Not set', textDomain)}</option> : null}
          {layouts.map((option) => (
            <option key={option.value} value={option.value}>{option.label}</option>
          ))}
        </select>
      </label>

      <fieldset className="docsync-wp-setup-post-types">
        <legend>{__('Docs can become', textDomain)}</legend>
        {settings.availablePostTypes.map((postType) => (
          <label key={postType.name}>
            <input
              checked={enabledPostTypes.includes(postType.name) || postType.name === 'post'}
              disabled={busy || postType.name === 'post'}
              onChange={() => togglePostType(postType.name)}
              type="checkbox"
            />
            {postType.label}
            {postType.name === 'post' ? <span>{__('(always)', textDomain)}</span> : null}
          </label>
        ))}
      </fieldset>

      <label className="docsync-wp-setup-check">
        <input
          checked={elementorSyncEnabled}
          disabled={busy || !getAdminConfig().elementorAvailable}
          onChange={(event) => {
            setElementorTouched(true);
            setElementorSyncEnabled(event.currentTarget.checked);
          }}
          type="checkbox"
        />
        <span>{__('Elementor sync', textDomain)}</span>
      </label>
      {!getAdminConfig().elementorAvailable ? <p className="docsync-wp-setup-help">{__('Elementor is inactive. Activate it to use Elementor layouts; your saved preference is retained.', textDomain)}</p> : null}
      {settings.elementorPreferencePresent ? null : (
        <p className="docsync-wp-setup-help">{__('Elementor support was not in the saved settings. Leave this unchecked to keep that choice.', textDomain)}</p>
      )}

      <label className="docsync-wp-setup-check">
        <input
          checked={telemetryEnabled}
          disabled={busy}
          onChange={(event) => setTelemetryEnabled(event.currentTarget.checked)}
          type="checkbox"
        />
        <span>{__('Anonymous diagnostics', textDomain)}</span>
      </label>
      <p className="docsync-wp-setup-help">
        {__('One weekly ping with an install ID hash, plugin slug, plugin, WordPress, PHP and consent versions. No Google data, site URL, email or Doc content.', textDomain)}
        {' '}<a href="https://docsyncwp.com/privacy-policy" rel="noreferrer" target="_blank">{__('Privacy', textDomain)}</a>
      </p>

      <div className="docsync-wp-setup-actions docsync-wp-setup-defaults-footer">
        <AdminButton disabled={!dirty || busy} type="submit" variant="primary">
          {__('Save defaults', textDomain)}
        </AdminButton>
      </div>
    </form>
  );
};
