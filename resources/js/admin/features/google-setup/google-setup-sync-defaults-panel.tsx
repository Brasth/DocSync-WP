import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SettingsResponse } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { GoogleSetupTargetsStep } from './google-setup-targets-step';
import { samePostTypes } from './google-setup-utils';

type Props = {
  busy: boolean;
  settings: SettingsResponse;
  onSave: (settings: Partial<SettingsResponse>) => Promise<void>;
};

export const GoogleSetupSyncDefaultsPanel = ({
  busy,
  settings,
  onSave
}: Props): JSX.Element => {
  const [enabledPostTypes, setEnabledPostTypes] = useState(settings.enabledPostTypes);
  const [syncInterval, setSyncInterval] = useState(settings.syncInterval);
  const [defaultLayoutPreset, setDefaultLayoutPreset] = useState(settings.defaultLayoutPreset);
  const [elementorSyncEnabled, setElementorSyncEnabled] = useState(settings.elementorSyncEnabled);

  const hasChanges =
    syncInterval !== settings.syncInterval ||
    defaultLayoutPreset !== settings.defaultLayoutPreset ||
    elementorSyncEnabled !== settings.elementorSyncEnabled ||
    !samePostTypes(enabledPostTypes, settings.enabledPostTypes);
  const stepState = hasChanges ? 'needs-action' : 'ready';

  useEffect(() => {
    setEnabledPostTypes(settings.enabledPostTypes);
    setSyncInterval(settings.syncInterval);
    setDefaultLayoutPreset(settings.defaultLayoutPreset);
    setElementorSyncEnabled(settings.elementorSyncEnabled);
  }, [settings]);

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
      enabledPostTypes,
      syncInterval,
      defaultLayoutPreset,
      elementorSyncEnabled
    });
  };

  return (
    <section className={`docsync-wp-setup-sidebar-panel${hasChanges ? ' is-dirty' : ''}`}>
      <GoogleSetupTargetsStep
        availableLayoutPresets={settings.availableLayoutPresets}
        availablePostTypes={settings.availablePostTypes}
        defaultLayoutPreset={defaultLayoutPreset}
        elementorSyncEnabled={elementorSyncEnabled}
        enabledPostTypes={enabledPostTypes}
        initialOpen={hasChanges}
        onDefaultLayoutPresetChange={setDefaultLayoutPreset}
        onElementorSyncChange={setElementorSyncEnabled}
        onSyncIntervalChange={setSyncInterval}
        onTogglePostType={togglePostType}
        stepState={stepState}
        syncInterval={syncInterval}
      />
      {hasChanges ? (
        <div className="docsync-wp-setup-secondary-actions">
          <span className="docsync-wp-setup-secondary-status">{__('Unsaved sync defaults', 'brasth-document-sync-for-google-docs')}</span>
          <AdminButton disabled={busy} onClick={submit}>
            {__('Save sync defaults', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      ) : null}
    </section>
  );
};
