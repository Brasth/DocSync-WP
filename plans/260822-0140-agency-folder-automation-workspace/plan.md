---
title: "Agency folder automation workspace"
description: "Analysis of the current Drive folder watch + schedule sync, and a phased plan for an agency-grade folder/doc setup UI with per-folder scheduling."
status: proposed
priority: P1
branch: "cursor/agency-folder-automation-plan-4fcc"
tags: [feature, frontend, backend, cron, drive, folder-watch, agency]
blockedBy: []
blocks: []
created: "2026-08-22T01:40:00.000Z"
createdBy: "cloud-agent"
source: analysis
---

# Agency folder automation workspace

## Overview

Target user shift: from "one operator links one Doc" to **agencies managing many client folders**. Goal: a real management UI where each Drive folder (and each Doc) has its own visible, editable automation setup, plus a scheduling engine that can actually honor per-folder cadence at agency scale.

## Current state (verified in code)

### What already exists

1. **Single-doc sources** — post-meta based (`SourceRepository`). Recurring site-wide WP-Cron hook `docsync_wp_sync_sources` re-syncs due sources; interval is one site setting: `off | hourly | twicedaily | daily` (`SyncCron`). Cheap skip when Google `modifiedTime`/`version`/layout hash unchanged (`SyncService::syncPost`).
2. **Folder watches** — `FolderWatchService` + `FolderWatchRepository` + `FolderWatchRunner`. Create from the doc-source modal: pick folder, optional subfolders (depth 3), exclude checkboxes, post type/status, layout or Elementor preset, per-watch scan interval (`site | off | hourly | twicedaily | daily`). Recurring `docsync_wp_scan_folder` discovers **new** Docs; `docsync_wp_import_folder` creates drafts in batches of 5.
3. **UI** — one small section on Sources (`sources-folder-watches.tsx`): folder name, `x/y imported`, status pill, `Scan now / Pause / Resume / Remove`. Nothing else.
4. Sources record `_docsync_wp_folder_watch_id`, so linked posts already know their originating watch.

### Gaps blocking the agency use case

| # | Gap | Where |
|---|-----|-------|
| 1 | **Watches are immutable after creation.** Confirm panel disables every control when `watch` exists; no edit endpoint. Changing schedule/status/preset/excludes = remove + re-add (loses counters, re-imports nothing but re-scans all). | `folder-watch-confirm-panel.tsx`, `FolderWatchController` |
| 2 | **Per-watch interval only controls discovery of new Docs.** Re-sync of already-imported Docs rides the single site interval. A client folder that needs hourly forces the whole site hourly. | `FolderWatchService::syncWatchSchedule` vs `SyncCron::run` |
| 3 | **Scheduler scale ceiling.** `SyncCron::run` processes 20 due sources per interval tick, no continuation event. 10 watches × 50 Docs = 500 sources → daily interval re-syncs 20/day. Backlog grows silently. | `SyncCron::BATCH_SIZE`, `listDueSourcePostIds` |
| 4 | **Full re-list every scan.** Each scan walks the whole folder tree (up to 20 pages/folder, depth 3). No Drive Changes API cursor. Expensive at agency folder sizes; also blind to renames/moves/deletes — trashed Docs leave orphaned WP posts silently. | `DriveFolderInventory`, `FolderWatchRunner::scan` |
| 5 | **Hard caps too small for agencies.** 10 watches/site, 50 Docs/watch inventory cap (`overflow` truncates), depth 3. | `FolderWatchRepository::MAX_WATCHES`, `DriveFolderInventory::MAX_DOCUMENTS` |
| 6 | **No mapping rules.** No folder→category/tag, no default author, no slug/title rules. Agencies structure Drive by client/topic; today every import lands flat. | `FolderWatchRunner::importFile` |
| 7 | **No operational visibility.** No next-scan time, no per-watch activity feed, no WP-Cron health warning, no notifications on failures. Failed files only visible inside the creation modal (`Retry failed`). | Sources UI, `sync-logs` |
| 8 | **Owner single point of failure.** Watch runs on `ownerUserId` token; token loss hard-fails the watch (`HARD_FAIL_CODES`). Sources have an ownership-transfer flow; watches do not. | `FolderWatchRunner`, `FolderWatchService::userCanAccess` |
| 9 | **Storage: one wp option array for all watches.** Load-all on every access, last-write-wins race between concurrent cron scans/imports; fine for 10 watches, not for 50+. | `FolderWatchRepository` |

## Proposal — 4 phases

### Phase 1 — Drive Folders management UI + editable watches (highest user value, lowest risk)

The direct answer to "UI for user can handle the setup each folder / each doc".

- New **Drive Folders** admin screen (submenu next to Sources; reuse admin shell + manifest pattern in `AssetRegistry`).
  - Watch table: folder name/path (link to Drive), owner, target post type + status, effective schedule, last scan, **next scan** (`wp_next_scheduled`), imported/pending/failed counts, health pill.
  - Row expand or detail drawer: per-watch settings form + failed-file list with per-file error and retry + "view synced posts" (filter Sources by existing `folderWatchId` meta).
