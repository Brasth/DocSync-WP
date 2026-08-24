# Phase 1 — Honest folder schedule

**Goal:** A watch interval governs member-Doc re-sync. Site cron drains more than 20 due sources. Weekly is valid everywhere.

**Interfaces produced:**

```php
final class SourceScheduleResolver {
	public const INTERVALS = array( 'off', 'hourly', 'twicedaily', 'daily', 'weekly' );

	public function __construct( SettingsRepository $settings, FolderWatchRepository $watches ) {}

	/** @param array<string,mixed> $source SourceRepository::getSource() row */
	public function resolveInterval( array $source ): string {}

	public function nextSyncAt( string $from_mysql_utc, string $interval ): string {}
}
```

- `SourceRepository::META_NEXT_SYNC = '_docsync_wp_next_sync_at'`
- `SourceRepository::META_SYNC_INTERVAL = '_docsync_wp_sync_interval'` (empty = inherit)
- Source REST adds `syncInterval` (`''|off|hourly|twicedaily|daily|weekly`) and `nextSyncAt` / `effectiveInterval`
- `SyncCron::CONTINUE_HOOK = 'docsync_wp_sync_sources_continue'`
- `FolderWatchService::recomputeMemberSchedules( string $watch_id ): void`

---

### Task 1: Schedule resolver + fixture

**Files:**
- Create: `src/Sync/SourceScheduleResolver.php`
- Create: `scripts/verify-schedule-resolver.php`
- Modify: `composer.json` — add `"test:schedule-resolver": "php scripts/verify-schedule-resolver.php"`

**Interfaces:**
- Consumes: watch record `syncInterval`, settings `sync_interval`
- Produces: `SourceScheduleResolver`

- [ ] **Step 1: Write the failing fixture**

`scripts/verify-schedule-resolver.php` follows `scripts/verify-folder-watch-update.php`: stub only the WP helpers the class needs, `require` the class, assert:

```php
$resolver = new SourceScheduleResolver( $settings, $watches );

// override > watch > site
assert_same( 'hourly', $resolver->resolveInterval( source( interval: 'hourly', watch: 'daily', site: 'weekly' ) ) );
assert_same( 'daily', $resolver->resolveInterval( source( interval: '', watch: 'daily', site: 'weekly' ) ) );
assert_same( 'weekly', $resolver->resolveInterval( source( interval: '', watch: 'site', site: 'weekly' ) ) );
assert_same( 'off', $resolver->resolveInterval( source( interval: '', watch: 'site', site: 'off' ) ) );
assert_same( 'off', $resolver->resolveInterval( source( interval: 'off', watch: 'hourly', site: 'daily' ) ) );

// next due
assert_same( '', $resolver->nextSyncAt( '2026-08-23 12:00:00', 'off' ) );
assert_same( '2026-08-23 13:00:00', $resolver->nextSyncAt( '2026-08-23 12:00:00', 'hourly' ) );
assert_same( '2026-08-24 00:00:00', $resolver->nextSyncAt( '2026-08-23 12:00:00', 'twicedaily' ) );
assert_same( '2026-08-24 12:00:00', $resolver->nextSyncAt( '2026-08-23 12:00:00', 'daily' ) );
assert_same( '2026-08-30 12:00:00', $resolver->nextSyncAt( '2026-08-23 12:00:00', 'weekly' ) );
```

Use `gmdate` + `strtotime` on UTC mysql datetimes. `twicedaily` is 12 hours.

- [ ] **Step 2: Run fixture — expect FAIL**

```sh
php scripts/verify-schedule-resolver.php
```

Expected: class not found / FAIL.

- [ ] **Step 3: Implement the resolver**

Keep the file under 200 lines. Interval seconds map:

```php
private const SECONDS = array(
	'hourly'     => HOUR_IN_SECONDS,
	'twicedaily' => 12 * HOUR_IN_SECONDS,
	'daily'      => DAY_IN_SECONDS,
	'weekly'     => 7 * DAY_IN_SECONDS,
);
```

Resolution:

1. Sanitize source `sync_interval`. If it is a member of `INTERVALS`, return it.
2. Else if `folder_watch_id` is non-empty, load the watch. If watch `syncInterval` is in `INTERVALS` (not `site`), return it.
3. Else return sanitized site `sync_interval`, default `off`.

`nextSyncAt`: `off` or unknown → `''`. Else `gmdate( 'Y-m-d H:i:s', strtotime( $from ) + SECONDS[ $interval ] )`.

- [ ] **Step 4: Run fixture — expect PASS**

```sh
php scripts/verify-schedule-resolver.php
composer test:schedule-resolver
```

- [ ] **Step 5: Commit**

```sh
git add src/Sync/SourceScheduleResolver.php scripts/verify-schedule-resolver.php composer.json
git commit -m "feat: add source schedule resolver with override watch site precedence"
```

---

### Task 2: Persist next sync and source interval

