---
title: "Agency automation setup"
description: "Honest per-folder re-sync plus folder-first Setup onboarding for agency operators. Feature and UI. All free."
status: approved
priority: P1
branch: "feature/agency-automation-setup-plan-2fee"
tags: [feature, frontend, backend, cron, drive, folder-watch, setup, agency]
blockedBy: []
blocks: []
created: "2026-08-23T13:26:00.000Z"
createdBy: "cloud-agent"
source: analysis
---

# Agency Automation Setup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Agencies can start a client-folder automation from Setup and that folder schedule actually re-syncs member Docs, not just discover new ones.

**Architecture:** Add `SourceScheduleResolver` (override > watch > site) and persist `_docsync_wp_next_sync_at` so `SyncCron` drains due sources with continuation events. Recompute member due times when a watch interval changes. Setup treats “Watch a client folder” as the primary activation path and tracks folder-import progress in the existing activation panel.

**Tech Stack:** WordPress 6.4+, PHP 8.1, WP-Cron, existing REST (`/folders`, `/sources`, `/workspace`, `/settings`), React admin (`wp.element`, Radix), Vite per-screen bundles.

## Global Constraints

- PHP 8.1+, WordPress 6.4+, plugin slug/text domain `brasth-document-sync-for-google-docs`, namespace `DocSyncWP\`.
- All free. No Pro gate, no Freemius, no new paid surface.
- Keep `drive.readonly`. No new Google scopes.
- WP-Cron remains the substrate. No Action Scheduler.
- Watch `postType` stays creation-fixed.
- Caps stay 10 watches / 50 Docs / depth 3 until the later CPT/incremental-scan train.
- Interval set is `off|hourly|twicedaily|daily|weekly` (plus watch value `site`). No 15-minute interval.
- Setup stays `manage_options`. Sources and Drive Folders stay capability-filtered operational surfaces.
- `/workspace` stays least-privilege: no OAuth secrets, Google identity, source IDs, owner IDs, or raw errors.
- Inline PHPCS suppressions are prohibited. New PHP files stay under 200 lines when a clear split exists.
- Copy uses agency nouns: client folder, member Docs, re-sync schedule. Remove “Posts list → Add Sync Doc.”
- Reuse `DocSourceModal`, `FolderWatchConfirmPanel`, `AdminButton`, Radix confirms. Do not fork a second folder wizard.

## Design

See [docs/plans/2026-08-23-agency-automation-setup-design.md](../../docs/plans/2026-08-23-agency-automation-setup-design.md).

This plan is the next agency increment after 1.1.5 Drive Folders (Phase 1 shipped). It implements the Aug 22 engine (honest schedules) plus Setup onboarding the Aug 22 plan left implicit.

## File map

| File | Responsibility |
|------|----------------|
| `src/Sync/SourceScheduleResolver.php` | Resolve effective interval and next due timestamp. |
| `src/Sync/SourceRepository.php` | New meta, due query, member-source listing, REST shape. |
| `src/Sync/SyncService.php` | Write `next_sync_at` on every terminal sync. |
| `src/Sync/FolderWatchService.php` | Recompute member due times on create/update interval. |
| `src/Cron/SyncCron.php` | Due query + continuation drain + weekly site interval. |
| `src/Cron/ScheduleBackfill.php` | Batched upgrade backfill of `next_sync_at`. |
| `src/Settings/SettingsRepository.php` | Allow `weekly` on site `sync_interval`. |
| `src/Rest/SourceController.php` | Accept `syncInterval` on source PATCH. |
| `src/Rest/WorkspaceController.php` | Folder-aware `activated` without leaking IDs. |
| `src/Plugin.php` | Wire resolver, backfill hook. |
| `resources/js/admin/app/setup-app.tsx` | Dual CTA, post-type select, folder activation. |
| `resources/js/admin/app/use-setup-app.ts` | Intent + activation-watch state. |
| `resources/js/admin/features/google-setup/*` | Agency copy, weekly, dual next action. |
| `resources/js/admin/features/activation/*` | Folder import activation panel. |
| `resources/js/admin/features/post-sync/post-meta-box-app.tsx` | Per-source schedule override. |
| `resources/js/admin/features/folder-watches/folder-watch-detail-page.tsx` | Member re-sync label. |
| `scripts/verify-schedule-resolver.php` | Resolver + due-arithmetic fixture. |

## Phases

| Phase | File | Ships independently |
|-------|------|---------------------|
| 1 | [Honest folder schedule](./phase-01-honest-folder-schedule.md) | Yes — agencies with existing watches get real re-sync |
| 2 | [Agency Setup onboarding](./phase-02-agency-setup-onboarding.md) | Yes — after Phase 1 types exist |

Sequence: 1 then 2. Do not start Setup UI until `next_sync_at` and `syncInterval` are on the source wire.

## Not in this plan

- CPT storage, 50 watches / 500 Docs, incremental Drive Changes API
- Removed-Doc policy, category/tag/author mapping, ownership transfer, failure email digests
- Preset gallery, preview, two-way sync, managed OAuth
- Replacing Drive Folders with a Setup-only management UI

## Success

- Agency admin on a fresh site: OAuth → Connect Google → **Watch a client folder** → first imported draft exists → Setup shows folder automation active → Drive Folders is the daily home.
- Watch set hourly, site daily: member Doc edited in Google updates in WordPress on the hourly tick; a non-watch source waits for the daily tick.
- 40 due member sources (above `BATCH_SIZE` 20) finish inside one interval via `docsync_wp_sync_sources_continue`.
- Source override `off` never auto-syncs; manual Sync now still works.
- Upgrade from 1.1.5: every linked source keeps a schedule after backfill.

## Unresolved questions

None blocking implementation. Later trains stay on the Aug 22 agency plan (Phases 3–4).
