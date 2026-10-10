import { Fragment, createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord, SettingsResponse, SourceRecord } from '../../api';
import type { AvailablePostType } from '../../config';
import { ActivationResult } from '../activation/activation-result';
import { FolderActivationResult } from '../activation/folder-activation-result';
import { setupAdminLinks } from './setup-admin-links';
import type { SetupJourneyBasis, SetupJourneyPhase } from './setup-journey-state';

const textDomain = 'brasth-document-sync-for-google-docs';

type Props = {
  availablePostTypes: AvailablePostType[];
  busy: boolean;
  canChoose: boolean;
  creatablePostTypes: string[];
  phase: Extract<SetupJourneyPhase, 'source' | 'progress' | 'recovery'>;
  basis: SetupJourneyBasis;
  showTargetPicker: boolean;
  source: SourceRecord | null;
  settings: SettingsResponse;
  targetPostType: string;
  watch: FolderWatchRecord | null;
  workspaceAttention: number;
  workspaceImporting: number;
  workspaceSyncing: number;
  workspaceWatchAttention: number;
  onChoose?: (intent: 'document' | 'folder') => void;
  onOpenMaintenance: () => void;
  onRetrySource?: () => Promise<void>;
  onTargetPostTypeChange?: (postType: string) => void;
};

const postTypeLabel = (postType: string, availablePostTypes: AvailablePostType[]): string => {
  return availablePostTypes.find((item) => item.name === postType)?.label || postType;
};

