import { createElement } from '@wordpress/element';

import type { DocumentMetadata, DriveItemSummary } from '../api';
import { driveItemTypeLabel, formatDriveModifiedTime } from './doc-source-drive-browser-utils';

type Props = {
  busy: boolean;
  items: DriveItemSummary[];
  loading: boolean;
  selectedDocument: DocumentMetadata | null;
  onActivate: (item: DriveItemSummary) => Promise<void>;
};

export const DocSourceDriveBrowserTable = ({ busy, items, loading, selectedDocument, onActivate }: Props): JSX.Element => {
  return (
    <div className="docsync-wp-drive-browser__table-wrap">
      <table className="widefat striped docsync-wp-drive-browser__table">
        <thead>
          <tr>
            <th scope="col">Name</th>
            <th scope="col">Modified</th>
            <th scope="col">Type</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => {
            const selected = item.itemType === 'document' && selectedDocument?.fileId === item.fileId;

            return (
              <tr className={selected ? 'is-selected' : ''} key={item.fileId}>
                <td className="docsync-wp-drive-browser__name-cell">
                  <button
                    aria-label={item.itemType === 'folder' ? `Open folder ${item.name}` : `Select Google Doc ${item.name}`}
                    aria-pressed={item.itemType === 'document' ? selected : undefined}
                    className="docsync-wp-drive-browser__row-button"
                    disabled={busy || loading}
                    onClick={() => onActivate(item).catch(() => undefined)}
                    type="button"
                  >
                    <span
                      aria-hidden="true"
                      className={`dashicons ${item.itemType === 'folder' ? 'dashicons-category' : 'dashicons-media-document'}`}
                    />
                    <span>{item.name}</span>
                  </button>
                </td>
                <td>{formatDriveModifiedTime(item.modifiedTime)}</td>
                <td>{driveItemTypeLabel(item)}</td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
};
