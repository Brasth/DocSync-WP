import { createElement, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { FolderWatchRecord } from '../../api';
import { StatusPill } from '../../shared/ui/status-pill';
import { foldersScreenUrl, sourcesForWatchUrl, watchStatusLabel } from '../folder-watches/folder-watch-labels';

type Props = {
  watch: FolderWatchRecord;
};

export const FolderActivationResult = ({ watch }: Props): JSX.Element => {
  const imported = watch.importedCount;
  const failed = watch.status === 'error';
  const active = imported >= 1 && (watch.status === 'watching' || watch.status === 'importing');
  const terminal = failed || (active && watch.status === 'watching');
  const resultRef = useRef<HTMLElement | null>(null);
  const titleId = `docsync-wp-folder-activation-title-${watch.id}`;

  useEffect(() => {
    if (!terminal) {
      return;
    }

    const timer = window.setTimeout(() => resultRef.current?.focus(), 0);

    return () => window.clearTimeout(timer);
  }, [active, failed, watch.id, watch.status]);

  return (
    <section
      aria-labelledby={titleId}
      className={`docsync-wp-activation-result${failed ? ' is-error' : active ? ' is-success' : ''}`}
      ref={resultRef}
      tabIndex={terminal ? -1 : undefined}
    >
      <div>
        <StatusPill status={watchStatusLabel(watch.status)} />
        <h2 id={titleId}>
          {failed
            ? __('Folder automation stopped', 'brasth-document-sync-for-google-docs')
            : active
              ? __('Folder automation is active', 'brasth-document-sync-for-google-docs')
              : __('Creating drafts from this client folder', 'brasth-document-sync-for-google-docs')}
        </h2>
        <p>
          {failed
            ? __('WordPress content already imported was not removed. Open Drive Folders to retry failed Docs.', 'brasth-document-sync-for-google-docs')
            : active && watch.status === 'importing'
              ? __('Folder automation is active. More Docs are still importing.', 'brasth-document-sync-for-google-docs')
              : active
                ? __('Member Docs will re-sync on this folder\'s schedule.', 'brasth-document-sync-for-google-docs')
                : sprintf(
                  /* translators: 1: imported count, 2: total count. */
                  __('Imported %1$d of %2$d Docs from this client folder.', 'brasth-document-sync-for-google-docs'),
                  imported,
                  watch.totalCount
                )}
        </p>
      </div>
      <div className="docsync-wp-actions-row">
        <a className="button button-primary" href={`${foldersScreenUrl}&watch=${encodeURIComponent(watch.id)}`}>
          {__('Open Drive Folders', 'brasth-document-sync-for-google-docs')}
        </a>
        {imported >= 1 ? (
          <a className="button button-secondary" href={sourcesForWatchUrl(watch.id)}>
            {__('View Sources', 'brasth-document-sync-for-google-docs')}
          </a>
        ) : null}
      </div>
    </section>
  );
};
