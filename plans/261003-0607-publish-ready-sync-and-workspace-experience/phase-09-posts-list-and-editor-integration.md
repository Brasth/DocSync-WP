---
phase: 9
title: "Phase 9: Posts list and editor integration"
status: todo
priority: P2
effort: "4d"
dependencies: [4, 8]
---

# Phase 9: Posts list and editor integration

## Overview

Sync actions live where editors already work: a native bulk action and clear badges in the Posts list, a native sidebar panel, pre-publish notice, and command palette entries in the block editor.

## Requirements

- Functional:
  - Bulk action "Sync from Google Docs" on enabled post types: queues background sync for selected linked posts (max 20), reports queued / skipped counts in an admin notice.
  - Sync column: status label from the shared map ("Up to date", "Update available", …) + relative last-sync time.
  - Block editor: plugin UI appears as a document settings panel, not under "Meta Boxes". Classic editor and non-block screens keep the meta box.
  - Pre-publish panel line when status is `update_available`.
  - Commands: "Sync from Google Doc", "Open Google Doc" when a source is linked.
- Non-functional: WordPress 6.4+ supported; no new bundled dependencies; same React component in both hosts.

## Architecture

- PHP: `bulk_actions-edit-{type}` + `handle_bulk_actions-edit-{type}` in `PostListActions`. Per post: `userCanSyncPost` → `markSyncQueued` → `SyncCron::scheduleSourceSync( …, spawn: false )`; one `spawnScheduledSyncs()` at the end. Redirect with `docsync_queued` / `docsync_skipped` args → `admin_notices`.
- Meta box registered with `__back_compat_meta_box => true` so the block editor hides it when the panel is available.
- Editor mount (`editor-panel-mount.ts`): read `window.wp.plugins`, `window.wp.editor?.PluginDocumentSettingPanel ?? window.wp.editPost?.PluginDocumentSettingPanel` at runtime (same dynamic-global approach already used for editor dirty state). Present → `registerPlugin` rendering `PostMetaBoxApp` inside the panel. Absent → mount into the meta box node as today.
- `AssetRegistry`: on block editor screens add `wp-plugins`, `wp-edit-post`, `wp-editor` as script dependencies of the post-sync handle. No Vite externals change.
- Commands via `window.wp.data.dispatch( 'core/commands' ).registerCommand` when the store exists.
- Initial source data: same inline config the meta box prints today, moved to an inline script so it exists without the meta box DOM.

## Related code files

- Create: `resources/js/admin/features/post-sync/editor-panel-mount.ts`, `editor-commands.ts`, `pre-publish-sync-notice.tsx`
- Modify: `resources/js/admin/entries/post-sync-entry.tsx`, `features/post-sync/post-meta-box-app.tsx` (host-agnostic wrapper class)
- Modify: `src/Admin/PostListActions.php`, `src/Admin/PostSyncMetaBox.php`, `src/Assets/AssetRegistry.php`
- Modify: `resources/css/components/post-sync-box.css` (panel width context)
- Modify: `readme.txt` screenshots/captions, `docs/design-guidelines.md` (Post Sync Surfaces), `docs/codebase-summary.md`

## Implementation steps

1. Bulk action + notice; capability and cap handling; unlinked posts counted as skipped.
2. Column label/time through a small PHP label map matching the JS map (single source: pass labels via existing inline config where JS renders, PHP map for server-rendered cells).
3. Move initial source payload to inline script; keep meta box rendering a mount node only.
4. Panel mount with runtime API detection; `__back_compat_meta_box` flag; verify meta box still shows in classic editor and for post types without block editor.
5. Pre-publish notice (uses stored status only; no Google call on publish).
6. Commands registration and cleanup on unmount.
7. Matrix in devcontainer: WP 6.4 and current; block editor post, page, CPT; classic editor (plugin or filter); Elementor-built post (meta box path).
8. `pnpm lint && pnpm typecheck && pnpm build`; update screenshots.

## Todo

- [ ] Bulk action + notice
- [ ] Column labels + relative time
- [ ] Inline initial payload
- [ ] Native panel mount + meta box fallback
- [ ] Pre-publish notice
- [ ] Command palette entries
- [ ] Version/editor matrix
- [ ] Docs + screenshots

## Success criteria

- [ ] Select 5 linked + 2 unlinked posts → notice "5 queued, 2 skipped"; progress visible in column.
- [ ] Block editor shows "Google Doc sync" panel in the document sidebar; no duplicate under Meta Boxes.
- [ ] Classic editor still shows the meta box with identical behaviour.
- [ ] `Cmd/Ctrl+K` → "Sync from Google Doc" starts a sync.

## Risk assessment

- Editor API location differs by WordPress version → runtime detection with meta box fallback keeps every version functional.
- Site editor / pattern editor screens → register only when a post ID and enabled post type are present.
- Third-party editors (Elementor) → unaffected; meta box path remains.

## Security considerations

- Bulk handler verifies the list-table nonce (core does) and per-post capability; ignores IDs outside enabled post types.

## Next steps

None blocking. Phase 10 completes the workspace side.
