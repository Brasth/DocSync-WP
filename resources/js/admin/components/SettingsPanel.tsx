import { createElement, useState } from '@wordpress/element';

import type { SettingsResponse } from '../api';

type Props = {
  settings: SettingsResponse;
  busy: boolean;
  onSave: (settings: Partial<SettingsResponse> & { clientSecret?: string }) => Promise<void>;
};

export const SettingsPanel = ({ settings, busy, onSave }: Props): JSX.Element => {
  const [clientId, setClientId] = useState(settings.clientId);
  const [clientSecret, setClientSecret] = useState('');
  const [pickerApiKey, setPickerApiKey] = useState(settings.pickerApiKey);
  const [pickerAppId, setPickerAppId] = useState(settings.pickerAppId);
  const [enabledPostTypes, setEnabledPostTypes] = useState(settings.enabledPostTypes);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);

  const togglePostType = (postType: string) => {
    setEnabledPostTypes((current) => {
      if (postType === 'post') {
        return current.includes('post') ? current : ['post', ...current];
      }

      if (current.includes(postType)) {
        return current.filter((item) => item !== postType);
      }

      return [...current, postType];
    });
  };

  const submit = async () => {
    await onSave({
      clientId,
      ...(clientSecret ? { clientSecret } : {}),
      pickerApiKey,
      pickerAppId,
      enabledPostTypes,
      syncInterval,
      defaultExportFormat: settings.defaultExportFormat,
      defaultPostStatus: settings.defaultPostStatus,
      scopeMode: settings.scopeMode
    });
    setClientSecret('');
  };

  return (
    <section className="docsync-wp-card">
      <div className="docsync-wp-card__header">
        <h2>Google settings</h2>
        <p>Use a Google OAuth web client. Redirect URI is the plugin REST callback URL.</p>
      </div>

      <div className="docsync-wp-settings-grid">
        <label>
          <span>OAuth client ID</span>
          <input className="regular-text" onChange={(event) => setClientId(event.currentTarget.value)} type="text" value={clientId} />
        </label>
        <label>
          <span>OAuth client secret</span>
          <input
            className="regular-text"
            onChange={(event) => setClientSecret(event.currentTarget.value)}
            placeholder={settings.hasClientSecret ? 'Saved. Enter a new secret to replace.' : ''}
            type="password"
            value={clientSecret}
          />
        </label>
        <label>
          <span>Picker API key</span>
          <input className="regular-text" onChange={(event) => setPickerApiKey(event.currentTarget.value)} type="text" value={pickerApiKey} />
        </label>
        <label>
          <span>Picker app ID</span>
          <input className="regular-text" onChange={(event) => setPickerAppId(event.currentTarget.value)} type="text" value={pickerAppId} />
        </label>
        <label>
          <span>Scheduled sync</span>
          <select onChange={(event) => setSyncInterval(event.currentTarget.value)} value={syncInterval}>
            <option value="off">Off</option>
            <option value="hourly">Hourly</option>
            <option value="twicedaily">Twice daily</option>
            <option value="daily">Daily</option>
          </select>
        </label>
      </div>

      <fieldset className="docsync-wp-post-types">
        <legend>Enabled post types</legend>
        {settings.availablePostTypes.map((postType) => (
          <label key={postType.name}>
            <input
              checked={enabledPostTypes.includes(postType.name)}
              disabled={postType.name === 'post'}
              onChange={() => togglePostType(postType.name)}
              type="checkbox"
            />
            {postType.label}
          </label>
        ))}
      </fieldset>

      <button className="button button-primary" disabled={busy} onClick={submit} type="button">
        Save settings
      </button>
    </section>
  );
};
