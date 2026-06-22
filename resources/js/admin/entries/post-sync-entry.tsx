import { createElement, createRoot } from '@wordpress/element';

import '../../../css/post-sync-entry.css';
import { ListEntryApp } from '../features/post-sync/list-entry-app';
import { PostMetaBoxApp } from '../features/post-sync/post-meta-box-app';
import { parseSource } from '../features/post-sync/post-sync-dom';

const postRoot = document.getElementById('docsync-wp-post-sync-root');

if (postRoot) {
  createRoot(postRoot).render(
    <PostMetaBoxApp
      elementorAvailable={postRoot.dataset.elementorAvailable === 'true'}
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
