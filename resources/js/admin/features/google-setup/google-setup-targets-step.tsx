import { createElement, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { AvailablePostType } from '../../config';
import { SetupStepStateBadge, type SetupStepState } from './setup-step-state';

type Props = {
  availablePostTypes: AvailablePostType[];
  elementorSyncEnabled: boolean;
  enabledPostTypes: string[];
  initialOpen: boolean;
  syncInterval: string;
  stepState: SetupStepState;
  onElementorSyncChange: (enabled: boolean) => void;
  onTogglePostType: (postType: string) => void;
  onSyncIntervalChange: (syncInterval: string) => void;
};

export const GoogleSetupTargetsStep = ({
  availablePostTypes,
  elementorSyncEnabled,
  enabledPostTypes,
  initialOpen,
  syncInterval,
  stepState,
  onElementorSyncChange,
  onTogglePostType,
  onSyncIntervalChange
}: Props): JSX.Element => {
  const [isOpen, setIsOpen] = useState(initialOpen);

  return (
    <details className="docsync-wp-setup-disclosure docsync-wp-setup-defaults" onToggle={(event) => setIsOpen(event.currentTarget.open)} open={isOpen}>
      <summary className="docsync-wp-step-heading">
        <span aria-hidden="true" className="dashicons dashicons-admin-settings docsync-wp-step-icon" />
        <div>
          <div className="docsync-wp-step-title-row">
            <h3>{__('Sync defaults', 'brasth-document-sync-for-google-docs')}</h3>
            <SetupStepStateBadge state={stepState} />
          </div>
          <p>{__('Post types, schedule, and Elementor support. These can be adjusted after setup.', 'brasth-document-sync-for-google-docs')}</p>
        </div>
      </summary>
      <div className="docsync-wp-step-body">
        <fieldset className="docsync-wp-post-types">
          <legend>{__('Enabled post types', 'brasth-document-sync-for-google-docs')}</legend>
          {availablePostTypes.map((postType) => (
            <label key={postType.name}>
              <input
                checked={enabledPostTypes.includes(postType.name)}
                disabled={postType.name === 'post'}
                onChange={() => onTogglePostType(postType.name)}
                type="checkbox"
              />
              {postType.label}
              {postType.name === 'post' ? <span>{__('Required', 'brasth-document-sync-for-google-docs')}</span> : null}
            </label>
          ))}
        </fieldset>

        <label className="docsync-wp-field docsync-wp-field--compact">
          <span>{__('Scheduled sync', 'brasth-document-sync-for-google-docs')}</span>
          <select onChange={(event) => onSyncIntervalChange(event.currentTarget.value)} value={syncInterval}>
            <option value="off">{__('Off', 'brasth-document-sync-for-google-docs')}</option>
            <option value="hourly">{__('Hourly', 'brasth-document-sync-for-google-docs')}</option>
            <option value="twicedaily">{__('Twice daily', 'brasth-document-sync-for-google-docs')}</option>
            <option value="daily">{__('Daily', 'brasth-document-sync-for-google-docs')}</option>
          </select>
        </label>

        <label className="docsync-wp-checkbox-row">
          <input
            checked={elementorSyncEnabled}
            onChange={(event) => onElementorSyncChange(event.currentTarget.checked)}
            type="checkbox"
          />
          <span>{__('Enable Elementor sync support when Elementor is active', 'brasth-document-sync-for-google-docs')}</span>
        </label>
      </div>
    </details>
  );
};
