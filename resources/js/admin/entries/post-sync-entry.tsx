import { createElement, createRoot } from '@wordpress/element';

import '../../../css/post-sync-entry.css';
import { registerEditorIntegration } from '../features/post-sync/editor-integration';
import { ListEntryApp } from '../features/post-sync/list-entry-app';
import { PostMetaBoxApp } from '../features/post-sync/post-meta-box-app';
import { parseSource } from '../features/post-sync/post-sync-dom';

const postRoot = document.getElementById('docsync-wp-post-sync-root');

if (postRoot) {
  const initialSource = parseSource(postRoot.dataset.source);
  const postId = Number(postRoot.dataset.postId ?? 0);

  createRoot(postRoot).render(
    <PostMetaBoxApp
      defaultElementorSync={postRoot.dataset.defaultElementorSync === 'true'}
      elementorAvailable={postRoot.dataset.elementorAvailable === 'true' && postRoot.dataset.elementorEnabled === 'true'}
      initialSource={initialSource}
      postId={postId}
      postType={postRoot.dataset.postType ?? 'post'}
    />
  );

  registerEditorIntegration(postId, initialSource);
}

const listRoot = document.getElementById('docsync-wp-list-sync-root');

if (listRoot) {
  createRoot(listRoot).render(<ListEntryApp postType={listRoot.dataset.postType ?? 'post'} />);
}
