import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { syncSource, type SourceRecord } from '../../api';

type Loose = Record<string, any>; // eslint-disable-line @typescript-eslint/no-explicit-any

const bindAttempts = 25;
const bindIntervalMs = 200;

const wpGlobal = (): Loose | undefined => (window as unknown as { wp?: Loose }).wp;

const notify = (status: 'info' | 'error', message: string): void => {
  try {
    wpGlobal()?.data?.dispatch?.('core/notices')?.createNotice?.(status, message, { type: 'snackbar', isDismissible: true });
  } catch {
    // Notices are best effort.
  }
};

const registerPrePublishNotice = (wp: Loose, source: SourceRecord): boolean => {
  const registerPlugin = wp.plugins?.registerPlugin;
  const Panel = wp.editor?.PluginPrePublishPanel ?? wp.editPost?.PluginPrePublishPanel;

  if (typeof registerPlugin !== 'function' || !Panel) {
    return false;
  }

  if (source.syncStatus === 'update_available') {
    registerPlugin('docsync-wp-pre-publish', {
      render: () => createElement(
        Panel,
        { initialOpen: true, title: __('Google Doc sync', 'brasth-document-sync-for-google-docs') },
        createElement('p', null, __('The Google Doc changed since this post was last synced. Use "Apply update" in the Google Doc sync box before publishing.', 'brasth-document-sync-for-google-docs'))
      )
    });
  }

  return true;
};

const registerCommands = (wp: Loose, postId: number, source: SourceRecord): boolean => {
  const registerCommand = wp.data?.dispatch?.('core/commands')?.registerCommand;

  if (typeof registerCommand !== 'function') {
    return false;
  }

  registerCommand({
    name: 'docsync-wp/sync',
    label: __('Sync from Google Doc', 'brasth-document-sync-for-google-docs'),
    callback: ({ close }: { close: () => void }) => {
      close();
      syncSource(postId, 'background')
        .then((result) => notify('info', result.source?.syncMessage || __('Google Doc sync queued.', 'brasth-document-sync-for-google-docs')))
        .catch((caught: unknown) => notify('error', caught instanceof Error ? caught.message : __('Sync failed.', 'brasth-document-sync-for-google-docs')));
    }
  });

  if (source.googleDocUrl) {
    registerCommand({
      name: 'docsync-wp/open-doc',
      label: __('Open Google Doc', 'brasth-document-sync-for-google-docs'),
      callback: ({ close }: { close: () => void }) => {
        close();
        window.open(source.googleDocUrl, '_blank', 'noopener');
      }
    });
  }

  return true;
};

/**
 * Block editor extras: a pre-publish notice when the Doc has an update waiting, and command palette entries.
 *
 * Editor scripts may load after this bundle, so binding retries briefly. Nothing happens in the classic editor.
 */
export const registerEditorIntegration = (postId: number, source: SourceRecord | null): void => {
  if (!source || postId <= 0) {
    return;
  }

  let attempts = 0;
  let noticeBound = false;
  let commandsBound = false;

  const bind = (): boolean => {
    const wp = wpGlobal();

    if (!wp?.data) {
      return false;
    }

    try {
      noticeBound = noticeBound || registerPrePublishNotice(wp, source);
      commandsBound = commandsBound || registerCommands(wp, postId, source);
    } catch {
      return true;
    }

    return noticeBound && commandsBound;
  };

  if (bind()) {
    return;
  }

  const timer = window.setInterval(() => {
    attempts += 1;

    if (bind() || attempts >= bindAttempts) {
      window.clearInterval(timer);
    }
  }, bindIntervalMs);
};
