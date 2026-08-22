---
title: "Agent folder & Doc automation setup UI"
description: "Promote Drive folder watches and linked Docs from create-only flows into an agent-operable setup surface with editable folder policy and per-Doc overrides."
status: proposed
priority: P1
branch: "cursor/agent-folder-doc-setup-plan-c201"
tags: [feature, ux, automation, folder-watch, schedule, agency]
blockedBy: []
blocks: []
created: "2026-08-22T01:27:00.000Z"
createdBy: "cursor-cloud-agent"
source: analysis
---

# Agent folder & Doc automation setup UI

## Verdict

Folder-watch automation already exists in code, but agents cannot operate it. Setup is create-once inside the Doc modal; after that the Sources strip only offers Scan / Pause / Resume / Remove. Linked Docs inherit a single site schedule with no per-Doc cadence. Next product step: an agent-facing Automations setup surface with editable folder policy and per-Doc overrides on top of the current watch + source model.

## Audience

**Agents** = agency operators / content ops who manage many client folders and Docs in WordPress admin. Not AI agents. Not end writers inside Google Docs (that is roadmap 1.5 Add-on).

## Current state (code-backed)

### Two sync tracks

| Track | What it does | Schedule | Where configured |
| --- | --- | --- | --- |
| Linked sources | Sync already-linked Google Docs → WP posts | Site-wide `sync_interval` via `SyncCron` (`off` / `hourly` / `twicedaily` / `daily`) | Setup → Sync defaults |
| Folder watches | Inventory a Drive folder, create drafts for Docs, scan for new Docs | Per-watch `syncInterval` (`site` / `off` / `hourly` / `twicedaily` / `daily`) at create time | Doc source modal confirm panel only |

### What agents can do today

- Watch a folder: include subfolders, exclude Docs from first inventory, draft vs publish, layout/Elementor preset, folder schedule override.
- Caps: 10 watches/site, inventory first 50 Docs, import batches of 5.
- After create: Scan now, Pause, Resume, Retry failed, Remove watch.
- Per linked Doc: layout preset / Elementor path from post metabox or link flow; Sync now; Sync all changed.

### What is too simple

1. **No post-create folder settings UI** — confirm-panel fields disable once a watch exists; REST has `GET/POST/DELETE` + scan/pause/resume/retry, **no update/PATCH**.
2. **Sources folder strip is operational only** — name, import count, status, 3 actions. No schedule, policy, inventory, or child Docs.
3. **No per-Doc sync interval** — every linked source rides the site cron batch.
4. **Folder policy ≠ Doc policy** — watch sets defaults for *new* imports; existing child sources cannot be managed as a folder group.
5. **Create UX is buried** — watch creation lives inside the shared Doc modal, not a dedicated Automations home agents can return to.
6. **Schedule semantics are easy to confuse** — site schedule refreshes content of linked sources; folder schedule mainly scans for *new* Docs. Agents need that distinction made explicit in UI copy.
7. **WP-Cron reliability** — still traffic-driven unless real server cron hits `wp-cron.php`. Fine for MVP, but agents need visible next-run / last-scan / last-error without digging Logs.

## Product model to aim for

Hierarchy of defaults (most specific wins):

```text
Site defaults (Setup)
  └─ Folder automation policy (watch)
       └─ Per-Doc override (linked source)
```

| Layer | Owns |
| --- | --- |
| Site | OAuth, enabled post types, default Gutenberg/Elementor preset, default content-sync interval |
| Folder | Which Drive folder + subfolders, new-Doc post type/status, default layout/output for new Docs, folder scan interval, exclude list, pause state |
| Doc | Layout/output override, optional sync-interval override, optional pause, link ownership |

Keep Google Docs as source of truth. Keep one-way sync. Do not invent a second content store.

## Proposed UI

### A. Automations home (promote Folders)

Add a first-class surface agents open daily. Prefer one of:

1. **Recommended:** Sources becomes a two-tab home: **Docs** | **Folders** (URL-backed `?view=folders`), or
2. New submenu **Automations** if Folders outgrow Sources.

Empty state: “Watch a Drive folder. New Docs become drafts. You publish.” CTA opens existing Drive browser → folder confirm flow.

Folder list columns (agent-critical):

- Folder name + Drive link
- Status (`importing` / `watching` / `paused` / `error`)
- Schedule (effective interval, not raw `site`)
- New-post policy (`draft` / `publish` + post type)
- Progress (`imported/total`, pending, failed count)
- Last scan / last error
- Row actions: Open setup, Scan now, Pause/Resume, Remove

### B. Folder setup panel (edit after create)

Drawer or dedicated panel for one watch. Sections:

1. **Folder** — name, path, include subfolders (immutable or warn-on-change), root confirm already stored
2. **New Docs policy** — post type, draft/publish, layout/output defaults for *future* imports
3. **Schedule** — scan interval with plain copy: “How often Brasth looks for new Docs in this folder”
4. **Inventory** — live Doc list with include/exclude for *not-yet-imported*; show already-linked children as read-only links into Sources
5. **Health** — pending queue, failed list + Retry, overflow warning (>50)

