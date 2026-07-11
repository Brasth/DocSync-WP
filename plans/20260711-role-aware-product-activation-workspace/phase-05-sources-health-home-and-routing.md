# Phase 05 Sources Health Home and Routing

## Context links

- Parent: [plan.md](plan.md)
- Previous: [phase-04-first-source-completion-flow.md](phase-04-first-source-completion-flow.md)
- UX research: [research/researcher-02-product-ux-report.md](research/researcher-02-product-ux-report.md)

## Overview

- Effort: 12h
- Priority: P1
- Status: Pending
- Outcome: make Sources the capability-safe daily workspace after activation while preserving dense operational workflows.

## Key Insights

- Agencies need per-site readiness and intervention evidence; editors need their actionable sources and account state.
- Existing `SourceRecord` already carries status, progress, error, timestamps, preset, owner relation, and edit URL.
- Empty, filtered-empty, disconnected, and insufficient-capability states require different actions.

## Requirements

- Order needs-attention sources before active syncs and healthy sources without breaking URL filters or pagination.
- Add compact health summary only when accessible sources exist.
- Each row communicates target, Doc, health/progress, last success, output policy, and next action.
- Add role-safe empty/recovery states and first-source action.
- Apply the Phase 01 landing decision while preserving all existing slugs and direct URLs.
- Keep Posts-list linking as a secondary familiar entry point.

## Architecture

Use workspace summary for the header and existing paginated Sources API for records. Prefer explicit status sort parameters or repository ordering only if client grouping would violate pagination correctness. Admin top-level resolution uses readiness/activation facts without redirect loops; editor/operator navigation must never require Setup permission. Keep Sources entry and lazy modal/Drive bundles stable.

## Related code files

- `src/Admin/AdminPage.php`
- `src/Assets/AssetRegistry.php`
- `src/Rest/SourceController.php`
- `src/Sync/SourceRepository.php`
- `resources/js/admin/app/sources-app.tsx`
- `resources/js/admin/app/use-sources-app.ts`
- `resources/js/admin/features/sources/sources-table.tsx`
- `resources/css/components/sources-table.css`
- `resources/css/sources-entry.css`

## Implementation Steps

1. Define health categories from stable source status/error/progress/timestamps.
2. Decide server ordering needed for correct pagination; add only an additive query/default if required.
3. Add compact summary, account/site readiness notice, and role-aware actions.
4. Rework rows and narrow-screen presentation without hiding the primary row action.
5. Distinguish no sources, filtered empty, disconnected account, waiting for admin, and request failure.
6. Implement approved top-level landing resolution and OAuth-success destination without changing submenu URLs.
7. Verify filters, pagination, sync-all, row sync, log links, custom roles, and source ownership messaging.

## Todo list

- [ ] Define health mapping and attention ordering.
- [ ] Preserve pagination/filter correctness.
- [ ] Add safe summary and role-aware empty/recovery states.
- [ ] Improve row hierarchy and narrow layout.
- [ ] Implement approved landing behavior.
- [ ] Test existing activated sites and zero-source sites for compatibility.

## Success Criteria

- Activated users reach Sources through the approved landing path.
- Attention items are discoverable without losing table density or filters.
- Editors see only actionable sources and safe readiness/account guidance.
- Existing direct Setup, Sources, Logs, Posts, and edit URLs remain valid.

## Risk Assessment

- Risk: client grouping misrepresents paginated priority. Mitigation: order at repository/query level when global ordering is required.
- Risk: redirects surprise admins. Mitigation: resolve only the top-level landing; preserve explicit submenu intent and back navigation.

## Security Considerations

- Source summaries, rows, bulk actions, and logs must share editability filters.
- Do not reveal another user's email or Google account; describe ownership relationally.
- Runtime render guards remain even when menu capability is broadened.

## Next steps

Validate recovery language, scheduling diagnostics, accessibility, compatibility, and release readiness across the entire journey.

## Unresolved Questions

- Product gate: final top-level landing and OAuth callback destination rules.
- Product gate: exact editor readiness summary shown above Sources.
