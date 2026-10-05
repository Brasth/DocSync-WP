<?php
/**
 * Verify Doc metadata table extraction fixtures without a WordPress bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once dirname( __DIR__ ) . '/src/Sync/Metadata/DocMetadataTableExtractor.php';

use DocSyncWP\Sync\Metadata\DocMetadataTableExtractor;

$extractor = new DocMetadataTableExtractor();
$root      = dirname( __DIR__ ) . '/tests/fixtures/doc-metadata';
$failures  = 0;
$count     = 0;

foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
	$result   = $extractor->extract( (string) file_get_contents( $dir . '/input.html' ) );
	$expected = json_decode( (string) file_get_contents( $dir . '/fields.json' ), true );
	$html     = (string) file_get_contents( $dir . '/expected.html' );
	++$count;

	// Field order is irrelevant.
	ksort( $expected );
	$actual = $result['fields'];
	ksort( $actual );

	if ( $actual !== $expected || $result['html'] !== $html ) {
		++$failures;
		fwrite( STDERR, 'FAIL ' . basename( $dir ) . "\n  fields: " . json_encode( $actual ) . "\n  html:   " . $result['html'] . "\n" );
	}
}

if ( 0 === $count || $failures > 0 ) {
	fwrite( STDERR, "Doc metadata fixtures failed ({$failures} of {$count}).\n" );
	exit( 1 );
}

echo "Doc metadata fixtures passed ({$count}).\n";