Requires new REST: `POST|PATCH /folders/{id}` for mutable fields (`postStatus`, `syncInterval`, `layoutPreset`, `elementorSync`, `elementorPreset`, `excludeFileIds`, maybe `includeSubfolders` with re-scan).

Do **not** allow changing `folderId` / owner without an explicit transfer flow (mirror source owner transfer).

### C. Per-Doc setup (agent row + inspector)

On Sources **Docs** table, add compact setup affordances:

- Effective schedule badge (`Site · Hourly` or `Doc · Off`)
- Layout preset chip
- Parent folder badge when source was created by a watch (store `folderWatchId` on source meta if missing)
- Row/inspector actions: Sync now, Edit setup, Open WP post, Open Google Doc, Logs

**Edit Doc setup** fields:

- Layout / Elementor override (already exists — surface it in Sources, not only post editor)
- Sync interval override: `site` | `off` | `hourly` | `twicedaily` | `daily` (new source meta)
- Optional “pause content sync” without detaching

Site cron should skip paused sources and respect per-Doc interval when implemented (batch runner filters by due interval, or schedule per-source single events — pick the smaller change that stays under WP-Cron limits).

### D. Agent mental model copy

| Control | Copy |
| --- | --- |
| Folder schedule | Looks for new Google Docs in this folder |
| Site / Doc schedule | Updates WordPress content when the Google Doc changes |
| Draft policy | New Docs become drafts. You publish. |
| Publish policy | New Docs publish immediately. Later syncs update content only. |

## Implementation phases

### Phase 0 — Product lock (no code)

Decide before build:

1. Sources tabs vs new Automations submenu
2. Whether per-Doc interval ships in the same release as folder edit UI
3. Whether `folderWatchId` backfill is required for parent badges
4. Free vs Pro boundary for >10 watches / >50 Docs (roadmap already points agency scale to 1.3 / Pro)

### Phase 1 — Folder edit API + Sources Folders tab

**Backend**

- `PATCH /folders/{id}` (or `POST` update) for mutable watch fields
- Reschedule scan cron on interval/status change
- Return effective schedule + next/last scan timestamps in `formatWatch()`

**Frontend**

- Promote `SourcesFolderWatches` into a real Folders view/table
- Folder setup drawer reusing `FolderWatchConfirmPanel` patterns, fields **editable**
- Keep create path in Doc modal

**Verify**

- Create watch → edit schedule/policy → pause/resume → scan → remove
- Capability isolation (owner vs `manage_options`)
- Cron unschedule when paused / interval `off`

### Phase 2 — Per-Doc setup in Sources

**Backend**

- Optional `_docsync_wp_sync_interval` on source
- Optional `_docsync_wp_folder_watch_id` set on folder-watch imports
- `SyncCron::run` respects pause + per-Doc interval (skip not-due)

**Frontend**

- Sources row inspector / modal for Doc setup
- Expose layout preset update already supported by `POST /sources/{id}`
- Parent folder link when `folderWatchId` present

**Verify**

- Doc override `off` skips site batch; folder scan still creates siblings
- Transfer ownership still explicit

### Phase 3 — Agent ops polish

- Next-run / last-scan / last-error on folder rows
- Filter folders by status
- Deep-link `Sources?view=folders&watch=<id>`
- Sync Activity filter by folder watch when useful
- Document real-server-cron requirement on Automations empty/help state

### Explicit non-goals (this plan)

- Drive webhooks / push Changes API
- Bidirectional sync
- Approval queues / scheduled publishing (roadmap 2.2)
- Durable bulk-import job table (roadmap 1.3) — edit UI first; raise caps later
- Google Docs Workspace Add-on (roadmap 1.5)
- Custom preset builder

## Risks

| Risk | Mitigation |
| --- | --- |
| Agents confuse folder scan vs content sync | Explicit two-schedule copy + separate controls |
| WP-Cron drift on low-traffic sites | Surface last-scan / next due; document system cron |
| Editing `includeSubfolders` after create | Require confirm + re-inventory; or lock field |
| Per-Doc cron fan-out | Prefer filter-in-batch over N recurring events |
| Scope creep into 1.3 bulk jobs | Cap stays 10/50 until durable jobs exist |

## Success criteria

- Agent can open Folders, change one folder’s schedule and draft/publish policy without deleting the watch
- Agent can open one linked Doc from Sources and change layout + sync interval without leaving admin Sources
- Folder scan and Doc content sync remain distinguishable in UI and behavior
- Existing create-watch and SyncCron paths keep working for sites that never open the new UI

## Suggested sequencing vs roadmap

- Fits as **post-1.1.4 agent-ops release** (or 1.1.5/1.2.x ops track), parallel to layout gallery
- Complements later **1.3 bulk import** — setup UI makes current caps usable; durable jobs raise scale
- Unlock for marketing: “Agents configure each Drive folder and each Doc from one WordPress workspace”

## Open questions

1. Prefer Sources tabs or a new Automations submenu for v1 of this UI?
2. Ship folder-edit alone first, or folder-edit + per-Doc interval together?
3. Should publish-on-create stay free, or move auto-publish behind Pro when Free/Pro lands?
4. Do agents need category/tag/author mapping on folder policy in the same track, or later?
