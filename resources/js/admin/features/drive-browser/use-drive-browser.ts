import { speak } from '@wordpress/a11y';
import { useEffect, useState } from '@wordpress/element';

import {
  listDriveItems,
  listSharedDrives,
  type DocumentMetadata,
  type DriveItemSummary,
  type SharedDriveSummary
} from '../../api';
import {
  driveBrowserPageSize,
  driveItemToDocumentMetadata,
  rootDriveBreadcrumb,
  type DriveBrowserBreadcrumb
} from './drive-browser-utils';

type Args = {
  onSelect: (document: DocumentMetadata | null) => void;
};

export const useDriveBrowser = ({ onSelect }: Args) => {
  const [driveId, setDriveId] = useState('');
  const [folderId, setFolderId] = useState(rootDriveBreadcrumb.fileId);
  const [breadcrumbs, setBreadcrumbs] = useState<DriveBrowserBreadcrumb[]>([rootDriveBreadcrumb]);
  const [sharedDrives, setSharedDrives] = useState<SharedDriveSummary[]>([]);
  const [searchInput, setSearchInput] = useState('');
  const [activeSearch, setActiveSearch] = useState('');
  const [items, setItems] = useState<DriveItemSummary[]>([]);
  const [nextPageToken, setNextPageToken] = useState('');
  const [incompleteSearch, setIncompleteSearch] = useState(false);
  const [loadingSharedDrives, setLoadingSharedDrives] = useState(false);
  const [loading, setLoading] = useState(false);
  const [sharedDriveError, setSharedDriveError] = useState('');
  const [error, setError] = useState('');

  const loadSharedDriveOptions = async () => {
    setLoadingSharedDrives(true);
    setSharedDriveError('');

    try {
      const response = await listSharedDrives();
      setSharedDrives(response.drives);
    } catch (caught) {
      setSharedDriveError(caught instanceof Error ? caught.message : 'Could not load shared drives.');
    } finally {
      setLoadingSharedDrives(false);
    }
  };

  const loadItems = async (nextFolderId: string, search: string, pageToken = '', nextDriveId = driveId) => {
    setLoading(true);
    setError('');

    if (!pageToken) {
      setItems([]);
      setNextPageToken('');
      setIncompleteSearch(false);
    }

    try {
      const response = await listDriveItems({
        driveId: nextDriveId || undefined,
        folderId: nextFolderId,
        search,
        pageToken,
        pageSize: driveBrowserPageSize
      });

      setDriveId(response.driveId || nextDriveId);
      setFolderId(response.folderId || nextFolderId);
      setItems((current) => pageToken ? [...current, ...response.items] : response.items);
      setNextPageToken(response.nextPageToken ?? '');
      setIncompleteSearch(Boolean(response.incompleteSearch));
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : 'Could not load Google Drive items.';
      setError(message);
      speak(message, 'assertive');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadSharedDriveOptions().catch(() => undefined);
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

  const changeDriveLocation = async (nextDriveId: string) => {
    const sharedDrive = sharedDrives.find((drive) => drive.driveId === nextDriveId) ?? null;
    const rootFolderId = sharedDrive?.driveId ?? rootDriveBreadcrumb.fileId;

    setDriveId(nextDriveId);
    setBreadcrumbs([{ fileId: rootFolderId, name: sharedDrive?.name ?? rootDriveBreadcrumb.name }]);
    setSearchInput('');
    setActiveSearch('');
    onSelect(null);
    await loadItems(rootFolderId, '', '', nextDriveId);
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

  return {
    activeSearch,
    activateItem,
    breadcrumbs,
    changeDriveLocation,
    clearSearch,
    currentFolderName: breadcrumbs[breadcrumbs.length - 1]?.name ?? rootDriveBreadcrumb.name,
    driveId,
    error,
    folderId,
    incompleteSearch,
    items,
    loading,
    loadingSharedDrives,
    loadItems,
    nextPageToken,
    openBreadcrumb,
    refreshFolder,
    searchInput,
    setSearchInput,
    sharedDriveError,
    sharedDrives,
    submitSearch
  };
};
