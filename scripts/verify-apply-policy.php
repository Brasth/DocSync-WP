<?php
/**
 * Verify apply policy decisions without WordPress.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/src/Sync/ApplyPolicyResolver.php';

use DocSyncWP\Sync\ApplyPolicyResolver as R;

$failures = 0;
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL {$name}\n" );
	}
};

// Effective policy.
$check( 'override wins', 'manual' === R::effective( 'manual', 'review', 'draft' ) );
$check( 'draft defaults to auto', 'auto' === R::effective( '', 'review', 'draft' ) );
$check( 'pending defaults to auto', 'auto' === R::effective( '', 'review', 'pending' ) );
$check( 'published uses site review', 'review' === R::effective( '', 'review', 'publish' ) );
$check( 'private uses site review', 'review' === R::effective( '', 'review', 'private' ) );
$check( 'scheduled uses site auto', 'auto' === R::effective( '', 'auto', 'future' ) );
$check( 'invalid override ignored', 'review' === R::effective( 'bogus', 'review', 'publish' ) );
$check( 'invalid site policy falls back', 'auto' === R::effective( '', 'weird', 'publish' ) );

// Decision table.
$rows = array(
	array( 'manual', true, true, R::SKIP ),
	array( 'manual', false, false, R::SKIP ),
	array( 'review', true, false, R::HOLD ),
	array( 'review', true, true, R::HOLD ),
	array( 'review', false, false, R::APPLY ),
	array( 'auto', true, false, R::APPLY ),
	array( 'auto', true, true, R::HOLD ),
	array( 'auto', false, true, R::APPLY ),
	array( 'auto', false, false, R::APPLY ),
);

foreach ( $rows as $row ) {
	$check( sprintf( 'decide(%s, remote=%d, local=%d)', $row[0], $row[1], $row[2] ), R::decide( $row[0], $row[1], $row[2] ) === $row[3] );
}

// Contract SyncService::syncPost() relies on: probe the policy with "nothing changed" and skip only
// when the answer is SKIP. Pinned in both directions so the scheduled guard cannot drift.
$check( 'manual never applies on a schedule', R::SKIP === R::decide( 'manual', false, false ) && R::SKIP === R::decide( 'manual', true, true ) );
$check( 'review and auto still run on a schedule', R::APPLY === R::decide( 'review', false, false ) && R::APPLY === R::decide( 'auto', false, false ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "Apply policy checks failed ({$failures}).\n" );
	exit( 1 );
}

echo "Apply policy checks passed.\n";
