import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { DriveItemSummary, FolderDocumentInventory, FolderWatchRecord } from '../../api';
import { getAdminConfig } from '../../config';
import { LayoutPresetSelector } from '../../shared/ui/layout-preset-selector';
import { OutputTypeChoice } from './output-type-choice';
import type { DocSourceOutputType } from './doc-source-modal-options';

type Props = {
  busy: boolean;
  canChooseElementor: boolean;
  excludedFileIds: string[];
  folderName: string;
  includeSubfolders: boolean;
  inventory: FolderDocumentInventory;
  interval: string;
  outputType: DocSourceOutputType;
  postStatus: 'draft' | 'publish';
  postType: string;
  layoutPreset: string;
  watch: FolderWatchRecord | null;
  onExcludeToggle: (fileId: string) => void;
  onIncludeSubfoldersChange: (value: boolean) => void;
  onIntervalChange: (value: string) => void;
  onLayoutPresetChange: (value: string) => void;
  onOutputTypeChange: (value: DocSourceOutputType) => void;
  onPostStatusChange: (value: 'draft' | 'publish') => void;
  onRetryFailed?: () => void;
};

const intervalLabel = (interval: string): string => {
  if (interval === 'hourly') {
    return __('Hourly', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'twicedaily') {
    return __('Twice daily', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'daily') {
    return __('Daily', 'brasth-document-sync-for-google-docs');
  }

  if (interval === 'off') {
    return __('Off', 'brasth-document-sync-for-google-docs');
  }

  return __('Use site default', 'brasth-document-sync-for-google-docs');
};

export const FolderWatchConfirmPanel = ({
  busy,
  canChooseElementor,
  excludedFileIds,
  folderName,
  includeSubfolders,
  inventory,
  interval,
  outputType,
  postStatus,
  postType,
  layoutPreset,
  watch,
  onExcludeToggle,
  onIncludeSubfoldersChange,
  onIntervalChange,
  onLayoutPresetChange,
  onOutputTypeChange,
  onPostStatusChange,
  onRetryFailed
}: Props): JSX.Element => {
  const config = getAdminConfig();
  const included = inventory.documents.filter((document) => !excludedFileIds.includes(document.fileId));
  const siteInterval = config.syncInterval || 'off';

  return (
    <div className="docsync-wp-folder-confirm">
      <div className="docsync-wp-folder-confirm__intro">
        <span className="docsync-wp-folder-confirm__label">{__('Folder inventory', 'brasth-document-sync-for-google-docs')}</span>
        <strong className="docsync-wp-folder-confirm__title">{folderName}</strong>
        <p>
          {includeSubfolders
            ? sprintf(
              /* translators: %s: folder name. */
              __('Google Docs in %s and its subfolders (up to 3 levels). New Docs become WordPress drafts. You publish.', 'brasth-document-sync-for-google-docs'),
              folderName
            )
            : sprintf(
              /* translators: %s: folder name. */
              __('Only Google Docs in %s, not subfolders. New Docs become WordPress drafts. You publish.', 'brasth-document-sync-for-google-docs'),
              folderName
            )}
        </p>
      </div>

      <label className="docsync-wp-folder-confirm__switch">
        <input
          checked={includeSubfolders}
          disabled={busy || Boolean(watch)}
          onChange={(event) => onIncludeSubfoldersChange(event.currentTarget.checked)}
          type="checkbox"
        />
        {__('Include subfolders', 'brasth-document-sync-for-google-docs')}
      </label>

      <ul className="docsync-wp-folder-confirm__list">
        {inventory.documents.map((document) => (
          <InventoryRow
            document={document}
            excluded={excludedFileIds.includes(document.fileId)}
            key={document.fileId}
            onToggle={() => onExcludeToggle(document.fileId)}
          />
        ))}
      </ul>

      <p className="docsync-wp-folder-confirm__count">
        <span className="docsync-wp-tabular">
          {sprintf(
            /* translators: 1: selected Doc count, 2: inventory cap. */
            __('%1$d of %2$d Docs selected', 'brasth-document-sync-for-google-docs'),
            included.length,
            50
          )}
        </span>
        {inventory.overflow
          ? __(' This folder has more than 50 Docs. Brasth will automate the first 50.', 'brasth-document-sync-for-google-docs')
          : null}
      </p>

      <div className="docsync-wp-folder-confirm__options">
        <label className="docsync-wp-field docsync-wp-field--compact">
          <span>{__('New posts', 'brasth-document-sync-for-google-docs')}</span>
          <select
            disabled={busy || Boolean(watch)}
            onChange={(event) => onPostStatusChange(event.currentTarget.value === 'publish' ? 'publish' : 'draft')}
            value={postStatus}
          >
            <option value="draft">{__('Create as drafts', 'brasth-document-sync-for-google-docs')}</option>
            <option value="publish">{__('Publish immediately', 'brasth-document-sync-for-google-docs')}</option>
          </select>
        </label>
        <label className="docsync-wp-field docsync-wp-field--compact">
          <span>{__('Folder schedule', 'brasth-document-sync-for-google-docs')}</span>
          <select
            disabled={busy || Boolean(watch)}
            onChange={(event) => onIntervalChange(event.currentTarget.value)}
            value={interval}
          >
            <option value="site">{sprintf(__('Use site default (%s)', 'brasth-document-sync-for-google-docs'), intervalLabel(siteInterval))}</option>
            <option value="off">{__('Off', 'brasth-document-sync-for-google-docs')}</option>
            <option value="hourly">{__('Hourly', 'brasth-document-sync-for-google-docs')}</option>
            <option value="twicedaily">{__('Twice daily', 'brasth-document-sync-for-google-docs')}</option>
            <option value="daily">{__('Daily', 'brasth-document-sync-for-google-docs')}</option>
            <option value="weekly">{__('Weekly', 'brasth-document-sync-for-google-docs')}</option>
          </select>
        </label>
        {canChooseElementor ? (
          <OutputTypeChoice disabled={busy || Boolean(watch)} onChange={onOutputTypeChange} value={outputType} />
        ) : null}
        {outputType === 'elementor' && canChooseElementor ? (
          <LayoutPresetSelector
            availableLayoutPresets={config.availableElementorLayoutPresets}
            defaultLayoutPreset="elementor_feature_block"
            disabled={busy || Boolean(watch)}
            label={__('Elementor layout preset', 'brasth-document-sync-for-google-docs')}
            onChange={onLayoutPresetChange}
            value={layoutPreset}
          />
        ) : (
          <LayoutPresetSelector
            availableLayoutPresets={config.availableLayoutPresets}
            defaultLayoutPreset={config.defaultLayoutPreset}
            disabled={busy || Boolean(watch)}
            onChange={onLayoutPresetChange}
            value={layoutPreset}
          />
        )}
      </div>

      {postStatus === 'publish' ? (
        <p className="docsync-wp-inline-warning">
          {__('New Docs in this folder will be published immediately. Later syncs update content only and do not change post status.', 'brasth-document-sync-for-google-docs')}
        </p>
      ) : null}

      <p className="docsync-wp-folder-confirm__hint">
        {sprintf(
          /* translators: 1: post type, 2: schedule label. */
          __('Creates %1$s posts. Folder schedule: %2$s.', 'brasth-document-sync-for-google-docs'),
          postType,
          interval === 'site' ? intervalLabel(siteInterval) : intervalLabel(interval)
        )}
      </p>

      {watch ? (
        <div className="docsync-wp-folder-confirm__progress" aria-live="polite">
          <strong className="docsync-wp-tabular">
            {sprintf(
              /* translators: 1: imported count, 2: total count. */
              __('Created %1$d of %2$d', 'brasth-document-sync-for-google-docs'),
              watch.importedCount,
              watch.totalCount
            )}
          </strong>
          {watch.failed.length > 0 ? (
            <div>
              <p>{__('Some Docs could not be imported.', 'brasth-document-sync-for-google-docs')}</p>
              {onRetryFailed ? (
                <button className="button" disabled={busy} onClick={onRetryFailed} type="button">
                  {__('Retry failed', 'brasth-document-sync-for-google-docs')}
                </button>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
    </div>
  );
};

const InventoryRow = ({
  document,
  excluded,
  onToggle
}: {
  document: DriveItemSummary;
  excluded: boolean;
  onToggle: () => void;
}): JSX.Element => {
  return (
    <li>
      <label>
        <input checked={!excluded} onChange={onToggle} type="checkbox" />
        <span>
          <strong>{document.name}</strong>
          {document.folderPath ? <span>{document.folderPath}</span> : null}
        </span>
      </label>
    </li>
  );
};
