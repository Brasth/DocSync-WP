import { createElement, Fragment } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { WorkspaceSourceSummary } from '../../api';

type Props = {
  summary: WorkspaceSourceSummary;
  activeStatus?: string;
  onSelect?: (status: string) => void;
};

export const SourceHealthSummary = ({ summary, activeStatus = '', onSelect }: Props): JSX.Element | null => {
  if (summary.total === 0) {
    return null;
  }

  const metric = (count: number | string, label: string, status: string) => {
    const content = (
      <>
        <strong>{count}</strong>
        <span>{label}</span>
      </>
    );

    if (!onSelect) {
      return <div>{content}</div>;
    }

    const active = activeStatus === status;

    return (
      <button
        aria-pressed={active}
        className={`docsync-wp-source-health-summary__filter${active ? ' is-active' : ''}`}
        onClick={() => onSelect(active ? '' : status)}
        type="button"
      >
        {content}
      </button>
    );
  };

  return (
    <section aria-label={__('Accessible source health', 'brasth-document-sync-for-google-docs')} className="docsync-wp-source-health-summary">
      {metric(summary.attention, __('Need attention', 'brasth-document-sync-for-google-docs'), 'attention')}
      {metric(summary.syncing, __('Syncing', 'brasth-document-sync-for-google-docs'), 'syncing')}
      {metric(summary.healthy, __('Healthy', 'brasth-document-sync-for-google-docs'), 'healthy')}
      {metric(`${summary.total}${summary.truncated ? '+' : ''}`, __('Accessible', 'brasth-document-sync-for-google-docs'), '')}
    </section>
  );
};
