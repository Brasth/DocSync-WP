---
phase: 8
title: "Phase 8: Review-before-overwrite policy"
status: todo
priority: P1
effort: "4d"
dependencies: [3, 7]
---

# Phase 8: Review-before-overwrite policy

## Overview

Live content does not change without a person agreeing. Each source has an apply policy; scheduled sync can flag "Update available" instead of writing; edits inside synced content are never replaced silently.

## Requirements

- Functional:
  - Policy per source: site default | `auto` | `review` | `manual`.
  - Site setting `published_apply_policy`: new installs `review`, upgraded installs `auto`. Drafts and pending posts always resolve to `auto` unless the source overrides.
  - New status `update_available` with message "Google Doc changed. Review and apply."
  - "Apply update" = existing manual sync. After apply, "View changes" opens the WordPress revision comparison when revisions exist.
  - Manual sync with edits inside synced content → confirmation "Replace WordPress edits?".
- Non-functional: decision logic pure and table-tested; no extra Google calls; no event spam (one event per remote version).

## Architecture

`ApplyPolicyResolver::decide( trigger, policy, remote_changed, local_edits )`:

| Trigger | Policy | Remote changed | Edits inside region | Result |
|---|---|---|---|---|
| scheduled/folder | manual | any | any | skip (no Google call) |
| scheduled/folder | review | yes | any | hold → `update_available` |
| scheduled/folder | auto | yes | no | apply (merge) |
| scheduled/folder | auto | yes | yes | hold → `update_available` (reason `local_edits`) |
| manual | any | yes/forced | no | apply |
| manual | any | yes/forced | yes | 409 `docsync_wp_local_edits` unless `confirmOverwrite=true` |
| any | any | no | any | existing skip path |

- Hold must not advance stored `google_modified_time` / `google_version` (else the next check sees "unchanged"). Pending remote version kept in `_docsync_wp_pending_remote_version`; used to log once per version.
- Local-edit check needs no Google call: baseline fingerprints (Phase 7) vs current content, evaluated only after the metadata check says remote changed.
- Effective policy: source meta `_docsync_wp_apply_policy` → else by post status (`publish`, `future`, `private` → site setting; others → `auto`).
- Health category: `update_available` counts as attention; health ordering SQL in `SourceRepository` updated.
- Phase 3 digest gains a short "N posts have updates waiting" line (no separate email).

## Related code files

- Create: `src/Sync/ApplyPolicyResolver.php`, `scripts/verify-apply-policy.php`
- Modify: `src/Sync/SyncService.php` (status const, decision call after metadata check, hold path, confirm flag), `src/Sync/SourceRepository.php` (meta keys, health ordering/counts, filter)
- Modify: `src/Rest/SourceController.php` (policy update, `confirmOverwrite`, revision URL in source payload), `src/Rest/WorkspaceController.php` (counts)
- Modify: `src/Settings/SettingsRepository.php` (new-install vs upgrade default), `src/Rest/SettingsController.php`
- Modify: `src/Notifications/FailureDigestCron.php`
- Modify: `resources/js/admin/api/types.ts`, `shared/ui/status-pill.tsx`, `features/sources/sources-table.tsx`, `features/sources/sync-error-recovery.ts`
- Modify: `features/post-sync/post-meta-box-app.tsx`, `use-post-sync-actions.ts`, `shared/ui/confirm-dialog.tsx` usage, `google-setup-sync-defaults-panel.tsx`
- Modify: `src/Admin/PostListActions.php`, `composer.json`, `pr-lint.yml`, `readme.txt`, `README.md`, `docs/system-architecture.md`, `docs/design-guidelines.md`

## Implementation steps

1. Resolver + verify script covering every table row plus effective-policy resolution by post status.
2. Settings: `published_apply_policy` with install-age default (mirror `default_layout_preset` handling).
3. `SyncService`: new status const; call resolver after metadata check; hold path saves status, message, pending version, single event.
4. Manual path: local-edits 409 with `confirmOverwrite` param; REST arg validation.
5. Source payload: `applyPolicy`, `effectiveApplyPolicy`, `latestRevisionUrl` (null when revisions disabled).
6. UI: status pill + filter option; "Apply update" primary action on held rows and in metabox; "View changes" link after apply; policy select in metabox Sync settings ("When the Doc changes": Site default / Apply automatically / Ask me first / Only when I sync); site default select in Setup.
7. Health counts and ordering; digest line.
8. Devcontainer: published post under review → scheduled tick flags, content unchanged, one event across three ticks; apply → content updated, revision link works; edit inside region → scheduled hold, manual confirm.

## Todo

- [x] Resolver + tests
- [x] Settings default by install age
- [x] Hold path in sync flow
- [x] Manual confirm flow
- [x] REST payload + args
- [x] UI: status, actions, policy selects
- [x] Health + digest
- [x] Devcontainer scenarios
- [x] Docs

## Success criteria

- [x] New install: published post is never modified by schedule until "Apply update".
- [x] Upgraded install: behaviour identical to before unless the setting is changed — except edits inside synced content now hold instead of being wiped.
- [x] Three scheduled ticks on a held source log one event and make one metadata call each, no export.
- [x] Every row of the decision table passes in `composer test:apply-policy`.

## Risk assessment

- Local-edit hold changes behaviour for upgraded `auto` installs → deliberate safety change; changelog headline. Owner confirmed upgraded installs otherwise keep auto-apply (2026-10-03).
- Folder watches publishing directly → changes to those posts wait for review on new installs; documented in folder help text.
- Revisions disabled → no "View changes"; state so in UI.

## Security considerations

- `confirmOverwrite` honoured only with the same capability as manual sync. Policy changes require edit capability on the post.

## Next steps

Phase 9 surfaces `update_available` in the Posts list and editor.
