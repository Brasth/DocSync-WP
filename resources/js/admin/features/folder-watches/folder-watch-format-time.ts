import { __, sprintf } from '@wordpress/i18n';

export const formatShortDateTime = (value?: string): string => {
  if (!value) {
    return '';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit'
  });
};

export const formatScanTiming = (lastScanAt?: string, nextScanAt?: string): { label: string; title: string } => {
  const parts: string[] = [];
  const titleParts: string[] = [];

  if (lastScanAt) {
    parts.push(sprintf(
      /* translators: %s: formatted date/time. */
      __('Last: %s', 'brasth-document-sync-for-google-docs'),
      formatShortDateTime(lastScanAt)
    ));
    titleParts.push(sprintf(
      /* translators: %s: ISO timestamp. */
      __('Last scan: %s', 'brasth-document-sync-for-google-docs'),
      lastScanAt
    ));
  }

  if (nextScanAt) {
    parts.push(sprintf(
      /* translators: %s: formatted date/time. */
      __('Next: %s', 'brasth-document-sync-for-google-docs'),
      formatShortDateTime(nextScanAt)
    ));
    titleParts.push(sprintf(
      /* translators: %s: ISO timestamp. */
      __('Next scan: %s', 'brasth-document-sync-for-google-docs'),
      nextScanAt
    ));
  }

  if (parts.length === 0) {
    return {
      label: __('Not scheduled', 'brasth-document-sync-for-google-docs'),
      title: ''
    };
  }

  return {
    label: parts.join(' · '),
    title: titleParts.join(' · ')
  };
};
