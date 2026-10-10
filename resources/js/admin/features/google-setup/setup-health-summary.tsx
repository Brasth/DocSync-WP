import { __ } from '@wordpress/i18n';

import type { SettingsHealthResponse } from '../../api/settings-health-api';

const domain = 'brasth-document-sync-for-google-docs';
const safe = (value: string): string => value.replace(/[^\w.:\- ]/g, '').slice(0, 80);

export const formatHealthDuration = (seconds: number): string => {
  if (seconds < 60) { return `${Math.round(seconds)}s`; }
  const minutes = Math.floor(seconds / 60);
  return `${minutes}m ${Math.round(seconds % 60)}s`;
};

export const formatHealthDay = (date: string): string => {
  const parsed = new Date(`${date}T00:00:00Z`);
  return Number.isNaN(parsed.getTime()) ? date : parsed.toLocaleDateString(undefined, { weekday: 'short', timeZone: 'UTC' });
};

/** Builds the clipboard report from an allowlist of non-private fields only. */
export const buildSettingsHealthReport = (health: SettingsHealthResponse): string => {
  const { activity, versions } = health;
  const lines = [
    `Document Sync health report`,
    `Checked: ${safe(health.checkedAt)}`,
    `WordPress ${safe(versions.wordpress)} · PHP ${safe(versions.php)} · Plugin ${safe(versions.plugin)}`,
    '',
    'Checks',
    ...health.checks.map((check) => `- ${safe(check.id)}: ${check.status}`),
    '',
    `Last 7 days: ${activity.completed} completed, ${activity.failed} failed, ${activity.waiting} waiting, median ${activity.medianSeconds === null ? 'n/a' : `${Math.round(activity.medianSeconds)}s`}${activity.limited ? ' (partial)' : ''}`,
    '',
    'Recent events',
    ...(health.reportEvents.length > 0
      ? health.reportEvents.slice(0, 20).map((event) => `- ${safe(event.timestamp)} ${safe(event.status)} ${safe(event.step)}${event.errorCode ? ` ${safe(event.errorCode)}` : ''}`)
      : [__('- none', domain)])
  ];

  return lines.join('\n');
};
