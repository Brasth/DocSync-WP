---
phase: 4
title: "Phase 4: Plain-language Sources and recovery actions"
status: todo
priority: P2
effort: "2d"
dependencies: []
---

# Phase 4: Plain-language Sources and recovery actions

## Overview

Sources screen reads in human terms and every error offers a fix. Mostly frontend; status keys and REST contracts unchanged.

## Requirements

- Functional:
  - Status label `skipped` shown as "Up to date" everywhere (Sources, post list column, editor box, filters).
  - Last sync shown as relative time with local absolute time on hover.
  - Post type and post status shown as labels, not slugs. Raw Google file ID removed from the row.
  - Search filters live (300 ms debounce); selects apply on change; "Apply filters" button removed; Reset kept.
  - Health summary counts are filter links.
  - Error rows show one recovery action chosen from `syncErrorCode`.
  - Row menu: Open Google Doc, View post, Change Doc, Detach. Row selection with "Sync selected" (max 20).
  - Per-row Sync button is secondary; "Sync all changed" remains the only primary.
- Non-functional: URL-backed filters keep working; keyboard and screen-reader behaviour per `docs/design-guidelines.md`; no new dependencies.

## Architecture

- `shared/format-time.ts`: single home for relative/absolute formatting. Moves logic out of `sync-log-events-table.tsx` and `folder-watch-format-time.ts`. Parses stored UTC `Y-m-d H:i:s`.
- `shared/ui/status-pill.tsx`: label map owns display text; keys untouched.
- `features/sources/sync-error-recovery.ts`: pure map `errorCode → { label, kind }`.

| Codes | Action |
|---|---|
| `docsync_wp_google_reconnect_required`, `docsync_wp_google_not_connected`, `docsync_wp_not_connected` | Reconnect Google (link to account panel) |
| `docsync_wp_access_denied`, `docsync_wp_docs_api_access_denied`, `docsync_wp_drive_download_blocked` | Open Doc to check sharing |
| `docsync_wp_source_not_found`, `docsync_wp_non_google_doc`, `docsync_wp_invalid_document_id` | Change Doc |
| `docsync_wp_google_transient_failure`, `docsync_wp_docs_api_transient_failure`, `docsync_wp_bad_google_response` | Retry sync |
| `docsync_wp_google_credentials_missing` | Ask an administrator (Setup link for admins) |
| anything else | Retry sync + View activity |

- Health filter: add `health` query param (`attention` | `syncing` | `healthy`) to `GET /sources`, backed by the same categories as the existing health ordering in `SourceRepository`. Additive.
- Row menu: `DropdownMenu` from `@wordpress/components` (already external). Change Doc opens existing `DocSourceModal`; Detach uses existing endpoint + `ConfirmDialog`.

## Related code files

- Create: `resources/js/admin/shared/format-time.ts`
- Create: `resources/js/admin/features/sources/sync-error-recovery.ts`, `source-row-menu.tsx`
- Modify: `resources/js/admin/features/sources/sources-table.tsx`, `source-health-summary.tsx`
- Modify: `resources/js/admin/app/use-sources-app.ts`, `resources/js/admin/api/sources-api.ts`, `api/types.ts`
- Modify: `resources/js/admin/shared/ui/status-pill.tsx`
- Modify: `resources/js/admin/features/sync-logs/sync-log-events-table.tsx`, `features/folder-watches/folder-watch-format-time.ts`
- Modify: `resources/css/components/sources-table.css`
- Modify: `src/Rest/SourceController.php`, `src/Sync/SourceRepository.php` (`health` filter), `src/Admin/PostListActions.php` (label)
- Modify: `assets/screenshot-*.png`, `readme.txt` screenshot captions, `languages/` POT

## Implementation steps

1. Extract time formatting to shared module; switch Logs and Folders to it; no visual change there.
2. Status label map; update filter option text; update PHP column label.
3. Sources row: relative time, labels, remove file ID, secondary Sync button.
4. Debounced search + immediate selects; keep form submit on Enter; keep URL sync.
5. `health` REST filter; make summary counts links that set it; active state styling.
6. Recovery map + inline action button in error cell.
7. Row menu and selection column; "Sync selected" queues background syncs through existing per-source endpoint.
8. Reshoot WordPress.org screenshots (Setup, Sources, Drive Folders, Doc modal, editor); update captions; `pnpm lint:screenshots`.
9. `pnpm lint && pnpm typecheck && pnpm build`; manual keyboard pass.

## Todo

- [ ] Shared time formatting
- [ ] "Up to date" label in JS and PHP
- [ ] Row cleanup + button hierarchy
- [ ] Live filters
- [ ] Clickable health summary + REST filter
- [ ] Recovery actions
- [ ] Row menu + bulk select
- [ ] Screenshots + POT

## Success criteria

- [ ] No raw UTC timestamp, slug, or file ID visible in Sources rows.
- [ ] Each error code in the table above renders its action; unknown codes render the fallback.
- [ ] Filters update results without a button press; URL reflects state; back button restores it.
- [ ] One primary button on the Sources screen.

## Risk assessment

- Debounced requests racing → ignore stale responses by request sequence number in `use-sources-app.ts`.
- Label change breaks translations → new strings; old ones removed in POT regeneration.

## Next steps

Phases 9 and 10 build on the shared label/time modules.
