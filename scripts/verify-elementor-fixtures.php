<?php
/**
 * Verify Elementor preset golden fixtures without a full WordPress bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( '__' ) ) {
	function __( string $text ): string {
		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		private string $code;
		private string $message;

		public function __construct( string $code, string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return trim( $url );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $text ): string {
		$text = strip_tags( $text );
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? '';

		return trim( $text );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	function wp_kses( string $html ): string {
		return $html;
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
		unset( $single );
		$fixture_meta = $GLOBALS['docsync_fixture_post_meta'][ $post_id ] ?? array();

		return is_array( $fixture_meta ) ? ( $fixture_meta[ $key ] ?? '' ) : '';
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DocSyncWP\Sync\Elementor\IdGenerator;
use DocSyncWP\Sync\Elementor\LayoutBuilder;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetConversionService;
use DocSyncWP\Sync\Elementor\WidgetFactory;

$manifest_paths = glob( dirname( __DIR__ ) . '/tests/fixtures/elementor-presets/*/manifest.json' );

if ( false === $manifest_paths || array() === $manifest_paths ) {
	fwrite( STDERR, "No Elementor fixture manifests found.\n" );
	exit( 1 );
}

$update_expected = '1' === getenv( 'DOCSYNC_UPDATE_ELEMENTOR_FIXTURES' );
$failures        = 0;

foreach ( $manifest_paths as $manifest_path ) {
	$fixture_dir = dirname( $manifest_path );
	$manifest    = json_decode( (string) file_get_contents( $manifest_path ), true );

	if ( ! is_array( $manifest ) ) {
		fwrite( STDERR, "Invalid manifest: {$manifest_path}\n" );
		++$failures;
		continue;
	}

	$name          = basename( $fixture_dir );
	$preset        = (string) ( $manifest['preset'] ?? '' );
	$seed          = (string) ( $manifest['seed'] ?? $name );
	$post_id       = (int) ( $manifest['postId'] ?? 123 );
	$input_path    = $fixture_dir . '/' . (string) ( $manifest['input'] ?? 'input.html' );
	$expected_path = $fixture_dir . '/' . (string) ( $manifest['expected'] ?? 'expected.json' );
	$post_meta     = $manifest['postMeta'] ?? array();
	$GLOBALS['docsync_fixture_post_meta'] = array(
		$post_id => is_array( $post_meta ) ? $post_meta : array(),
	);
	$ids           = new IdGenerator( $seed );
	$converter     = new ElementorPresetConversionService(
		new WidgetFactory( null, $ids ),
		new LayoutBuilder( null, $ids )
	);
	$actual_json   = $converter->convert( (string) file_get_contents( $input_path ), $post_id, $preset );

	if ( is_wp_error( $actual_json ) ) {
		fwrite( STDERR, "{$name}: {$actual_json->get_error_code()} {$actual_json->get_error_message()}\n" );
		++$failures;
		continue;
	}

	$actual = json_decode( $actual_json, true );

	if ( ! is_array( $actual ) ) {
		fwrite( STDERR, "{$name}: invalid Elementor JSON output\n" );
		++$failures;
		continue;
	}

	$pretty_actual = (string) wp_json_encode( $actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	if ( $update_expected ) {
		file_put_contents( $expected_path, $pretty_actual . "\n" );
		fwrite( STDOUT, "{$name}: updated\n" );
		continue;
	}

	$expected = json_decode( (string) file_get_contents( $expected_path ), true );

	if ( $actual !== $expected ) {
		$pretty_expected = (string) wp_json_encode( $expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		fwrite( STDERR, "{$name}: output mismatch\n\nExpected:\n{$pretty_expected}\n\nActual:\n{$pretty_actual}\n\n" );
		++$failures;
		continue;
	}

	fwrite( STDOUT, "{$name}: ok\n" );
}

exit( $failures > 0 ? 1 : 0 );
