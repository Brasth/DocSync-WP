---
phase: 10
title: "Phase 10: Unified workspace navigation and activity drawer"
status: todo
priority: P2
effort: "3d"
dependencies: [4]
---

# Phase 10: Unified workspace navigation and activity drawer

## Overview

Plugin screens feel like one workspace: shared tab navigation in the masthead and per-source activity in a side drawer, without merging bundles or changing URLs.

## Requirements

- Functional:
  - Tabs in the admin shell: Sources · Drive Folders · Activity · Settings (administrators only). Current screen marked.
  - Submenu label "Logs" → "Activity". "Setup" → "Settings" once site connection is complete and a source exists; "Setup" before.
  - Sources row "Activity" opens a right-side drawer with that source's recent events and a link to the full Activity screen pre-filtered.
  - Drive Folders rows keep "View sources" (existing `folderWatchId` filter).
- Non-functional: submenu slugs, direct URLs, Vite entries, REST contracts unchanged; drawer keyboard-safe (focus trap, Escape, return focus).

## Architecture

- `WorkspaceNav` in `shared/ui/`: plain links built from admin URLs in the existing inline config; `aria-current="page"`. No client-side routing.
- Capability-aware: Settings tab only when config says the user can manage settings; other tabs follow existing menu capability.
- Drawer: Radix Dialog with side-sheet styles (new CSS partial). Content = compact variant of `sync-log-events-table` rows + existing `GET` logs call with `post_id`, page size 20.
- PHP: `AdminPage` menu labels; label switch uses the readiness check it already performs for top-level routing.
- Explicitly not done: SPA merge of screens, removing the Drive Folders screen shipped in the folder automation work.

## Related code files

- Create: `resources/js/admin/shared/ui/workspace-nav.tsx`
- Create: `resources/js/admin/features/sources/source-activity-drawer.tsx`
- Create: `resources/css/components/workspace-nav.css`, `resources/css/components/activity-drawer.css`
- Modify: `resources/js/admin/shared/ui/admin-shell.tsx`, `resources/js/admin/config.ts` (nav URLs, capability flag)
- Modify: `resources/js/admin/features/sources/sources-table.tsx` (Logs link → Activity button)
- Modify: `resources/js/admin/features/sync-logs/sync-log-events-table.tsx` (compact prop)
- Modify: `resources/css/sources-entry.css`, `setup-entry.css`, `folders-entry.css`, `logs-entry.css` (import nav partial)
- Modify: `src/Admin/AdminPage.php`, `src/Assets/AssetRegistry.php` (inline config)
- Modify: `assets/screenshot-*.png`, `readme.txt`, `docs/design-guidelines.md`, `docs/codebase-summary.md`, `README.md`

## Implementation steps

1. Add nav URLs and `canManageSettings` to inline config (no secrets, URLs only).
2. `WorkspaceNav` + CSS; mount in `AdminShell` under the masthead; verify on all four screens and at mobile width (tabs scroll horizontally inside their container, page does not).
3. Menu label changes in `AdminPage`; keep page titles consistent with tab names.
4. Compact mode for events table; drawer component with loading, empty, error states.
5. Replace per-row Logs link with Activity button opening the drawer; keep "Open full activity" link inside.
6. Accessibility pass: tab order, focus return, `aria-current`, reduced motion for drawer transition.
7. Final screenshot refresh and docs.

## Todo

- [x] Inline config additions
- [x] Workspace nav on all screens
- [x] Menu labels
- [x] Compact events + drawer
- [x] Sources row integration
- [x] Accessibility pass
- [x] Screenshots + docs

## Success criteria

- [x] From any plugin screen, any other is one click through the same tab bar.
- [x] Operator without `manage_options` never sees the Settings tab.
- [x] Source activity viewable without leaving Sources; full Activity screen still reachable and filtered.
- [x] All existing bookmarked URLs load the same screens.

## Risk assessment

- Sources bundle grows by importing log components → import only the events table and API module; check bundle size before/after in build output.
- Dynamic "Setup/Settings" label confuses docs → docs use "Settings (Setup until configured)".

## Security considerations

- Drawer uses the existing permission-filtered logs route; nav exposes URLs only for screens the user's capabilities already allow.

## Next steps

None blocking.