**Files:**
- Modify: `src/Sync/SourceRepository.php`
- Modify: `src/Sync/SyncService.php` (every terminal `last_synced_at` write)
- Modify: `src/Plugin.php` (construct resolver; pass into `SyncService` and `FolderWatchService`)

**Interfaces:**
- Consumes: `SourceScheduleResolver`
- Produces: source row keys `sync_interval`, `next_sync_at`; REST later

Add constants next to `META_LAST_SYNCED`:

```php
public const META_NEXT_SYNC     = '_docsync_wp_next_sync_at';
public const META_SYNC_INTERVAL = '_docsync_wp_sync_interval';
```

`getSource()` / `saveSource()` read and write both. Empty `sync_interval` means inherit.

`SyncService` after each terminal status (`synced`, `skipped`, `error`) that already writes `last_synced_at`:

```php
$interval = $this->schedule->resolveInterval( $source );
$source['next_sync_at'] = $this->schedule->nextSyncAt( current_time( 'mysql', true ), $interval );
```

Do not write a future `next_sync_at` on `off`.

Add `SourceRepository::listPostIdsForFolderWatch( string $watch_id, int $page, int $per_page = 100 ): array` — `meta_query` on `META_FOLDER_WATCH_ID`, `fields => ids`. Used by Task 4.

- [ ] **Step 1: Extend `scripts/verify-schedule-resolver.php`** with a documented expected mapping: after a daily sync at `T`, `next_sync_at === T + 1 day`. Keep this as comments plus a pure helper test if `SyncService` is too heavy to stub; do not boot WordPress here.

- [ ] **Step 2: Implement meta + SyncService writes**

- [ ] **Step 3: `composer lint` and `composer test:schedule-resolver`**

- [ ] **Step 4: Commit**

```sh
git commit -m "feat: persist next_sync_at and per-source interval meta"
```

---

### Task 3: Due query + continuation drain

**Files:**
- Modify: `src/Sync/SourceRepository.php` `queryDueSourceIds()`
- Modify: `src/Cron/SyncCron.php`

**Due query** (replace the `last_synced <= before` clause):

```php
'relation' => 'AND',
'has_source' => array( 'key' => self::META_FILE_ID, 'compare' => 'EXISTS' ),
'due' => array(
	'relation' => 'OR',
	array(
		'key'     => self::META_NEXT_SYNC,
		'value'   => sanitize_text_field( $before ),
		'compare' => '<=',
		'type'    => 'CHAR',
	),
	array(
		'key'     => self::META_NEXT_SYNC,
		'compare' => 'NOT EXISTS',
	),
),
'has_schedule' => array(
	'relation' => 'OR',
	array(
		'key'     => self::META_NEXT_SYNC,
		'value'   => '',
		'compare' => '!=',
	),
	array(
		'key'     => self::META_NEXT_SYNC,
		'compare' => 'NOT EXISTS',
	),
),
'not_syncing' => /* keep existing */,
```

`orderby` uses the `due` clause alias ASC. `NOT EXISTS` covers pre-backfill rows (treated due). Empty `next_sync_at` (`off`) must **not** match the `<=` branch; the `has_schedule` clause drops them. Rows with no meta still match `NOT EXISTS` so they backfill on the next tick.

**Continuation** in `SyncCron`:

```php
public const CONTINUE_HOOK = 'docsync_wp_sync_sources_continue';
private const MAX_CONTINUATIONS = 20;
private const CONTINUE_OPTION = 'docsync_wp_sync_continuations';
```

`register()`: `add_action( self::CONTINUE_HOOK, array( $this, 'runContinue' ) );`

`run()` (recurring tick): reset continuation counter to 0, mark heartbeat, process batch, maybe schedule continue.

`runContinue()`: increment counter; if `> MAX_CONTINUATIONS` return; otherwise same batch as `run()` without resetting the counter.

After a full batch (`count === BATCH_SIZE`) and no pending continue event:

```php
wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK );
if ( function_exists( 'spawn_cron' ) ) {
	spawn_cron();
}
```

`unschedule()` also clears `CONTINUE_HOOK`.

Allow `weekly` in `getInterval()`.

- [ ] **Step 1: Implement due query + continuation**
- [ ] **Step 2: `composer lint`**
- [ ] **Step 3: Commit**

```sh
git commit -m "feat: schedule due sources by next_sync_at with continuation drain"
```

---

### Task 4: Recompute member schedules + weekly site setting

**Files:**
- Modify: `src/Sync/FolderWatchService.php` `create()` and `update()`
- Modify: `src/Settings/SettingsRepository.php` `isValidSyncInterval()`
- Modify: `resources/js/admin/features/google-setup/google-setup-targets-step.tsx`
- Modify: `scripts/verify-folder-watch-update.php`

When create/update changes `syncInterval`, page `listPostIdsForFolderWatch` in 100s and rewrite each member `next_sync_at` from `current_time( 'mysql', true )` using the resolver (source override still wins).

Settings: add `weekly` to `isValidSyncInterval`. Setup select already has Off/Hourly/Twice daily/Daily — add Weekly.

