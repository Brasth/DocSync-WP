import type { SourceRecord } from '../../api';

export const parseSource = (value: string | undefined): SourceRecord | null => {
  if (!value) {
    return null;
  }

  try {
    return JSON.parse(value) as SourceRecord | null;
  } catch {
    return null;
  }
};

export const sourceLabel = (source: SourceRecord | null): string => {
  if (!source) {
    return 'No Google Doc linked.';
  }

  const title = source.googleTitle || source.googleFileId;
  const status = source.syncStatus || 'linked';
  return `${title} (${status})`;
};

export const updateListRowSource = (source: SourceRecord | null): void => {
  if (!source) {
    return;
  }

  const row = document.getElementById(`post-${source.postId}`);
  const statusCell = row?.querySelector('.column-docsync_wp_status');
  const rowAction = row?.querySelector(`.docsync-wp-row-action[data-post-id="${source.postId}"]`) as HTMLElement | null;

  if (statusCell) {
    statusCell.replaceChildren(sourceStatusElement(source));
  }

  if (rowAction) {
    rowAction.dataset.mode = 'sync';
    rowAction.textContent = 'Sync Doc';
  }
};

const sourceStatusElement = (source: SourceRecord): HTMLDivElement => {
  const wrapper = document.createElement('div');
  const title = document.createElement('strong');
  const status = document.createElement('span');

  wrapper.className = 'docsync-wp-list-status is-linked';
  title.textContent = source.googleTitle || source.googleFileId;
  status.textContent = capitalizeStatus(source.syncStatus || 'linked');
  wrapper.append(title, document.createElement('br'), status);

  if (source.lastSyncedAt) {
    const syncedAt = document.createElement('small');
    syncedAt.textContent = source.lastSyncedAt;
    wrapper.append(document.createElement('br'), syncedAt);
  }

  if (source.syncError) {
    const error = document.createElement('small');
    error.className = 'docsync-wp-list-error';
    error.textContent = source.syncError;
    wrapper.append(document.createElement('br'), error);
  }

  return wrapper;
};

const capitalizeStatus = (status: string): string => {
  return status ? `${status.charAt(0).toUpperCase()}${status.slice(1)}` : 'Linked';
};
