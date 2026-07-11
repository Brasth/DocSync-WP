# Phase 01 Product Gates and Capability Baseline

## Context links

- Parent: [plan.md](plan.md)
- Design: [../../docs/plans/2026-07-11-role-aware-product-activation-design.md](../../docs/plans/2026-07-11-role-aware-product-activation-design.md)
- Research: [research/researcher-01-architecture-report.md](research/researcher-01-architecture-report.md), [research/researcher-02-product-ux-report.md](research/researcher-02-product-ux-report.md)

## Overview

- Effort: 8h
- Priority: P1
- Status: Pending
- Outcome: approve four product gates and prove the current admin/editor/custom-role permission matrix before contract changes.

## Key Insights

- Roles are copy context only; WordPress capabilities and post editability are authority.
- Setup currently owns both global configuration and personal connection, while Sources depends on admin settings data.
- `_docsync_wp_sync_owner_user_id` is an operational Google identity, so implicit ownership replacement is unsafe.

## Requirements

- Record current menu, asset, REST, source, log, and OAuth access for administrator, editor, author, and one custom role.
- Approve source-owner handoff, editor readiness summary, landing behavior, and telemetry consent policy.
- Define activation as an accessible source with `synced` or `skipped` and a successful timestamp.
- Do not modify runtime behavior before gate decisions are recorded.

## Architecture

Produce an implementation decision record inside this phase file or an adjacent report. Use `RestPermissions` and `SourceRepository::userCanSyncPost()` as the baseline. No new role names, database state, or persona selection.

## Related code files

- `src/Admin/AdminPage.php`
- `src/Assets/AssetRegistry.php`
- `src/Rest/RestPermissions.php`
- `src/Rest/SettingsController.php`
- `src/Rest/SourceController.php`
- `src/Sync/SourceRepository.php`

## Implementation Steps

1. Enumerate capabilities used by menus, render guards, assets, settings, OAuth, Sources, logs, and source mutations.
2. Exercise the matrix with nonces for admin/editor/author/custom-role accounts in the devcontainer.
3. Trace relink/sync behavior around `_docsync_wp_sync_owner_user_id` and document current transfer semantics.
4. Approve gate defaults: explicit owner transfer; safe editor readiness in Sources; conditional top-level landing; local-only current-state metrics with elapsed-stage timing deferred.
5. Convert decisions into acceptance cases for Phases 02-06.

## Todo list

- [ ] Capture capability matrix and current route results.
- [ ] Approve explicit source-owner handoff behavior.
- [ ] Approve exact editor-safe readiness fields and labels.
- [ ] Approve top-level landing resolution and URL preservation.
- [ ] Approve local-only current-state metrics; defer elapsed-stage timing and expanded remote telemetry consent.
- [ ] Record acceptance cases without credentials, tokens, emails, or Google IDs.

## Success Criteria

- Every surface and mutation has a named server authority.
- The four product gates have written decisions and owners.
- Dependent phases can implement without inventing authorization or privacy policy.

## Risk Assessment

- Risk: visual role assumptions weaken security. Mitigation: map each UI action to an existing or explicit server permission.
- Risk: product gates remain vague. Mitigation: block Phases 02-05 until acceptance wording is approved.

## Security Considerations

- Use test accounts and redact nonce/token/account values from evidence.
- Treat menu visibility as discoverability, never authorization.

## Next steps

Proceed to the operational workspace contract after all four gates close.

## Unresolved Questions

- Product gate: must handoff require confirmation by the receiving editor, or only an explicit action by an authorized operator?
- Product gate: should admin readiness show both site-wide and current-user activation, or a single combined summary?
- Product gate: should conditional landing apply only to the top-level slug or also after OAuth callback?
- Product gate: who approves a future telemetry consent version and retention policy?
