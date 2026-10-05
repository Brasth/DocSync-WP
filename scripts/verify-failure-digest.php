<?php
/**
 * Verify failure digest dedupe and recipient planning without WordPress.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/src/Notifications/FailureDigestPlanner.php';

use DocSyncWP\Notifications\FailureDigestPlanner;

$failures = 0;
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL {$name}\n" );
	}
};

// Dedupe: same post + code recorded once; different code or post kept.
$pending = FailureDigestPlanner::record( array(), 1, 'a', 100 );
$pending = FailureDigestPlanner::record( $pending, 1, 'a', 200 );
$check( 'dedupe same post+code', 1 === count( $pending ) && 100 === $pending[0]['at'] );
$pending = FailureDigestPlanner::record( $pending, 1, 'b', 300 );
$pending = FailureDigestPlanner::record( $pending, 2, 'a', 300 );
$check( 'keeps different code and post', 3 === count( $pending ) );

// Cap keeps newest entries.
$capped = array();
for ( $i = 1; $i <= FailureDigestPlanner::MAX_PENDING + 5; $i++ ) {
	$capped = FailureDigestPlanner::record( $capped, $i, 'x', $i );
}
$check( 'cap', FailureDigestPlanner::MAX_PENDING === count( $capped ) && 6 === $capped[0]['post_id'] );

$owner_of   = static fn ( int $post_id ): int => array( 1 => 10, 2 => 11, 3 => 0 )[ $post_id ] ?? 0;
$can_access = static fn ( int $user_id, int $post_id ): bool => ! ( 11 === $user_id && 2 === $post_id && false );
$entries    = array(
	array( 'post_id' => 1, 'code' => 'a', 'at' => 1 ),
	array( 'post_id' => 2, 'code' => 'a', 'at' => 1 ),
	array( 'post_id' => 3, 'code' => 'a', 'at' => 1 ),
);

$plan = FailureDigestPlanner::plan( $entries, 'owners_and_admin', array( 1 ), $owner_of, $can_access );
$check( 'admin gets all', 3 === count( $plan[1] ) );
$check( 'owner gets own only', 1 === count( $plan[10] ) && 1 === $plan[10][0]['post_id'] && 1 === count( $plan[11] ) );
$check( 'unknown owner skipped', ! isset( $plan[0] ) );

$plan = FailureDigestPlanner::plan( $entries, 'admin', array( 1 ), $owner_of, $can_access );
$check( 'admin mode only admins', array( 1 ) === array_keys( $plan ) );

$plan = FailureDigestPlanner::plan( $entries, 'off', array( 1 ), $owner_of, $can_access );
$check( 'off mode empty', array() === $plan );

$denied = static fn ( int $user_id, int $post_id ): bool => 10 !== $user_id;
$plan   = FailureDigestPlanner::plan( $entries, 'owners_and_admin', array( 1 ), $owner_of, $denied );
$check( 'owner without capability excluded', ! isset( $plan[10] ) && isset( $plan[1] ) );

$plan = FailureDigestPlanner::plan( array(), 'owners_and_admin', array( 1 ), $owner_of, $can_access );
$check( 'empty pending empty plan', array() === $plan );

if ( $failures > 0 ) {
	fwrite( STDERR, "Failure digest checks failed ({$failures}).\n" );
	exit( 1 );
}

echo "Failure digest checks passed.\n";
