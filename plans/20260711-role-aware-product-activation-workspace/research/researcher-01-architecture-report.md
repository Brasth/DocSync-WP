# Architecture Research: Role-aware Product Activation Workspace

## Executive finding

The domain layer is already mostly role-aware; the admin shell is not. OAuth/account, Drive, source, and log REST routes accept any authenticated user who can edit/create an enabled target, then re-check target capabilities. However, Setup, Sources, and Logs menus, mounts, assets, and `GET /settings` are all `manage_options`-only. The approved product therefore needs a deliberate split between **site administration** and **content operations**, not a frontend-only redesign.

Recommended boundary:

- **Site manager (`manage_options`)**: configure/clear site OAuth, defaults, post types, scheduling, telemetry.
- **Content operator (capability-derived)**: connect/disconnect only their Google account, browse Docs, create/link/sync sources they can edit, inspect their accessible diagnostics.
- Do not store agency/editor “roles.” Derive responsibilities from WordPress capabilities and current connection/source state.

## Current boundaries

| Concern | Current contract | Consequence |
|---|---|---|
| Plugin admin pages | `AdminPage::CAPABILITY = manage_options` for Setup, Sources, Logs | Editors cannot reach the otherwise editor-capable workflow. |
| Admin assets | `AssetRegistry` enqueues plugin-page entries only for `manage_options` | Relaxing menus alone produces an empty/unbootstrapped screen. |
| Site settings | `GET`, `POST`, and OAuth-clear use `RestPermissions::canManageSettings` | Sources app calls `getSettings()`, so it cannot simply become editor-visible. |
| Personal Google account | OAuth URL/account/disconnect use `canUseAuthenticatedRest`; token stored per user | Correct reusable personal boundary. Callback is public but protected by signed, short-lived, user-bound OAuth state. |
| Source operations | Routes use `canUseAuthenticatedRest`, then validate enabled post type plus create/edit capability | Correct reusable operational boundary; server still validates Drive access and target permissions. |
| Source visibility | `SourceRepository::listSourcesPage()` filters enabled types and `userCanSyncPost()` | Admins see all editable sources; editors see only posts WordPress lets them edit. |
| Scheduled identity | `_docsync_wp_sync_owner_user_id`; cron syncs with that user/token | Linking/relinking assigns the operational Google identity; UI should explain ownership/handoff. |

## Reusable state and components

- `SettingsResponse.hasRequiredSettings`, `GoogleAccount.connected`, and `hasRequiredScope` already produce the credential/connect/reconnect/ready sequence.
- `google-setup-task-state.ts` is a useful pure decision seam, but “first draft” currently means account-ready, not first source created/synced.
- `DocSourceModal` already supports `{ mode: 'new', postType }`, lazy Drive browsing, inspection, output choice, preset choice, background creation, and completion callback. Reuse it directly from activation; do not redirect to Posts.
- `POST /sources` returns the created `postId`, queued/source state, and later polling is already supported by `GET /sources/{postId}`.
- `SourceRecord` already carries health inputs: status, step, progress, error/code, timestamps, output preset, owner ID, and edit URL.
- Shared `AdminShell`, notices, buttons, loading states, status pills, poller, Radix modal, screen-specific Vite entries, and lazy modal/browser assets should remain.

## Recommended contract design

1. Keep Setup and every settings mutation strictly `manage_options`.
2. Make Sources (the operational workspace) visible when `RestPermissions::currentUserCanUseDocSync()` is true; retain a runtime render guard, nonce checks, and post-level authorization.
3. Split menu/asset gating by page: Setup requires settings management; Sources/Logs require DocSync use. Do not weaken all pages to `read` without a second server-side guard.
4. Stop making the Sources app depend on admin `GET /settings`. Add an additive, read-only operational bootstrap contract (recommended `GET /workspace`) guarded by `canUseAuthenticatedRest`.
5. Suggested workspace response: `canManageSettings`, `siteConnectionReady`, filtered `availablePostTypes`, enabled types, safe output defaults/preset labels, Elementor availability, and optional source summary counts. Keep personal account on `/oauth/google/account` or aggregate it without changing its per-user semantics.
6. Exclude client ID, telemetry choices, sync scheduling, and other site-maintenance fields from the editor response. An OAuth client ID is not a secret, but editors do not need it.
7. Build a pure derived `ActivationState`: `site_needs_admin`, `account_disconnected`, `scope_outdated`, `ready_for_first_source`, `first_source_syncing`, `activated`, `source_needs_attention`. Never persist wizard progress.
8. Define activation as at least one accessible source with `synced` or `skipped` plus a successful timestamp. Account readiness alone is not activation.
9. Open `DocSourceModal` from the workspace CTA. After creation, poll the returned post and transition to activated/error recovery with its `editUrl`.

