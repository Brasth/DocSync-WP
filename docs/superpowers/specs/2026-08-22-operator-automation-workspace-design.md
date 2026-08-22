# Operator Automation Workspace Design

Date: 2026-08-22  
Status: Proposed  
Scope: Analysis of current schedule/folder automation, plus the recommended UI and data model for agency operators. No implementation in this document.

## Product Outcome

Agency operators (content agents) can configure automation at two levels they already think in:

1. **Each Google Drive folder** they watch.
2. **Each Google Doc** that folder created, or that they linked by hand.

The product promise becomes:

> Watch a Drive folder. Control how new Docs arrive. Control how each Doc keeps syncing. Do it from Sources, without asking an administrator to change the site cron.

Google Docs stays the source of truth. WordPress stays the publish target. One-way sync does not change.

## Audience Assumption

Primary user: **agency / in-house content operators** who already use Sources after activation. They are the "agents" in the request: people who own client folders and recurring Docs, not site owners wiring OAuth.

Administrators still own the site OAuth client and the site-wide default interval. Operators inherit that default and override it per folder and per Doc.

Secondary user later: the same REST surface can serve scripted agents. This increment does not add a public application-password API or a new menu for machines.

## Current State

Folder watch already exists (1.1.4 line). The backend is more capable than the operator UI.

### What works

- Site default schedule in Setup: `off`, `hourly`, `twicedaily`, `daily`.
- Recurring `docsync_wp_sync_sources` checks up to 20 due linked sources per tick.
- Manual / first-import sync uses single `docsync_wp_sync_source` events.
- Operators can choose **This Google Doc** or **This Drive folder** in the source modal.
- Folder create can inventory Docs, exclude some, include subfolders (3 levels), set draft vs publish, override the site interval, and pick layout / Elementor output.
- Sources shows a compact **Drive folders** strip: scan, pause, resume, remove.
- Imported posts store `_docsync_wp_folder_watch_id`.
- Hard limits: 10 watches per site, 50 Docs per inventory, import batches of 5.

### Why it still feels too simple

The schedule model is one site cron. The folder model is create-once. The source list is flat.

| Layer | What operators can do now | What they cannot do |
| --- | --- | --- |
| Site | Admin sets one interval for the whole site | Operators cannot see or change it from Sources |
| Folder | Create watch, scan, pause, remove | Edit interval, layout, draft/publish, exclude list, or subfolders after create |
| Doc | Sync now, open draft, filter the flat table | Set this Doc's schedule, pause this Doc without detaching, or see which folder owns it |

Concrete gaps in code:

- `FolderWatchController` has GET/POST/DELETE plus scan/pause/resume/retry. There is no PATCH/update.
- `FolderWatchConfirmPanel` disables every setting once `watch` exists.
- `SourcesFolderWatches` is a status strip, not a folder workspace. It does not list member Docs.
- Sources table already receives `folderWatchId` and never shows or filters it.
- Intent copy is honest: a single Doc "follows the site schedule."
- Folder `syncInterval` only drives **new-Doc scans**. Existing sources still enter the site-wide due query.
- `queryDueSourceIds()` treats a source as due when `last_synced <= now`. The site interval only controls how often the cron fires, not which sources are eligible. Mixed folder/doc cadences cannot work until due selection becomes interval-aware.
- Watches live in the `docsync_wp_folder_watches` site option. Fine for 10 watches; not a reason to add a CPT in this increment.

### Two clocks operators currently cannot see

```text
Site cron          -> re-sync already-linked Docs
Folder scan cron   -> discover new Docs and enqueue drafts
```

The create modal labels the second clock "Folder schedule." After create, Sources only shows imported counts. Operators reasonably think one schedule means "keep this folder in sync," but existing Docs ignore the folder interval.

## Approaches Considered

### A. Folder workspace inside Sources (recommended)

Keep Sources as the daily home. Promote the folder strip into a selectable workspace: folder list, folder settings, member Docs. Add PATCH for watches and a per-source schedule override that the due query honors.

- Pros: matches current IA, reuses `folderWatchId`, no new admin page, operators stay in one screen.
- Cons: Sources gets denser; due-query change needs careful tests.

