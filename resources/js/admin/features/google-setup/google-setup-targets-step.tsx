import { createElement } from '@wordpress/element';

import type { AvailablePostType } from '../../config';

type Props = {
  availablePostTypes: AvailablePostType[];
  enabledPostTypes: string[];
  syncInterval: string;
  onTogglePostType: (postType: string) => void;
  onSyncIntervalChange: (syncInterval: string) => void;
};

export const GoogleSetupTargetsStep = ({
  availablePostTypes,
  enabledPostTypes,
  syncInterval,
  onTogglePostType,
  onSyncIntervalChange
}: Props): JSX.Element => {
  return (
    <li>
      <div className="docsync-wp-step-heading">
        <span>4</span>
        <div>
          <h3>Choose WordPress targets</h3>
          <p>Post is required. Page and public custom post types can also accept synced drafts.</p>
        </div>
      </div>
      <fieldset className="docsync-wp-post-types">
        <legend>Enabled post types</legend>
        {availablePostTypes.map((postType) => (
          <label key={postType.name}>
            <input
              checked={enabledPostTypes.includes(postType.name)}
              disabled={postType.name === 'post'}
              onChange={() => onTogglePostType(postType.name)}
              type="checkbox"
            />
            {postType.label}
            {postType.name === 'post' ? <span>Required</span> : null}
          </label>
        ))}
      </fieldset>

      <label className="docsync-wp-field docsync-wp-field--compact">
        <span>Scheduled sync</span>
        <select onChange={(event) => onSyncIntervalChange(event.currentTarget.value)} value={syncInterval}>
          <option value="off">Off</option>
          <option value="hourly">Hourly</option>
          <option value="twicedaily">Twice daily</option>
          <option value="daily">Daily</option>
        </select>
      </label>
    </li>
  );
};
