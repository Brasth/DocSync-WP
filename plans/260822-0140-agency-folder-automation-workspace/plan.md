---
title: "Agency folder automation workspace"
description: "Full plan: agency-grade Drive folder management UI, per-folder scheduling engine, incremental scans with CPT watch storage, and agency mapping/notifications. All free."
status: approved
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

Target user shift: from "one operator links one Doc" to **agencies managing many client folders**. Deliver a management UI where each Drive folder (and each Doc inside it) has visible, editable automation setup, plus a scheduling engine that honors per-folder cadence at agency scale.

## Product lock (decisions confirmed 2026-08-22)

- **All free.** No Pro gate on any phase.
- **Watch storage: CPT** (`docsync_folder_watch`, non-public, meta-backed fields), migrated in Phase 3. Rationale: zero schema/dbDelta maintenance for a WordPress.org free plugin, WP_Query + meta cache for free, multisite-safe, trivial uninstall. Custom-table perf advantage only materializes at hundreds of rows with complex filtering; cap is 50 watches, so it never does.
- **Watch `postType` stays creation-fixed.** Changing it mid-watch splits one folder's content across post types. UI shows it read-only with a "create a new watch to target another type" hint.
- **Caps:** keep 10 watches / 50 Docs until scans are incremental; Phase 3 raises to **50 watches / 500 Docs per watch**, overridable via `docsync_wp_max_folder_watches` / `docsync_wp_max_watch_documents` filters.
- **Removed-Doc policy default:** keep post (safest; agencies opt into draft/trash per watch).
- **Failure digest default:** on for watch owners + admins, opt-out in Sync defaults.

## Current state (verified in code)

Already shipped (1.1.5): folder watches plus Drive Folders management UI, `PATCH /folders/:id`, cron health, inventory reconcile. Recurring `docsync_wp_scan_folder` still only discovers new Docs; imported Docs re-sync on the site `SyncCron` interval. Honest member re-sync and folder-first Setup are in [260823](../260823-agency-automation-setup/plan.md).

### Gaps

| # | Gap | Where |
|---|-----|-------|
| 1 | Watches immutable after creation; no edit endpoint; confirm panel disables all controls once watch exists | `folder-watch-confirm-panel.tsx`, `FolderWatchController` |
| 2 | Per-watch interval only schedules discovery; re-sync of imported Docs rides the single site interval | `FolderWatchService::syncWatchSchedule` vs `SyncCron::run` |
| 3 | Scheduler ceiling: 20 due sources per tick, no continuation → backlog at 500 sources | `SyncCron::BATCH_SIZE` |
| 4 | Full folder re-list every scan; blind to renames/moves/trash → orphaned WP posts | `DriveFolderInventory`, `FolderWatchRunner::scan` |
| 5 | Caps 10 watches / 50 Docs / depth 3 too small for agencies | `FolderWatchRepository`, `DriveFolderInventory` |
| 6 | No folder→category/tag/author mapping | `FolderWatchRunner::importFile` |
| 7 | No next-run visibility, no cron-health warning, failures buried in creation modal | Sources UI |
| 8 | Watch owner token loss hard-fails watch; no transfer flow (sources have one) | `FolderWatchRunner::HARD_FAIL_CODES` |
| 9 | Single wp option stores all watches; last-write-wins race between concurrent cron ticks | `FolderWatchRepository` |

## Phases

| Phase | Name | Status |
|-------|------|--------|
| 1 | [Drive Folders management UI + editable watches](./phase-01-drive-folders-management-ui.md) | Shipped in 1.1.5 |
| 2 | [Per-folder scheduling engine](./phase-02-per-folder-scheduling-engine.md) | Superseded — implement via [260823 agency automation setup](../260823-agency-automation-setup/plan.md) Phase 1 |
| 3 | [CPT storage, incremental scans, lifecycle policy, raised caps](./phase-03-cpt-storage-incremental-scans-lifecycle.md) | Planned |
| 4 | [Agency mapping, ownership transfer, failure digests](./phase-04-agency-mapping-ownership-notifications.md) | Planned |

Next increment (2026-08-23): Setup is folder-first for agency operators, and Phase 2 schedules become honest. See [plans/260823-agency-automation-setup/plan.md](../260823-agency-automation-setup/plan.md).

Sequencing: 1 → 2 as one release train (UI plus the engine that makes per-folder schedules honest). 3 → 4 as the next. Each phase is independently shippable.

## Dependencies

- Existing: folder-watch service/runner/repository, `_docsync_wp_folder_watch_id` source meta, admin shell + per-screen Vite manifests (`AssetRegistry`), Radix dialogs, Sync Activity log, source ownership-transfer flow (pattern for Phase 4).
- Google: `drive.readonly` scope already covers `changes.getStartPageToken` / `changes.list` — no re-consent needed for Phase 3.
- WP-Cron remains the substrate; Phase 2 continuation events + health warning mitigate. No Action Scheduler dependency.

## Not in this plan

- Google push notifications / `changes.watch` webhooks (needs public HTTPS endpoint + renewal daemon; revisit after Phase 3 ships)
- Two-way sync (WP → Docs)
- Slack/webhook notification channels (email digest first; channels later)
- Pro tier of any kind

## Success

- Operator edits any watch's schedule/status/preset/excludes without recreating it.
- A folder set to hourly re-syncs its Docs hourly while site default stays daily.
- 500 linked sources stay within one interval of freshness via continuation draining.
- Deleted/trashed Docs surface in Sync Activity and follow the watch's removal policy.
- Drive Folders screen shows next scan time and warns on stalled WP-Cron.
- Client folder imports land with the watch's category/author mapping applied.

## Unresolved questions

None. All prior open questions resolved in Product lock above.
