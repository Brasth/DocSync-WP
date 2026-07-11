# Phase 01 Product Gates and Capability Baseline

## Context links

- Parent: [plan.md](plan.md)
- Design: [../../docs/plans/2026-07-11-role-aware-product-activation-design.md](../../docs/plans/2026-07-11-role-aware-product-activation-design.md)
- Research: [research/researcher-01-architecture-report.md](research/researcher-01-architecture-report.md), [research/researcher-02-product-ux-report.md](research/researcher-02-product-ux-report.md)

## Overview

- Effort: 8h
- Priority: P1
- Status: Complete
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

## Implementation Decision Record

### Capability baseline

Menu visibility is only discoverability. Every REST request still requires its named server authority and a valid WordPress REST nonce, except the signed OAuth callback.

| Surface | Administrator | Editor | Author | Custom role | Server authority |
| --- | --- | --- | --- | --- | --- |
| Setup menu, mount, and assets | Allowed | Denied | Denied | Denied | `manage_options` |
| Sources and Logs menus, mounts, and assets (current baseline) | Allowed | Denied | Denied | Denied | `manage_options` |
| `GET`/`POST /settings` and OAuth configuration clear | Allowed | Denied | Denied | Denied | `RestPermissions::canManageSettings()` |
| Personal OAuth URL/account/disconnect | Allowed | Allowed when an enabled target is usable | Allowed when an enabled target is usable | Capability-derived | `RestPermissions::canUseAuthenticatedRest()`; callback uses signed, expiring, user-bound state |
| Drive document inspection/browser | Allowed | Allowed when an enabled target is usable | Allowed when an enabled target is usable | Capability-derived | `canUseAuthenticatedRest()` plus Google access checks |
| Source list and logs | Editable enabled sources | Editable enabled sources | Own editable enabled sources | Capability-derived | `canUseAuthenticatedRest()` plus enabled-type and `userCanSyncPost()` filtering |
| Source create/link/sync/update/detach | Editable/creatable enabled targets | Editable/creatable enabled targets | Own editable/creatable enabled targets | Capability-derived | `canUseAuthenticatedRest()` plus route-specific post-type/post checks |
| Post edit/list entry points | Enabled editable targets | Enabled editable targets | Own enabled editable targets | Capability-derived | `currentUserCanUsePostType()` plus WordPress post capabilities |

The custom-role outcome is intentionally capability-derived: it matches the relevant enabled post type's `create_posts`, `edit_posts`, `edit_others_posts`, and per-post `edit_post` capabilities rather than a role name. Runtime nonce/role smoke cases remain release-validation work; this matrix records the code-authoritative baseline without storing nonce, token, account, or identity evidence.

### Approved product gates

1. **Source-owner handoff:** ownership must change only through a separately labelled explicit transfer action by an operator who can edit the target. Receiving-editor confirmation is not required in this release. Ordinary sync/relink actions must not silently replace `_docsync_wp_sync_owner_user_id`.
2. **Editor readiness:** the operational contract may expose `siteConnectionReady`, `canManageSettings`, capability-filtered available/enabled target types, safe publishing defaults and preset labels, Elementor availability, and permission-filtered source summary counts. It must not expose OAuth identifiers/secrets, Google account data, telemetry, scheduling, cross-user IDs, or raw errors. Admin presentation may combine site and current-user readiness but must label the two facts separately.
3. **Landing behavior:** preserve the Setup slug, URL, and submenu. Conditional routing applies only to the top-level plugin entry: unconfigured site managers resolve to Setup; activated/operational users resolve to Sources. OAuth callback destinations remain unchanged.
4. **Telemetry consent:** activation milestones are derived locally from existing records only. No new persistence or remote funnel payload ships in this release. A future payload requires a separately approved versioned consent contract, privacy/retention review, and product-owner approval.

### Activation definition and dependent acceptance cases

Activation exists when at least one source accessible to the current user has `syncStatus` `synced` or `skipped` and a non-empty successful sync timestamp. Account readiness alone is never activation.

- `/workspace` is additive, read-only, nonce-protected, and available only through `canUseAuthenticatedRest()`.
- `/settings`, site OAuth clearing, and all settings mutations remain `manage_options` only and retain their existing shapes.
- Source summary counts include only enabled post types and posts passing `userCanSyncPost()` for the current user.
- Workspace serialization contains no credentials, tokens, account/email fields, Google IDs, source/post IDs, telemetry fields, schedule fields, cross-user ownership identifiers, or raw errors/messages.
- Phase 03 derives advisor state from workspace plus the existing current-user account endpoint; no wizard state is persisted.
- Phase 04 reuses the existing source modal and establishes activation only after a successful or unchanged-complete sync.
- Phase 05 preserves Setup and Sources URLs while applying the approved top-level landing rule.

## Todo list

- [x] Capture the code-authoritative capability matrix; defer runtime route/nonce smoke evidence to Phase 06.
- [x] Approve explicit source-owner handoff behavior.
- [x] Approve exact editor-safe readiness fields and labels.
- [x] Approve top-level landing resolution and URL preservation.
- [x] Approve local-only current-state metrics; defer elapsed-stage timing and expanded remote telemetry consent.
- [x] Record acceptance cases without credentials, tokens, emails, or Google IDs.

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

- None. All four product gates are closed above.
