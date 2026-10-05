---
phase: 3
title: "Phase 3: Sync failure alerts"
status: todo
priority: P1
effort: "2d"
dependencies: []
---

# Phase 3: Sync failure alerts

## Overview

Unattended sync failures reach a person: one daily email digest and a Site Health test. Introduces the sync `trigger` concept reused by Phase 8.

## Requirements

- Functional:
  - Scheduled and folder-triggered failures collected; one digest per recipient per day; nothing sent when no new failures.
  - Site Health test reports stalled WP-Cron, sources in error, sources whose owner lost Google access.
  - Admin setting: recipients `owners_and_admin` (default) | `admin` | `off`.
- Non-functional: no Google identity, file IDs, or raw upstream errors in email; recipient sees only sources they can edit.

## Architecture

```
SyncService::syncPost( $post_id, $user_id, $force, $trigger = 'manual' )
  markError() → do_action( 'docsync_wp_sync_failed', $post_id, $code, $trigger )
SyncFailureNotifier (listener) → pending list (option, max 50, autoload no)
FailureDigestCron (daily) → group by recipient → wp_mail() → clear pending
SyncHealthSiteStatus → site_status_tests (direct test)
```

- `$trigger`: `manual` | `scheduled` | `folder`. `SyncCron::run()` passes `scheduled`; `FolderWatchRunner` passes `folder`; `runSingle()` (user-queued) stays `manual`. Digest collects `scheduled` and `folder` only.
- Pending entry: `post_id`, `code`, `at`. Same `post_id + code` not re-reported within 24h.
- Recipients: source sync owner (if `userCanSyncPost`) + users with `manage_options` (admin gets all). Admin email fallback: `get_option( 'admin_email' )`.
- Email: plain text, translatable. Per source: post title, existing public error message, link to Sources filtered to errors. Footer: how to turn alerts off.
- Site Health: reuse `CronHeartbeat::snapshot()` and the workspace health counts (extract shared count method from `WorkspaceController` path into `SourceRepository` if not already callable).

## Related code files

- Create: `src/Notifications/SyncFailureNotifier.php`, `src/Notifications/FailureDigestCron.php`
- Create: `src/Admin/SyncHealthSiteStatus.php`
- Create: `scripts/verify-failure-digest.php` (pure grouping/dedupe logic)
- Modify: `src/Sync/SyncService.php` (trigger param, action in `markError`), `src/Cron/SyncCron.php`, `src/Sync/FolderWatchRunner.php`
- Modify: `src/Settings/SettingsRepository.php`, `src/Rest/SettingsController.php`, `src/Plugin.php`, `uninstall.php`
- Modify: `resources/js/admin/features/google-setup/google-setup-sync-defaults-panel.tsx`, `resources/js/admin/api/types.ts`
- Modify: `composer.json`, `.github/workflows/pr-lint.yml`, `readme.txt` (Privacy: emails sent to site users), `docs/system-architecture.md`

## Implementation steps

1. Add `$trigger` param (default `manual`) to `syncPost`; thread through cron and folder runner. No behaviour change yet.
2. Fire `docsync_wp_sync_failed` in `markError`; document as first public action hook.
3. Implement notifier: bounded pending option, dedupe window.
4. Implement digest cron: schedule daily on `init` when alerts enabled and OAuth configured; unschedule otherwise; clear on uninstall.
5. Build recipient grouping as pure function; verify script covers owner-only, admin-only, owner-without-capability, dedupe, empty.
6. Add `failure_alerts` setting: default, sanitize, public payload, Setup select with help text.
7. Register Site Health test; statuses `good` / `recommended` / `critical` (critical only for stalled cron with active schedules).
8. Devcontainer: break a source (revoke access), run `wp cron event run docsync_wp_sync_sources`, then the digest hook; confirm one mail (mail catcher or `wp_mail` log) and Site Health output.

## Todo

- [x] Trigger param threaded
- [x] Failure action hook
- [x] Notifier + digest cron
- [x] Recipient scoping verified
- [x] Setting + Setup UI
- [x] Site Health test
- [x] Uninstall cleanup
- [x] Docs + readme privacy text

## Success criteria

- [x] Scheduled failure → exactly one email to owner and admin within one digest cycle; repeat failure same day → no second email.
- [x] Manual sync failure in UI → no email.
- [x] Setting `off` → no schedule registered.
- [x] Site Health shows actionable item with link to Sources.

## Risk assessment

- Host blocks `wp_mail` → Site Health test is the second channel; mention in help text.
- Mass failure (revoked token, 200 sources) → pending capped at 50; email states "and N more".

## Security considerations

- Email body built from post titles and existing sanitized messages only. Capability check per recipient per source prevents cross-user disclosure.

## Next steps

Phase 8 reuses `$trigger`. Folder automation plan Phase 4 reuses the notifier.
