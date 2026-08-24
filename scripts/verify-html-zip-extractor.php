<?php
/**
 * Verify HTML ZIP extractor can walk extracted files in a namespaced class.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../' );

/**
 * Fail the script with a message.
 *
 * @param string $message Failure message.
 */
function docsync_wp_fail( string $message ): void {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

require_once __DIR__ . '/../src/Sync/HtmlZipPackageExtractor.php';

$temp_dir = sys_get_temp_dir() . '/docsync-wp-zip-walk-' . bin2hex( random_bytes( 8 ) );
$nested   = $temp_dir . '/images';

if ( ! mkdir( $nested, 0777, true ) && ! is_dir( $nested ) ) {
	docsync_wp_fail( 'Could not create extractor walk fixture directory.' );
}

$html_path = $temp_dir . '/index.html';
$image     = $nested . '/photo.png';

if ( false === file_put_contents( $html_path, '<p>ok</p>' ) || false === file_put_contents( $image, 'x' ) ) {
	docsync_wp_fail( 'Could not write extractor walk fixture files.' );
}

$extractor = new DocSyncWP\Sync\HtmlZipPackageExtractor();
$walk      = new ReflectionMethod( $extractor, 'walkFiles' );
$walk->setAccessible( true );

$found = array();

try {
	foreach ( $walk->invoke( $extractor, $temp_dir ) as $path ) {
		$found[] = $path;
	}
} catch ( Throwable $error ) {
	array_map( 'unlink', array_filter( array( $html_path, $image ) ) );
	@rmdir( $nested );
	@rmdir( $temp_dir );
	docsync_wp_fail( 'walkFiles threw: ' . $error->getMessage() );
}

array_map( 'unlink', array_filter( array( $html_path, $image ) ) );
@rmdir( $nested );
@rmdir( $temp_dir );

$normalized = array_map( 'strtolower', $found );
sort( $normalized );

if ( 2 !== count( $found ) ) {
	docsync_wp_fail( 'walkFiles expected 2 files, got ' . (string) count( $found ) );
}

if ( ! in_array( strtolower( $html_path ), $normalized, true ) || ! in_array( strtolower( $image ), $normalized, true ) ) {
	docsync_wp_fail( 'walkFiles missed fixture files.' );
}

fwrite( STDOUT, "html-zip-extractor-walk: ok\n" );
