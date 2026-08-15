<?php
/**
 * Verify layout preset golden fixtures without a full WordPress bootstrap.
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
	 * Minimal WP_Error shim for converter tests.
	 */
	final class WP_Error {
		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( string $code, string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Get error code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Get error message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * WP_Error check shim.
	 *
	 * @param mixed $value Value.
	 */
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encoding shim.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options JSON options.
	 * @param int   $depth   Max depth.
	 * @return string|false
	 */
	function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * HTML escaping shim.
	 *
	 * @param string $text Text.
	 */
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Attribute escaping shim.
	 *
	 * @param string $text Text.
	 */
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * URL escaping shim.
	 *
	 * @param string $url URL.
	 */
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * Raw URL escaping shim.
	 *
	 * @param string $url URL.
	 */
	function esc_url_raw( string $url ): string {
		return trim( $url );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * Key sanitization shim.
	 *
	 * @param string $key Key.
	 */
	function sanitize_key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Text sanitization shim.
	 *
	 * @param string $text Text.
	 */
	function sanitize_text_field( string $text ): string {
		$text = strip_tags( $text );
		$text = preg_replace( '/[\r\n\t ]+/', ' ', $text ) ?? '';

		return trim( $text );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	/**
	 * Textarea sanitization shim.
	 *
	 * @param string $text Text.
	 */
	function sanitize_textarea_field( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip HTML tags for fixture emptiness checks.
	 *
	 * @param string $text Text.
	 */
	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'wp_kses' ) ) {
	/**
	 * Fixture HTML is trusted; production sanitization is covered by WordPress.
	 *
	 * @param string $html HTML.
	 */
	function wp_kses( string $html ): string {
		return $html;
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	/**
	 * Serialize block arrays for deterministic fixture checks.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 */
	function serialize_blocks( array $blocks ): string {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}

	/**
	 * Serialize one block.
	 *
	 * @param array<string,mixed> $block Block.
	 */
	function serialize_block( array $block ): string {
		$name = (string) ( $block['blockName'] ?? '' );

		if ( str_starts_with( $name, 'core/' ) ) {
			$name = substr( $name, 5 );
		}

		$attrs = empty( $block['attrs'] )
			? ''
			: ' ' . (string) wp_json_encode( $block['attrs'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$inner_content = $block['innerContent'] ?? null;
		$inner_blocks  = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
		$body          = '';

		if ( is_array( $inner_content ) ) {
			$child_index = 0;

			foreach ( $inner_content as $chunk ) {
				if ( null === $chunk ) {
					$child = $inner_blocks[ $child_index ] ?? null;
					++$child_index;

					if ( is_array( $child ) ) {
						$body .= serialize_block( $child );
					}

					continue;
				}

				$body .= (string) $chunk;
			}
		} else {
			$body = (string) ( $block['innerHTML'] ?? '' );
		}

		return '<!-- wp:' . $name . $attrs . ' -->' . $body . '<!-- /wp:' . $name . ' -->';
	}
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\HtmlToBlockContentConverter;
use DocSyncWP\Sync\Layout\LayoutConversionService;

$converter = new LayoutConversionService(
	new SettingsRepository( new EncryptionService() ),
	new HtmlToBlockContentConverter()
);

$manifest_paths = glob( dirname( __DIR__ ) . '/tests/fixtures/layout-presets/*/manifest.json' );

if ( false === $manifest_paths || array() === $manifest_paths ) {
	fwrite( STDERR, "No layout fixture manifests found.\n" );
	exit( 1 );
}

$failures = 0;

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
	$source        = $manifest['source'] ?? null;
	$input_path    = $fixture_dir . '/' . (string) ( $manifest['input'] ?? 'input.html' );
	$expected_path = $fixture_dir . '/' . (string) ( $manifest['expected'] ?? 'expected.html' );
	$input         = (string) file_get_contents( $input_path );
	$expected      = rtrim( (string) file_get_contents( $expected_path ) );
	$actual        = is_array( $source ) ? $converter->convertForSource( $input, $source ) : $converter->convert( $input, $preset );

	if ( is_wp_error( $actual ) ) {
		fwrite( STDERR, "{$name}: {$actual->get_error_code()} {$actual->get_error_message()}\n" );
		++$failures;
		continue;
	}

	$actual = rtrim( $actual );

	if ( $actual !== $expected ) {
		fwrite( STDERR, "{$name}: output mismatch\n\nExpected:\n{$expected}\n\nActual:\n{$actual}\n\n" );
		++$failures;
		continue;
	}

	fwrite( STDOUT, "{$name}: ok\n" );
}

exit( $failures > 0 ? 1 : 0 );
