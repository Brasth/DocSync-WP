<?php
/**
 * Verify ZIP package safety limits without a WordPress bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Translation shim.
	 *
	 * @param string $text Text.
	 */
	function __( string $text ): string {
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error shim.
	 */
	final class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Constructor.
		 *
		 * @param string $code Error code.
		 */
		public function __construct( string $code ) {
			$this->code = $code;
		}

		/**
		 * Get error code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * Error check shim.
	 *
	 * @param mixed $value Value.
	 */
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

require_once dirname( __DIR__ ) . '/src/Sync/HtmlZipPackageExtractor.php';

use DocSyncWP\Sync\HtmlZipPackageExtractor;

$extractor = new HtmlZipPackageExtractor();
$method    = new ReflectionMethod( $extractor, 'validateZipPaths' );
$failures  = 0;

/**
 * Build a ZIP in a temp file and open it.
 *
 * @param array<string,string> $entries Entry name => contents.
 */
$open = static function ( array $entries ): ZipArchive {
	$path = tempnam( sys_get_temp_dir(), 'docsync-zip-' ) . '.zip';
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

	foreach ( $entries as $name => $contents ) {
		$zip->addFromString( $name, $contents );
	}

	$zip->close();
	$zip = new ZipArchive();
	$zip->open( $path );

	return $zip;
};

$expect = static function ( string $name, ZipArchive $zip, ?string $code ) use ( $method, $extractor, &$failures ): void {
	$result = $method->invoke( $extractor, $zip );
	$actual = is_wp_error( $result ) ? $result->get_error_code() : null;

	if ( $actual !== $code ) {
		++$failures;
		fwrite( STDERR, 'FAIL ' . $name . ' expected ' . var_export( $code, true ) . ' got ' . var_export( $actual, true ) . "\n" );
	}
};

$expect( 'valid export', $open( array( 'index.html' => '<p>x</p>', 'images/image1.png' => 'png' ) ), null );
$expect( 'path traversal', $open( array( '../evil.html' => 'x' ) ), 'docsync_wp_zip_path_invalid' );
$expect( 'absolute path', $open( array( '/etc/x.html' => 'x' ) ), 'docsync_wp_zip_path_invalid' );

$many = array();
for ( $i = 0; $i <= HtmlZipPackageExtractor::MAX_ENTRIES; $i++ ) {
	$many[ 'f' . $i . '.txt' ] = 'x';
}
$expect( 'too many entries', $open( $many ), 'docsync_wp_zip_too_large' );

$expect( 'oversized entry', $open( array( 'big.bin' => str_repeat( "\0", HtmlZipPackageExtractor::MAX_ENTRY_BYTES + 1 ) ) ), 'docsync_wp_zip_too_large' );

$chunk = str_repeat( "\0", HtmlZipPackageExtractor::MAX_ENTRY_BYTES );
$expect( 'oversized total', $open( array( 'a.bin' => $chunk, 'b.bin' => $chunk, 'c.bin' => $chunk, 'd.bin' => $chunk, 'e.bin' => 'x' ) ), 'docsync_wp_zip_too_large' );

if ( $failures > 0 ) {
	fwrite( STDERR, "ZIP package limit checks failed ({$failures}).\n" );
	exit( 1 );
}

echo "ZIP package limit checks passed.\n";
