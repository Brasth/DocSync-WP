import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { listFolderDocuments, type FolderDocumentInventory, type FolderWatchRecord } from '../../api';

export const useFolderWatchInventory = (
  watch: FolderWatchRecord | null,
  includeSubfolders?: boolean
) => {
  const [inventory, setInventory] = useState<FolderDocumentInventory | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!watch) {
      setInventory(null);
      setLoading(false);
      setError(null);
      return;
    }

    const resolvedIncludeSubfolders = includeSubfolders ?? watch.includeSubfolders;
    let cancelled = false;

    setLoading(true);
    setError(null);
    setInventory(null);

    void listFolderDocuments(watch.folderId, {
      driveId: watch.driveId || undefined,
      includeSubfolders: resolvedIncludeSubfolders,
      watchId: watch.id
    })
      .then((response) => {
        if (!cancelled) {
          setInventory(response);
        }
      })
      .catch((caught) => {
        if (!cancelled) {
          const message = caught instanceof Error
            ? caught.message
            : __('Could not load folder inventory.', 'brasth-document-sync-for-google-docs');
          setError(message);
          setInventory({
            documents: [],
            folderId: watch.folderId,
            driveId: watch.driveId,
            overflow: false,
            includeSubfolders: resolvedIncludeSubfolders,
            scannedFolderCount: 0
          });
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [watch?.id, watch?.folderId, watch?.driveId, watch?.includeSubfolders, includeSubfolders]);

  return { inventory, loading, error };
};
