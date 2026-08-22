# Phase 1 — Drive Folders management UI + editable watches

## Objective

Dedicated admin surface where agencies see and edit every folder automation. Kills gap #1 (immutable watches) and #7 (no visibility). No storage change; option-backed repository stays.

## Backend

### 1. Update route

`PATCH /folders/(?P<id>[a-z0-9-]+)` in `src/Rest/FolderWatchController.php`, permission `RestPermissions::canUseAuthenticatedRest` + owner-or-admin check (reuse `FolderWatchService::userCanAccess` via service).

Editable fields (server-validated with existing sanitizers):

- `syncInterval` (`site|off|hourly|twicedaily|daily`) → call `syncWatchSchedule`
- `postStatus` (`draft|publish`) → re-check `userCanPublishSyncedPost`; applies to future imports only
- `layoutPreset`, `elementorSync`, `elementorPreset` → validate against `LayoutPresetRegistry` / `ElementorPresetRegistry`; applies to future imports (existing sources keep per-source override behavior)
- `includeSubfolders` (bool) → re-inventory folder, reconcile pending
- `excludedFileIds` (string[]) → reconcile pending: drop newly excluded IDs from `pendingFileIds`; newly included IDs re-enter pending when not already linked

Not editable: `folderId`, `driveId`, `postType` (product lock), `ownerUserId` (Phase 4 transfer).

### 2. `FolderWatchService::update()`

New method: load via `requireWatch`, apply whitelisted fields, run reconciliation, save, `syncWatchSchedule`, `scheduleImport` when pending non-empty, return `formatWatch`.

### 3. Richer `formatWatch`

Add:

- `nextScanAt` — `wp_next_scheduled( SCAN_HOOK, [id] )` as ISO string or empty
- `ownerDisplayName` — `get_userdata(ownerUserId)->display_name` (safe; no email)
- `effectiveInterval` — resolved `site` → actual value so UI never recomputes

### 4. Cron heartbeat

- `SyncCron::run` and `FolderWatchService::runScan` write `update_option( 'docsync_wp_last_cron_run_at', time(), false )`.
- `GET /workspace` (`WorkspaceController`) returns `cronHealth: { lastRunAt, stalled }` where `stalled` = last run older than 2× shortest active interval (min 2h). No secrets exposed; matches route's least-privilege contract.

## Frontend

### 5. New admin screen "Drive Folders"

- `src/Admin/AdminPage.php`: submenu between Sources and Logs, capability `read` (same role model as Sources; service filters by access), slug `FOLDERS_MENU_SLUG`, mount `renderFolders`.
- `src/Assets/AssetRegistry.php` + `vite.config.ts`: new entry `resources/js/admin/entries/folders-entry.tsx` + `resources/css/folders-entry.css`, own manifest, enqueued only on this screen.

### 6. Feature module `resources/js/admin/features/folder-watches/`

- `folder-watches-view.tsx` — page shell: health strip, watch table, empty state ("Watch your first Drive folder" → opens existing doc-source modal in folder mode)
- `folder-watch-table.tsx` — columns: folder (name + Drive link + subfolders badge), owner, target (`postType` read-only + `postStatus`), schedule (effective label), last scan, next scan, imported/pending/failed counts, status pill; row actions Scan now / Pause-Resume / Edit / Remove (Radix confirm)
- `folder-watch-detail-drawer.tsx` — edit form (interval, post status, presets, include subfolders), failed-file list with per-file `code`/`message` + Retry failed, per-Doc inventory include/exclude checklist (fetch via existing `GET /drive/folders/:folderId/documents`), "View synced posts" link → Sources filtered by `folderWatchId`
- `use-folder-watches.ts` — load/poll (reuse Sources polling pattern), optimistic pause/resume, PATCH wiring
- `resources/js/admin/api/folder-watch-api.ts` — add `updateFolderWatch(id, patch)`; extend `FolderWatchRecord` type with `nextScanAt`, `ownerDisplayName`, `effectiveInterval`

### 7. Cross-links

- Sources screen: `folderWatchId` filter param + "From folder: X" chip on rows (meta already returned as `folderWatchId`).
- Existing `sources-folder-watches.tsx` section becomes a compact summary linking to the new screen (keep, don't duplicate actions).

### 8. Cron health strip

Banner on Drive Folders (and Sources) when `cronHealth.stalled`: copy explains WP-Cron traffic dependency, links README server-cron guidance, `DISABLE_WP_CRON` hint.

## Acceptance

- Edit a watch's schedule from daily → hourly; `wp_get_schedule` for its scan hook changes without recreation; counters preserved.
- Exclude a linked Doc → it stops appearing in future scans; include a previously excluded Doc → it enters pending and imports.
- Next scan time visible and matches `wp_next_scheduled`.
- Non-owner non-admin cannot PATCH (403); admin can.
- Screen loads only its own bundle (check enqueued handles).

## Verification

`composer lint`, `pnpm typecheck`, `pnpm lint`, `pnpm build`, devcontainer manual pass (create watch → edit → scan → verify), REST permission tests for PATCH.

## Risks

- Reconcile logic on exclude/include must not duplicate posts → always gate on `findPostIdByGoogleFileId`.
- Editing while an import batch runs: `FolderWatchLock` already serializes import ticks; `update()` must re-read watch after lock-free save races (acceptable at option storage; fully fixed by Phase 3 CPT rows).
