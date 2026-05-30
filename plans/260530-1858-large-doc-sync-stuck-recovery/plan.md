# Large Doc Sync Stuck Recovery

## Status

Complete in code.

## Context

- Large Google Docs can run through the Docs API fallback after Drive export fails.
- Existing sync state is post meta, exposed through the current source REST response.
- Manual admin syncs already run in background mode and poll source state.
- The source modal is already fullscreen, but its CSS still offsets for the admin bar.

## Scope

1. Add lightweight sync health fields to existing source meta.
2. Detect stale background sync state and convert it into an actionable error.
3. Keep admin polling alive with retry/backoff instead of stopping after one error or ten minutes.
4. Remove admin-bar offset from the post type source modal.
5. Update project docs for the behavior change.

## Result

- Existing source meta now stores sync start time, last heartbeat time, and the latest error code.
- Source polling recovers abandoned `syncing` states when no active lock or pending cron event remains and the heartbeat is stale.
- Admin polling keeps checking long-running syncs at a slower interval and retries transient status failures.
- Post editor progress shows the last heartbeat timestamp.
- The Doc source modal now starts at the top of the viewport.

## Out of Scope

- Custom queue tables.
- Full sync history/audit log.
- Replacing WP-Cron with Action Scheduler.
- Reworking Google Docs conversion fidelity.

## Acceptance Criteria

- A large-doc sync shows last update timing/health through the existing source response.
- A dead `syncing` state becomes `error` when no lock or scheduled event remains and the heartbeat is stale.
- Polling continues slowly after the long-running threshold and tolerates transient REST failures.
- The custom post type detail modal fills the viewport from `top: 0`.
- PHP and frontend compile/type checks pass.

## Files

- `src/Sync/SourceRepository.php`
- `src/Sync/SyncService.php`
- `src/Rest/SourceController.php`
- `resources/js/admin/api/types.ts`
- `resources/js/admin/features/post-sync/background-sync-poller.tsx`
- `resources/js/admin/features/post-sync/post-meta-box-app.tsx`
- `resources/css/components/doc-source-modal.css`
- `docs/project-changelog.md`
- `docs/development-roadmap.md`
