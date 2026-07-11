# Phase 02 Operational Workspace Contract

## Context links

- Parent: [plan.md](plan.md)
- Previous: [phase-01-product-gates-and-capability-baseline.md](phase-01-product-gates-and-capability-baseline.md)
- Architecture: [../../docs/system-architecture.md](../../docs/system-architecture.md)

## Overview

- Effort: 12h
- Priority: P1
- Status: Pending
- Outcome: provide authenticated operators a least-privilege readiness/bootstrap contract without weakening `/settings`.

## Key Insights

- Sources cannot become an editor workspace while its bootstrap requires admin-only settings.
- Existing records can derive readiness and activation; a new persisted onboarding model is unnecessary.
- Summary queries must use the same enabled-post-type and editability boundaries as source listing.

## Requirements

- Add `GET /workspace`, guarded by authenticated DocSync-use permission and nonce.
- Return `canManageSettings`, site readiness, safe available/enabled post types, safe output defaults/labels, Elementor availability, and accessible-source summary.
- Keep current-user Google account data on the existing account endpoint.
- Exclude OAuth client identifiers/secrets, telemetry choices, schedule configuration, cross-user identity, and raw errors.
- Preserve all existing REST shapes and build entry names.

## Architecture

Add a focused workspace controller/service registered by `RestServiceProvider`. It reads settings through domain methods but serializes only operational fields. A source-summary query reuses repository filters and returns counts for attention, syncing, healthy, and activation evidence. If current repository APIs are sufficient at expected scale, compose them first; add a bounded count method only when needed.

## Related code files

- `src/Rest/RestServiceProvider.php`
- `src/Rest/RestPermissions.php`
- `src/Rest/SettingsController.php`
- `src/Rest/SourceController.php`
- `src/Settings/SettingsRepository.php`
- `src/Sync/SourceRepository.php`
- `resources/js/admin/api/types.ts`
- `resources/js/admin/api/client.ts`

## Implementation Steps

1. Define the additive workspace wire type and stable readiness/summary vocabulary.
2. Add controller registration, nonce/login permission callback, and response serialization.
3. Reuse settings sanitization/preset registries while omitting maintenance-only fields.
4. Calculate accessible-source status using enabled types and `userCanSyncPost()` semantics.
5. Add typed frontend client and normalize wire values at the API boundary.
6. Verify admin/editor/custom-role access and forbidden settings mutations.

## Todo list

- [ ] Define and document `WorkspaceResponse`.
- [ ] Implement read-only controller and route registration.
- [ ] Implement bounded, permission-filtered source summary.
- [ ] Add TypeScript API client/types.
- [ ] Test malformed nonce, logged-out, editor, admin, disabled type, and uneditable source cases.
- [ ] Confirm no existing route response changed.

## Success Criteria

- An editor can bootstrap operational UI without `manage_options`.
- Workspace data contains no credential, token, account, telemetry, or schedule leakage.
- Admin and editor summaries include only sources they can edit.
- Existing settings, OAuth, Sources, and Logs clients remain compatible.

## Risk Assessment

- Risk: expensive counts on large sites. Mitigation: bounded statuses and repository-level queries; avoid loading full post content.
- Risk: duplicated readiness logic. Mitigation: centralize safe backend facts and keep presentation decisions pure on the client.

## Security Considerations

- Keep `POST /settings` and OAuth-clear strictly admin-only.
- Test data minimization and cross-user/cross-post isolation explicitly.
- Never expose `syncOwnerUserId` as a user identity; return an ownership relation only if the approved UX requires it.

## Next steps

Use the workspace and account responses to build the pure activation advisor and role-aware presentation.

## Unresolved Questions

- Resolved by default: use a new `/workspace` endpoint instead of conditionally changing `/settings` response permissions.
- Confirm the Phase 01-approved shape of the editor readiness summary before freezing the wire contract.
