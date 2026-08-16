import { createElement, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { DocumentMetadata } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { AdminNotice } from '../../shared/ui/admin-notice';
import { EmptyState } from '../../shared/ui/empty-state';
import { DriveBrowserBreadcrumbNav } from './drive-browser-breadcrumb';
import { DriveBrowserTable, DriveBrowserTableSkeleton } from './drive-browser-table';
import { DriveBrowserToolbar } from './drive-browser-toolbar';
import { useDriveBrowser } from './use-drive-browser';

export type FolderBrowserLocation = {
  driveId: string;
  folderId: string;
  folderName: string;
  isRoot: boolean;
};

export type DriveBrowserPanelProps = {
  allowMultiSelect?: boolean;
  busy: boolean;
  folderMode?: boolean;
  selectedDocument: DocumentMetadata | null;
  selectedDocuments?: DocumentMetadata[];
  onLocationChange?: (location: FolderBrowserLocation) => void;
  onSelect: (document: DocumentMetadata | null) => void;
};

export const DriveBrowserPanel = ({
  allowMultiSelect = false,
  busy,
  selectedDocument,
  selectedDocuments,
  onLocationChange,
  onSelect
}: DriveBrowserPanelProps): JSX.Element => {
  const browser = useDriveBrowser({ onSelect });
  const selected = selectedDocuments ?? (selectedDocument ? [selectedDocument] : []);

  useEffect(() => {
    if (!onLocationChange) {
      return;
    }

    const isRoot = browser.folderId === 'root' || (browser.driveId !== '' && browser.folderId === browser.driveId);
    onLocationChange({
      driveId: browser.driveId,
      folderId: browser.folderId,
      folderName: browser.currentFolderName,
      isRoot
    });
  }, [browser.currentFolderName, browser.driveId, browser.folderId, onLocationChange]);

  return (
    <div className="docsync-wp-drive-browser">
      <div className="docsync-wp-drive-browser__heading">
        <div className="docsync-wp-drive-browser__heading-title">
          <span aria-hidden="true" className="dashicons dashicons-cloud" />
          <div>
            <span className="docsync-wp-drive-browser__eyebrow">{__('Browse source', 'brasth-document-sync-for-google-docs')}</span>
            <strong>{__('Choose from Google Drive', 'brasth-document-sync-for-google-docs')}</strong>
          </div>
        </div>
        <DriveBrowserBreadcrumbNav
          breadcrumbs={browser.breadcrumbs}
          busy={busy}
          driveId={browser.driveId}
          folderId={browser.folderId}
          loading={browser.loading}
          onOpen={browser.openBreadcrumb}
        />
      </div>

      <DriveBrowserToolbar
        activeSearch={browser.activeSearch}
        busy={busy}
        driveId={browser.driveId}
        loading={browser.loading}
        loadingSharedDrives={browser.loadingSharedDrives}
        onClearSearch={browser.clearSearch}
        onDriveChange={browser.changeDriveLocation}
        onRefresh={browser.refreshFolder}
        onSearchInputChange={browser.setSearchInput}
        onSubmitSearch={browser.submitSearch}
        searchInput={browser.searchInput}
        sharedDrives={browser.sharedDrives}
      />

      {browser.sharedDriveError ? (
        <p className="docsync-wp-inline-warning">
          {sprintf(__('Shared drives are unavailable: %s', 'brasth-document-sync-for-google-docs'), browser.sharedDriveError)}
        </p>
      ) : null}

      <AdminNotice className="inline" notice={browser.error ? { type: 'error', message: browser.error } : null} />
      {browser.loading && browser.items.length === 0 ? <DriveBrowserTableSkeleton /> : null}
      {!browser.loading && !browser.error && browser.items.length === 0 ? (
        <EmptyState
          action={browser.activeSearch ? (
            <AdminButton disabled={busy} onClick={browser.clearSearch}>
              {__('Clear search', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          ) : (
            <AdminButton disabled={busy} onClick={browser.refreshFolder}>
              {__('Refresh Drive', 'brasth-document-sync-for-google-docs')}
            </AdminButton>
          )}
          description={browser.activeSearch
            ? sprintf(
              /* translators: %s: Google Drive search query. */
              __('No folders or Google Docs found for "%s". Try another search, switch shared drives, or confirm this Google account can open the Doc.', 'brasth-document-sync-for-google-docs'),
              browser.activeSearch
            )
            : __('No folders or Google Docs were found here. Try a search, switch shared drives, or confirm this Google account has access.', 'brasth-document-sync-for-google-docs')}
          title={browser.activeSearch
            ? __('No Drive items match this search.', 'brasth-document-sync-for-google-docs')
            : __('No Drive items in this folder.', 'brasth-document-sync-for-google-docs')}
          variant="drive"
        />
      ) : null}

      {browser.items.length > 0 ? (
        <DriveBrowserTable
          allowMultiSelect={allowMultiSelect}
          busy={busy}
          hasMore={Boolean(browser.nextPageToken)}
          items={browser.items}
          loading={browser.loading}
          onActivate={browser.activateItem}
          onLoadMore={browser.loadNextPage}
          selectedDocuments={selected}
        />
      ) : null}

      {browser.incompleteSearch ? (
        <p className="docsync-wp-inline-warning">{__('Google could not search every Drive item. Narrow the search if the Doc is missing.', 'brasth-document-sync-for-google-docs')}</p>
      ) : null}

    </div>
  );
};
