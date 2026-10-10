import { createElement, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { SourceRecord } from '../api';
import type { ContentKind, ContentOrderBy, ContentItem } from '../api/journey-types';
import { type AvailablePostType, getAdminConfig } from '../config';
import { BackgroundSyncPoller } from '../features/post-sync/background-sync-poller';
import { SourcesFolderWatches } from '../features/sources/sources-folder-watches';
import { SourcesTableSkeleton } from '../features/sources/sources-table';
import { SourceHealthSummary } from '../features/sources/source-health-summary';
import { ActivationGuidance } from '../features/activation/activation-guidance';
import { ActivationResult } from '../features/activation/activation-result';
import { LinkExistingPosts } from '../features/add-content/link-existing-posts';
import { formatEditedDate } from '../features/add-content/use-add-content';
import { DocSourceModal } from '../features/doc-source-modal/doc-source-modal';
import { ensureLazyStyle } from '../features/doc-source-modal/lazy-drive-browser-panel';
import { AdminButton } from '../shared/ui/admin-button';
import { AdminShell } from '../shared/ui/admin-shell';
import { CronHealthBanner } from '../shared/ui/cron-health-banner';
import { EmptyState } from '../shared/ui/empty-state';
import { SkeletonTableRows } from '../shared/ui/skeleton';
import { StatusPill } from '../shared/ui/status-pill';
import { isQueuedSync, shouldShowSyncProgress, SyncProgress } from '../shared/ui/sync-progress';
import { type SourcesContentFilters, type SourcesContentRow, type SourcesListingMode, useSourcesApp } from './use-sources-app';

const TEXT_DOMAIN = 'brasth-document-sync-for-google-docs';

const statusOptions = [
  { value: '', label: __('All statuses', TEXT_DOMAIN) },
  { value: 'linked', label: __('Linked', TEXT_DOMAIN) },
  { value: 'syncing', label: __('Syncing', TEXT_DOMAIN) },
  { value: 'synced', label: __('Synced', TEXT_DOMAIN) },
  { value: 'skipped', label: __('Skipped', TEXT_DOMAIN) },
  { value: 'error', label: __('Error', TEXT_DOMAIN) }
];

const kindOptions: { value: ContentKind; label: string }[] = [
  { value: 'all', label: __('All content', TEXT_DOMAIN) },
  { value: 'google', label: __('Synced Google Docs', TEXT_DOMAIN) },
  { value: 'oneTime', label: __('One-time imports', TEXT_DOMAIN) }
];

const sortOptions: { value: string; label: string }[] = [
  { value: 'modified:desc', label: __('Recently modified', TEXT_DOMAIN) },
  { value: 'modified:asc', label: __('Least recently modified', TEXT_DOMAIN) },
  { value: 'date:desc', label: __('Newest first', TEXT_DOMAIN) },
  { value: 'date:asc', label: __('Oldest first', TEXT_DOMAIN) },
  { value: 'title:asc', label: __('Title A–Z', TEXT_DOMAIN) },
  { value: 'title:desc', label: __('Title Z–A', TEXT_DOMAIN) }
];

const formatLabels: Record<'docx' | 'pptx' | 'pdf', string> = {
  docx: __('Word document', TEXT_DOMAIN),
  pptx: __('PowerPoint deck', TEXT_DOMAIN),
  pdf: __('PDF', TEXT_DOMAIN)
};

const statusLabel = (source: SourceRecord): string => (source.syncError ? 'error' : source.syncStatus || 'linked');

const syncMethodLabel = (source: SourceRecord): string => {
  if (source.lastSyncMethod === 'docs_api_fallback') {
    return __('Large-doc fallback', TEXT_DOMAIN);
  }

  return source.lastSyncMethod === 'html_zip' ? __('HTML ZIP', TEXT_DOMAIN) : '';
};

const logsUrl = (postId: number): string => `admin.php?page=brasth-document-sync-for-google-docs-logs&post_id=${encodeURIComponent(String(postId))}`;

const postLabel = (title: string, postId: number): string => title || sprintf(__('Post %d', TEXT_DOMAIN), postId);

const FormatTag = ({ format }: { format: 'docx' | 'pptx' | 'pdf' }): JSX.Element => (
  <span className={`docsync-wp-content-format docsync-wp-content-format--${format}`}>{format.toUpperCase()}</span>
);

type TableProps = {
  rows: SourcesContentRow[];
  sources: SourceRecord[];
  mode: SourcesListingMode;
  truncated: boolean;
  availablePostTypes: AvailablePostType[];
  folderWatchNames: Record<string, string>;
  filters: SourcesContentFilters;
  hasMore: boolean;
  busy: boolean;
  canAddContent: boolean;
  canUpload: boolean;
  onFiltersChange: (filters: SourcesContentFilters) => Promise<void>;
  onRefresh: () => Promise<void>;
  onLoadMore: () => Promise<void>;
  onSync: (postId: number) => Promise<void>;
  onSyncAll: () => Promise<void>;
  onAddContent: () => void;
  onUpload: () => void;
  onWatchFolder: () => void;
};

const emptyFilters = (filters: SourcesContentFilters): SourcesContentFilters => ({
  ...filters,
  search: '',
  postType: '',
  status: '',
  folderWatchId: '',
  kind: 'all'
});

/**
 * Synced Google Docs and one-time imports in one server-paged listing. One-time posts have
 * no Google file, so they show their upload provenance and never offer Sync.
 */
const SourcesContentTable = ({
  rows,
  sources,
  mode,
  truncated,
  availablePostTypes,
  folderWatchNames,
  filters,
  hasMore,
  busy,
  canAddContent,
  canUpload,
  onFiltersChange,
  onRefresh,
  onLoadMore,
  onSync,
  onSyncAll,
  onAddContent,
  onUpload,
  onWatchFolder
}: TableProps): JSX.Element => {
  const [draft, setDraft] = useState<SourcesContentFilters>(filters);
  const hasActiveFilters = Boolean(filters.search || filters.postType || filters.status || filters.folderWatchId || filters.kind !== 'all');
  const googleOnlyFilter = draft.status !== '' || draft.folderWatchId !== '';
  // The legacy listing without status or folder filters means the combined route is not available on this site.
  const legacyOnly = mode === 'sources' && filters.status === '' && filters.folderWatchId === '';
  const kindLocked = googleOnlyFilter || legacyOnly;
  const sortValue = `${draft.orderBy}:${draft.order}`;
  const sourceById = new Map(sources.map((source) => [source.postId, source]));

  useEffect(() => {
    setDraft(filters);
  }, [filters]);

  const apply = (next: SourcesContentFilters) => onFiltersChange({
    ...next,
    search: next.search.trim(),
    // Status and folder describe Google sync, so they always list Google sources.
    kind: next.status !== '' || next.folderWatchId !== '' ? 'all' : next.kind
  });

  const folderWatchLabel = filters.folderWatchId ? folderWatchNames[filters.folderWatchId] || filters.folderWatchId : '';

  const renderGoogleRow = (row: Extract<SourcesContentRow, { kind: 'google' }>, source: SourceRecord) => (
    <tr key={`google-${row.postId}`}>
      <td>
        <div className="docsync-wp-source-target">
          <a className="docsync-wp-source-target__title" href={source.editUrl}>{postLabel(source.postTitle, source.postId)}</a>
          <div className="docsync-wp-source-target__meta">
            {source.postType ? <span className="docsync-wp-row-tag">{source.postType}</span> : null}
            {source.postStatus ? <span className="docsync-wp-row-tag">{source.postStatus}</span> : null}
            {source.folderWatchId ? (
              <span className="docsync-wp-folder-watch-chip">
                {sprintf(
                  /* translators: %s: folder name. */
                  __('From folder: %s', TEXT_DOMAIN),
                  folderWatchNames[source.folderWatchId] || source.folderWatchId
                )}
              </span>
            ) : null}
          </div>
        </div>
      </td>
      <td>
        <div className="docsync-wp-source-doc">
          {source.googleDocUrl ? (
            <a className="docsync-wp-source-doc__title" href={source.googleDocUrl} rel="noreferrer" target="_blank">
              {source.googleTitle || source.googleFileId}
            </a>
          ) : (
            <span className="docsync-wp-source-doc__title">{source.googleTitle || source.googleFileId}</span>
          )}
          {row.importedFrom ? (
            <small className="docsync-wp-content-provenance">
              <FormatTag format={row.importedFrom.format} />
              {sprintf(
                /* translators: %s: original uploaded file name. */
                __('Imported from %s · kept in sync', TEXT_DOMAIN),
                row.importedFrom.originalName
              )}
            </small>
          ) : source.googleFileId && source.googleTitle ? (
            <small className="docsync-wp-source-doc__id">{source.googleFileId}</small>
          ) : null}
        </div>
      </td>
      <td>
        <div className="docsync-wp-source-status-cell">
          <StatusPill status={statusLabel(source)} />
          {shouldShowSyncProgress(source) ? (
            <div className="docsync-wp-source-sync-block">
              <SyncProgress indeterminate={isQueuedSync(source)} message={source.syncMessage} progress={source.syncProgress} />
            </div>
          ) : null}
          {source.syncError ? <small className="docsync-wp-source-error-text">{source.syncError}</small> : null}
        </div>
      </td>
      <td>
        <div className="docsync-wp-source-last-sync">
          <span>{source.lastSyncedAt || __('Never', TEXT_DOMAIN)}</span>
          {syncMethodLabel(source) ? <span className="docsync-wp-row-tag">{syncMethodLabel(source)}</span> : null}
        </div>
      </td>
      <td>
        <div className="docsync-wp-source-actions">
          <AdminButton className="docsync-wp-source-sync-button" disabled={busy} onClick={() => onSync(source.postId)} size="small" variant="primary">
            {__('Sync', TEXT_DOMAIN)}
          </AdminButton>
          <a
            aria-label={sprintf(__('View logs for %s', TEXT_DOMAIN), postLabel(source.postTitle, source.postId))}
            className="button button-secondary docsync-wp-button docsync-wp-button--small docsync-wp-view-logs-link"
            href={logsUrl(source.postId)}
          >
            <span aria-hidden="true" className="dashicons dashicons-list-view" />
            <span>{__('Logs', TEXT_DOMAIN)}</span>
          </a>
        </div>
      </td>
    </tr>
  );

  const renderOneTimeRow = (item: ContentItem) => {
    if (item.provenance.kind !== 'oneTime') {
      return null;
    }

    const provenance = item.provenance;
    const importedOn = formatEditedDate(provenance.importedAt);

    return (
      <tr key={`one-time-${item.postId}`}>
        <td>
          <div className="docsync-wp-source-target">
            <a className="docsync-wp-source-target__title" href={item.editUrl}>{postLabel(item.title, item.postId)}</a>
            <div className="docsync-wp-source-target__meta">
              {item.postType ? <span className="docsync-wp-row-tag">{item.postType}</span> : null}
              {item.postStatus ? <span className="docsync-wp-row-tag">{item.postStatus}</span> : null}
            </div>
          </div>
        </td>
        <td>
          <div className="docsync-wp-source-doc">
            <span className="docsync-wp-source-doc__title docsync-wp-content-provenance">
              <FormatTag format={provenance.format} />
              {provenance.originalName}
            </span>
            <small className="docsync-wp-source-doc__id">
              {sprintf(
                /* translators: %s: file type such as PDF. */
                __('%s · one-time import, not synced', TEXT_DOMAIN),
                formatLabels[provenance.format]
              )}
            </small>
          </div>
        </td>
        <td>
          <div className="docsync-wp-source-status-cell">
            <span className="docsync-wp-row-tag">{__('One-time', TEXT_DOMAIN)}</span>
          </div>
        </td>
        <td>
          <div className="docsync-wp-source-last-sync">
            <span>
              {importedOn
                ? sprintf(
                  /* translators: %s: import date. */
                  __('Imported %s', TEXT_DOMAIN),
                  importedOn
                )
                : __('Imported', TEXT_DOMAIN)}
            </span>
          </div>
        </td>
        <td>
          <div className="docsync-wp-source-actions">
            <a
              aria-label={sprintf(__('Edit %s', TEXT_DOMAIN), postLabel(item.title, item.postId))}
              className="button button-secondary docsync-wp-button docsync-wp-button--small"
              href={item.editUrl}
            >
              {__('Edit', TEXT_DOMAIN)}
            </a>
          </div>
        </td>
      </tr>
    );
  };

  return (
    <section className="docsync-wp-card docsync-wp-card--wide">
      <div className="docsync-wp-card__header docsync-wp-card__header--row">
        <div>
          <h2>{__('Sources', TEXT_DOMAIN)}</h2>
          <p>
            {mode === 'content'
              ? __('Synced Google Docs and one-time imports across enabled WordPress targets.', TEXT_DOMAIN)
              : __('Linked Google Docs across enabled WordPress targets.', TEXT_DOMAIN)}
          </p>
        </div>
        <div className="docsync-wp-actions-row">
          <AdminButton disabled={busy} onClick={onRefresh}>{__('Refresh', TEXT_DOMAIN)}</AdminButton>
          <AdminButton disabled={busy} onClick={onSyncAll} variant="primary">{__('Sync all changed', TEXT_DOMAIN)}</AdminButton>
        </div>
      </div>

      <form
        className="docsync-wp-source-filters docsync-wp-source-filters--content"
        onSubmit={(event) => {
          event.preventDefault();
          void apply(draft);
        }}
      >
        <label>
          <span>{__('Search', TEXT_DOMAIN)}</span>
          <input
            className="regular-text"
            onChange={(event) => setDraft({ ...draft, search: event.currentTarget.value })}
            placeholder={kindLocked ? __('Post or Google Doc', TEXT_DOMAIN) : __('Post title', TEXT_DOMAIN)}
            type="search"
            value={draft.search}
          />
        </label>
        <label>
          <span>{__('Post type', TEXT_DOMAIN)}</span>
          <select onChange={(event) => setDraft({ ...draft, postType: event.currentTarget.value })} value={draft.postType}>
            <option value="">{__('All enabled', TEXT_DOMAIN)}</option>
            {availablePostTypes.map((item) => <option key={item.name} value={item.name}>{item.label}</option>)}
          </select>
        </label>
        <label>
          <span>{__('Content', TEXT_DOMAIN)}</span>
          <select
            disabled={kindLocked}
            onChange={(event) => setDraft({ ...draft, kind: event.currentTarget.value as ContentKind })}
            value={kindLocked ? 'google' : draft.kind}
          >
            {kindOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <label>
          <span>{__('Sync status', TEXT_DOMAIN)}</span>
          <select
            disabled={draft.kind === 'oneTime' && !kindLocked}
            onChange={(event) => setDraft({ ...draft, status: event.currentTarget.value })}
            value={draft.status}
          >
            {statusOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <label>
          <span>{__('Sort', TEXT_DOMAIN)}</span>
          <select
            disabled={kindLocked}
            onChange={(event) => {
              const [orderBy, order] = event.currentTarget.value.split(':');
              setDraft({ ...draft, orderBy: orderBy as ContentOrderBy, order: order === 'asc' ? 'asc' : 'desc' });
            }}
            value={sortValue}
          >
            {sortOptions.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
          </select>
        </label>
        <div className="docsync-wp-source-filters__actions">
          <AdminButton disabled={busy} type="submit" variant="primary">{__('Apply filters', TEXT_DOMAIN)}</AdminButton>
          <AdminButton disabled={busy || !hasActiveFilters} onClick={() => apply(emptyFilters(filters))}>{__('Reset', TEXT_DOMAIN)}</AdminButton>
        </div>
      </form>

      {filters.folderWatchId ? (
        <p className="docsync-wp-source-folder-filter">
          <span className="docsync-wp-folder-watch-chip">
            {sprintf(
              /* translators: %s: folder name. */
              __('From folder: %s', TEXT_DOMAIN),
              folderWatchLabel
            )}
          </span>
          <AdminButton disabled={busy} onClick={() => void apply({ ...filters, folderWatchId: '' })} size="small" variant="link">
            {__('Clear folder filter', TEXT_DOMAIN)}
          </AdminButton>
        </p>
      ) : null}

      {truncated ? (
        <p className="docsync-wp-content-truncated" role="status">
          {__('Sources stopped after scanning 2,000 posts for this view. Search or pick a post type to see the rest.', TEXT_DOMAIN)}
        </p>
      ) : null}

      <div className="docsync-wp-table-scroll">
        <table aria-busy={busy && rows.length === 0} className="docsync-wp-data-table docsync-wp-sources-table">
          <thead>
            <tr>
              <th>{__('WordPress target', TEXT_DOMAIN)}</th>
              <th>{__('Source', TEXT_DOMAIN)}</th>
              <th>{__('Status', TEXT_DOMAIN)}</th>
              <th>{__('Last sync', TEXT_DOMAIN)}</th>
              <th>{__('Actions', TEXT_DOMAIN)}</th>
            </tr>
          </thead>
          <tbody>
            {busy && rows.length === 0 ? (
              <SkeletonTableRows columns={['62%', '58%', '44%', '48%', '72%']} rows={5} />
            ) : rows.length === 0 ? (
              <tr>
                <td colSpan={5}>
                  <EmptyState
                    action={hasActiveFilters ? (
                      <AdminButton disabled={busy} onClick={() => apply(emptyFilters(filters))}>{__('Reset filters', TEXT_DOMAIN)}</AdminButton>
                    ) : canAddContent || canUpload ? (
                      <div className="docsync-wp-empty-state__action-row">
                        {canAddContent ? (
                          <AdminButton disabled={busy} onClick={onAddContent} variant="primary">{__('Add Google Docs', TEXT_DOMAIN)}</AdminButton>
                        ) : null}
                        {canUpload ? (
                          <AdminButton disabled={busy} onClick={onUpload} variant="secondary">{__('Upload files', TEXT_DOMAIN)}</AdminButton>
                        ) : null}
                        {canAddContent ? (
                          <AdminButton disabled={busy} onClick={onWatchFolder} variant="secondary">{__('Watch a client folder', TEXT_DOMAIN)}</AdminButton>
                        ) : null}
                      </div>
                    ) : undefined}
                    className="docsync-wp-table-empty-state"
                    description={hasActiveFilters
                      ? __('Adjust the filters or reset to see all content.', TEXT_DOMAIN)
                      : canAddContent || canUpload
                        ? __('Nothing added yet. Add Google Docs, upload Word, PowerPoint, or PDF files, or watch a client folder.', TEXT_DOMAIN)
                        : __('Complete the connection responsibility above before creating a source.', TEXT_DOMAIN)}
                    title={hasActiveFilters ? __('No content matches these filters.', TEXT_DOMAIN) : __('No content yet', TEXT_DOMAIN)}
                    variant="sources"
                  />
                </td>
              </tr>
            ) : rows.map((row) => {
              if (row.kind === 'oneTime') {
                return renderOneTimeRow(row.item);
              }

              const source = sourceById.get(row.postId);

              return source ? renderGoogleRow(row, source) : null;
            })}
          </tbody>
        </table>
      </div>

      {hasMore ? (
        <p className="docsync-wp-table-footer">
          <AdminButton disabled={busy} onClick={onLoadMore}>{__('Load more', TEXT_DOMAIN)}</AdminButton>
        </p>
      ) : null}
    </section>
  );
};

export const SourcesApp = (): JSX.Element => {
  const app = useSourcesApp();

  useEffect(() => {
    // Add content, the one-time provenance tags, and bulk linking share the modal stylesheet.
    app.config.docSourceModalStyleUrls.forEach((href, index) => {
      ensureLazyStyle(href, `docsync-wp-doc-source-modal-style-${index}`);
    });

    app.refresh().catch((caught) => {
      app.runAction(async () => {
        throw caught instanceof Error ? caught : new Error(__('Could not load Brasth Document Sync sources.', TEXT_DOMAIN));
      }).catch(() => undefined);
    });
  }, []);

  const workspace = app.workspace;
  const enabledPostTypes = workspace
    ? workspace.availablePostTypes.filter((postType) => workspace.enabledPostTypes.includes(postType.name))
    : [];
  const googleReady = Boolean(workspace?.siteConnectionReady && app.account.connected && app.account.hasRequiredScope);
  const canCreate = Boolean(workspace && workspace.creatablePostTypes.length > 0);
  const canAddContent = Boolean(workspace?.siteConnectionReady) && canCreate;
  const canUploadFiles = canCreate && getAdminConfig().canUploadFiles;
  const canLinkExisting = googleReady && enabledPostTypes.length > 0;

  if (app.matchView.open && workspace) {
    return (
      <LinkExistingPosts
        initialJobId={app.matchView.jobId}
        onExit={app.closeMatchView}
        onLinked={app.handleMatchLinked}
        postTypes={enabledPostTypes}
      />
    );
  }

  return (
    <AdminShell
      notice={app.notice}
      status={{
        label: app.rows.length === 1 ? __('shown item', TEXT_DOMAIN) : __('shown items', TEXT_DOMAIN),
        value: app.rows.length
      }}
      title={__('Sources', TEXT_DOMAIN)}
      version={app.config.version}
    >
      {!workspace ? (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          <div className="docsync-wp-admin-grid__main">
            <SourcesTableSkeleton />
          </div>
        </div>
      ) : (
        <div className="docsync-wp-admin-grid docsync-wp-admin-grid--single">
          {app.trackedSourceIds.map((postId) => (
            <BackgroundSyncPoller
              key={postId}
              onError={(message) => app.handleSourcePollingError(postId, message)}
              onStatus={app.handleSourceStatus}
              onTerminal={app.handleSourceTerminal}
              onTimeout={() => app.handleSourcePollingTimeout(postId)}
              postId={postId}
            />
          ))}
          <div className="docsync-wp-admin-grid__main">
            <ActivationGuidance
              account={app.account}
              busy={app.busy}
              onConnect={app.connectGoogle}
              onCreateSource={() => app.openAddContent('google')}
              setupUrl="admin.php?page=brasth-document-sync-for-google-docs"
              workspace={workspace}
            />
            <CronHealthBanner health={workspace.cronHealth} />
            <SourceHealthSummary summary={workspace.sourceSummary} />
            <SourcesFolderWatches watches={app.folderWatches} />
            {app.activationSource ? (
              <ActivationResult busy={app.busy} onRetry={app.retryActivationSource} source={app.activationSource} />
            ) : null}
            <div className="docsync-wp-sources-entry" role="group" aria-label={__('Add to Sources', TEXT_DOMAIN)}>
              <AdminButton disabled={app.busy || !canAddContent} onClick={() => app.openAddContent('google')} variant="primary">
                {__('Add Google Docs', TEXT_DOMAIN)}
              </AdminButton>
              {canUploadFiles ? (
                <AdminButton disabled={app.busy} onClick={() => app.openAddContent('upload')} variant="secondary">
                  {__('Upload files', TEXT_DOMAIN)}
                </AdminButton>
              ) : null}
              <AdminButton disabled={app.busy || !canAddContent} onClick={() => app.openSourceModal('folder')} variant="secondary">
                {__('Watch a folder', TEXT_DOMAIN)}
              </AdminButton>
              <AdminButton disabled={app.busy || !canLinkExisting} onClick={app.openMatchView} variant="secondary">
                {__('Link existing posts', TEXT_DOMAIN)}
              </AdminButton>
            </div>
            <SourcesContentTable
              availablePostTypes={enabledPostTypes}
              busy={app.busy}
              canAddContent={canAddContent}
              canUpload={canUploadFiles}
              filters={app.sourceFilters}
              folderWatchNames={Object.fromEntries(app.folderWatches.map((watch) => [watch.id, watch.folderName]))}
              hasMore={app.hasMoreSources}
              mode={app.listingMode}
              onAddContent={() => app.openAddContent('google')}
              onFiltersChange={app.applySourceFilters}
              onLoadMore={app.loadMoreSources}
              onRefresh={() => app.runAction(app.refresh)}
              onSync={app.syncOne}
              onSyncAll={app.syncAll}
              onUpload={() => app.openAddContent('upload')}
              onWatchFolder={() => app.openSourceModal('folder')}
              rows={app.rows}
              sources={app.sources}
              truncated={app.contentTruncated}
            />
          </div>
          <DocSourceModal
            initialIntent={app.sourceIntent}
            initialView={app.sourceView}
            isOpen={app.sourceModalOpen}
            onClose={app.closeSourceModal}
            onCompleted={app.handleSourceCreated}
            onFolderWatchCreated={() => {
              void app.refresh();
            }}
            onImported={app.handleImported}
            target={app.sourceModalOpen && workspace.creatablePostTypes[0] ? { mode: 'new', postType: workspace.creatablePostTypes[0] } : null}
          />
        </div>
      )}
    </AdminShell>
  );
};
