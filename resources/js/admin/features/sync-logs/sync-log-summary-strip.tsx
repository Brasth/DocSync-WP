import { createElement, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import type { SyncLogEntry } from '../../api';

type Props = {
  entries: SyncLogEntry[];
};

const latestEventLabel = (entries: SyncLogEntry[]): string => {
  if (entries.length === 0) {
    return __('None', 'brasth-document-sync-for-google-docs');
  }

  return entries[0]?.timestamp || __('Unknown', 'brasth-document-sync-for-google-docs');
};

export const SyncLogSummaryStrip = ({ entries }: Props): JSX.Element | null => {
  const metrics = useMemo(() => {
    const errors = entries.filter((entry) => entry.level === 'error').length;
    const warnings = entries.filter((entry) => entry.level === 'warning').length;

    return [
      {
        label: __('Visible events', 'brasth-document-sync-for-google-docs'),
        value: String(entries.length)
      },
      {
        label: __('Errors', 'brasth-document-sync-for-google-docs'),
        value: String(errors)
      },
      {
        label: __('Warnings', 'brasth-document-sync-for-google-docs'),
        value: String(warnings)
      },
      {
        label: __('Latest event', 'brasth-document-sync-for-google-docs'),
        value: latestEventLabel(entries)
      }
    ];
  }, [entries]);

  if (entries.length === 0) {
    return null;
  }

  return (
    <dl className="docsync-wp-log-summary" aria-label={__('Visible log summary', 'brasth-document-sync-for-google-docs')}>
      {metrics.map((item) => (
        <div key={item.label}>
          <dt>{item.label}</dt>
          <dd>{item.value}</dd>
        </div>
      ))}
    </dl>
  );
};
