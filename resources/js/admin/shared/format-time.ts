import { __, sprintf } from '@wordpress/i18n';

const storedUtcPattern = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/;

/** Parse a stored timestamp. Plugin-stored "Y-m-d H:i:s" values are UTC. */
export const parseStoredTime = (value?: string | null): Date | null => {
  if (!value) {
    return null;
  }

  const date = new Date(storedUtcPattern.test(value) ? `${value.replace(' ', 'T')}Z` : value);

  return Number.isNaN(date.getTime()) ? null : date;
};

/** Local, human-readable absolute time. */
export const formatLocalDateTime = (value?: string | null): string => {
  const date = parseStoredTime(value);

  return date ? date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '';
};

/** Relative time up to one week; empty string beyond that or when unparseable. */
export const formatRelativeTime = (value?: string | null, now: number = Date.now()): string => {
  const date = parseStoredTime(value);

  if (!date) {
    return '';
  }

  const diffMin = Math.floor((now - date.getTime()) / 60000);
  const diffHr = Math.floor(diffMin / 60);
  const diffDay = Math.floor(diffHr / 24);

  if (diffMin < 1) {
    return __('just now', 'brasth-document-sync-for-google-docs');
  }

  if (diffMin < 60) {
    return sprintf(__('%d min ago', 'brasth-document-sync-for-google-docs'), diffMin);
  }

  if (diffHr < 24) {
    return sprintf(__('%d hr ago', 'brasth-document-sync-for-google-docs'), diffHr);
  }

  if (diffDay < 7) {
    return sprintf(__('%d days ago', 'brasth-document-sync-for-google-docs'), diffDay);
  }

  return '';
};

/** Relative time when recent, otherwise the local date. */
export const formatSyncTime = (value?: string | null): string => {
  return formatRelativeTime(value) || formatLocalDateTime(value);
};
