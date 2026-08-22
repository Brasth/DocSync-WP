import { speak } from '@wordpress/a11y';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
  deleteFolderWatch,
  getGoogleAccount,
  getGoogleAuthUrl,
  getWorkspace,
  listFolderWatches,
  pauseFolderWatch,
  resumeFolderWatch,
  retryFolderWatch,
  scanFolderWatch,
  updateFolderWatch,
  type FolderWatchRecord,
  type GoogleAccount,
  type UpdateFolderWatchPayload,
  type WorkspaceResponse
} from '../../api';
import { getAdminConfig } from '../../config';
import type { AdminNoticeState } from '../../shared/ui/admin-notice';

const emptyAccount: GoogleAccount = { connected: false, hasRequiredScope: false };

const mergeWatch = (watches: FolderWatchRecord[], next: FolderWatchRecord): FolderWatchRecord[] => {
  return watches.map((watch) => watch.id === next.id ? next : watch);
};

export const useFolderWatches = () => {
  const config = useMemo(() => getAdminConfig(), []);
  const [workspace, setWorkspace] = useState<WorkspaceResponse | null>(null);
  const [account, setAccount] = useState<GoogleAccount>(emptyAccount);
  const [watches, setWatches] = useState<FolderWatchRecord[]>([]);
  const [notice, setNotice] = useState<AdminNoticeState | null>(null);
  const [busy, setBusy] = useState(false);
  const [sourceModalOpen, setSourceModalOpen] = useState(false);
  const requestGeneration = useRef(0);

  const refresh = async () => {
    const generation = ++requestGeneration.current;
    const [workspaceResponse, accountResponse, foldersResponse] = await Promise.all([
      getWorkspace(),
      getGoogleAccount(),
      listFolderWatches()
    ]);

    if (generation !== requestGeneration.current) {
      return;
    }

    setWorkspace(workspaceResponse);
    setAccount(accountResponse);
    setWatches(foldersResponse.folders);
  };

  useEffect(() => {
    const importing = watches.some((watch) => watch.status === 'importing');

    if (!importing) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      void listFolderWatches().then((response) => setWatches(response.folders)).catch(() => undefined);
    }, 4000);

    return () => window.clearInterval(timer);
  }, [watches]);

  const runAction = async (action: () => Promise<void>) => {
    setBusy(true);
    setNotice(null);

    try {
      await action();
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Action failed.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
    } finally {
      setBusy(false);
    }
  };

  const applyWatch = async (action: () => Promise<FolderWatchRecord>) => {
    await runAction(async () => {
      const next = await action();
      setWatches((current) => mergeWatch(current, next));
    });
  };

  const connectGoogle = async () => {
    await runAction(async () => {
      const response = await getGoogleAuthUrl();
      window.location.assign(response.authUrl);
    });
  };

  const saveWatch = async (watchId: string, payload: UpdateFolderWatchPayload): Promise<FolderWatchRecord> => {
    setBusy(true);
    setNotice(null);

    try {
      const next = await updateFolderWatch(watchId, payload);
      setWatches((current) => mergeWatch(current, next));
      return next;
    } catch (caught) {
      const message = caught instanceof Error ? caught.message : __('Action failed.', 'brasth-document-sync-for-google-docs');
      setNotice({ type: 'error', message });
      speak(message, 'assertive');
      throw caught;
    } finally {
      setBusy(false);
    }
  };

  return {
    account,
    busy,
    config,
    connectGoogle,
    notice,
    onPause: (watchId: string) => applyWatch(() => pauseFolderWatch(watchId)),
    onRemove: (watchId: string) => runAction(async () => {
      await deleteFolderWatch(watchId);
      setWatches((current) => current.filter((watch) => watch.id !== watchId));
    }),
    onResume: (watchId: string) => applyWatch(() => resumeFolderWatch(watchId)),
    onRetry: (watchId: string) => applyWatch(() => retryFolderWatch(watchId)),
    onScan: (watchId: string) => applyWatch(() => scanFolderWatch(watchId)),
    onUpdate: (watchId: string, payload: UpdateFolderWatchPayload) => applyWatch(() => updateFolderWatch(watchId, payload)),
    saveWatch,
    openSourceModal: () => setSourceModalOpen(true),
    closeSourceModal: () => setSourceModalOpen(false),
    refresh,
    runAction,
    setNotice,
    sourceModalOpen,
    watches,
    workspace
  };
};
