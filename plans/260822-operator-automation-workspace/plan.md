---
title: "Operator Automation Workspace"
description: "Give agency operators a Sources UI to configure each Drive folder and each linked Doc, with interval-aware scheduled sync."
status: proposed
priority: P1
branch: cursor/operator-automation-workspace-44f3
tags: [sources, folder-watch, schedule, operators, ux]
created: 2026-08-22
---

# Operator Automation Workspace

## Goal

Replace the create-only folder strip and site-only cron with a Sources workspace where operators set policy on each watched Drive folder and cadence on each linked Google Doc.

Approved design: [docs/superpowers/specs/2026-08-22-operator-automation-workspace-design.md](../../docs/superpowers/specs/2026-08-22-operator-automation-workspace-design.md)

## Why This Is Next

Folder watch already imports Docs and stores `folderWatchId`. The missing product is the operator UI plus a due query that honors per-folder and per-Doc intervals. Without the due-query change, any schedule dropdown is cosmetic.

## Constraints

- Keep Sources as the only operational home. No new submenu.
- Additive REST. `GET /workspace` stays least-privilege.
- Keep 10-watch and 50-Doc caps. Do not build the 1.3.0 durable job queue.
- Keep one recurring re-sync hook. Do not register a cron event per source.
- Removing a watch or pausing a Doc must not delete WordPress content.
- WP-Cron remains traffic-driven; copy must keep the real-server-cron hint.

## Phase Overview

| Phase | Work | Risk |
| --- | --- | --- |
| 01 Interval-aware due query | Source meta, resolution, backfill, cron selection | High. False schedules if this is skipped or wrong. |
| 02 Folder policy PATCH | Update watch after create; re-inventory when subfolders/excludes change | Medium. Must not detach existing members. |
| 03 Folder workspace UI | Sources folder card + in-page panel + member table | Medium. Density vs current strip. |
| 04 Per-Doc schedule UI | Table column, inspector, inherit labels | Low if phase 01 is done. |
| 05 Verification | Fixtures, lint/typecheck, create-then-edit path | Release gate. |

Do not start UI phases until phase 01 due selection is proven with PHP tests.

## Phase 01 — Interval-aware due query

### Files

- `src/Sync/SourceRepository.php`
- `src/Cron/SyncCron.php`
- `src/Sync/FolderWatchService.php` (effective interval helper reuse)
- new focused helper if `SourceRepository` would exceed a clean boundary, e.g. `src/Sync/SourceScheduleResolver.php`
- PHP tests under the existing fixture/test layout

### Behavior

1. Add `_docsync_wp_sync_interval` and `_docsync_wp_next_sync_at`.
2. Resolve effective interval: source → folder (`site` falls through) → site default.
3. After `synced` / `skipped`, write `next_sync_at`.
4. `off` writes no future time; recurring cron skips those posts.
5. `listDueSourcePostIds*` filters on `next_sync_at <= now` instead of `last_synced <= now`.
6. On schedule reconcile (settings update, watch interval PATCH, plugin init), backfill missing `next_sync_at` as due now.
7. Expose `syncInterval`, `effectiveSyncInterval`, `nextSyncAt` on formatted sources.

### Done when

- Mixed intervals in one site produce the expected due set.
- Existing owner-transfer and lock behavior unchanged.
- Upgrade path: old sources become due once, then follow inherit.

## Phase 02 — Folder policy PATCH

### Files

- `src/Rest/FolderWatchController.php`
- `src/Sync/FolderWatchService.php`
- `resources/js/admin/api/folder-watch-api.ts`

### Behavior

1. `PATCH /folders/{id}` accepts the create policy fields.
2. Unknown keys still 400.
3. `includeSubfolders` or exclude-list change re-inventories and queues new pending IDs only.
4. Existing members stay linked. Newly excluded IDs are not detached.
5. Interval change reschedules that watch's scan hook and triggers source `next_sync_at` reconcile for inherit members.
6. Publish permission check stays on `postStatus=publish`.

### Done when

- Create-then-edit works through REST without delete/recreate.
- Pause still drops scan events; PATCH does not auto-resume.

## Phase 03 — Folder workspace UI

### Files

- `resources/js/admin/features/sources/sources-folder-watches.tsx` (replace or split)
- `resources/js/admin/app/sources-app.tsx`
- `resources/js/admin/app/use-sources-app.ts`
- `resources/js/admin/features/doc-source-modal/folder-watch-confirm-panel.tsx` (unlock fields when editing)
- `resources/css` sources partials
- `GET /sources?folder_watch_id=`

### Behavior

1. Folder card lists watches with status, counts, next scan, attention.
2. Select a folder: in-page panel with policy controls and member table.
3. After modal create, Sources selects that folder.
4. Copy names the scan clock vs the Doc re-sync clock.
5. Keep scan / pause / resume / remove / retry.
6. No fourth admin submenu.

### Done when

- Operator can change folder interval and exclude a Doc from Sources.
- Keyboard and existing Brasth tokens still apply. No nested cards.

## Phase 04 — Per-Doc schedule UI

### Files

- `resources/js/admin/features/sources/sources-table.tsx`
- `resources/js/admin/features/post-sync/*` inspector
- `src/Rest/SourceController.php`

### Behavior

1. Sources table: folder name (if any), effective schedule, next sync.
2. Filter by folder, including hand-linked / ungrouped.
3. Row or inspector control: inherit / off / hourly / twicedaily / daily. `off` is the pause-this-Doc shortcut.
4. Post editor shows the same effective cadence; does not expose site OAuth.
5. Sync now ignores `next_sync_at`.

### Done when

- One member can be `off` while siblings still follow the folder inherit path.
- Intent card copy no longer says every single Doc only follows the site schedule.

## Phase 05 — Verification

### Commands

```sh
composer lint
composer test:layout-fixtures
pnpm lint
pnpm typecheck
pnpm build
```

Plus new PHP coverage for schedule resolution and due selection.

### Manual

- Create folder watch, leave modal, edit policy on Sources.
- Set site daily, folder hourly, one Doc off.
- Confirm scan vs re-sync copy.
- Remove watch: posts remain; inherit falls back to site.

## Non-goals

- Automations submenu
- Custom intervals
- Raising 10/50 caps
- Preset gallery, preview, bulk archive import
- Machine-agent API keys

## Success Criteria

- Operators configure each folder and each Doc from Sources.
- Stored intervals match cron eligibility.
- Site default remains inherit.
- Current create / pause / scan / single-Doc link paths keep working.

## Unresolved Questions

- Confirm operators (human agents) are the audience, not a machine client.
- Historical folder label after watch delete: keep or ungroup.
- Pause-folder vs pause-members: spec pauses scans only.
