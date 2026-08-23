<?php
/**
 * Verify source schedule resolver precedence and due arithmetic.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

/**
 * Fail the script with a message.
 *
 * @param string $message Failure message.
 */
function docsync_wp_fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

/**
 * Compare two values.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual   Actual value.
 * @param string $message  Failure message.
 */
function docsync_wp_assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		docsync_wp_fail( $message . ' expected ' . var_export( $expected, true ) . ' got ' . var_export( $actual, true ) );
	}
}

require_once __DIR__ . '/../src/Sync/SourceScheduleResolver.php';

/**
 * Build a source row for resolver tests.
 *
 * @param string $interval Source override interval.
 * @param string $watch_id Folder watch ID.
 * @return array<string,mixed>
 */
function docsync_wp_test_source( string $interval, string $watch_id = '' ): array {
	return array(
		'sync_interval'   => $interval,
		'folder_watch_id' => $watch_id,
	);
}

$resolver = 'DocSyncWP\\Sync\\SourceScheduleResolver';

docsync_wp_assert_same(
	'hourly',
	$resolver::resolve( docsync_wp_test_source( 'hourly', 'watch-1' ), array( 'syncInterval' => 'daily' ), 'weekly' ),
	'Source override beats watch and site'
);
docsync_wp_assert_same(
	'daily',
	$resolver::resolve( docsync_wp_test_source( '', 'watch-1' ), array( 'syncInterval' => 'daily' ), 'weekly' ),
	'Watch interval beats site when source inherits'
);
docsync_wp_assert_same(
	'weekly',
	$resolver::resolve( docsync_wp_test_source( '', 'watch-1' ), array( 'syncInterval' => 'site' ), 'weekly' ),
	'Site interval used when watch is site'
);
docsync_wp_assert_same(
	'off',
	$resolver::resolve( docsync_wp_test_source( '', '' ), null, 'off' ),
	'Site off when no watch'
);
docsync_wp_assert_same(
	'off',
	$resolver::resolve( docsync_wp_test_source( 'off', 'watch-1' ), array( 'syncInterval' => 'hourly' ), 'daily' ),
	'Source off beats hourly watch'
);

docsync_wp_assert_same( '', $resolver::nextSyncAt( '2026-08-23 12:00:00', 'off' ), 'Off has no next due' );
docsync_wp_assert_same( '2026-08-23 13:00:00', $resolver::nextSyncAt( '2026-08-23 12:00:00', 'hourly' ), 'Hourly next due' );
docsync_wp_assert_same( '2026-08-24 00:00:00', $resolver::nextSyncAt( '2026-08-23 12:00:00', 'twicedaily' ), 'Twice-daily next due' );
docsync_wp_assert_same( '2026-08-24 12:00:00', $resolver::nextSyncAt( '2026-08-23 12:00:00', 'daily' ), 'Daily next due' );
docsync_wp_assert_same( '2026-08-30 12:00:00', $resolver::nextSyncAt( '2026-08-23 12:00:00', 'weekly' ), 'Weekly next due' );
docsync_wp_assert_same( 'hourly', $resolver::finest( array( 'daily', 'hourly', 'weekly' ) ), 'Finest among mixed intervals is hourly' );
docsync_wp_assert_same( 'daily', $resolver::finest( array( 'weekly', 'off', 'daily' ) ), 'Off is ignored when finding finest' );
docsync_wp_assert_same( 'off', $resolver::finest( array( 'off', 'site', '' ) ), 'No active interval stays off' );
docsync_wp_assert_same(
	'2026-08-21 12:00:00',
	$resolver::nextSyncAt( '2026-08-20 12:00:00', 'daily' ),
	'Backfill from last_synced stays due when overdue'
);

$repository = file_get_contents( __DIR__ . '/../src/Sync/SourceRepository.php' );

if ( ! is_string( $repository ) || ! str_contains( $repository, 'META_NEXT_SYNC' ) || ! str_contains( $repository, 'listPostIdsForFolderWatch' ) ) {
	docsync_wp_fail( 'SourceRepository must persist next_sync_at and list folder-watch members' );
}

$cron = file_get_contents( __DIR__ . '/../src/Cron/SyncCron.php' );

if ( ! is_string( $cron ) || ! str_contains( $cron, 'CONTINUE_HOOK' ) || ! str_contains( $cron, 'docsync_wp_sync_sources_continue' ) ) {
	docsync_wp_fail( 'SyncCron must drain due sources with a continuation hook' );
}

if ( ! is_string( $cron ) || ! str_contains( $cron, 'finestActiveInterval' ) ) {
	docsync_wp_fail( 'SyncCron must tick at the finest active watch or site interval' );
}

$service = file_get_contents( __DIR__ . '/../src/Sync/FolderWatchService.php' );

if ( ! is_string( $service ) || ! str_contains( $service, 'function recomputeMemberSchedules(' ) ) {
	docsync_wp_fail( 'FolderWatchService must recompute member schedules' );
}

if ( ! is_string( $service ) || ! preg_match( '/function syncAllSchedules\(\): void \{\s+foreach \( \$this->watches->all\(\) as \$watch \) \{\s+\$this->syncWatchSchedule\( \$watch \);\s+\}\s+\}/s', $service ) ) {
	docsync_wp_fail( 'syncAllSchedules must only reconcile scan events, not rewrite member next_sync_at' );
}

if ( ! is_string( $repository ) || ! str_contains( $repository, '$previous !== $sync_interval' ) ) {
	docsync_wp_fail( 'saveSource must recompute next_sync_at only when the override interval changes' );
}

echo "Source schedule resolver passed." . PHP_EOL;