- Backend: `PATCH /folder-watches/:id` accepting `syncInterval`, `postStatus`, `layoutPreset`, `elementorPreset`, `elementorSync`, `includeSubfolders`, `excludedFileIds`. On subfolder/exclude change: re-inventory and reconcile pending list; on interval change: `syncWatchSchedule`.
- Per-Doc handling inside a watch: inventory view with include/exclude toggles post-creation (today excludes are creation-time only).
- WP-Cron health strip: warn when `docsync_wp_sync_sources` last ran > 2× interval ago; link server-cron docs.
- Touches: `FolderWatchController`, `FolderWatchService` (update path), new `resources/js/admin/features/folder-watches/` + entry, `AdminPage`, `AssetRegistry`. No storage change. Invasiveness: moderate frontend, small backend.

### Phase 2 — Per-folder scheduling engine

Make the folder schedule mean what agencies expect: *this client's content refreshes hourly*.

- Store effective re-sync cadence per source: new meta `_docsync_wp_next_sync_at`, computed from watch interval override → site default. `SyncCron::run` selects `next_sync_at <= now` instead of `last_synced <= now-with-cutoff`; on completion (including `skipped`) write next due time.
- **Continuation events**: when a batch returns a full batch, schedule an immediate single event to keep draining instead of waiting a full interval. Keeps 500+ sources fresh on shared hosting without long requests.
- Optional per-source interval override (source detail + post sync metabox), `site | watch | off | hourly | twicedaily | daily`.
- Add `weekly` (and consider `every 15 min` behind a filter) to the interval list; register custom cron schedule.
- Migration: backfill `next_sync_at` from `last_synced_at` on upgrade; keep old query as fallback for one release.
- Touches: `SyncCron`, `SourceRepository` (due query + meta), `SyncService` (write-back), `SettingsController`, settings UI copy. Invasive in the scheduler core → needs the existing fixture/test scripts plus new due-query tests.

### Phase 3 — Incremental scans (Drive Changes API) + lifecycle policy

- Per-watch Drive `changes.list` cursor (`startPageToken` stored on the watch). Scan tick consumes changes: new Doc → pending; renamed → update source title metadata; moved out / trashed → lifecycle policy.
- Per-watch removed-Doc policy: `keep post (default) | set to draft | move to trash`, always logged to Sync Activity.
- Raise caps once scans are incremental: watches 10 → 50, inventory 50 → 500 (keep first-import batching at 5).
- Fallback: keep full re-list path for cursor expiry (Google invalidates tokens) and shared-drive edge cases.
- Touches: `DriveClient` (changes endpoints), `FolderWatchRunner`, watch record shape, `SyncLog` events. `drive.readonly` scope already covers `changes.list` — no re-consent.

### Phase 4 — Agency mapping, ownership, notifications, storage

- **Mapping rules per watch**: default category/tags (subfolder→child category optional), default author. Applied at import in `FolderWatchRunner::importFile`.
- **Watch ownership transfer**, mirroring the existing source transfer confirmation flow, so an operator leaving doesn't kill client folders.
- **Failure digests**: daily email to watch owner + admins on hard-fail watches, cron stall, repeated per-file failures. Webhook/Slack later.
- **Storage migration**: watches move from single option to a CPT (`docsync_folder_watch`) or custom table once caps rise; repository interface already isolates this.

## Recommended sequencing

Phase 1 alone is shippable and answers the request. Phase 2 is the highest technical-leverage change (per-folder cadence is a lie without it). Phase 3/4 unlock scale and multi-client polish. Suggest 1 → 2 as one release train, 3 → 4 as the next.

## Not in this plan

- Google push notifications / webhooks (`changes.watch` needs public HTTPS endpoint + renewal daemon; revisit after Phase 3)
- Two-way sync (WP → Docs)
- Action Scheduler dependency (continuation events keep us dependency-free; reconsider if Phase 2 proves insufficient)
- Pro/free gating decisions

## Success

- Operator edits any watch's schedule/status/preset/excludes without recreating it.
- A folder set to hourly has its Docs re-synced hourly while site default stays daily.
- 500 linked sources stay within one interval of freshness (continuation draining).
- Deleted/trashed Docs surface in Sync Activity and follow the watch's removal policy.
- Watch table shows next scan time and warns on stalled cron.

## Unresolved questions

1. Free vs Pro boundary — which phases (2? 3? 4?) are paid features?
2. Phase 4 storage: CPT vs custom table for watches (CPT = cheaper, table = cleaner queries at 50+ watches).
3. Should watch `postType` become editable in Phase 1, or stay creation-fixed (changing it mid-watch splits content across types)?
4. Cap targets after Phase 3 — 50 watches / 500 Docs assumed; confirm against real agency sizes.