### B. New Automations admin page

Add **Brasth Document Sync > Automations**. Sources stays a flat post table. Folders and schedules move to the new screen.

- Pros: cleaner separation of "content" vs "jobs."
- Cons: second operational home; duplicates health, scan, and recovery; fights the 1.1.3 decision that Sources is the daily home.

### C. Row-level settings only

Add an interval dropdown on each source row and an Edit button on each folder strip row. No folder detail, no member list, no due-query rewrite beyond storing meta.

- Pros: smallest UI change.
- Cons: still no "this folder's Docs" view; interval storage without due-query change would lie to operators; settings stay hidden in tiny controls.

**Recommendation: A.** The request is an operator UI for each folder and each Doc. Approach A is the smallest architecture that makes both objects first-class without splitting the product.

## Design

### Information architecture

Sources stays one screen with two object types:

1. **Folders** — automations that discover and stamp policy onto new Docs.
2. **Docs / sources** — linked WordPress targets that re-sync on a cadence.

Do not add a fourth top-level submenu. Do not hide folder settings behind Setup. Administrators keep the site default in Setup; operators override below it.

Default Sources layout:

- Health summary unchanged.
- Folder list becomes the first operational card: name, status, imported/total, next scan, attention.
- Selecting a folder opens an in-page folder panel (not a new wizard).
- The existing source table remains, with a folder filter and a schedule column.
- Unwatched / hand-linked Docs stay visible when the folder filter is `All`.

### Folder panel

One folder panel, two sections.

**Policy (applies to future imports and scans)**

- Include subfolders
- New posts: draft or publish
- Folder scan interval: `site`, `off`, `hourly`, `twicedaily`, `daily`
- Layout / Elementor output (same controls as create)
- Root-folder confirm stays required
- Exclude / include Docs from the latest inventory
- Pause, resume, scan now, retry failed, stop watching

**Members (Docs this watch created)**

- Table of sources where `folderWatchId` matches
- Status, last sync, effective schedule, WordPress target
- Actions: sync now, open draft, set this Doc's schedule (`inherit` / interval / `off`), exclude from future folder scans without deleting the WordPress post
- "Pause this Doc" is the `off` schedule shortcut, not a second status field

Create flow stays in `DocSourceModal`. After create, the modal can close and Sources should land on that folder panel. Do not keep the create-time fields locked.

Copy must name both clocks:

- Folder schedule: "How often Brasth looks for new Docs in this folder."
- Doc schedule: "How often this linked Doc overwrites its WordPress target when Google changed."

### Per-Doc schedule

Every source gets an explicit cadence:

| Stored value | Meaning |
| --- | --- |
| `inherit` (default / empty) | Use folder interval if `folderWatchId` is set, otherwise the site interval |
| `off` | Scheduled re-sync skipped; Scan now / Sync now still work |
| `hourly` / `twicedaily` / `daily` | This Doc only |

Effective interval resolution:

```text
source.syncInterval
  -> if inherit and folderWatchId: folder.syncInterval
    -> if folder is `site` or missing: site.sync_interval
  -> if inherit and no folder: site.sync_interval
```

Paused folder: scans stop. Member Docs keep their own re-sync cadence unless the operator paused the Doc.

Paused Doc: scheduled re-sync skipped; folder can still import sibling Docs.

Detaching a source or removing a watch does not delete WordPress content. Removing a watch clears future scans; existing `_docsync_wp_folder_watch_id` may remain as historical membership so the table can still group those Docs, but inherit then falls back to the site interval. Show that fallback in the schedule column.

### Due-query contract

This is the required backend change. UI-only interval controls would be false.

Replace "any source with `last_synced <= now`" with interval-aware eligibility:

- Persist `_docsync_wp_sync_interval` (`inherit` / `off` / `hourly` / `twicedaily` / `daily`).
- Persist `_docsync_wp_next_sync_at` as a UTC mysql timestamp.
- After each successful or skipped sync, set `next_sync_at = last_synced + effective interval`.
- `off` or unresolved `off` site default: do not set a future `next_sync_at`.
- Recurring cron selects sources where `next_sync_at <= now` and status is not `syncing`.
- Batch size stays 20.
- Manual sync and first folder import still use `docsync_wp_sync_source` and do not wait for `next_sync_at`.
- Existing sources without `next_sync_at` are backfilled on first schedule reconcile as "due now" so upgrades do not stall.

