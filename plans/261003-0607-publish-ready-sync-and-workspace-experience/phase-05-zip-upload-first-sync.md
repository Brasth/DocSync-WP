---
phase: 5
title: "Phase 5: ZIP upload first sync"
status: todo
priority: P1
effort: "3d"
dependencies: [2]
---

# Phase 5: ZIP upload first sync

## Overview

A user with no Google Cloud setup uploads the ZIP from Google Docs (File → Download → Web Page) and gets a formatted WordPress draft. One-time import; connecting Google is the follow-up offer.

## Requirements

- Functional:
  - Entry points: Setup (before credentials exist) and Sources empty state.
  - Dialog: choose `.zip`, target post type (creatable types only), layout preset (default site preset) → draft created → "Open draft".
  - Result panel offers "Connect Google to keep this post in sync".
  - Draft is a normal post: no source meta, no schedule.
- Non-functional: upload treated as untrusted input; synchronous request within typical PHP limits; WordPress Blocks output only (no Elementor path).

## Architecture

```
POST /imports/zip (multipart)  → ZipImportController
  permission: logged in + nonce + can create target post type
  validate file → ZipImportService::import()
     wp_insert_post (draft, title from <title> or file name)
     HtmlZipImporter::import( bytes, 'upload-{hash12}', post_id, user_id )
     LayoutConversionService::convertForPreset( html, preset )
     wp_update_post( post_content )
  → { postId, editUrl, title }
```

- `ZipImportService` is new and separate from `SyncService`; shares importer and layout converter only.
- Threat model changes: ZIPs so far came from Google; now from users. `HtmlZipPackageExtractor` validates paths but has no size or entry caps. Add to extractor (applies to both paths): max entries 500, max total uncompressed 100 MB, max single entry 25 MB, read sizes via `statIndex` before extraction.
- Upload validation: `is_uploaded_file`, `wp_check_filetype_and_ext` = zip, size ≤ min( 25 MB, `wp_max_upload_size()` ). Rate limit 10 imports per user per hour (transient, same approach as feedback route).
- On failure after draft creation: delete the empty draft (`wp_delete_post( …, true )`) so no orphan remains.
- Activation contract unchanged: ZIP import does not mark activation complete.

## Related code files

- Create: `src/Rest/ZipImportController.php`, `src/Sync/ZipImportService.php`
- Create: `resources/js/admin/features/zip-import/zip-import-dialog.tsx`, `use-zip-import.ts`
- Create: `resources/js/admin/api/zip-import-api.ts`
- Create: `scripts/verify-zip-package-limits.php`, `tests/fixtures/zip-import/{valid-export,path-traversal,too-many-entries,oversized-entry,no-html}/`
- Modify: `src/Sync/HtmlZipPackageExtractor.php` (caps), `src/Plugin.php` (routes, wiring)
- Modify: `resources/js/admin/features/google-setup/google-setup-active-task-panel.tsx`, `features/sources/sources-table.tsx` (empty state secondary action)
- Modify: `resources/css/components/doc-source-modal.css` or new `zip-import.css` partial
- Modify: `.devcontainer/scripts` route verification, `composer.json`, `pr-lint.yml`
- Modify: `readme.txt` (feature, FAQ "Can I try it without Google Cloud setup?"), `README.md`, `docs/system-architecture.md`

## Implementation steps

1. Extractor caps + fixtures + verify script. Confirm Google-sourced fixtures still pass.
2. `ZipImportService` with injected importer/converter; title extraction; cleanup on failure.
3. REST controller: args schema, file validation, rate limit, capability check via existing `RestPermissions` helpers.
4. Frontend dialog (Radix Dialog, existing tokens): file input with drag-and-drop, post type select, preset select, progress state, error state with plain-language causes, success panel.
5. Entry points in Setup first task panel (secondary link under the credential task) and Sources empty state.
6. Devcontainer: upload Phase 1 export; verify blocks, images in Media Library, links clean; upload malformed fixtures → 400 with safe message.
7. Docs, readme FAQ, screenshot of dialog.

## Todo

- [x] Extractor caps + fixtures
- [x] Import service
- [x] REST route + rate limit
- [x] Dialog UI
- [x] Entry points
- [x] Devcontainer verification
- [x] Docs + readme

## Success criteria

- [x] Fresh install, no OAuth settings: ZIP → draft with blocks and imported images in under 2 minutes of user time.
- [x] Traversal, oversize, entry-count, and non-ZIP fixtures rejected before any file is written outside the temp dir.
- [x] Failed import leaves no draft and no temp directory.
- [x] User without create capability for the post type gets 403.

## Risk assessment

- Large ZIP exceeds `max_execution_time` → size cap + clear error telling user to connect Google for background sync.
- Users expect re-sync from ZIP → copy says "one-time import" in dialog and result panel.

## Security considerations

- Zip bomb and traversal handled in extractor; HTML passes existing sanitizer; images pass existing `wp_check_filetype_and_ext` image check; nonce + capability + rate limit on the route.

## Next steps

Measure via optional telemetry later (not in this plan).
