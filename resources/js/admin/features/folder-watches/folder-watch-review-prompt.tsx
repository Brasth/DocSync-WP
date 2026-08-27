import { createElement, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';
import { AdminButton } from '../../shared/ui/admin-button';

const storageKey = 'docsync-wp-review-prompt-v1';
const reviewUrl = 'https://wordpress.org/support/plugin/brasth-document-sync-for-google-docs/reviews/#new-post';

type Props = {
  watches: FolderWatchRecord[];
};

const hasImportedFolder = (watches: FolderWatchRecord[]): boolean => {
  return watches.some((watch) => watch.importedCount >= 1);
};

export const FolderWatchReviewPrompt = ({ watches }: Props): JSX.Element | null => {
  const [dismissed, setDismissed] = useState(true);

  useEffect(() => {
    try {
      setDismissed(window.localStorage.getItem(storageKey) === '1');
    } catch {
      setDismissed(true);
    }
  }, []);

  if (dismissed || !hasImportedFolder(watches)) {
    return null;
  }

  const dismiss = () => {
    try {
      window.localStorage.setItem(storageKey, '1');
    } catch {
      // Keep the prompt hidden for this session if storage is blocked.
    }

    setDismissed(true);
  };

  return (
    <aside className="docsync-wp-review-prompt" aria-label={__('WordPress.org review', 'brasth-document-sync-for-google-docs')}>
      <p>
        {__('A client folder imported its first Doc. If Drive Folders is working, a short WordPress.org review helps the next agency find it.', 'brasth-document-sync-for-google-docs')}
      </p>
      <div className="docsync-wp-review-prompt__actions">
        <a className="button button-secondary docsync-wp-button docsync-wp-button--small" href={reviewUrl} rel="noreferrer" target="_blank">
          {__('Leave a review', 'brasth-document-sync-for-google-docs')}
        </a>
        <AdminButton onClick={dismiss} size="small">
          {__('Dismiss', 'brasth-document-sync-for-google-docs')}
        </AdminButton>
      </div>
    </aside>
  );
};
