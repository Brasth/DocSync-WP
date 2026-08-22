import { createElement, Fragment } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderDocumentInventory, FolderWatchFailedItem } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';

type Props = {
  busy: boolean;
  excludedFileIds: string[];
  failed: FolderWatchFailedItem[];
  inventory: FolderDocumentInventory | null;
  onExcludeToggle: (fileId: string) => void;
  onRetryFailed: () => void;
};

export const FolderWatchInventoryList = ({
  busy,
  excludedFileIds,
  failed,
  inventory,
  onExcludeToggle,
  onRetryFailed
}: Props): JSX.Element => {
  const documents = inventory?.documents ?? [];
  const included = documents.filter((document) => !excludedFileIds.includes(document.fileId));

  return (
    <div className="docsync-wp-folder-watch-inventory">
      <h3>{__('Folder inventory', 'brasth-document-sync-for-google-docs')}</h3>
      {inventory === null ? (
        <p>{__('Loading Docs in this folder…', 'brasth-document-sync-for-google-docs')}</p>
      ) : (
        <>
          <ul className="docsync-wp-folder-confirm__list">
            {documents.map((document) => (
              <li key={document.fileId}>
                <label>
                  <input
                    checked={!excludedFileIds.includes(document.fileId)}
                    disabled={busy}
                    onChange={() => onExcludeToggle(document.fileId)}
                    type="checkbox"
                  />
                  <span>
                    <strong>{document.name}</strong>
                    {document.folderPath ? <span>{document.folderPath}</span> : null}
                  </span>
                </label>
              </li>
            ))}
          </ul>
          <p className="docsync-wp-tabular">
            {sprintf(
              /* translators: 1: selected Doc count, 2: inventory cap. */
              __('%1$d of %2$d Docs selected', 'brasth-document-sync-for-google-docs'),
              included.length,
              50
            )}
          </p>
        </>
      )}
      {failed.length > 0 ? (
        <div className="docsync-wp-folder-watch-failed">
          <h3>{__('Failed imports', 'brasth-document-sync-for-google-docs')}</h3>
          <ul>
            {failed.map((item) => (
              <li key={`${item.fileId}-${item.code}`}>
                <strong>{item.name || item.fileId}</strong>
                <span>{item.code}</span>
                <span>{item.message}</span>
              </li>
            ))}
          </ul>
          <AdminButton disabled={busy} onClick={onRetryFailed} variant="secondary">
            {__('Retry failed', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      ) : null}
    </div>
  );
};
