import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getAdminConfig } from '../../config';
import { LayoutPresetSelector } from '../../shared/ui/layout-preset-selector';
import { intervalLabel } from './folder-watch-labels';

export type FolderWatchEditDraft = {
  syncInterval: string;
  postStatus: 'draft' | 'publish';
  includeSubfolders: boolean;
  layoutPreset: string;
  elementorSync: boolean;
  elementorPreset: string;
};

type Props = {
  busy: boolean;
  draft: FolderWatchEditDraft;
  nextScanAt?: string;
  postType: string;
  onChange: (draft: FolderWatchEditDraft) => void;
};

export const FolderWatchEditForm = ({ busy, draft, nextScanAt = '', postType, onChange }: Props): JSX.Element => {
  const config = getAdminConfig();
  const canChooseElementor = config.elementorAvailable && config.elementorSyncEnabled;

  return (
    <div className="docsync-wp-folder-watch-form">
      <p className="docsync-wp-folder-watch-form__hint">
        {__('Post type is fixed for this watch. Create a new watch to target another type.', 'brasth-document-sync-for-google-docs')}
        {' '}
        <span className="docsync-wp-row-tag">{postType}</span>
      </p>
      <label className="docsync-wp-field docsync-wp-field--compact">
        <span>{__('Folder schedule', 'brasth-document-sync-for-google-docs')}</span>
        <select
          disabled={busy}
          onChange={(event) => onChange({ ...draft, syncInterval: event.currentTarget.value })}
          value={draft.syncInterval}
        >
          <option value="site">{__('Use site default', 'brasth-document-sync-for-google-docs')}</option>
          <option value="off">{intervalLabel('off')}</option>
          <option value="hourly">{intervalLabel('hourly')}</option>
          <option value="twicedaily">{intervalLabel('twicedaily')}</option>
          <option value="daily">{intervalLabel('daily')}</option>
          <option value="weekly">{intervalLabel('weekly')}</option>
        </select>
        <small>
          {nextScanAt
            ? sprintf(
              __('Member Docs re-sync on this same interval. Next scan: %s.', 'brasth-document-sync-for-google-docs'),
              nextScanAt
            )
            : __('Member Docs re-sync on this same interval.', 'brasth-document-sync-for-google-docs')}
        </small>
      </label>
      <label className="docsync-wp-field docsync-wp-field--compact">
        <span>{__('New posts', 'brasth-document-sync-for-google-docs')}</span>
        <select
          disabled={busy}
          onChange={(event) => onChange({ ...draft, postStatus: event.currentTarget.value === 'publish' ? 'publish' : 'draft' })}
          value={draft.postStatus}
        >
          <option value="draft">{__('Create as drafts', 'brasth-document-sync-for-google-docs')}</option>
          <option value="publish">{__('Publish immediately', 'brasth-document-sync-for-google-docs')}</option>
        </select>
      </label>
      <label className="docsync-wp-folder-confirm__switch">
        <input
          checked={draft.includeSubfolders}
          disabled={busy}
          onChange={(event) => onChange({ ...draft, includeSubfolders: event.currentTarget.checked })}
          type="checkbox"
        />
        {__('Include subfolders', 'brasth-document-sync-for-google-docs')}
      </label>
      {canChooseElementor ? (
        <label className="docsync-wp-field docsync-wp-field--compact">
          <span>{__('Output', 'brasth-document-sync-for-google-docs')}</span>
          <select
            disabled={busy}
            onChange={(event) => onChange({ ...draft, elementorSync: event.currentTarget.value === 'elementor' })}
            value={draft.elementorSync ? 'elementor' : 'blocks'}
          >
            <option value="blocks">{__('WordPress Blocks', 'brasth-document-sync-for-google-docs')}</option>
            <option value="elementor">{__('Elementor Layout', 'brasth-document-sync-for-google-docs')}</option>
          </select>
        </label>
      ) : null}
      {canChooseElementor && draft.elementorSync ? (
        <LayoutPresetSelector
          availableLayoutPresets={config.availableElementorLayoutPresets}
          defaultLayoutPreset="elementor_feature_block"
          disabled={busy}
          label={__('Elementor layout preset', 'brasth-document-sync-for-google-docs')}
          onChange={(value) => onChange({ ...draft, elementorPreset: value })}
          value={draft.elementorPreset}
        />
      ) : (
        <LayoutPresetSelector
          availableLayoutPresets={config.availableLayoutPresets}
          defaultLayoutPreset={config.defaultLayoutPreset}
          disabled={busy}
          onChange={(value) => onChange({ ...draft, layoutPreset: value })}
          value={draft.layoutPreset}
        />
      )}
    </div>
  );
};
