# Phase 03: Sync Service and Import Pipeline

## Overview

Priority: P1  
Status: Pending  
Effort: 8h

Build the service that attaches Google Docs to posts, creates new posts from Docs, exports content, sanitizes it, and stores sync state.

## Files

Create:

- [/Volumes/500GB/Projects/DocSync-WP/src/Sync/SyncService.php](/Volumes/500GB/Projects/DocSync-WP/src/Sync/SyncService.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Sync/ContentConverter.php](/Volumes/500GB/Projects/DocSync-WP/src/Sync/ContentConverter.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Sync/SyncLock.php](/Volumes/500GB/Projects/DocSync-WP/src/Sync/SyncLock.php)
- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/SourceController.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/SourceController.php)

Modify:

- [/Volumes/500GB/Projects/DocSync-WP/src/Rest/RestServiceProvider.php](/Volumes/500GB/Projects/DocSync-WP/src/Rest/RestServiceProvider.php)

## REST Contracts

`POST /docsync-wp/v1/sources`

Create or attach a source.

```json
{
  "fileId": "...",
  "target": {
    "mode": "existing|new",
    "postId": 123,
    "postType": "post"
  },
  "exportFormat": "markdown"
}
```

`POST /docsync-wp/v1/sources/{postId}/sync`

Sync an attached source now.

`DELETE /docsync-wp/v1/sources/{postId}`

Detach Google Doc source from a post.

`GET /docsync-wp/v1/sources?post_type=post`

List synced sources for admin page and post list state.

## Implementation Steps

1. Add `SourceController` with create, sync, detach, list.
2. For `mode=existing`, verify `current_user_can( 'edit_post', $post_id )`.
3. For `mode=new`, verify post type enabled and current user can create/edit that type.
4. Inspect Drive metadata before saving source.
5. Create new post as `draft` by default:
   - title from Google Doc name.
   - post type from listing context.
   - empty content until first sync completes, or sync immediately.
6. Add `SyncService::syncPost( int $post_id, int $user_id )`.
7. Lock per post using transient to avoid concurrent sync.
8. Fetch metadata, compare modified time/version/hash, skip unchanged unless forced.
9. Export Markdown.
10. Convert Markdown to safe HTML.
11. Update post with `wp_update_post()`.
12. Save sync metadata and status in post meta.

## Conversion Strategy

MVP options:

- Prefer a small Markdown parser if dependency is acceptable.
- If no dependency, start with conservative plain-text/paragraph conversion and keep Markdown syntax mostly literal.
- Do not ship unsafe custom HTML parsing.

Recommended implementation:

- Add `league/commonmark` only if Composer dependency size is acceptable.
- Sanitize final HTML with `wp_kses_post()`.
- Store original export hash before and after conversion for change detection.

## Conflict Policy

MVP policy:

- Google overwrites WordPress content on sync.
- Show warning in UI.
- Preserve WordPress revisions by using normal post update APIs.
- Later phase can add diff/preview.

## Success Criteria

- Existing post can be linked to a Google Doc and synced.
- New post can be created from a Google Doc and synced.
- Unchanged Docs are skipped.
- Concurrent clicks do not trigger duplicate syncs.
- Sync errors are stored and visible to UI.

## Todo

- [ ] Add source REST controller.
- [ ] Add sync service.
- [ ] Add sync lock.
- [ ] Add Markdown conversion.
- [ ] Add source attach/detach.
- [ ] Add create-new target flow.
- [ ] Add sync metadata updates.