Do not add custom WP-Cron recurrences. Do not add per-source cron events. One site hook remains the re-sync pump; eligibility moves into source meta.

Folder scan scheduling stays as it is: one `docsync_wp_scan_folder` event per watch, using the watch's effective interval.

### REST

Additive only. Unknown-key rejection on folder create stays.

- `PATCH /folders/{id}` — policy fields already accepted on create: `includeSubfolders`, `postStatus`, `syncInterval`, `layoutPreset`, `elementorSync`, `elementorPreset`, `excludeFileIds`. Changing `includeSubfolders` re-inventories; it does not detach existing members.
- `GET /sources` gains `folder_watch_id` filter.
- Source GET/POST already exist; add optional `syncInterval` and read-only `effectiveSyncInterval`, `nextSyncAt`, `folderWatchId`.
- `GET /workspace` stays least-privilege. Do not add schedule values, folder IDs, or owner IDs there.
- No new public namespace.

### Data and limits

- Keep watches in `docsync_wp_folder_watches`.
- Keep the 10-watch and 50-Doc inventory caps in this increment. Raising them is a later durable-job problem (roadmap 1.3.0).
- Overflow copy stays: Brasth automates the first 50 Docs; scan can pick up later slots only after members drop or the cap is raised in a later release.
- Encrypt nothing new. Tokens stay per user. Folder policy is not secret.

### Error handling

- PATCH on a paused watch is allowed for policy; scan still requires resume.
- Root confirm still required to watch My Drive / shared-drive root.
- Hard folder failures (OAuth, access denied) stay watch-level `error`.
- Soft per-Doc import failures stay on `failed[]` with Retry failed.
- Invalid interval or preset: 400, no partial write.
- Owner transfer of a member Doc keeps current rules; the new owner becomes the scheduled Google identity. Folder ownership does not silently change.
- WP-Cron remains traffic-driven. Folder panel should show last scan / next scan and the existing real-server-cron hint when site interval is not `off`.

### Testing

- PHP: interval resolution matrix (inherit / folder / site / off), due query backfill, PATCH policy, exclude membership, pause Doc vs pause folder.
- PHP: existing folder import/scan fixtures and source owner-transfer cases still pass.
- TS: folder panel state, source table folder filter, schedule column labels.
- Manual: create folder, edit interval after create, exclude a Doc, set one member to `off`, confirm site cron still updates siblings, confirm Sync now works on the paused-schedule Doc.

### Out of scope

- New Automations submenu
- Custom cron intervals (`every 15 minutes`)
- Raising the 10/50 caps or replacing WP-Cron with a durable queue
- Preset gallery, preview, bulk archive import, Pro gating
- Bidirectional sync
- Machine/API-key agent product
- Changing Google scopes or OAuth setup

## Success Criteria

- An operator can open Sources and change one folder's scan interval without touching Setup.
- An operator can see every Doc that folder created and change one Doc's re-sync cadence.
- Copy distinguishes "new Docs" from "update this Doc."
- Site default remains the inherit target.
- Removing a watch does not delete WordPress posts.
- Existing create, pause, scan, retry, and single-Doc link flows keep working.

## Roadmap Fit

Current roadmap parks operator scale at 1.3.0 (bulk import) and keeps 1.2.0 as a preset gallery. This increment is smaller than bulk import and more urgent than a gallery if agencies already use folder watch.

Suggested slot: ship after 1.1.4 evidence, as a Sources workspace increment, before durable bulk import. Do not let it absorb 1.3.0 job-queue scope.

## Unresolved Questions

1. Confirm "agents" means human agency operators in WordPress admin, not a machine/API client in this increment.
2. After a watch is removed, should member Docs keep showing a historical folder name, or become ungrouped immediately?
3. Should pausing a folder also pause member re-syncs, or only scans (this spec chooses scans only)?
