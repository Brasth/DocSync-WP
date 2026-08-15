---
title: "Plain-text folder drafts and Gutenberg lists"
description: "Fix Gutenberg list blocks, then let operators multi-select Google Docs in a folder and create free WordPress drafts."
status: in_progress
priority: P1
branch: "main"
tags: [feature, bugfix, frontend, backend, gutenberg, drive]
blockedBy: []
blocks: []
created: "2026-08-15T03:55:06.184Z"
createdBy: "ck:plan"
source: skill
---

# Plain-text folder drafts and Gutenberg lists

## Overview

Ship the smallest agency-impressing update: SEO-style Google Docs stay valid in Gutenberg (lists), then operators pick several Docs in a Drive folder and get drafts. Free. Always draft. No watch daemon. No auto-publish.

Example Doc (acceptance fixture class): [Da Nang getting around](https://docs.google.com/document/d/18uBD2pW6Xh1JSuVK9VfxGcXv88xYAlVMKbdMnzA32Ts/edit?tab=t.0)

That Doc is headings + paragraphs + **two sibling bullet lists** + images. Numbered `1.` / `1.1.` lines are **H2/H3 text**, not `<ol>`. Current `HtmlBlockFactory` emits flat `core/list` with empty `innerBlocks`. WP 6.4+ expects `core/list` → `core/list-item`. Editor recovery fails more as list count grows.

## Product lock

- Free. No Pro gate.
- Plain Gutenberg only for this update (Clean Article / Plain Blocks).
- New posts stay `draft`. Sync updates content only.
- Folder = multi-select Docs now. Not recursive. Not overnight watch.
- Marketing line after ship: *Select Docs in a folder. Brasth creates drafts. You publish.*

## Scope Challenge

- Existing: 1 Doc → 1 draft, Drive browser, background sync, list HTML import.
- Minimum: list-item serialization + FE multi-select looping `POST /sources`.
- Complexity: ~10 files, 1 new list builder helper, 3 phases.
- Selected mode: **HOLD** reduced scope from prior discussion.

## Phases

| Phase | Name | Status |
|-------|------|--------|
| 1 | [Gutenberg list serialization](./phase-01-gutenberg-list-serialization.md) | Done (code + fixtures green; editor spot-check → Phase 3) |
| 2 | [Folder multi-select drafts](./phase-02-folder-multi-select-drafts.md) | In progress — needs partial-fail UX + progress copy |
| 3 | [Verification and demo kit](./phase-03-verification-and-demo-kit.md) | Pending |

## Code review (2026-08-15 uncommitted)

- Report: [reports/260815-code-review-uncommitted.md](./reports/260815-code-review-uncommitted.md)
- Score: **7.5/10 — Needs changes**
- Automated: `composer test:layout-fixtures` 16/16, `pnpm typecheck`, `pnpm lint:js` green
- Before ship Phase 2: (1) keep modal/notice on partial multi-create failures, (2) show `Created n of m` during loop, (3) avoid setState inside selection updater
- Phase 3 still required for live Gutenberg recovery + staging multi-draft demo

## Dependencies

- Product: start now. User overrode the 1.1.4 freeze — this update is more important than waiting.
- Code: existing `POST /sources`, `createDraftFromSource` (always draft), Drive browser, `LayoutConversionService`.
- Fixtures: Phase 1 must update `scripts/verify-layout-fixtures.php` `serialize_block()` so it walks `innerBlocks` / `innerContent` (current shim dumps `innerHTML` only).
- Research: `research/researcher-01-gutenberg-lists.md`, `research/researcher-02-folder-multiselect.md`.
- No cross-plan `blockedBy`. Older in-progress plans are already shipped in code.

## Not in this plan

- Folder watch / Drive Changes / webhooks
- Auto-publish, Ready folder, clock time
- Elementor multi-import
- REST batch create / durable job table (roadmap 1.3)
- Yoast / slug / category mapping
- Turning `"1. Title"` headings into ordered lists

## Success

- Example Doc (or same-shape fixture) opens in Gutenberg with **no** list recovery UI.
- Two sibling `ul` stay two list blocks.
- Operator selects up to 20 Docs in one folder and gets that many drafts.
- Site schedule already refreshes those sources.

## Open questions

None.

## Validation Log

### Session 1 — 2026-08-15
**Trigger:** `/ck:plan validate`
**Questions asked:** 4

#### Questions & Answers

1. **[Architecture]** Phase 1 fixtures: `serialize_block()` only writes `innerHTML` and ignores `innerBlocks`. What should Phase 1 do?
   - Options: Update shim to walk innerBlocks (Recommended) | Keep shim; substring tokens | Add full parse_blocks shim too
   - **Answer:** Update the serialize_block shim to walk innerBlocks
   - **Rationale:** Without this, nested list-item fixtures cannot pass.

2. **[Scope]** Timing vs 1.1.4 freeze. When should implementation start?
   - Options: After 1.1.4 ships (Recommended) | Start now on a side branch | Ignore the freeze; this is more important
   - **Answer:** Ignore the freeze; this is more important
   - **Rationale:** User priority. Plan no longer waits on 1.1.4.

3. **[Architecture]** Multi-create `onCompleted(result)` is a single SyncResult. How should multi-create report?
   - Options: Call onCompleted once per draft (Recommended) | Change to array | Add onBatchCompleted
   - **Answer:** Call onCompleted once per created draft
   - **Rationale:** No callback signature change. Existing Sources/Setup/post-sync handlers stay valid.

4. **[Scope]** Where should Drive multi-select be available?
   - Options: Sources + Setup new-draft browse only (Recommended) | Every Drive browser | Sources only
   - **Answer:** Sources + Setup new-draft browse only
   - **Rationale:** Post editor / list-table link stays one Doc to one post.

#### Confirmed Decisions
- Fixture shim: recurse `innerBlocks` in `serialize_block`
- Timing: implement now, freeze overridden
- Multi-create: `onCompleted` per draft
- Multi-select surfaces: Sources + Setup new-draft browse only
- `wp-block-list` class: still yes (not re-asked; Phase 1 default)

#### Action Items
- [x] Propagate shim update into Phase 1 files
- [x] Remove 1.1.4 wait from plan + Phase 3
- [x] Lock per-item onCompleted + Sources/Setup-only multi-select in Phase 2

#### Impact on Phases
- Phase 1: required file `scripts/verify-layout-fixtures.php`
- Phase 2: no `onCompleted` type change; hide multi-select on post-edit/list link
- Phase 3: changelog allowed with this ship, not gated on 1.1.4

### Verification Results
- **Tier:** Standard (Fact Checker + Contract Verifier)
- **Claims checked:** 18
- **Verified:** 16 | **Failed:** 1 | **Unverified:** 1

#### Failures
1. [Contract Verifier] `scripts/verify-layout-fixtures.php:200-211` — `serialize_block()` emits only `innerHTML`; ignores `innerBlocks`/`innerContent`. Phase 1 nested list fixtures cannot be asserted until the shim is updated. **Accepted fix:** update shim.

#### Unverified
1. [Fact Checker] Live Gutenberg “Attempt recovery” on the example Doc — inferred from format + research, not reproduced in a WP editor this session. Phase 3 still required.

### Whole-Plan Consistency Sweep
- Files reread: plan.md, phase-01, phase-02, phase-03
- Decision deltas checked: 4
- Reconciled stale references: see Session 1 propagation
- Unresolved contradictions: 0
