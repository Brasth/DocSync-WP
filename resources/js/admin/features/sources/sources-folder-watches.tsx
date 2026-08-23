import { createElement } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';
import { foldersScreenUrl, watchStatusLabel } from '../folder-watches/folder-watch-labels';
import { StatusPill } from '../../shared/ui/status-pill';

type Props = {
  watches: FolderWatchRecord[];
};

export const SourcesFolderWatches = ({ watches }: Props): JSX.Element | null => {
  if (watches.length === 0) {
    return null;
  }

  return (
    <section className="docsync-wp-folder-watches" aria-label={__('Folder watches', 'brasth-document-sync-for-google-docs')}>
      <header className="docsync-wp-folder-watches__header">
        <h3>{__('Drive folders', 'brasth-document-sync-for-google-docs')}</h3>
        <a href={foldersScreenUrl}>{__('Manage client folders', 'brasth-document-sync-for-google-docs')}</a>
      </header>
      <ul className="docsync-wp-folder-watches__list">
        {watches.map((watch) => (
          <li className="docsync-wp-folder-watches__item" key={watch.id}>
            <div>
              <strong>{watch.folderName}</strong>
              <span className="docsync-wp-tabular">
                {sprintf(
                  /* translators: 1: imported count, 2: total count. */
                  __('%1$d/%2$d imported', 'brasth-document-sync-for-google-docs'),
                  watch.importedCount,
                  watch.totalCount
                )}
              </span>
            </div>
            <StatusPill status={watchStatusLabel(watch.status)} />
          </li>
        ))}
      </ul>
    </section>
  );
};
