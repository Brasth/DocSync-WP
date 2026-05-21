import { createElement } from '@wordpress/element';

import type { DocumentMetadata } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
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
        <div>
          <strong>Choose from Google Drive</strong>
          <p>Browse My Drive folders and Google Docs visible to your connected account.</p>
        </div>
        <span>Current: {browser.currentFolderName}</span>
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

      <DriveBrowserBreadcrumbNav
        breadcrumbs={browser.breadcrumbs}
        busy={busy}
        driveId={browser.driveId}
        folderId={browser.folderId}
        loading={browser.loading}
        onOpen={browser.openBreadcrumb}
      />

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
          items={browser.items}
          loading={browser.loading}
          onActivate={browser.activateItem}
          selectedDocument={selectedDocument}
        />
      ) : null}

      {browser.incompleteSearch ? (
        <p className="docsync-wp-inline-warning">Google could not search every Drive item. Narrow the search if the Doc is missing.</p>
      ) : null}

      {browser.nextPageToken ? (
        <div className="docsync-wp-drive-browser__more">
          <AdminButton
            disabled={busy || browser.loading}
            onClick={() => browser.loadItems(browser.folderId, browser.activeSearch, browser.nextPageToken)}
          >
            {browser.loading ? 'Loading...' : 'Load more'}
          </AdminButton>
        </div>
      ) : null}
    </div>
  );
};
