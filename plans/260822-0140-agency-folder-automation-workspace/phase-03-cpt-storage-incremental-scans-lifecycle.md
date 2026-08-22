# Phase 3 — CPT storage, incremental scans, lifecycle policy, raised caps

## Objective

Scale the watch subsystem: move watches to CPT rows (kills gap #9 races), replace full re-list scans with the Drive Changes API (gap #4), handle renamed/moved/trashed Docs (lifecycle), then raise caps (gap #5).

## 1. CPT storage migration

- Register `docsync_folder_watch`: `public => false`, `show_ui => false`, `supports => []`, no rewrite. Title = folder name (searchability in future admin), all fields as individual post meta (`_docsync_watch_folder_id`, `_docsync_watch_drive_id`, `_docsync_watch_interval`, `_docsync_watch_status`, `_docsync_watch_pending` (array), `_docsync_watch_excluded`, `_docsync_watch_failed`, `_docsync_watch_counts`, `_docsync_watch_owner`, `_docsync_watch_changes_token`, `_docsync_watch_removed_policy`, timestamps). Watch public `id` stays the existing UUID (meta `_docsync_watch_uuid`, indexed lookup) so REST URLs, cron args, and `_docsync_wp_folder_watch_id` source meta never change.
- `FolderWatchRepository` keeps its exact interface (`all/get/findByFolder/save/delete/deleteAll`); only internals change to `WP_Query`/`get_posts` + meta. Callers untouched.
- Per-watch save now writes one post row → concurrent scan/import ticks on different watches no longer clobber each other; same-watch writes stay serialized by `FolderWatchLock`.
- Upgrade routine: read `docsync_wp_folder_watches` option → create CPT rows → verify count → delete option. Idempotent (skip when UUID exists). `uninstall.php`: delete CPT posts + meta.

## 2. Drive Changes API incremental scans

- `DriveClient`: add `getChangesStartPageToken(user_id, drive_id)` and `listChanges(user_id, token, drive_id)` (fields: `fileId, file(name, mimeType, parents, trashed), removed, nextPageToken, newStartPageToken`). `drive.readonly` already authorizes both.
- Watch creation and migration store `changesPageToken` from `getStartPageToken`.
- `FolderWatchRunner::scan` becomes two-path:
  - **Incremental** (token present): consume changes pages; for each change resolve membership (file's `parents` chain within watched folder tree — cache folder-id set per scan; refresh subfolder set when a change touches a folder). Classify:
    - new Doc in scope, not linked, not excluded → pending
    - known Doc renamed → update source stored title metadata; log `source_renamed`
    - known Doc moved out of scope / `trashed` / `removed` → lifecycle policy (below)
    - store `newStartPageToken`
  - **Full re-list fallback**: token empty or Google returns 410/invalid token → existing `DriveFolderInventory` walk, then re-seed token. Also used for the very first scan.
- Shared-drive watches pass `driveId`; My Drive watches use user changes feed.

## 3. Removed-Doc lifecycle policy

- Per-watch `removedDocPolicy`: `keep` (default) | `draft` | `trash`. Editable in Phase 1 drawer (field lands with this phase).
- On removal detection: apply policy to linked post (`wp_update_post` status / `wp_trash_post`), set source `sync_status` to new `orphaned` state, clear from schedule (`next_sync_at = ''`), log Sync Activity event `source_removed_in_drive` with action taken.
- `orphaned` surfaces in Sources health summary (attention bucket) with row action "Unlink" or "Relink to another Doc" (existing flows).
- Docs restored from trash: next scan sees them again → relink pending if policy left post alive and it is still linked; otherwise import as new.

## 4. Raised caps

- `MAX_WATCHES 10 → 50` (`apply_filters('docsync_wp_max_folder_watches', 50)`).
- `DriveFolderInventory::MAX_DOCUMENTS 50 → 500`, `MAX_DEPTH 3 → 5` (`docsync_wp_max_watch_documents`, `docsync_wp_max_watch_depth`). Inventory UI checklist paginates at 50/page.
- First-import pacing unchanged (batch 5 per tick) — 500-Doc onboarding drains via existing import chain; show progress on Drive Folders screen.

## Acceptance

- Migration: site with N option watches boots to N CPT watches, option gone, REST ids unchanged, cron args still fire.
- Rename a Doc in Drive → source title metadata updates on next scan tick without full re-list (verify via request count/log).
- Trash a Doc with policy `draft` → WP post switches to draft, source `orphaned`, activity event present, no further scheduled syncs.
- Token invalidation (simulate 410) → fallback full scan re-seeds token; no data loss.
- 50 watches × hourly scans stay under Drive quota: incremental `changes.list` is 1–2 requests/scan vs up to 60 today.

## Verification

- `scripts/verify-watch-migration.php` (composer `test:watch-migration`): option→CPT fidelity on fixture data.
- `scripts/verify-changes-classifier.php`: classification matrix (new/renamed/moved-out/trashed/restored, excluded, out-of-scope).
- Full suite: `composer lint`, all `composer test:*`, `pnpm typecheck && pnpm lint && pnpm build`, devcontainer end-to-end.

## Risks

- Changes feed is per-user/per-drive, not per-folder → membership resolution needs the watched folder-id set; keep a cached subtree index on the watch, rebuilt on folder-structure changes (folder-type change entries) or fallback scans.
- Multiple watches sharing one owner consume one changes feed — process feed once per owner per tick and fan out to that owner's watches (optimization; correctness holds either way since tokens are per watch).
- Meta array fields (pending 500 ids) are one serialized row each — same shape as today's option, now per-watch. Acceptable; revisit only if profiling says otherwise.
