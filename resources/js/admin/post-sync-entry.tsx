import { createElement, Fragment, createRoot, useEffect, useState } from '@wordpress/element';

import { detachSource, syncSource, type SourceRecord, type SyncResult } from './api';
import { DocSourceModal } from './components/DocSourceModal';
import '../../css/admin.css';

type ModalTarget =
  | { mode: 'existing'; postId: number; postType?: string }
  | { mode: 'new'; postType: string };

type Notice = {
  type: 'success' | 'error';
  message: string;
};

const parseSource = (value: string | undefined): SourceRecord | null => {
  if (!value) {
    return null;
  }

  try {
    return JSON.parse(value) as SourceRecord | null;
  } catch {
    return null;
  }
};

const NoticeBlock = ({ notice }: { notice: Notice | null }): JSX.Element | null => {
  if (!notice) {
    return null;
  }

  return <div className={`notice notice-${notice.type} inline`}><p>{notice.message}</p></div>;
};

const sourceLabel = (source: SourceRecord | null): string => {
  if (!source) {
    return 'No Google Doc linked.';
  }

  const title = source.googleTitle || source.googleFileId;
  const status = source.syncStatus || 'linked';
  return `${title} (${status})`;
};

const PostMetaBoxApp = ({ postId, postType, initialSource }: {
  postId: number;
  postType: string;
  initialSource: SourceRecord | null;
}): JSX.Element => {
  const [source, setSource] = useState<SourceRecord | null>(initialSource);
  const [modalTarget, setModalTarget] = useState<ModalTarget | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);
  const [busy, setBusy] = useState(false);

  const syncNow = async () => {
    setBusy(true);
    setNotice(null);

    try {
      const result = await syncSource(postId);
      setSource(result.source ?? source);
      setNotice({ type: 'success', message: `Sync ${result.status}.` });
    } catch (caught) {
      setNotice({ type: 'error', message: caught instanceof Error ? caught.message : 'Sync failed.' });
    } finally {
      setBusy(false);
    }
  };

  const detach = async () => {
    setBusy(true);
    setNotice(null);

    try {
      await detachSource(postId);
      setSource(null);
      setNotice({ type: 'success', message: 'Google Doc detached.' });
    } catch (caught) {
      setNotice({ type: 'error', message: caught instanceof Error ? caught.message : 'Detach failed.' });
    } finally {
      setBusy(false);
    }
  };

  const onCompleted = (result: SyncResult) => {
    setSource(result.source ?? source);
    setNotice({ type: 'success', message: source ? 'Google Doc changed.' : 'Google Doc linked.' });
  };

  return (
    <Fragment>
      <div className="docsync-wp-post-box">
        <p>{sourceLabel(source)}</p>
        {source?.lastSyncedAt ? <p><strong>Last sync:</strong> {source.lastSyncedAt}</p> : null}
        {source?.syncError ? <p className="docsync-wp-list-error">{source.syncError}</p> : null}
        <NoticeBlock notice={notice} />
        <div className="docsync-wp-post-box__actions">
          <button className="button button-primary" disabled={busy} onClick={() => setModalTarget({ mode: 'existing', postId, postType })} type="button">
            {source ? 'Change Doc' : 'Link Google Doc'}
          </button>
          {source ? <button className="button" disabled={busy} onClick={syncNow} type="button">Sync now</button> : null}
          {source ? <button className="button-link-delete" disabled={busy} onClick={detach} type="button">Detach</button> : null}
        </div>
      </div>
      <DocSourceModal
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={onCompleted}
        target={modalTarget}
      />
    </Fragment>
  );
};

const ListEntryApp = ({ postType }: { postType: string }): JSX.Element => {
  const [modalTarget, setModalTarget] = useState<ModalTarget | null>(null);
  const [notice, setNotice] = useState<Notice | null>(null);

  useEffect(() => {
    const onClick = async (event: MouseEvent) => {
      const link = (event.target as Element | null)?.closest('.docsync-wp-row-action') as HTMLElement | null;

      if (!link) {
        return;
      }

      event.preventDefault();
      const postId = Number(link.dataset.postId ?? 0);
      const rowPostType = link.dataset.postType ?? postType;

      if (link.dataset.mode === 'sync') {
        try {
          const result = await syncSource(postId);
          setNotice({ type: 'success', message: `Post ${postId} sync ${result.status}.` });
        } catch (caught) {
          setNotice({ type: 'error', message: caught instanceof Error ? caught.message : 'Sync failed.' });
        }
        return;
      }

      setModalTarget({ mode: 'existing', postId, postType: rowPostType });
    };

    document.addEventListener('click', onClick);
    return () => document.removeEventListener('click', onClick);
  }, [postType]);

  return (
    <Fragment>
      <button className="button button-primary docsync-wp-add-sync-doc" onClick={() => setModalTarget({ mode: 'new', postType })} type="button">
        Add Sync Doc
      </button>
      <NoticeBlock notice={notice} />
      <DocSourceModal
        isOpen={modalTarget !== null}
        onClose={() => setModalTarget(null)}
        onCompleted={(result) => {
          const editLink = result.source?.editUrl;
          setNotice({ type: 'success', message: result.created && editLink ? 'Synced draft created.' : `Sync ${result.status}.` });
        }}
        target={modalTarget}
      />
    </Fragment>
  );
};

const postRoot = document.getElementById('docsync-wp-post-sync-root');

if (postRoot) {
  createRoot(postRoot).render(
    <PostMetaBoxApp
      initialSource={parseSource(postRoot.dataset.source)}
      postId={Number(postRoot.dataset.postId ?? 0)}
      postType={postRoot.dataset.postType ?? 'post'}
    />
  );
}

const listRoot = document.getElementById('docsync-wp-list-sync-root');

if (listRoot) {
  createRoot(listRoot).render(<ListEntryApp postType={listRoot.dataset.postType ?? 'post'} />);
}