## Likely affected files

Backend/navigation:

- `src/Admin/AdminPage.php` — per-surface menu capability/landing behavior and render guards.
- `src/Assets/AssetRegistry.php` — per-entry authorization and least-privilege bootstrap data.
- `src/Rest/RestPermissions.php` — reuse public capability helpers; avoid duplicating role logic.
- `src/Rest/RestServiceProvider.php` plus a small workspace controller, or a carefully separated read method in `SettingsController.php`.
- `src/Sync/SourceRepository.php` — only if efficient activation/health counts are added; existing page response may suffice first.

Frontend:

- `resources/js/admin/api/types.ts`, `api/*` — additive workspace response/client.
- `resources/js/admin/app/setup-app.tsx`, `use-setup-app.ts` — admin activation flow and direct modal launch.
- `resources/js/admin/app/sources-app.tsx`, `use-sources-app.ts` — editor onboarding and daily workspace without admin settings.
- `resources/js/admin/features/google-setup/google-setup-task-state.ts` — pure role/state advisor.
- `resources/js/admin/features/doc-source-modal/*` — reuse; only generalize presentation/callback if required.
- `resources/js/admin/config.ts` and setup/sources CSS entry imports — safe capability flags and modal assets.
- `resources/css/components/setup-settings.css`, Sources/workspace styles — blueprint/two-lane hierarchy using existing tokens.

## REST and data impact

- Prefer one additive read endpoint; no breaking changes to `/settings`, `/oauth`, `/sources`, or source response fields.
- No new option, user meta, post meta, custom table, or onboarding migration is required.
- If summary counts are added, calculate only across posts the current user can edit; keep pagination semantics unchanged.
- Preserve menu slugs and existing URLs. Admins may keep Setup as the top-level landing; operators should land on Sources/workspace.
- Keep build entry names/manifests stable; reuse lazy source-modal and Drive-browser bundles.

## Security and privacy constraints

- Never relax `POST /settings` or OAuth-clear: clearing credentials disconnects every user and cancels queued work.
- Never return client secret/token material. Keep Google email/account status current-user-only.
- A role-aware UI is not authorization; every source create/link/sync/detach must retain target capability, enabled-type, nonce, and Drive-access validation.
- Avoid exposing cross-user account details. Prefer `isOwnedByCurrentUser`/“connected by another user” over expanding `syncOwnerUserId` into identity/email.
- Source health and activation counts must use the same editability filters as source listing and logs.
- Do not add telemetry for activation by default. If later introduced, require existing opt-in and exclude URL, user/email, post/document IDs, titles, or content.

## Migration and compatibility

- Existing configured sites derive `siteConnectionReady`; existing connected users derive account state; existing sources derive activation automatically.
- Old `drive.file` tokens continue to map to `scope_outdated` and require reconnect.
- Existing source ownership, post meta, revisions, cron events, and sync behavior remain untouched.
- Capability expansion changes discoverability, not data authority. Test custom roles and public custom post types before release.
- Keep direct Posts-list linking as a secondary entry point for backward familiarity.

## Recommended test seams

- Pure TypeScript table tests for every `ActivationState`, including dirty credentials, missing site config for editors, old scope, syncing, skipped/synced, and error recovery. The repo has no JS test runner today; add one only if the implementation plan accepts that dependency, otherwise use a deterministic Node verification script.
- PHP contract tests for admin vs editor vs author/custom-role access to menus, asset enqueue, workspace GET, settings POST/clear, OAuth account, source list/create/sync, and logs.
- Repository tests proving source summaries never include uneditable posts or disabled post types.
- Runtime REST smoke tests in the devcontainer with two users and nonce failures.
- Component/E2E checks: admin first run, editor waiting for admin, reconnect, direct Doc selection, queued polling, error recovery, keyboard/focus return, reduced motion, and 375/768/1440 widths.
- Compatibility gate: `composer lint`, existing fixture suites, telemetry settings test, `pnpm lint`, `pnpm typecheck`, and all six Vite builds.

## Unresolved questions

1. Should editors receive a dedicated “Workspace” label, or should the existing Sources screen become the workspace while retaining its slug?
2. When an editor can edit a source owned by another user, may they re-sync it with their own Google token (and thereby transfer sync ownership), or should handoff require an explicit action?
3. Should activation be site-wide for admins but per-user/per-accessible-source for editors? Recommended: show both, clearly labelled.
4. Is a new read-only `/workspace` endpoint acceptable, or must the plan minimize routes by splitting `GET /settings` permissions and response shape by capability?
