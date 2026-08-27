import { createElement } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';
import { folderWatchDetailUrl, foldersScreenUrl, watchStatusLabel } from '../folder-watches/folder-watch-labels';
import { attentionRank, watchNeedsAttention } from '../folder-watches/folder-watch-ops';
import { StatusPill } from '../../shared/ui/status-pill';

type Props = {
  watches: FolderWatchRecord[];
};

export const SourcesFolderWatches = ({ watches }: Props): JSX.Element | null => {
  if (watches.length === 0) {
    return null;
  }

  const ordered = watches.slice().sort((left, right) => {
    const rankDiff = attentionRank(left) - attentionRank(right);

    if (rankDiff !== 0) {
      return rankDiff;
    }

    return left.folderName.localeCompare(right.folderName);
  });
  const attentionCount = watches.filter((watch) => watchNeedsAttention(watch)).length;

  return (
    <section className="docsync-wp-folder-watches" aria-label={__('Folder watches', 'brasth-document-sync-for-google-docs')}>
      <header className="docsync-wp-folder-watches__header">
        <div>
          <h3>{__('Drive Folders', 'brasth-document-sync-for-google-docs')}</h3>
          <p>
            {attentionCount > 0
              ? sprintf(
                /* translators: %d: number of folder watches that need attention. */
                _n(
                  '%d client folder needs attention.',
                  '%d client folders need attention.',
                  attentionCount,
                  'brasth-document-sync-for-google-docs'
                ),
                attentionCount
              )
              : __('All client folders are watching.', 'brasth-document-sync-for-google-docs')}
          </p>
        </div>
        <a href={foldersScreenUrl}>{__('Open Drive Folders', 'brasth-document-sync-for-google-docs')}</a>
      </header>
      <ul className="docsync-wp-folder-watches__list">
        {ordered.slice(0, 5).map((watch) => (
          <li className="docsync-wp-folder-watches__item" key={watch.id}>
            <div>
              <a href={folderWatchDetailUrl(watch.id)}>
                <strong>{watch.folderName}</strong>
              </a>
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
