---
phase: 11
title: "Phase 11: Knowledge base mode"
status: todo
priority: P3
effort: "6d"
dependencies: [6]
---

# Phase 11: Knowledge base mode

## Overview

A watched Drive folder publishes as a hierarchical set of pages: subfolders become parent pages, links between Docs become WordPress links, long documents get anchors and a table of contents.

**Gate:** start only after the owner confirms documentation / knowledge base as a target niche. Market bet, not evidence-backed.

## Requirements

- Functional:
  - Folder watch option `structure`: `flat` (today) | `hierarchy`. Hierarchy offered only for hierarchical post types.
  - Each subfolder → one container page (title = folder name, content = child page list). Docs → child pages (`post_parent`), `menu_order` by natural name order.
  - Folder rename → container title updates if not edited in WordPress; Doc moved between subfolders → re-parented on next scan.
  - Links to `docs.google.com/document/d/{id}` resolve to the synced post's permalink when one exists and is published. Applies to all synced Gutenberg posts, not only hierarchy watches. Site setting, default on.
  - Documentation preset: `id` anchors on H2/H3; table of contents list at top when the Doc has 3+ H2.
- Non-functional: link resolution never stores rewritten URLs (always current); cached lookups; no new post type.

## Architecture

- Hierarchy: `DriveFolderInventory` already tracks folder `path` during traversal → include `parentFolderId` and path segments on each document item. `FolderWatchRunner::importFile()` resolves/creates container pages via meta `_docsync_wp_folder_node_id` (+ existing `_docsync_wp_folder_watch_id`), then sets `post_parent`.
- Container content: `<!-- wp:page-list {"parentPageID":ID} /-->`, written once; never overwritten afterwards (container pages are not sources).
- Link resolution at render time: `the_content` filter (posts with `_docsync_wp_google_file_id` only) → regex on hrefs → `DocLinkResolver::permalinkForFileId()` → meta lookup, published + publicly viewable only, result cached in object cache, invalidated on attach, detach, status change, delete. Heading fragment (`#heading=…`) dropped.
- TOC + anchors: generated in `LayoutConversionService` for presets with the documentation flag; slug anchors deduplicated; pipeline version bump.
- Depends on current inventory caps (50 Docs, depth 3) until the folder automation plan raises them.

## Related code files

- Create: `src/Sync/FolderHierarchyMapper.php`, `src/Sync/DocLinkResolver.php`, `src/Sync/Layout/HeadingAnchorBuilder.php`
- Create: `scripts/verify-folder-hierarchy-mapping.php`, `scripts/verify-doc-link-resolution.php`
- Create: `tests/fixtures/layout-presets/documentation-toc/`, `tests/fixtures/folder-hierarchy/`
- Modify: `src/Google/DriveFolderInventory.php`, `src/Sync/FolderWatchRunner.php`, `src/Sync/FolderWatchService.php`, `src/Sync/FolderWatchRepository.php`, `src/Rest/FolderWatchController.php`
- Modify: `src/Sync/Layout/LayoutConversionService.php`, `LayoutBlueprint.php`, `src/Sync/ConversionPipelineVersion.php`
- Modify: `src/Settings/SettingsRepository.php`, `src/Plugin.php` (content filter), `uninstall.php`
- Modify: `resources/js/admin/features/doc-source-modal/folder-watch-confirm-panel.tsx`, `features/folder-watches/folder-watch-edit-form.tsx`, `folder-watch-detail-page.tsx`, `api/types.ts`
- Modify: `composer.json`, `pr-lint.yml`, `readme.txt`, `README.md`, `docs/system-architecture.md`

## Implementation steps

1. Inventory: add parent folder info to document items; mapper as pure function (inventory → tree → ordered create/reparent operations); fixtures for nested, renamed, moved, emptied folder.
2. Runner: container page create/lookup, `post_parent`, `menu_order`; `structure` stored on the watch, fixed at creation like `postType`.
3. Watch UI: structure choice shown only for hierarchical post types; detail page shows the tree.
4. Link resolver + content filter + cache invalidation hooks; fixtures for resolved, unpublished target, unknown ID, fragment.
5. Anchors + TOC in Documentation preset; fixtures; version bump.
6. Devcontainer: folder with 2 subfolders and cross-linked Docs → page tree, working internal links for logged-out visitor, TOC anchors; rename and move in Drive → next scan reflects it.
7. Docs, readme, screenshots.

## Todo

- [x] Inventory parent info + mapper
- [x] Container pages + parenting
- [x] Watch structure option UI
- [x] Link resolver + filter + cache
- [x] Anchors + TOC
- [x] Devcontainer scenario
- [x] Docs

## Success criteria

- [x] Folder `Handbook/{Onboarding,Policies}` with Docs produces matching parent/child pages in order.
- [x] Link from Doc A to Doc B opens the WordPress page for B; link to an unsynced Doc still opens Google Docs.
- [x] Draft target is never exposed: link stays a Google link until the target is published.
- [x] Deactivating the plugin leaves working (Google) links in content.

## Risk assessment

- Niche not validated → gate above; link resolution and TOC are useful alone and can ship without hierarchy.
- Content filter cost on high-traffic sites → runs only on posts with source meta; cached; setting to disable.
- Editors delete or move container pages → mapper recreates only when the node meta is missing; never moves user-reparented pages back (record manual parent override).

## Security considerations

- Resolver returns permalinks only for published, publicly viewable posts → no disclosure of draft or private URLs.

## Next steps

Revisit caps and incremental scans with the folder automation plan before promoting to agencies.
