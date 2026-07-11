# Phase 06 Reliability, Accessibility, and Release Validation

## Context links

- Parent: [plan.md](plan.md)
- Previous: [phase-05-sources-health-home-and-routing.md](phase-05-sources-health-home-and-routing.md)
- Standards: [../../docs/code-standards.md](../../docs/code-standards.md), [../../docs/design-guidelines.md](../../docs/design-guidelines.md)

## Overview

- Effort: 8h
- Priority: P1
- Status: Pending
- Outcome: prove the role-aware activation and Sources workflow is safe, accessible, compatible, and privacy-preserving.

## Key Insights

- Reliability messaging must explain content safety and recovery, not expose raw codes as headlines.
- Existing telemetry consent does not silently authorize a materially broader activation funnel.
- Capability, source ownership, WP-Cron, long-copy, and background polling are cross-surface risks.

## Requirements

- Validate the full admin/editor/custom-role journey from site-not-ready through second successful sync.
- Audit keyboard, screen reader semantics, focus return, live updates, contrast, reduced motion, and 375/768/1440 layouts.
- Verify recovery for token/scope, site config, download, cron stale, large export, transform/import, and insufficient capability failures.
- Keep activation metrics local-only unless a new consent version and privacy review are separately approved.
- Run focused and full project verification without weakening tests.

## Architecture

Use existing sync events and source state for recovery context. Add no analytics table or remote event stream. Local current-state milestones, if retained, must derive from existing records and avoid new persistence. Elapsed-stage timing is deferred because the current model does not record every stage transition. Update product/system/design docs only for shipped contracts and behavior.

## Related code files

- `resources/js/admin/app/setup-app.tsx`
- `resources/js/admin/app/sources-app.tsx`
- `resources/js/admin/shared/ui/`
- `resources/css/shared/responsive.css`
- `src/Telemetry/TelemetryService.php`
- `src/Telemetry/TelemetryCron.php`
- `.devcontainer/scripts/verify-runtime.sh`
- `README.md`
- `docs/system-architecture.md`
- `docs/design-guidelines.md`

## Implementation Steps

1. Run capability/REST tests for admin, editor, author, custom role, nonce failure, and cross-post access.
2. Run end-to-end activation scenarios including reconnect, failure, stale queue, success, and second sync.
3. Audit semantic progress, headings, error association, announcements, focus behavior, touch targets, and narrow tables.
4. Verify telemetry remains default-off and unchanged; document any future payload as a separate proposal only.
5. Run Composer validation/lint/fixtures/telemetry test and pnpm lint/typecheck/build.
6. Run devcontainer runtime verification and inspect all six Vite manifests/bundles.
7. Update durable docs and prepare release QA evidence.

## Todo list

- [ ] Complete role/capability and data-isolation matrix.
- [ ] Complete activation/recovery E2E scenarios.
- [ ] Complete keyboard, screen-reader, responsive, and reduced-motion audit.
- [ ] Confirm telemetry contract and consent are unchanged.
- [ ] Run all PHP/frontend/build/runtime checks.
- [ ] Update documentation for final route, contract, ownership, and landing decisions.

## Success Criteria

- All acceptance scenarios from the approved design pass.
- No credentials, tokens, private IDs, emails, titles, URLs, or content enter telemetry or logs beyond existing safe contracts.
- Existing settings, source meta, sync output, revisions, scheduled sync, and Vite entries remain compatible.
- No critical accessibility, authorization, build, or runtime regression remains.

## Risk Assessment

- Risk: happy-path QA misses ownership and recovery. Mitigation: require the role matrix and every named terminal condition.
- Risk: release pressure broadens telemetry consent. Mitigation: ship local derivation only and treat remote funnel telemetry as separate product/security work.

## Security Considerations

- Inspect network payloads for data minimization and nonce enforcement.
- Redact screenshots/reports and never store OAuth JSON, cookies, tokens, Google IDs, or user emails.
- Reconfirm destructive reset and handoff confirmation describe retained WordPress content.

## Next steps

Ship only after product gates, compatibility suite, runtime checks, and accessibility acceptance are complete; then measure support/recovery feedback before team-scale work.

## Unresolved Questions

- Product gate: whether a future remote activation funnel is valuable enough to justify new versioned consent, payload documentation, retention, and privacy review.
