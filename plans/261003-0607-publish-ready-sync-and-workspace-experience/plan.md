---
title: "Publish-ready sync and workspace experience"
description: "Fix output fidelity, alert on failures, add a zero-setup first sync, make the Doc a complete post, protect WordPress edits, and simplify the admin workspace."
status: pending
priority: P1
effort: "35d"
branch: main
tags: [feature, backend, frontend, sync, ux]
blockedBy: []
blocks: []
created: 2026-10-03
---

# Publish-ready sync and workspace experience

## Overview

Product promise: write in Google Docs, post is ready to publish, nobody touches WordPress.
Today sync writes only `post_content`, overwrites WordPress edits, fails silently on schedule, and needs full Google Cloud setup before first value. This plan closes those gaps in five release trains. Each phase ships independently.

## Goals

| # | Goal | Priority |
|---|------|----------|
| 1 | Synced output is correct: clean links, meaningful image names | P1 |
| 2 | Scheduled failures reach a human (email digest + Site Health) | P1 |
| 3 | First formatted draft in under 2 minutes, no Google Cloud setup | P1 |
| 4 | Doc carries title, slug, excerpt, featured image, terms, SEO meta | P1 |
| 5 | Sync never silently wipes WordPress-side edits | P1 |
| 6 | Daily work happens in Posts list and editor; plugin screens feel like one workspace | P2 |
| 7 | Drive folder publishes as a hierarchical knowledge base | P3 (gated) |

## Release trains

| Train | Phases | Theme |
|---|---|---|
| A | 1-4 | Trust: correct output, alerts, plain language |
| B | 5-6 | First value: ZIP upload, Doc metadata |
| C | 7-9 | Coexist: preserve WordPress edits, review policy, native surfaces |
| D | 10 | One workspace |
| E | 11 | Knowledge base (start only after niche decision) |

## Phases

| # | Phase | Status |
|---|-------|--------|
| 1 | [Phase 1: Start — baseline verification and release hygiene](./phase-01-start.md) | Pending |
| 2 | [Phase 2: Output fidelity — links and image names](./phase-02-output-fidelity-links-and-image-names.md) | Pending |
| 3 | [Phase 3: Sync failure alerts](./phase-03-sync-failure-alerts.md) | Pending |
| 4 | [Phase 4: Plain-language Sources and recovery actions](./phase-04-plain-language-sources-and-recovery-actions.md) | Pending |
| 5 | [Phase 5: ZIP upload first sync](./phase-05-zip-upload-first-sync.md) | Pending |
| 6 | [Phase 6: Doc metadata table](./phase-06-doc-metadata-table.md) | Pending |
| 7 | [Phase 7: Synced region merge and pattern placeholders](./phase-07-synced-region-merge-and-pattern-placeholders.md) | Pending |
| 8 | [Phase 8: Review-before-overwrite policy](./phase-08-review-before-overwrite-policy.md) | Pending |
| 9 | [Phase 9: Posts list and editor integration](./phase-09-posts-list-and-editor-integration.md) | Pending |
| 10 | [Phase 10: Unified workspace navigation and activity drawer](./phase-10-unified-workspace-navigation-and-activity-drawer.md) | Pending |
| 11 | [Phase 11: Knowledge base mode](./phase-11-knowledge-base-mode.md) | Pending |

Phase dependencies: 2←1 · 5←2 · 6←2 · 7←1,2 · 8←3,7 · 9←4,8 · 10←4 · 11←6. Phases 3 and 4 have none.

## Design decisions (apply across phases)

- **No new runtime dependencies.** PHP stays dependency-free; frontend keeps WordPress packages + Radix.
- **Contracts preserved.** Existing REST routes, meta keys, status keys, submenu URLs, Vite entries stay. Additions only.
- **Pure logic in small new classes**, WordPress glue thin. `SyncService.php` (1318 lines) gets call sites only, no new logic blocks.
- **Tests follow repo pattern**: fixture directories + `scripts/verify-*.php` + Composer script + `pr-lint.yml` step. Runtime-only behaviour (block parser round-trip, REST upload) verified in the devcontainer with `wp eval-file`.
- **Conversion pipeline version**: one constant folded into Gutenberg and Elementor-preset fingerprints so output fixes reach unchanged Docs on their next sync.
- **Synced region uses block fingerprints, not wrapper markup.** No front-end DOM change, backwards compatible.
- **Safe defaults on upgrade**: behaviour-changing settings default to current behaviour for existing installs, new behaviour for new installs (same pattern as `default_layout_preset`).

## Cross-plan dependencies

