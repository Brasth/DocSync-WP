import { createElement, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { DriveItemSummary, FolderDocumentInventory, FolderWatchFailedItem } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';
import { formatShortDateTime } from './folder-watch-format-time';

type InventoryFilter = 'all' | 'included' | 'excluded';

type Props = {
  busy: boolean;
  excludedFileIds: string[];
  failed: FolderWatchFailedItem[];
  inventory: FolderDocumentInventory | null;
  inventoryError: string | null;
  loading: boolean;
  onExcludeToggle: (fileId: string) => void;
  onExcludedChange: (fileIds: string[]) => void;
  onRetryFailed: () => void;
};

const matchesSearch = (document: DriveItemSummary, search: string): boolean => {
  if (!search) {
    return true;
  }

  const needle = search.toLowerCase();

  return document.name.toLowerCase().includes(needle)
    || (document.folderPath || '').toLowerCase().includes(needle);
};

export const FolderWatchInventoryPanel = ({
  busy,
  excludedFileIds,
  failed,
  inventory,
  inventoryError,
  loading,
  onExcludeToggle,
  onExcludedChange,
  onRetryFailed
}: Props): JSX.Element => {
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<InventoryFilter>('all');

  const documents = inventory?.documents ?? [];

  const visibleDocuments = useMemo(() => {
    return documents.filter((document) => {
      if (!matchesSearch(document, search)) {
        return false;
      }

      const excluded = excludedFileIds.includes(document.fileId);

      if (filter === 'included') {
        return !excluded;
      }

      if (filter === 'excluded') {
        return excluded;
      }

      return true;
    });
  }, [documents, excludedFileIds, filter, search]);

  const includedCount = documents.filter((document) => !excludedFileIds.includes(document.fileId)).length;

  const selectVisible = (include: boolean) => {
    const visibleIds = visibleDocuments.map((document) => document.fileId);

    if (include) {
      onExcludedChange(excludedFileIds.filter((id) => !visibleIds.includes(id)));
      return;
    }

    onExcludedChange([...new Set([...excludedFileIds, ...visibleIds])]);
  };

  return (
    <section className="docsync-wp-card docsync-wp-folder-watch-inventory-panel">
      <div className="docsync-wp-card__header docsync-wp-card__header--row">
        <div>
          <h2>{__('Folder inventory', 'brasth-document-sync-for-google-docs')}</h2>
          <p className="docsync-wp-folder-watch-inventory-panel__summary">
            {sprintf(
              /* translators: 1: included Doc count, 2: total Doc count, 3: inventory cap. */
              __('%1$d of %2$d Docs selected (up to %3$d)', 'brasth-document-sync-for-google-docs'),
              includedCount,
              documents.length,
              50
            )}
          </p>
        </div>
        <div className="docsync-wp-folder-watch-inventory-panel__bulk">
          <AdminButton disabled={busy || loading || visibleDocuments.length === 0} onClick={() => selectVisible(true)} size="small">
            {__('Select visible', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
          <AdminButton disabled={busy || loading || visibleDocuments.length === 0} onClick={() => selectVisible(false)} size="small">
            {__('Exclude visible', 'brasth-document-sync-for-google-docs')}
          </AdminButton>
        </div>
      </div>

      <div className="docsync-wp-folder-watch-inventory-panel__toolbar">
        <input
          aria-label={__('Search Docs in this folder', 'brasth-document-sync-for-google-docs')}
          className="docsync-wp-folder-watch-inventory-panel__search"
          disabled={busy || loading}
          onChange={(event) => setSearch(event.currentTarget.value)}
          placeholder={__('Search Docs…', 'brasth-document-sync-for-google-docs')}
          type="search"
          value={search}
        />
        <div className="docsync-wp-folder-watch-inventory-panel__filters" role="tablist">
          {([
            ['all', __('All', 'brasth-document-sync-for-google-docs')],
            ['included', __('Included', 'brasth-document-sync-for-google-docs')],
            ['excluded', __('Excluded', 'brasth-document-sync-for-google-docs')]
          ] as const).map(([value, label]) => (
            <button
              aria-selected={filter === value}
              className={filter === value ? 'is-active' : ''}
              disabled={busy || loading}
              key={value}
              onClick={() => setFilter(value)}
              role="tab"
              type="button"
            >
              {label}
            </button>
          ))}
        </div>
      </div>

      {loading ? (
        <p className="docsync-wp-folder-watch-inventory-panel__state">{__('Loading Docs in this folder…', 'brasth-document-sync-for-google-docs')}</p>
      ) : null}

      {!loading && inventoryError ? (
        <div className="docsync-wp-inline-warning docsync-wp-folder-watch-inventory-panel__error" role="alert">
          <p>{inventoryError}</p>
        </div>
      ) : null}

      {!loading && !inventoryError && documents.length === 0 ? (
        <p className="docsync-wp-folder-watch-inventory-panel__state">{__('No Google Docs found in this folder.', 'brasth-document-sync-for-google-docs')}</p>
      ) : null}

      {!loading && documents.length > 0 ? (
        <div className="docsync-wp-folder-watch-inventory-panel__list-wrap">
          <ul className="docsync-wp-folder-confirm__list docsync-wp-folder-watch-inventory-panel__list">
            {visibleDocuments.map((document) => (
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
                    <span className="docsync-wp-folder-watch-inventory-panel__meta">
                      {document.folderPath ? <span>{document.folderPath}</span> : null}
                      {document.modifiedTime ? (
                        <span className="docsync-wp-tabular">{formatShortDateTime(document.modifiedTime)}</span>
                      ) : null}
                    </span>
                  </span>
                </label>
              </li>
            ))}
          </ul>
          {visibleDocuments.length === 0 ? (
            <p className="docsync-wp-folder-watch-inventory-panel__state">{__('No Docs match the current search or filter.', 'brasth-document-sync-for-google-docs')}</p>
          ) : null}
        </div>
      ) : null}

      {inventory?.overflow ? (
        <p className="docsync-wp-inline-warning">
          {__('This folder has more than 50 Docs. Brasth will automate the first 50.', 'brasth-document-sync-for-google-docs')}
        </p>
      ) : null}

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
    </section>
  );
};
