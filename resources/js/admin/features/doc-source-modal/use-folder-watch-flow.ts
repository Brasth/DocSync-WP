import { speak } from '@wordpress/a11y';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
  createFolderWatch,
  getFolderWatch,
  listFolderDocuments,
  retryFolderWatch,
  type FolderDocumentInventory,
  type FolderWatchRecord
} from '../../api';
import { AdminApiError } from '../../api/client';
import type { DocSourceOutputType } from './doc-source-modal-options';
import type { SourceIntent } from './source-intent-cards';

export type FolderLocation = {
  driveId: string;
  folderId: string;
  folderName: string;
  isRoot: boolean;
};

type Args = {
  canChooseElementor: boolean;
  initialIntent?: SourceIntent;
  isOpen: boolean;
  postType: string;
  onWatchCreated?: (watch: FolderWatchRecord) => void;
};

export const useFolderWatchFlow = ({ canChooseElementor, initialIntent = 'document', isOpen, postType, onWatchCreated }: Args) => {
  const [intent, setIntent] = useState<SourceIntent>(initialIntent);
  const [location, setLocation] = useState<FolderLocation | null>(null);
  const [includeSubfolders, setIncludeSubfolders] = useState(false);
  const [confirmRoot, setConfirmRoot] = useState(false);
  const [inventory, setInventory] = useState<FolderDocumentInventory | null>(null);
  const [excludedFileIds, setExcludedFileIds] = useState<string[]>([]);
  const [postStatus, setPostStatus] = useState<'draft' | 'publish'>('draft');
  const [syncInterval, setSyncInterval] = useState('site');
  const [watch, setWatch] = useState<FolderWatchRecord | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [outputType, setOutputType] = useState<DocSourceOutputType>('blocks');
  const [layoutPreset, setLayoutPreset] = useState('');

  useEffect(() => {
    if (!isOpen) {
      setIntent(initialIntent);
      setLocation(null);
      setIncludeSubfolders(false);
      setConfirmRoot(false);
      setInventory(null);
      setExcludedFileIds([]);
      setPostStatus('draft');
      setSyncInterval('site');
      setWatch(null);
      setBusy(false);
      setError('');
      setOutputType('blocks');
      setLayoutPreset('');
    }
  }, [initialIntent, isOpen]);

  useEffect(() => {
    if (!watch || watch.status !== 'importing') {
      return undefined;
    }

    const timer = window.setInterval(() => {
      void getFolderWatch(watch.id).then(setWatch).catch(() => undefined);
    }, 2000);

    return () => window.clearInterval(timer);
  }, [watch]);

  const loadInventory = async (nextInclude = includeSubfolders) => {
    if (!location) {
      return;
    }

    setBusy(true);
    setError('');

    try {
      const next = await listFolderDocuments(location.folderId, {
        driveId: location.driveId || undefined,
        includeSubfolders: nextInclude
      });
      setInventory(next);
      setExcludedFileIds([]);
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not list Google Docs in this folder.', 'brasth-document-sync-for-google-docs');
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const changeIncludeSubfolders = (value: boolean) => {
    setIncludeSubfolders(value);
    void loadInventory(value);
  };

  const toggleExcluded = (fileId: string) => {
    setExcludedFileIds((current) => (
      current.includes(fileId) ? current.filter((id) => id !== fileId) : [...current, fileId]
    ));
  };

  const startWatch = async () => {
    if (!location) {
      setError(__('Open a Google Drive folder first.', 'brasth-document-sync-for-google-docs'));
      return;
    }

    if (location.isRoot && !confirmRoot) {
      setError(__('Watching the top of this Drive can import many Google Docs. Confirm the root folder to continue.', 'brasth-document-sync-for-google-docs'));
      return;
    }

    setBusy(true);
    setError('');

    try {
      const created = await createFolderWatch({
        folderId: location.folderId,
        driveId: location.driveId || undefined,
        includeSubfolders,
        confirmRoot: location.isRoot ? true : undefined,
        postType,
        postStatus,
        syncInterval: syncInterval as 'site' | 'off' | 'hourly' | 'twicedaily' | 'daily' | 'weekly',
        layoutPreset: outputType === 'elementor' ? undefined : layoutPreset || undefined,
        elementorSync: canChooseElementor && outputType === 'elementor' ? true : undefined,
        elementorPreset: canChooseElementor && outputType === 'elementor' ? layoutPreset || undefined : undefined,
        excludeFileIds: excludedFileIds
      });
      setWatch(created);
      setConfirmRoot(true);
      onWatchCreated?.(created);
      speak(__('Folder watch started. Drafts are being created.', 'brasth-document-sync-for-google-docs'));
    } catch (caught) {
      const message = caught instanceof AdminApiError || caught instanceof Error
        ? caught.message
        : __('Could not start this folder watch.', 'brasth-document-sync-for-google-docs');
      setError(message);
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const retryFailed = async () => {
    if (!watch) {
      return;
    }

    setBusy(true);
    setError('');

    try {
      setWatch(await retryFolderWatch(watch.id));
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Could not retry failed Google Docs.', 'brasth-document-sync-for-google-docs');
      setError(message);
    } finally {
      setBusy(false);
    }
  };

  return {
    busy,
    changeIncludeSubfolders,
    confirmRoot,
    error,
    excludedFileIds,
    includeSubfolders,
    intent,
    inventory,
    layoutPreset,
    loadInventory,
    location,
    outputType,
    postStatus,
    retryFailed,
    setConfirmRoot,
    setIntent,
    setLayoutPreset,
    setLocation,
    setOutputType,
    setPostStatus,
    setSyncInterval,
    startWatch,
    syncInterval,
    toggleExcluded,
    watch
  };
};