| Relationship | Plan | Note |
|---|---|---|
| Related | `260822-0140-agency-folder-automation-workspace` | Its Phase 4 failure digest reuses the notifier from Phase 3 here. Its Phase 2 scheduler work is not duplicated here. Phase 11 here benefits from its Phase 3 (incremental scans, raised caps) but is not blocked. |

## Not in this plan

- Per-source scheduler continuation, changes API, raised folder caps (folder automation plan).
- Managed OAuth, OAuth "Testing mode" guidance, PHPUnit migration, sync-events table.
- Preset gallery, custom preset builder, AI, Notion, two-way sync, Workspace add-on.
- Shortcode placeholders, ZIP re-sync, link-shared Docs without OAuth.

## Success criteria

- [ ] Real-Doc sync produces zero `google.com/url` links; new images have slug-based names.
- [ ] A scheduled failure produces one digest email and a Site Health warning.
- [ ] New user reaches a formatted draft from a ZIP without OAuth configuration.
- [ ] Doc with metadata table syncs to a post needing no manual field edits.
- [ ] Blocks added above/below synced content survive re-sync; edits inside it are never overwritten without confirmation.
- [ ] Published posts on new installs wait for review; existing installs keep current behaviour until changed.
- [ ] `composer lint`, all `composer test:*`, `pnpm lint`, `pnpm typecheck`, `pnpm build` pass per phase.

## Implementation status (2026-10-03)

Code-level only: composer lint, all `composer test:*` suites, `pnpm lint`, `pnpm typecheck`, and `pnpm build` pass. Nothing was run inside WordPress (Docker unavailable), so the items below marked "needs runtime check" are unverified end to end. Those items are consolidated as an actionable pre-release checklist in [Phase 1: Runtime verification gate](./phase-01-start.md#runtime-verification-gate).

| Phase | State | Open items |
|---|---|---|
| 1 | Partial | Baseline test run recorded; 1.1.5 readme changelog written. Real-Doc link/image/editor round-trip checks not done. |
| 2 | Done | Needs runtime check: link unwrap and image names on a real Doc. |
| 3 | Done | Deviation: failure hook fired from `SyncCron::run` (no trigger param). Needs runtime check: email delivery, Site Health. |
| 4 | Mostly done | No per-row menu (Change Doc / Detach stay in the editor box). Screenshots and POT not refreshed. |
| 5 | Done | Needs runtime check: multipart upload through `apiFetch`, real export ZIP. |
| 6 | Done | Needs runtime check: capability matrix, Yoast / Rank Math. Fallback path applies fields at completion only. |
| 7 | Done | Needs runtime check: block round-trip stability (false conflicts fall back to full replace). |
| 8 | Done | "View changes" revision link and digest line not built. |
| 9 | Mostly done | Posts list bulk action, block editor pre-publish notice, command palette entries (bound late; no-op in classic editor). Native sidebar panel not built (meta box kept). Needs runtime check. |
| 10 | Mostly done | Nav tabs, Logs renamed Activity, source activity drawer. Setup/Settings label stays "Setup". Screenshots not refreshed. |
| 11 | Done | Owner green-lit. Folder hierarchy (watch option, container pages), cross-Doc link resolution, heading anchors + TOC. Needs runtime check: container pages, link filter. Inventory caps (50 Docs, depth 3) unchanged. |

### Review follow-up (applied)

Independent review of Phases 3-10 (6/10) led to fixes: pattern placeholder regex no longer spans blocks; scheduled syncs honor review policy and local edits for layout/pipeline-only rewrites; metadata table defaults off for upgraded sites; ZIP import requires `upload_files`, deletes imported images when it fails, and extracts only HTML/CSS/image entries; "needs attention" filter matches the count; `update_available` labelled in Site Health, post list, and log filters; digest skips recovered posts; bulk sync no longer aborts on first error; live search no longer overwrites typing; non-admins are not sent to the admin-only Setup page. Also fixed afterwards: block fingerprints now hash only block name, semantic attributes, visible text, link/image targets, and inner blocks, so editor re-save differences (classes, markup normalization, extra attributes) no longer read as WordPress edits; metadata applier reports a field as applied only after the write succeeds; a blank-key row with a value means the table is real content; ZIP rate limit claims unique option slots (atomic); settings with non-scalar values get a 400. Still unverified: real Gutenberg re-save behaviour needs a WordPress check.

## Owner decisions (2026-10-03)

- Existing installs keep auto-apply on published posts; only new installs default to review (Phase 8).
- After the link fix, all sources re-convert on their next sync (Phase 2).
- Knowledge base mode stays gated; decide after trains A-C ship (Phase 11).

## Open questions

1. Working tree bumps version to 1.1.5 with no changelog entry: what ships as 1.1.5? (resolved in Phase 1, step 7)

<!-- slug: publish-ready-sync-and-workspace-experience -->
