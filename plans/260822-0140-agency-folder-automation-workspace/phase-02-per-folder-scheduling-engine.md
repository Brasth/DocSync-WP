# Phase 2 — Per-folder scheduling engine

## Objective

Make the folder schedule govern **content re-sync** of that folder's Docs, not just discovery of new ones. Remove the 20-per-tick backlog ceiling. Kills gaps #2 and #3.

## Design

### 1. `next_sync_at` per source

- New meta `SourceRepository::META_NEXT_SYNC = '_docsync_wp_next_sync_at'` (UTC mysql string, matches `META_LAST_SYNCED` format).
- Effective interval resolution (new `SourceScheduleResolver`, `src/Sync/`):
  1. per-source override meta `_docsync_wp_sync_interval` when set (`off|hourly|twicedaily|daily|weekly`)
  2. else watch interval via `_docsync_wp_folder_watch_id` → watch record (non-`site` value)
  3. else site `sync_interval`
- `SyncService::syncPost` completion paths (synced, skipped, error) write `next_sync_at = now + interval` (error path: same cadence; hard errors already flip status and are excluded by the due query's `not_syncing` guard semantics — keep retrying on schedule so transient Google errors self-heal).

### 2. Due query

- `SourceRepository::queryDueSourceIds`: replace `last_synced <= before` clause with `next_sync_at <= now OR next_sync_at NOT EXISTS` (NOT EXISTS covers pre-migration rows), keep ordering by `next_sync_at ASC`.
- `off` effective interval writes empty `next_sync_at` and adds `next_sync_at != ''` guard → source drops out of scheduling without status change.

### 3. Continuation draining

- `SyncCron::run`: after processing a batch, if the batch was full (`count === BATCH_SIZE`), schedule `wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK )` (new hook `docsync_wp_sync_sources_continue`, handler = `run`) + `spawn_cron()`.
- Guard: skip scheduling when a continue event is already queued; hard stop after `MAX_CONTINUATIONS = 20` chained events per interval window (option-stored counter reset by the recurring tick) so a broken source can't loop forever.

### 4. Watch interval semantics

- `FolderWatchService::update()` (Phase 1) and `create()`: when interval changes, bulk-recompute `next_sync_at` for member sources (query by `META_FOLDER_WATCH_ID`, capped pages of 100).
- Scan schedule keeps using the same watch interval — one knob, two effects (discover + refresh), which is what users expect.

### 5. New intervals

- Add `weekly` via `cron_schedules` filter (`docsync_wp_weekly`, 7 × DAY_IN_SECONDS). Allowed everywhere intervals are validated: `SyncCron::getInterval`, `FolderWatchService::sanitizeWatchInterval`, settings sanitize, UI selects.
- 15-minute interval NOT added (free plugin on shared hosting; hourly floor keeps support load sane). Revisit only with real demand.

### 6. Per-source override UI

- Post sync metabox (`post-meta-box-app.tsx`) + source detail: select `Use folder/site schedule | Off | Hourly | Twice daily | Daily | Weekly`; PATCH via existing source update route (`SourceController`).

### 7. Migration

- Version-gated upgrade routine (follow existing pattern in `Plugin.php`): backfill `next_sync_at = last_synced_at` (due immediately if stale) for all linked sources, batched 200/tick via single cron event to avoid timeout on big sites.
- Keep the legacy `last_synced` clause as OR-fallback for one release, then remove.

## Acceptance

- Watch set hourly, site daily: member Doc edited in Google → WP updates within ~1h; non-watch source untouched until daily tick.
- 300 due sources, batch 20: all synced within one interval via continuation chain (observe `docsync_wp_sync_sources_continue` events).
- Source override `off` → never auto-syncs; manual sync still works and does not re-enter schedule.
- Upgrade from current release: no source loses scheduling; backfill completes on cron.

## Verification

- New PHP test script `scripts/verify-schedule-resolver.php` (composer script `test:schedule-resolver`): resolver precedence matrix (override > watch > site), next-due arithmetic, off handling.
- Existing suites: `composer lint`, `composer test:*` fixtures unaffected, `pnpm typecheck && pnpm lint && pnpm build`.
- Devcontainer: WP-CLI `wp cron event list` before/after interval edits.

## Risks

- Meta-query cost: `next_sync_at` is a `CHAR` compare like `last_synced` today — same query shape, no regression. Index pressure fine at expected volume.
- Continuation storms: bounded by MAX_CONTINUATIONS + `SyncLock` per post + `not_syncing` exclusion.
- Two sources of truth during fallback release: due query OR-clause documented and removed next release.
