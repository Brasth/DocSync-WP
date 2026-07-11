import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SourceRecord } from '../../api';
import { StatusPill } from '../../shared/ui/status-pill';
import { isQueuedSync } from '../../shared/ui/sync-progress';

type Props = {
  source: SourceRecord | null;
};

const sourceStatus = (source: SourceRecord): string => {
  if (source.syncError) {
    return 'error';
  }

  if (isQueuedSync(source)) {
    return 'queued';
  }

  return source.syncStatus || 'linked';
};

export const SourceInspectorSummary = ({ source }: Props): JSX.Element => {
  if (!source) {
    return (
      <section aria-labelledby="docsync-wp-post-box-source-heading" className="docsync-wp-post-box__section docsync-wp-post-box__section--source">
        <h3 className="docsync-wp-post-box__section-label" id="docsync-wp-post-box-source-heading">{__('Source', 'brasth-document-sync-for-google-docs')}</h3>
        <p className="docsync-wp-post-box__empty-source">{__('No Google Doc linked yet.', 'brasth-document-sync-for-google-docs')}</p>
      </section>
    );
  }

  return (
    <section aria-labelledby={`docsync-wp-post-box-source-heading-${source.postId}`} className="docsync-wp-post-box__section docsync-wp-post-box__section--source">
      <div className="docsync-wp-post-box__source-header">
        <h3 className="docsync-wp-post-box__section-label" id={`docsync-wp-post-box-source-heading-${source.postId}`}>{__('Google Doc source', 'brasth-document-sync-for-google-docs')}</h3>
        <StatusPill status={sourceStatus(source)} />
      </div>
      <p className="docsync-wp-post-box__source-title" title={source.googleTitle || source.googleFileId}>{source.googleTitle || source.googleFileId}</p>
      {source.lastSyncedAt ? (
        <p className="docsync-wp-post-box__source-meta">
          <span>{__('Last synced', 'brasth-document-sync-for-google-docs')}</span>
          <span>{source.lastSyncedAt}</span>
        </p>
      ) : null}
    </section>
  );
};
