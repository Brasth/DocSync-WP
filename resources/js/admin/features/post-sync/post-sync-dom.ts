import type { SourceRecord } from '../../api';
import { shouldShowSyncProgress } from '../../shared/ui/sync-progress';

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

export const refreshPostListTable = async (): Promise<boolean> => {
  try {
    const response = await fetch(window.location.href, {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    if (!response.ok) {
      return false;
    }

    const html = await response.text();
    const parsed = new DOMParser().parseFromString(html, 'text/html');

    return replaceSingle('#the-list', parsed)
      && replaceAll('.tablenav-pages', parsed)
      && replaceAll('.displaying-num', parsed)
      && replaceAll('.subsubsub', parsed);
  } catch {
    return false;
  }
};

export const reloadPostListPage = (): void => {
  window.location.reload();
};

const replaceSingle = (selector: string, parsed: Document): boolean => {
  const current = document.querySelector(selector);
  const next = parsed.querySelector(selector);

  if (!current || !next) {
    return false;
  }

  current.replaceWith(next.cloneNode(true));
  return true;
};

const replaceAll = (selector: string, parsed: Document): boolean => {
  const currentNodes = Array.from(document.querySelectorAll(selector));
  const nextNodes = Array.from(parsed.querySelectorAll(selector));

  if (currentNodes.length !== nextNodes.length) {
    return false;
  }

  currentNodes.forEach((node, index) => {
    const next = nextNodes[index];

    if (next) {
      node.replaceWith(next.cloneNode(true));
    }
  });

  return true;
};

const sourceStatusElement = (source: SourceRecord): HTMLDivElement => {
  const wrapper = document.createElement('div');
  const title = document.createElement('strong');
  const status = document.createElement('span');

  wrapper.className = 'docsync-wp-list-status is-linked';
  title.textContent = source.googleTitle || source.googleFileId;
  status.textContent = capitalizeStatus(source.syncStatus || 'linked');
  wrapper.append(title, document.createElement('br'), status);

  if (shouldShowSyncProgress(source)) {
    wrapper.append(sourceProgressElement(source));
  }

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

const sourceProgressElement = (source: SourceRecord): HTMLDivElement => {
  const progress = Math.max(0, Math.min(100, Math.round(source.syncProgress ?? 0)));
  const message = source.syncMessage || '';
  const wrapper = document.createElement('div');
  const bar = document.createElement('div');
  const fill = document.createElement('span');
  const label = document.createElement('small');

  wrapper.className = 'docsync-wp-sync-progress-wrap';
  bar.className = 'docsync-wp-sync-progress';
  bar.setAttribute('role', 'progressbar');
  bar.setAttribute('aria-label', `Sync progress: ${progress}%`);
  bar.setAttribute('aria-valuemin', '0');
  bar.setAttribute('aria-valuemax', '100');
  bar.setAttribute('aria-valuenow', String(progress));
  fill.style.width = `${progress}%`;
  label.textContent = message ? `${progress}% - ${message}` : `${progress}%`;
  bar.append(fill);
  wrapper.append(bar, label);

  return wrapper;
};

const capitalizeStatus = (status: string): string => {
  return status ? `${status.charAt(0).toUpperCase()}${status.slice(1)}` : 'Linked';
};
