import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { WorkspaceSourceSummary } from '../../api';

export const SourceHealthSummary = ({ summary }: { summary: WorkspaceSourceSummary }): JSX.Element | null => {
  if (summary.total === 0) {
    return null;
  }

  return (
    <section aria-label={__('Accessible source health', 'brasth-document-sync-for-google-docs')} className="docsync-wp-source-health-summary">
      <div><strong>{summary.attention}</strong><span>{__('Need attention', 'brasth-document-sync-for-google-docs')}</span></div>
      <div><strong>{summary.syncing}</strong><span>{__('Syncing', 'brasth-document-sync-for-google-docs')}</span></div>
      <div><strong>{summary.healthy}</strong><span>{__('Healthy', 'brasth-document-sync-for-google-docs')}</span></div>
      <div><strong>{summary.total}{summary.truncated ? '+' : ''}</strong><span>{__('Accessible', 'brasth-document-sync-for-google-docs')}</span></div>
    </section>
  );
};

