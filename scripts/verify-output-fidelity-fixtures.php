<?php
/**
 * Verify output-fidelity fixtures (Google redirect link cleaning) without a WordPress bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse URL shim.
	 *
	 * @param string $url       URL.
	 * @param int    $component Component constant.
	 */
	function wp_parse_url( string $url, int $component = -1 ): mixed {
		return parse_url( $url, $component );
	}
}

require_once dirname( __DIR__ ) . '/src/Sync/HtmlGoogleRedirectLinkCleaner.php';

use DocSyncWP\Sync\HtmlGoogleRedirectLinkCleaner;

$cleaner  = new HtmlGoogleRedirectLinkCleaner();
$root     = dirname( __DIR__ ) . '/tests/fixtures/output-fidelity';
$failures = 0;
$count    = 0;

foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
	$input    = (string) file_get_contents( $dir . '/input.html' );
	$expected = (string) file_get_contents( $dir . '/expected.html' );
	$actual   = $cleaner->clean( $input );
	++$count;

	if ( $actual !== $expected ) {
		++$failures;
		fwrite( STDERR, 'FAIL ' . basename( $dir ) . "\n  expected: {$expected}\n  actual:   {$actual}\n" );
	}
}

if ( 0 === $count || $failures > 0 ) {
	fwrite( STDERR, "Output fidelity fixtures failed ({$failures} of {$count}).\n" );
	exit( 1 );
}

echo "Output fidelity fixtures passed ({$count}).\n";
