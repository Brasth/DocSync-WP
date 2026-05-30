import { createElement } from '@wordpress/element';

import type { DocumentMetadata } from '../../api';
import { AdminNotice } from '../../shared/ui/admin-notice';
import { EmptyState } from '../../shared/ui/empty-state';
import { LoadingState } from '../../shared/ui/loading-state';
import { DriveBrowserBreadcrumbNav } from './drive-browser-breadcrumb';
import { DriveBrowserTable } from './drive-browser-table';
import { DriveBrowserToolbar } from './drive-browser-toolbar';
import { useDriveBrowser } from './use-drive-browser';

type Props = {
  busy: boolean;
  selectedDocument: DocumentMetadata | null;
  onSelect: (document: DocumentMetadata | null) => void;
};

export const DriveBrowserPanel = ({ busy, selectedDocument, onSelect }: Props): JSX.Element => {
  const browser = useDriveBrowser({ onSelect });

  return (
    <div className="docsync-wp-drive-browser">
      <div className="docsync-wp-drive-browser__heading">
        <strong>Choose from Google Drive</strong>
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

      {browser.sharedDriveError ? <p className="docsync-wp-inline-warning">Shared drives are unavailable: {browser.sharedDriveError}</p> : null}

      <AdminNotice className="inline" notice={browser.error ? { type: 'error', message: browser.error } : null} />
      {browser.loading && browser.items.length === 0 ? <LoadingState>Loading Drive items...</LoadingState> : null}
      {!browser.loading && !browser.error && browser.items.length === 0 ? (
        <EmptyState>
          {browser.activeSearch ? `No folders or Google Docs found for "${browser.activeSearch}".` : 'This folder has no folders or Google Docs.'}
        </EmptyState>
      ) : null}

      {browser.items.length > 0 ? (
        <DriveBrowserTable
          busy={busy}
          hasMore={Boolean(browser.nextPageToken)}
          items={browser.items}
          loading={browser.loading}
          onActivate={browser.activateItem}
          onLoadMore={browser.loadNextPage}
          selectedDocument={selectedDocument}
        />
      ) : null}

      {browser.incompleteSearch ? (
        <p className="docsync-wp-inline-warning">Google could not search every Drive item. Narrow the search if the Doc is missing.</p>
      ) : null}

    </div>
  );
};
