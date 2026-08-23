<?php
/**
 * Verify folder-watch update helpers without a full WordPress test suite.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['docsync_wp_test_options'] = array();

/**
 * Option getter stub.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['docsync_wp_test_options'][ $name ] ?? $default;
}

/**
 * Option updater stub.
 *
 * @param string $name  Option name.
 * @param mixed  $value Option value.
 */
function update_option( string $name, mixed $value, mixed ...$args ): bool {
	unset( $args );
	$GLOBALS['docsync_wp_test_options'][ $name ] = $value;

	return true;
}

/**
 * Absolute integer stub.
 *
 * @param mixed $value Value.
 */
function absint( mixed $value ): int {
	return abs( (int) $value );
}

/**
 * Sanitize key stub.
 *
 * @param string $key Key.
 */
function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $key ) ) ?? '';
}

/**
 * Sanitize text stub.
 *
 * @param string $value Value.
 */
function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

require_once __DIR__ . '/../src/Cron/CronHeartbeat.php';
require_once __DIR__ . '/../src/Sync/FolderWatchReconciler.php';

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

$heartbeat = new DocSyncWP\Cron\CronHeartbeat();
$now       = 1_700_000_000;

$fresh_baseline = $heartbeat->snapshot( array( 'hourly' ), $now );
docsync_wp_assert_same( '', $fresh_baseline['lastRunAt'], 'Unused heartbeat lastRunAt' );
docsync_wp_assert_same( false, $fresh_baseline['stalled'], 'Fresh monitoring baseline is not stalled yet' );

$GLOBALS['docsync_wp_test_options'][ DocSyncWP\Cron\CronHeartbeat::BASELINE_OPTION_NAME ] = $now - ( 3 * HOUR_IN_SECONDS );
$never_ran = $heartbeat->snapshot( array( 'hourly' ), $now );
docsync_wp_assert_same( true, $never_ran['stalled'], 'No heartbeat stalls after monitoring threshold' );

$heartbeat->mark( $now - ( 3 * HOUR_IN_SECONDS ) );
$hourly_stall = $heartbeat->snapshot( array( 'hourly' ), $now );
docsync_wp_assert_same( true, $hourly_stall['stalled'], 'Hourly watch stalls after 2x interval' );
docsync_wp_assert_same( gmdate( 'c', $now - ( 3 * HOUR_IN_SECONDS ) ), $hourly_stall['lastRunAt'], 'Heartbeat lastRunAt is ISO' );

$heartbeat->mark( $now - ( 3 * HOUR_IN_SECONDS ) );
$daily = $heartbeat->snapshot( array( 'daily' ), $now );
docsync_wp_assert_same( false, $daily['stalled'], 'Daily watch uses 48h stall window' );

$heartbeat->mark( $now - ( 90 * 60 ) );
$fresh = $heartbeat->snapshot( array( 'hourly' ), $now );
docsync_wp_assert_same( false, $fresh['stalled'], 'Fresh hourly run is not stalled' );

$off_only = $heartbeat->snapshot( array( 'off', 'site' ), $now - ( 3 * HOUR_IN_SECONDS ) );
docsync_wp_assert_same( false, $off_only['stalled'], 'No active interval does not stall' );

$reconciler = new DocSyncWP\Sync\FolderWatchReconciler();

docsync_wp_assert_same(
	array( 'keep-pending' ),
	$reconciler->reconcilePending(
		array( 'keep-pending', 'newly-excluded', 'already-linked' ),
		array( 'newly-excluded' ),
		array( 'keep-pending', 'newly-excluded', 'already-linked' ),
		array( 'already-linked' )
	),
	'Exclude drops pending IDs'
);

$after_include = $reconciler->reconcilePending(
	array( 'keep-pending' ),
	array(),
	array( 'keep-pending', 'newly-included', 'already-linked' ),
	array( 'already-linked' )
);
sort( $after_include );
docsync_wp_assert_same(
	array( 'keep-pending', 'newly-included' ),
	$after_include,
	'Newly included unlinked Docs re-enter pending'
);

docsync_wp_assert_same(
	array( 'root-doc' ),
	$reconciler->reconcilePending(
		array( 'root-doc', 'subfolder-doc' ),
		array(),
		array( 'root-doc' ),
		array()
	),
	'Out-of-scope pending IDs drop when subfolders turn off'
);

docsync_wp_assert_same(
	array( 'root-doc' ),
	$reconciler->reconcilePending(
		array(),
		array(),
		array( 'root-doc' ),
		array()
	),
	'Unlinked in-scope Docs enter pending'
);

$controller = file_get_contents( __DIR__ . '/../src/Rest/FolderWatchController.php' );

if ( ! is_string( $controller ) ) {
	docsync_wp_fail( 'FolderWatchController is unreadable' );
}

if ( ! str_contains( $controller, 'WP_REST_Server::EDITABLE' ) || ! str_contains( $controller, 'updateWatch' ) ) {
	docsync_wp_fail( 'PATCH update route is missing' );
}

if ( ! str_contains( $controller, "permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' )" ) ) {
	docsync_wp_fail( 'Folder watch routes must stay nonce-authenticated' );
}

$service = file_get_contents( __DIR__ . '/../src/Sync/FolderWatchService.php' );

if ( ! is_string( $service ) || ! str_contains( $service, 'function update(' ) || ! str_contains( $service, 'requireWatch' ) ) {
	docsync_wp_fail( 'update() must reuse requireWatch owner-or-admin access' );
}

if ( ! str_contains( $service, 'function recomputeMemberSchedules(' ) ) {
	docsync_wp_fail( 'Interval edits must recompute member next_sync_at' );
}

echo "Folder watch update helpers passed." . PHP_EOL;
