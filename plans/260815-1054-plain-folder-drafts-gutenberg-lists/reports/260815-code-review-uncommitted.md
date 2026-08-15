## Code Review Summary

### Scope
- Files reviewed:
  - `src/Sync/HtmlListBlockBuilder.php` (new)
  - `src/Sync/HtmlBlockFactory.php`
  - `src/Sync/HtmlBlockMarkupSanitizer.php`
  - `scripts/verify-layout-fixtures.php`
  - `tests/fixtures/layout-presets/lists-table-callout/expected.html`
  - `tests/fixtures/layout-presets/seo-article-*` (4 fixtures)
  - `resources/js/admin/features/doc-source-modal/*` (modal, hook, selection helper, lazy props)
  - `resources/js/admin/features/drive-browser/drive-browser-panel.tsx`
  - `resources/js/admin/features/drive-browser/drive-browser-table.tsx`
- Lines of code analyzed: ~500 new/changed (diff + new files)
- Review focus: uncommitted cook run — Gutenberg list serialization + folder multi-select drafts
- Updated plans: `plans/260815-1054-plain-folder-drafts-gutenberg-lists/plan.md` (+ phase notes)

### Overall Assessment
**Score: 7.5/10 — Needs changes**

Phase 1 (lists) is solid: correct `core/list` → `core/list-item` shape, `wp-block-list`, nested + sibling fixtures green, heading-number fixture proves `"1."` stays headings, Elementor path untouched. Fixture `serialize_block` shim correctly walks `innerContent` null slots.

Phase 2 (multi-select) hits product locks (free, draft-only, no batch REST, multi only `mode:new`, `onCompleted` once per draft, cap 20, de-dupe fileId in selection). Gaps: partial-failure UX closes modal before errors stick; no in-loop progress `"N/M created"`; React setState-inside-updater; best-effort already-linked skip absent. Phase 3 staging/demo not in this diff (expected).

Verification run this review:
- `composer test:layout-fixtures` → 16/16 ok
- `pnpm typecheck` → pass
- `pnpm lint:js` → pass
- `phpcs` on new/changed Sync PHP → clean
- Gutenberg live editor recovery still Phase 3 (not verified here)

### Critical Issues
None (no security, data-loss, or hard contract breaks found).

### High Priority Findings

1. **Partial multi-create closes modal and drops visual errors**
   - File: `use-doc-source-modal.ts` `attach()`
   - After some successes + some failures, code sets error then always `onClose()`. Close effect clears error state; operator may miss which Docs failed (only `speak()` remains).
   - Impact: silent-ish partial failure vs plan “Partial-failure reporting”.
   - Fix direction: if `failures.length > 0` and any success, keep modal open (or surface parent notice with failure list) before close; only auto-close on full success.

2. **No create progress copy (`3/12 created`)**
   - Plan Phase 2 requirement missing.
   - Busy disables UI with no index progress during sequential `createSource`.
   - Fix: track `createdIndex` / `total` and set footer/notice text while looping.

### Medium Priority Improvements

1. **setState inside `setSelectedDocuments` updater**
   - `setMetadata` / `setDocumentInput` / `setError` inside functional updater → fragile under Strict Mode double-invoke.
   - Fix: compute `next` first, then call setters sequentially outside updater.

2. **Already-linked fileId skip not implemented**
   - Plan step: best-effort skip if fileId already in loaded Sources list.
   - Not blocking (server still allows duplicate drafts); duplicates remain easy.

3. **Setup multi-create tracks only last activation source**
   - `use-setup-app.ts` `handleSourceCreated` replaces single `activationSource`.
   - Sources path merges via `trackSourceIds` — OK for Sources.
   - Setup multi: earlier drafts still queue in cron; activation UI only last. Acceptable for first-source UX, weak for multi from Setup.

4. **N × `refreshSources` on Sources multi-create**
   - Each `onCompleted` fires refresh (up to 20). Works via merge; thrashy. Debounce/batch later if needed — not extra product scope.

5. **No native checkbox column / CSS delta**
   - Toggle via `aria-pressed` + existing `is-selected` styles. Meets “toggle”; not literal checkboxes. Fine if a11y labels stay (they do).

### Low Priority Suggestions

1. Multi-select name join in preview can get long for 20 titles — consider “First, Second, +N more”.
2. `cleanListInnerHtml` still present for Elementor only — good keep; no dead path for Gutenberg factory.
3. Span unwrap is recursive for all `span`/`div`/`p` — matches fixtures; rare adjacent spans without inter-space may glue words (GDocs usually spaces second span).
4. Journal `docs/journals/2026-08-15-...` still says “after 1.1.4 freeze” — plan was overridden; docs only.

### Positive Observations

- Clean extraction `HtmlListBlockBuilder` keeps factory &lt;200 lines.
- Nested `innerContent` pattern matches core save (open, null children, close).
- Sibling `ul` not merged; ordered `start` only when &gt;1.
- `canChooseElementor && selectedDocuments.length <= 1` + `documents.length === 1` force Gutenberg multi-import.
- Cap 20 + silent refuse extra with clear message.
- Auth/nonce failure aborts remaining creates; per-doc errors continue.
- `onCompleted` signature unchanged; Sources polling accumulates IDs.
- Fixtures cover plan matrix: two-ul, heading numbers, li span/p unwrap, nested ul.
- verify shim `serialize_block` fix unblocks nested fixtures.

### Recommended Actions
1. Keep modal open (or parent notice) on partial multi-create failure; list failed Doc names.
2. Show sequential progress `Created %1$d of %2$d…` during attach loop.
3. Move side-effect state updates out of `setSelectedDocuments` updater.
4. Optional: warn/skip fileIds already present in current Sources list when available.
5. Phase 3: live Gutenberg recovery on example Doc + 5-doc staging folder run before ship claim.

### Plan task completeness

| Phase | Status vs uncommitted code |
|-------|----------------------------|
| 1 Gutenberg lists | **Done in code** — builder, factory, sanitizer, fixtures, shim; fixtures green |
| 2 Multi-select drafts | **Mostly done** — multi + sequential create + caps + locks; missing progress copy + partial-fail UX polish; optional already-linked skip |
| 3 Verification/demo | **Not in this run** — automated layout/lint/typecheck green here; staging editor/GIF/changelog open |

### Metrics
- Type Coverage: `pnpm typecheck` clean (project-level)
- Test Coverage: layout fixtures 16/16 pass; no new JS unit tests
- Linting Issues: JS 0; Sync PHP phpcs 0; fixture script pre-existing OO/function mix warnings only

### Unresolved questions
- Live WP editor still show recovery on example Doc post-sync? (Phase 3 only)
- Should Setup multi-create track all postIds for progress UI, or Sources-only multi is enough?

---

**Verdict: Needs changes** (High #1–#2 before ship; Phase 1 merge-ready alone)

**Status: DONE_WITH_CONCERNS**
**Summary:** List serialization looks correct and fixture-verified; multi-select implements product locks but partial-failure close and missing create progress need fixes before full approve.
**Concerns:** Partial fail UX; progress copy; setState-in-updater; Phase 3 editor spot-check still open.
