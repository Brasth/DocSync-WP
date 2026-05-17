# Phase 04: Post Edit and List Table Entry Points

## Overview

Priority: P1  
Status: Pending  
Effort: 8h

Add the requested admin entry points: post detail sync button, post list "Add Sync Doc", and inline row actions for each post item.

## UX Requirements

- Post edit/detail page:
  - Show DocSync meta box for enabled post types.
  - If no source: "Link Google Doc".
  - If linked: "Sync now", "Change Doc", "Detach", status.
- Post listing page:
  - Add top action "Add Sync Doc" for current post type.
  - Inline row action per item:
    - "Link Google Doc" if unlinked.
    - "Sync Doc" if linked.
  - Optional status column: linked title, last synced, error badge.
- Modal input:
  - Choose from Google.
  - Paste Google Doc URL.
  - Paste Google Doc ID.

## Files

Create:

- [/Volumes/500GB/Projects/DocSync-WP/src/Admin/PostSyncMetaBox.php](/Volumes/500GB/Projects/DocSync-WP/src/Admin/PostSyncMetaBox.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Admin/PostListActions.php](/Volumes/500GB/Projects/DocSync-WP/src/Admin/PostListActions.php)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/post-sync-entry.tsx](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/post-sync-entry.tsx)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/components/DocSourceModal.tsx](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/components/DocSourceModal.tsx)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/google-picker.ts](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/google-picker.ts)
- [/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/api.ts](/Volumes/500GB/Projects/DocSync-WP/resources/js/admin/api.ts)

Modify:

- [/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php](/Volumes/500GB/Projects/DocSync-WP/src/Plugin.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Assets/AssetRegistry.php](/Volumes/500GB/Projects/DocSync-WP/src/Assets/AssetRegistry.php)
- [/Volumes/500GB/Projects/DocSync-WP/vite.config.ts](/Volumes/500GB/Projects/DocSync-WP/vite.config.ts)
- [/Volumes/500GB/Projects/DocSync-WP/resources/css/admin.css](/Volumes/500GB/Projects/DocSync-WP/resources/css/admin.css)

## WordPress Hooks

- `add_meta_boxes`: register DocSync meta box for enabled post types.
- `admin_enqueue_scripts`: enqueue post sync entry bundle on `post.php`, `post-new.php`, and `edit.php`.
- `post_row_actions`: add row action for posts.
- `page_row_actions`: add row action for pages if enabled.
- `bulk_actions-edit-{post_type}`: optional later, not MVP.
- `manage_{post_type}_posts_columns`: optional status column.
- `manage_{post_type}_posts_custom_column`: render status.
- `restrict_manage_posts`: add top "Add Sync Doc" button/mount point.

## Implementation Steps

1. Create `PostSyncMetaBox` that renders a lightweight mount node with post id/type and source state.
2. Create `PostListActions` that injects:
   - row action links with nonce/data attrs.
   - top list-table button.
   - optional status column.
3. Extend Vite config for a second admin entry bundle.
4. Update `AssetRegistry` to enqueue correct bundle for DocSync top-level page and post/list pages.
5. Add `wp-api-fetch` dependency for scripts.
6. Build `DocSourceModal` with three tabs/options:
   - Google Picker.
   - URL paste.
   - File ID paste.
7. Implement `google-picker.ts`:
   - Load Google Picker script.
   - Use picker API key/app id/client id from safe config.
   - Use current access flow from connected Google account.
8. On submit:
   - Call `documents/inspect`.
   - Show doc title and target.
   - Attach to existing post or create new post.
   - Optionally trigger immediate sync.
9. Row "Sync Doc" calls source sync endpoint and updates row status without full page reload.

## Accessibility and UX Notes

- Use native buttons/links matching WordPress admin style.
- Modal must trap focus and close on escape.
- Show clear error for not connected, access denied, invalid URL, unsupported file type.
- Do not use vague copy. Tell user when Picker is required because pasted doc is not accessible.

## Success Criteria

- Existing post edit screen has working DocSync controls.
- Listing page has "Add Sync Doc" for current enabled post type.
- Existing rows have inline link/sync actions.
- User can choose a Doc or paste link/id.
- New post creation uses current list post type.

## Todo

- [ ] Add meta box.
- [ ] Add list table actions.
- [ ] Add status column.
- [ ] Add secondary Vite entry.
- [ ] Add modal component.
- [ ] Add Google Picker helper.
- [ ] Add REST client helper.
- [ ] Wire row action events.