export const SetupSourceTaskPanel = ({
  availablePostTypes,
  busy,
  canChoose,
  creatablePostTypes,
  phase,
  basis,
  showTargetPicker,
  source,
  settings,
  targetPostType,
  watch,
  workspaceAttention,
  workspaceImporting,
  workspaceSyncing,
  workspaceWatchAttention,
  onChoose,
  onOpenMaintenance,
  onRetrySource,
  onTargetPostTypeChange
}: Props): JSX.Element => {
  const chooseEnabled = canChoose && Boolean(onChoose) && targetPostType !== '' && !busy;
  const showSourceResult = Boolean(source) && (basis === 'source-running' || basis === 'source-failed') && Boolean(onRetrySource);
  const showWatchResult = Boolean(watch) && (basis === 'watch-running' || basis === 'watch-failed');
  const showWorkspaceProgress = basis === 'workspace-running';
  const showWorkspaceRecovery = basis === 'workspace-attention';

  const choose = (intent: 'document' | 'folder') => {
    if (!chooseEnabled || !onChoose) {
      return;
    }

    onChoose(intent);
  };

  return (
    <section aria-labelledby="docsync-wp-setup-source-title" className={`docsync-wp-setup-card${phase === 'source' ? ' docsync-wp-setup-source' : ''}`}>
      <header className="docsync-wp-setup-card__header">
        <h2 id="docsync-wp-setup-source-title">
          {phase === 'progress'
            ? __('First import is running', textDomain)
            : phase === 'recovery'
              ? __('First import needs attention', textDomain)
              : __('How do you want to bring Docs in?', textDomain)}
        </h2>
        {phase === 'source' ? (
          <p>{__('Both create WordPress drafts linked to Google. You publish when you’re ready.', textDomain)}</p>
        ) : null}
      </header>

      {phase === 'source' ? (
        <>
          <div className="docsync-wp-setup-choices">
            <button className="docsync-wp-setup-choice" disabled={!chooseEnabled} onClick={() => choose('document')} type="button">
              <span className="docsync-wp-setup-choice__icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6"><path d="M6 3h8l4 4v14H6zM14 3v5h4M9 12h6M9 16h6" /></svg></span>
              <strong>{__('Add one Google Doc', textDomain)}</strong>
              <span>{__('Paste a link or pick from Drive. Good for a single page or post.', textDomain)}</span>
              <span className="docsync-wp-setup-choice__action">{__('Choose a Doc', textDomain)} →</span>
            </button>
            <button className="docsync-wp-setup-choice" disabled={!chooseEnabled} onClick={() => choose('folder')} type="button">
              <span className="docsync-wp-setup-choice__icon is-folder" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6"><path d="M3 6h7l2 3h9v11H3z" /></svg></span>
              <strong>{__('Watch a Drive folder', textDomain)}</strong>
              <span>{__('Every Doc in the folder becomes a draft. A folder schedule brings in new Docs and syncs changes.', textDomain)}</span>
              <span className="docsync-wp-setup-choice__action">{__('Choose a folder', textDomain)} →</span>
            </button>
          </div>
          {showTargetPicker && creatablePostTypes.length > 1 ? <label className="docsync-wp-setup-target" htmlFor="docsync-wp-setup-target-type"><span>{__('Docs become', textDomain)}</span><select disabled={busy} id="docsync-wp-setup-target-type" onChange={(event) => onTargetPostTypeChange?.(event.currentTarget.value)} value={targetPostType}>{creatablePostTypes.map((postType) => <option key={postType} value={postType}>{postTypeLabel(postType, availablePostTypes)}</option>)}</select></label> : null}
          {!creatablePostTypes.length ? <p>{__('This site has no post type you can create yet, so the first import cannot start.', textDomain)}</p> : null}
          <div className="docsync-wp-setup-source-footer">
            <a href={setupAdminLinks.sources}>{__('Skip for now', textDomain)}</a>
            <p>{__('Defaults: new drafts use', textDomain)} <strong>{settings.availableLayoutPresets.find((preset) => preset.id === settings.defaultLayoutPreset)?.label || settings.defaultLayoutPreset}</strong> · {settings.syncInterval === 'off' ? __('scheduled sync is off.', textDomain) : `${__('sync', textDomain)} ${settings.syncInterval}.`}{' '}<button onClick={onOpenMaintenance} type="button">{__('Change defaults', textDomain)}</button></p>
          </div>
        </>
      ) : null}

      {showSourceResult && source && onRetrySource ? (
        <ActivationResult busy={busy} source={source} onRetry={onRetrySource} />
      ) : null}
      {showWatchResult && watch ? <FolderActivationResult watch={watch} /> : null}

      {showWorkspaceProgress ? (
        <div className="docsync-wp-setup-status" role="status">
          <p>{__('Your first import is still running. You can check its progress in Sources or Folders.', textDomain)}</p>
          {workspaceSyncing > 0 ? (
            <p>
              {sprintf(
                /* translators: %d: number of sources currently syncing. */
                __('Sources syncing: %d.', textDomain),
                workspaceSyncing
              )}
            </p>
          ) : null}
          {workspaceImporting > 0 ? (
            <p>
              {sprintf(
                /* translators: %d: number of folders currently importing. */
                __('Folders importing: %d.', textDomain),
                workspaceImporting
              )}
            </p>
          ) : null}
          <a className="button docsync-wp-button" href={setupAdminLinks.sources}>{__('View Sources', textDomain)}</a>
        </div>
      ) : null}

      {showWorkspaceRecovery ? (
        <div className="docsync-wp-setup-status" role="status">
          <p>{__('Some imports need attention. Open Sources or Folders to review the details and retry.', textDomain)}</p>
          {workspaceAttention > 0 ? (
            <p>
              {sprintf(
                /* translators: %d: number of sources that need attention. */
                __('Sources needing attention: %d.', textDomain),
                workspaceAttention
              )}
            </p>
          ) : null}
          {workspaceWatchAttention > 0 ? (
            <p>
              {sprintf(
                /* translators: %d: number of folders that need attention. */
                __('Folders needing attention: %d.', textDomain),
                workspaceWatchAttention
              )}
            </p>
          ) : null}
          <a className="button docsync-wp-button" href={setupAdminLinks.sources}>{__('Review Sources', textDomain)}</a>
          <a className="button docsync-wp-button" href={setupAdminLinks.folders}>{__('Review Folders', textDomain)}</a>
        </div>
      ) : null}
    </section>
  );
};
