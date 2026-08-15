---
phase: 2
title: "Folder multi-select drafts"
status: in_progress
priority: P1
dependencies: [1]
effort: "1-2d"
---

# Phase 2: Folder multi-select drafts

## Context Links

- Research: [researcher-02-folder-multiselect.md](./research/researcher-02-folder-multiselect.md)
- Create path: `src/Rest/SourceController.php` `createSource`
- Draft hardcode: `SyncService::createDraftFromSource` (`post_status => 'draft'`)
- UI: `resources/js/admin/features/drive-browser/`, `doc-source-modal/`

## Overview

Let an operator pick multiple Google Docs in the current Drive folder and create one draft per Doc. Reuse `POST /sources`. No new batch route. No folder subscription table.

## Key Insights

- Drive browser is single-select. Folders navigate. Docs are selectable.
- `createSource` already: metadata check, always draft, background queue.
- Same Google file ID can create duplicate drafts (no unique index). FE must de-dupe selection.
- Sequential create + `syncMode: background` avoids one huge PHP request.
- Sync-all already batches 20. Cap multi-select at 20.

## Requirements

- Functional: checkboxes / toggle on selectable Docs in the current folder page.
- Functional: confirm once: post type + Gutenberg preset. Force blocks (omit Elementor for multi).
- Functional: sequential `createSource` per fileId, `target.mode=new`, `syncMode=background`.
- Functional: progress copy `3/12 created`. Partial success stays; no rollback.
- Functional: created `postId`s enter existing Sources progress polling.
- Non-functional: cap 20. No recursive subfolders. No "select entire Drive".
- Non-functional: only Docs already loaded (operator must scroll/load more).
- Non-functional: always draft. Never set publish.

## Architecture

```text
Drive browser (multi selected fileIds)
  → Doc source modal confirm (post type, layoutPreset, Gutenberg only)
    → for each fileId: POST /sources { mode:new, syncMode:background }
      → existing queue + SyncService
        → Phase 1 list builder on convert
```

Do not add `POST /sources/batch` unless Phase 3 proves timeout. YAGNI.

Single-doc link (edit screen, list-table, advanced URL) stays single-select. Multi-select is the Drive browse path when creating **new** drafts from Sources and Setup only. Post-edit "link this post" stays one Doc.

`onCompleted(result: SyncResult)` stays a single result. Multi-create calls it **once per successful draft**. Do not change the callback type. Consumers: `use-sources-app.ts`, `use-setup-app.ts`, `post-meta-box-app.tsx`, `list-entry-app.tsx`.
<!-- Updated: Validation Session 1 - per-item onCompleted; Sources+Setup only -->

## Related Code Files

- Modify: `resources/js/admin/features/drive-browser/use-drive-browser.ts`
- Modify: `resources/js/admin/features/drive-browser/drive-browser-table.tsx`
- Modify: `resources/js/admin/features/drive-browser/drive-browser-panel.tsx`
- Modify: `resources/js/admin/features/doc-source-modal/use-doc-source-modal.ts`
- Modify: `resources/js/admin/features/doc-source-modal/doc-source-modal.tsx`
- Modify: `resources/js/admin/features/doc-source-modal/lazy-drive-browser-panel.ts`
- Modify if needed: `resources/js/admin/app/use-sources-app.ts` (track many postIds)
- Modify: `resources/css/components/drive-browser-table.css` (checkbox/selected rail)
- Optional thin helper: `resources/js/admin/api/sources-api.ts` (no new REST)
- Do not modify: `SourceController.php`, `SyncService.php`, Phase 1 PHP list files

## Implementation Steps

1. Add `selectedDocuments: DriveItemSummary[]` to Drive browser. Toggle selectable rows. Folders still only open.
2. Footer: enable primary when `selection.length >= 1`. Label `Create N drafts` when N>1.
3. Confirm panel: post type, Gutenberg preset. Hide Elementor output choice when N>1.
4. Loop `createSource` sequentially. After each success, call `onCompleted(result)` once. Stop adding new requests after first hard auth/nonce failure. Continue on per-Doc metadata errors.
5. De-dupe by fileId in the selection set. Best-effort skip if that fileId already appears in the loaded Sources list; tell the operator.
6. Enable multi-select only when target is `mode: 'new'` (Sources + Setup). Force single-select when target is `existing` (post editor / list-table).
7. Copy: *New Google Docs become WordPress drafts. You publish.*

## Todo List

- [x] Multi-select in current folder view
- [x] Sequential background create, cap 20
- [ ] Partial-failure reporting (partial success closes modal; visual error lost — fix before ship)
- [x] Sources polling for all new postIds (`onCompleted` × N → `trackSourceIds`)
- [x] `pnpm lint` + `pnpm typecheck`
- [ ] Progress copy `Created n of m` during sequential create

## Success Criteria

- [x] 1 Doc selected → same as today (one draft) (code path preserved)
- [ ] 5 Docs selected → 5 drafts, all `draft` (code ready; staging Phase 3)
- [x] Duplicate fileId in selection creates one request (`uniqueDocuments` + toggle)
- [x] Folder click still navigates, never creates a source
- [x] Existing single-post link flow unchanged (post editor + list-table stay one Doc)
- [x] `onCompleted` signature unchanged; N drafts → N calls
- [x] No new REST route

## Risk Assessment

- N × `spawnScheduledSyncs` can wake cron often. Accept for N≤20.
- Pagination: users think they selected "the folder". Copy must say selected Docs only.
- Timeout: sequential metadata+insert is small; import runs later in cron.
- Owner token / quota: same as single create.

## Security Considerations

- Same REST nonce + creatable post-type caps.
- Do not accept folder IDs as `fileId`.
- Do not persist a folder watch record.

## Next Steps

Phase 3: sync the example Doc and record the agency GIF.