Extend `verify-folder-watch-update.php` with a pure recompute helper test if you extract `FolderWatchService` scheduling into a small helper; otherwise document the interval-change contract in `verify-schedule-resolver.php` using a fake watch repo.

- [ ] **Step 1: Implement recompute + weekly**
- [ ] **Step 2: Run `composer test:folder-watch-update` and `composer test:schedule-resolver`**
- [ ] **Step 3: Commit**

```sh
git commit -m "feat: recompute folder member schedules and allow weekly site interval"
```

---

### Task 5: Source PATCH + metabox + Drive Folders label

**Files:**
- Modify: `src/Rest/SourceController.php` — add `syncInterval` to `update` optional params; sanitize via `SourceScheduleResolver::INTERVALS` or `''` for inherit
- Modify: `src/Sync/SourceRepository.php` `formatSource` / REST mapper — emit `syncInterval`, `effectiveInterval`, `nextSyncAt`
- Modify: `resources/js/admin/api/types.ts` `SourceRecord`
- Modify: `resources/js/admin/features/post-sync/post-meta-box-app.tsx`
- Modify: `resources/js/admin/features/folder-watches/folder-watch-detail-page.tsx` and/or `folder-watch-edit-form.tsx`

Metabox select, only when a source exists:

```tsx
<label className="docsync-wp-field docsync-wp-field--compact">
  <span>{__('Re-sync schedule', 'brasth-document-sync-for-google-docs')}</span>
  <select value={actions.source.syncInterval || ''} onChange={(event) => void actions.updateSource({ syncInterval: event.currentTarget.value })}>
    <option value="">{__('Use folder or site schedule', 'brasth-document-sync-for-google-docs')}</option>
    <option value="off">{__('Off', 'brasth-document-sync-for-google-docs')}</option>
    <option value="hourly">{__('Hourly', 'brasth-document-sync-for-google-docs')}</option>
    <option value="twicedaily">{__('Twice daily', 'brasth-document-sync-for-google-docs')}</option>
    <option value="daily">{__('Daily', 'brasth-document-sync-for-google-docs')}</option>
    <option value="weekly">{__('Weekly', 'brasth-document-sync-for-google-docs')}</option>
  </select>
</label>
<p>{sprintf(__('Effective: %s. Next re-sync %s.', 'brasth-document-sync-for-google-docs'), actions.source.effectiveInterval || __('off', 'brasth-document-sync-for-google-docs'), actions.source.nextSyncAt || __('not scheduled', 'brasth-document-sync-for-google-docs'))}</p>
```

Wire `updateSource` through the existing source PATCH helper in `use-post-sync-actions.ts` (add `syncInterval` to the allowed patch object).

Drive Folders detail: under the schedule field, show:

```text
Member Docs re-sync on this same interval. Next scan: {nextScanAt}.
```

Do not add a second interval control.

- [ ] **Step 1: Backend PATCH + wire fields**
- [ ] **Step 2: Metabox + Drive Folders copy**
- [ ] **Step 3: `pnpm typecheck && pnpm lint`**
- [ ] **Step 4: Commit**

```sh
git commit -m "feat: expose per-source re-sync override in REST and post metabox"
```

---

### Task 6: Upgrade backfill

**Files:**
- Create: `src/Cron/ScheduleBackfill.php` (keep under 200 lines)
- Modify: `src/Plugin.php` `register()` / `boot()`

```php
final class ScheduleBackfill {
	public const HOOK = 'docsync_wp_backfill_next_sync';
	public const OPTION = 'docsync_wp_next_sync_backfill_page';
	private const PAGE_SIZE = 200;

	public function register(): void {
		add_action( 'init', array( $this, 'maybeSchedule' ) );
		add_action( self::HOOK, array( $this, 'runPage' ) );
	}
}
```

`maybeSchedule`: if option `docsync_wp_next_sync_backfill_done` is truthy, return. Else schedule a single event if none pending.

`runPage`: query linked sources missing `META_NEXT_SYNC` (200). For each, set `next_sync_at` from `last_synced_at` or `current_time` using the resolver. If the page was full, schedule another event in 30s. If short, set done.

This is the existing plugin pattern for deferred work: cron single events, not `dbDelta`.

- [ ] **Step 1: Implement backfill**
- [ ] **Step 2: `composer lint`**
- [ ] **Step 3: Commit**

```sh
git commit -m "feat: backfill next_sync_at for existing linked sources"
```

---

### Phase 1 acceptance

- Hourly watch + daily site: member source `effectiveInterval === hourly`; standalone source stays daily.
- Override `off`: `next_sync_at === ''`; excluded from due query; manual sync works.
- Full batch of 20 due sources schedules `docsync_wp_sync_sources_continue`.
- Setup Sync defaults accepts Weekly and `SettingsRepository` persists it.
- `composer lint`, `composer test:schedule-resolver`, `composer test:folder-watch-update`, `pnpm typecheck`, `pnpm lint`.
