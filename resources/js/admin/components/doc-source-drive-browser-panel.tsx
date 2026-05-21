import { createElement, useEffect, useState } from '@wordpress/element';

import { listDriveItems, type DocumentMetadata, type DriveItemSummary } from '../api';
import { DocSourceDriveBrowserTable } from './doc-source-drive-browser-table';
import { driveBrowserPageSize, driveItemToDocumentMetadata, rootDriveBreadcrumb, type DriveBrowserBreadcrumb } from './doc-source-drive-browser-utils';

type Props = {
  busy: boolean;
  selectedDocument: DocumentMetadata | null;
  onSelect: (document: DocumentMetadata | null) => void;
};

export const DocSourceDriveBrowserPanel = ({ busy, selectedDocument, onSelect }: Props): JSX.Element => {
  const [folderId, setFolderId] = useState(rootDriveBreadcrumb.fileId);
  const [breadcrumbs, setBreadcrumbs] = useState<DriveBrowserBreadcrumb[]>([rootDriveBreadcrumb]);
  const [searchInput, setSearchInput] = useState('');
  const [activeSearch, setActiveSearch] = useState('');
  const [items, setItems] = useState<DriveItemSummary[]>([]);
  const [nextPageToken, setNextPageToken] = useState('');
  const [incompleteSearch, setIncompleteSearch] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');

  const currentFolderName = breadcrumbs[breadcrumbs.length - 1]?.name ?? rootDriveBreadcrumb.name;

  const loadItems = async (nextFolderId: string, search: string, pageToken = '') => {
    setLoading(true);
    setError('');

    if (!pageToken) {
      setItems([]);
      setNextPageToken('');
      setIncompleteSearch(false);
    }

    try {
      const response = await listDriveItems({
        folderId: nextFolderId,
        search,
        pageToken,
        pageSize: driveBrowserPageSize
      });

      setFolderId(response.folderId || nextFolderId);
      setItems((current) => pageToken ? [...current, ...response.items] : response.items);
      setNextPageToken(response.nextPageToken ?? '');
      setIncompleteSearch(Boolean(response.incompleteSearch));
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Could not load Google Drive items.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadItems(rootDriveBreadcrumb.fileId, '').catch(() => undefined);
  }, []);

  const submitSearch = async () => {
    const search = searchInput.trim();
    setActiveSearch(search);
    onSelect(null);
    await loadItems(folderId, search);
  };

  const clearSearch = async () => {
    setSearchInput('');
    setActiveSearch('');
    onSelect(null);
    await loadItems(folderId, '');
  };

  const refreshFolder = async () => {
    await loadItems(folderId, activeSearch);
  };

  const openFolder = async (item: DriveItemSummary) => {
    setBreadcrumbs((current) => [...current, { fileId: item.fileId, name: item.name }]);
    setSearchInput('');
    setActiveSearch('');
    onSelect(null);
    await loadItems(item.fileId, '');
  };

  const openBreadcrumb = async (breadcrumb: DriveBrowserBreadcrumb, index: number) => {
    if (breadcrumb.fileId === folderId) {
      return;
    }

    setBreadcrumbs((current) => current.slice(0, index + 1));
    setSearchInput('');
    setActiveSearch('');
    onSelect(null);
    await loadItems(breadcrumb.fileId, '');
  };

  const activateItem = async (item: DriveItemSummary) => {
    if (item.itemType === 'folder') {
      await openFolder(item);
      return;
    }

    onSelect(driveItemToDocumentMetadata(item));
    setError('');
  };

  return (
    <div className="docsync-wp-drive-browser">
      <div className="docsync-wp-drive-browser__heading">
        <div>
          <strong>Choose from Google Drive</strong>
          <p>Browse My Drive folders and Google Docs visible to your connected account.</p>
        </div>
        <span>Current: {currentFolderName}</span>
      </div>

      <form
        className="docsync-wp-drive-browser__toolbar"
        onSubmit={(event) => {
          event.preventDefault();
          submitSearch().catch(() => undefined);
        }}
      >
        <label>
          <span>Search this folder</span>
          <input
            className="regular-text"
            onChange={(event) => setSearchInput(event.currentTarget.value)}
            placeholder="Folder or document name"
            type="search"
            value={searchInput}
          />
        </label>
        <button className="button" disabled={busy || loading} type="submit">
          Search
        </button>
        <button className="button" disabled={busy || loading} onClick={() => refreshFolder().catch(() => undefined)} type="button">
          Refresh
        </button>
        {activeSearch ? (
          <button className="button button-link" disabled={busy || loading} onClick={() => clearSearch().catch(() => undefined)} type="button">
            Clear
          </button>
        ) : null}
      </form>

      <nav aria-label="Google Drive folder path" className="docsync-wp-drive-browser__breadcrumb">
        {breadcrumbs.map((breadcrumb, index) => {
          const isCurrent = breadcrumb.fileId === folderId;

          return (
            <span key={breadcrumb.fileId}>
              {index > 0 ? <span aria-hidden="true">/</span> : null}
              <button
                aria-current={isCurrent ? 'page' : undefined}
                className={isCurrent ? 'is-current' : ''}
                disabled={busy || loading || isCurrent}
                onClick={() => openBreadcrumb(breadcrumb, index).catch(() => undefined)}
                type="button"
              >
                {breadcrumb.name}
              </button>
            </span>
          );
        })}
      </nav>

      {error ? <div className="notice notice-error inline"><p>{error}</p></div> : null}
      {loading && items.length === 0 ? <div className="docsync-wp-drive-browser__state">Loading Drive items...</div> : null}
      {!loading && !error && items.length === 0 ? (
        <div className="docsync-wp-drive-browser__state">
          {activeSearch ? `No folders or Google Docs found for "${activeSearch}".` : 'This folder has no folders or Google Docs.'}
        </div>
      ) : null}

      {items.length > 0 ? (
        <DocSourceDriveBrowserTable
          busy={busy}
          items={items}
          loading={loading}
          onActivate={activateItem}
          selectedDocument={selectedDocument}
        />
      ) : null}

      {incompleteSearch ? (
        <p className="docsync-wp-inline-warning">Google could not search every Drive item. Narrow the search if the Doc is missing.</p>
      ) : null}

      {nextPageToken ? (
        <div className="docsync-wp-drive-browser__more">
          <button className="button" disabled={busy || loading} onClick={() => loadItems(folderId, activeSearch, nextPageToken).catch(() => undefined)} type="button">
            {loading ? 'Loading...' : 'Load more'}
          </button>
        </div>
      ) : null}
    </div>
  );
};
