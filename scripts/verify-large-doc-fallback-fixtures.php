<?php
/**
 * Verify large-doc fallback partial writes honor selected Elementor presets.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace Elementor {
	/**
	 * Minimal active-Elementor marker for compatibility checks.
	 */
	final class Plugin {
		/**
		 * Return a plugin instance.
		 */
		public static function instance(): self {
			return new self();
		}
	}
}

namespace {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );

	if ( ! function_exists( '__' ) ) {
		function __( string $text ): string {
			return $text;
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

	if ( ! function_exists( 'sanitize_textarea_field' ) ) {
		function sanitize_textarea_field( string $text ): string {
			return trim( strip_tags( $text ) );
		}
	}

	if ( ! function_exists( 'wp_kses' ) ) {
		function wp_kses( string $html ): string {
			return $html;
		}
	}

	if ( ! function_exists( 'wp_kses_post' ) ) {
		function wp_kses_post( string $html ): string {
			return $html;
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
			return json_encode( $data, $options, $depth );
		}
	}

	if ( ! function_exists( 'wp_slash' ) ) {
		function wp_slash( mixed $value ): mixed {
			return $value;
		}
	}

	if ( ! function_exists( 'is_wp_error' ) ) {
		function is_wp_error( mixed $value ): bool {
			return $value instanceof WP_Error;
		}
	}

	if ( ! function_exists( 'absint' ) ) {
		function absint( mixed $value ): int {
			return abs( (int) $value );
		}
	}

	if ( ! function_exists( 'current_time' ) ) {
		function current_time( string $type, bool $gmt = false ): string {
			unset( $type, $gmt );

			return gmdate( 'Y-m-d H:i:s' );
		}
	}

	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-' . substr( hash( 'sha256', uniqid( '', true ) ), 0, 12 );
		}
	}

	if ( ! function_exists( 'get_option' ) ) {
		function get_option( string $option, mixed $default = false ): mixed {
			global $docsync_wp_test_options;

			return $docsync_wp_test_options[ $option ] ?? $default;
		}
	}

	if ( ! function_exists( 'update_option' ) ) {
		function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
			unset( $autoload );
			global $docsync_wp_test_options;

			$docsync_wp_test_options[ $option ] = $value;

			return true;
		}
	}

	if ( ! function_exists( 'delete_option' ) ) {
		function delete_option( string $option ): bool {
			global $docsync_wp_test_options;
			unset( $docsync_wp_test_options[ $option ] );

			return true;
		}
	}

	if ( ! function_exists( 'wp_cache_delete' ) ) {
		function wp_cache_delete( string $key, string $group = '' ): bool {
			unset( $key, $group );

			return true;
		}
	}

	if ( ! function_exists( 'metadata_exists' ) ) {
		function metadata_exists( string $type, int $object_id, string $meta_key ): bool {
			global $docsync_wp_test_post_meta;

			return 'post' === $type && array_key_exists( $meta_key, $docsync_wp_test_post_meta[ $object_id ] ?? array() );
		}
	}

	if ( ! function_exists( 'get_post_meta' ) ) {
		function get_post_meta( int $post_id, string $key = '', bool $single = false ): mixed {
			global $docsync_wp_test_post_meta;

			if ( '' === $key ) {
				return $docsync_wp_test_post_meta[ $post_id ] ?? array();
			}

			$value = $docsync_wp_test_post_meta[ $post_id ][ $key ] ?? '';

			if ( $single ) {
				return $value;
			}

			return '' === $value ? array() : array( $value );
		}
	}

	if ( ! function_exists( 'update_post_meta' ) ) {
		function update_post_meta( int $post_id, string $key, mixed $value ): bool {
			global $docsync_wp_test_post_meta;

			$docsync_wp_test_post_meta[ $post_id ][ $key ] = $value;

			return true;
		}
	}

	if ( ! function_exists( 'delete_post_meta' ) ) {
		function delete_post_meta( int $post_id, string $key ): bool {
			global $docsync_wp_test_post_meta;
			unset( $docsync_wp_test_post_meta[ $post_id ][ $key ] );

			return true;
		}
	}

	if ( ! function_exists( 'get_post' ) ) {
		function get_post( int $post_id ): ?WP_Post {
			global $docsync_wp_test_posts;

			return $docsync_wp_test_posts[ $post_id ] ?? null;
		}
	}

	if ( ! function_exists( 'wp_update_post' ) ) {
		function wp_update_post( array $postarr, bool $wp_error = false ): int|WP_Error {
			unset( $wp_error );
			global $docsync_wp_test_posts;

			$post_id = absint( $postarr['ID'] ?? 0 );

			if ( ! isset( $docsync_wp_test_posts[ $post_id ] ) ) {
				return new WP_Error( 'invalid_post', 'Invalid post.' );
			}

			foreach ( $postarr as $key => $value ) {
				if ( 'ID' !== $key ) {
					$docsync_wp_test_posts[ $post_id ]->{$key} = $value;
				}
			}

			return $post_id;
		}
	}

	if ( ! function_exists( 'get_the_title' ) ) {
		function get_the_title( int $post_id ): string {
			$post = get_post( $post_id );

			return null !== $post ? $post->post_title : '';
		}
	}

	if ( ! function_exists( 'get_post_type_object' ) ) {
		function get_post_type_object( string $post_type ): ?WP_Post_Type {
			if ( 'post' !== $post_type ) {
				return null;
			}

			return new WP_Post_Type();
		}
	}

	if ( ! function_exists( 'get_post_types' ) ) {
		function get_post_types( array $args = array(), string $output = 'names' ): array {
			unset( $args );
			$object = new WP_Post_Type();

			return 'objects' === $output ? array( 'post' => $object ) : array( 'post' );
		}
	}

	if ( ! function_exists( 'get_post_stati' ) ) {
		function get_post_stati( array $args = array(), string $output = 'names' ): array {
			unset( $args, $output );

			return array( 'draft', 'publish', 'pending', 'private' );
		}
	}

	if ( ! function_exists( 'user_can' ) ) {
		function user_can( int $user_id, string $capability, mixed ...$args ): bool {
			unset( $user_id, $capability, $args );

			return true;
		}
	}

	if ( ! class_exists( 'WP_Error' ) ) {
		final class WP_Error {
			private string $code;
			private string $message;
			private mixed $data;

			public function __construct( string $code, string $message = '', mixed $data = null ) {
				$this->code    = $code;
				$this->message = $message;
				$this->data    = $data;
			}

			public function get_error_code(): string {
				return $this->code;
			}

			public function get_error_message(): string {
				return $this->message;
			}

			public function get_error_data(): mixed {
				return $this->data;
			}
		}
	}

	if ( ! class_exists( 'WP_Post' ) ) {
		final class WP_Post {
			public int $ID;
			public string $post_type = 'post';
			public string $post_status = 'draft';
			public string $post_content = '';
			public string $post_title = 'Fixture Post';

			public function __construct( int $post_id ) {
				$this->ID = $post_id;
			}
		}
	}

	if ( ! class_exists( 'WP_Post_Type' ) ) {
		final class WP_Post_Type {
			public bool $public = true;
			public bool $_builtin = true;
			public string $label = 'Post';
			public object $labels;
			public object $cap;

			public function __construct() {
				$this->labels = (object) array( 'singular_name' => 'Post' );
				$this->cap    = (object) array(
					'create_posts'      => 'edit_posts',
					'edit_posts'        => 'edit_posts',
					'edit_others_posts' => 'edit_others_posts',
				);
			}
		}
	}

	final class Docsync_WP_Test_WPDB {
		public string $options = 'wp_options';

		public function prepare( string $query, mixed ...$args ): string {
			return vsprintf( str_replace( '%s', "'%s'", $query ), array_map( 'strval', $args ) );
		}

		public function get_var( string $query ): ?string {
			unset( $query );

			return null;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): int {
			unset( $table, $data, $where, $format, $where_format );

			return 0;
		}
	}

	require_once dirname( __DIR__ ) . '/vendor/autoload.php';

	use DocSyncWP\Security\EncryptionService;
	use DocSyncWP\Settings\SettingsRepository;
	use DocSyncWP\Sync\Elementor\CompatibilityChecker;
	use DocSyncWP\Sync\Elementor\DataConverter as ElementorDataConverter;
	use DocSyncWP\Sync\Elementor\IdGenerator;
	use DocSyncWP\Sync\Elementor\LayoutBuilder;
	use DocSyncWP\Sync\Elementor\PostUpdater;
	use DocSyncWP\Sync\Elementor\Preset\ElementorPresetConversionService;
	use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
	use DocSyncWP\Sync\Elementor\SyncDecider;
	use DocSyncWP\Sync\Elementor\WidgetFactory;
	use DocSyncWP\Sync\HtmlToBlockContentConverter;
	use DocSyncWP\Sync\Layout\LayoutConversionService;
	use DocSyncWP\Sync\SourceRepository;
	use DocSyncWP\Sync\SyncLock;
	use DocSyncWP\Sync\SyncService;

	global $docsync_wp_test_options, $docsync_wp_test_post_meta, $docsync_wp_test_posts, $wpdb;

	$post_id                      = 456;
	$fixture_dir                  = dirname( __DIR__ ) . '/tests/fixtures/elementor-presets/hero-page-basic';
	$input_html                   = (string) file_get_contents( $fixture_dir . '/input.html' );
	$docsync_wp_test_options      = array(
		SettingsRepository::OPTION_NAME => array(
			'enabled_post_types'     => array( 'post' ),
			'default_layout_preset'  => 'plain_blocks',
			'elementor_sync_enabled' => true,
		),
	);
	$docsync_wp_test_posts        = array( $post_id => new WP_Post( $post_id ) );
	$docsync_wp_test_post_meta    = array(
		$post_id => array(
			SourceRepository::META_FILE_ID          => 'fixture-google-file',
			SourceRepository::META_DOC_URL          => 'https://docs.google.com/document/d/fixture-google-file/edit',
			SourceRepository::META_TITLE            => 'Fixture Google Doc',
			SourceRepository::META_ELEMENTOR_SYNC   => true,
			SourceRepository::META_ELEMENTOR_PRESET => ElementorPresetRegistry::PRESET_HERO_PAGE,
			SourceRepository::META_SYNC_STATUS      => SyncService::STATUS_SYNCING,
			SourceRepository::META_SYNC_PROGRESS    => 55,
			SourceRepository::META_SYNC_STEP        => 'importing',
			SourceRepository::META_SYNC_MESSAGE     => 'Importing through the large-doc fallback.',
		),
	);
	$wpdb                         = new Docsync_WP_Test_WPDB();
	$settings                     = new SettingsRepository( new EncryptionService() );
	$source_repository            = new SourceRepository( $settings );
	$sync_service_reflection      = new ReflectionClass( SyncService::class );
	$sync_service                 = $sync_service_reflection->newInstanceWithoutConstructor();
	$preset_ids                   = new IdGenerator( 'hero-page-basic' );
	$elementor_preset_converter   = new ElementorPresetConversionService(
		new WidgetFactory( null, $preset_ids ),
		new LayoutBuilder( null, $preset_ids )
	);
	$legacy_ids                   = new IdGenerator( 'legacy-hero-page-basic' );
	$legacy_converter             = new ElementorDataConverter(
		new WidgetFactory( null, $legacy_ids ),
		new LayoutBuilder( null, $legacy_ids )
	);
	$properties                   = array(
		'source_repository'          => $source_repository,
		'layout_converter'           => new LayoutConversionService( $settings, new HtmlToBlockContentConverter() ),
		'sync_lock'                  => new SyncLock(),
		'elementor_decider'          => new SyncDecider( $settings, $source_repository, new CompatibilityChecker() ),
		'elementor_converter'        => $legacy_converter,
		'elementor_updater'          => new PostUpdater( new CompatibilityChecker() ),
		'elementor_preset_converter' => $elementor_preset_converter,
	);

	foreach ( $properties as $property => $value ) {
		$reflection_property = $sync_service_reflection->getProperty( $property );
		$reflection_property->setAccessible( true );
		$reflection_property->setValue( $sync_service, $value );
	}

	$source = $source_repository->getSource( $post_id );

	if ( ! is_array( $source ) ) {
		fwrite( STDERR, "large-doc-fallback-elementor-preset: source missing\n" );
		exit( 1 );
	}

	$callback_method = $sync_service_reflection->getMethod( 'progressiveFallbackFlushCallback' );
	$callback_method->setAccessible( true );
	$callback = $callback_method->invokeArgs( $sync_service, array( $post_id, &$source ) );
	$result   = $callback( $input_html, 1, 1 );

	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, "large-doc-fallback-elementor-preset: {$result->get_error_code()} {$result->get_error_message()}\n" );
		exit( 1 );
	}

	$actual_json = (string) ( $docsync_wp_test_post_meta[ $post_id ]['_elementor_data'] ?? '' );

	if ( '' === $actual_json ) {
		fwrite( STDERR, "large-doc-fallback-elementor-preset: missing _elementor_data write\n" );
		exit( 1 );
	}

	$expected_ids  = new IdGenerator( 'hero-page-basic' );
	$expected_json = ( new ElementorPresetConversionService(
		new WidgetFactory( null, $expected_ids ),
		new LayoutBuilder( null, $expected_ids )
	) )->convert( $input_html, $post_id, ElementorPresetRegistry::PRESET_HERO_PAGE );
	$legacy_ids    = new IdGenerator( 'legacy-hero-page-basic' );
	$legacy_json   = ( new ElementorDataConverter(
		new WidgetFactory( null, $legacy_ids ),
		new LayoutBuilder( null, $legacy_ids )
	) )->convert( $input_html, $post_id );

	if ( is_wp_error( $expected_json ) || is_wp_error( $legacy_json ) ) {
		fwrite( STDERR, "large-doc-fallback-elementor-preset: fixture conversion failed\n" );
		exit( 1 );
	}

	$actual   = json_decode( $actual_json, true );
	$expected = json_decode( (string) $expected_json, true );
	$legacy   = json_decode( (string) $legacy_json, true );

	if ( $actual !== $expected ) {
		fwrite( STDERR, "large-doc-fallback-elementor-preset: partial write did not use selected Elementor preset\n" );
		exit( 1 );
	}

	if ( $actual === $legacy ) {
		fwrite( STDERR, "large-doc-fallback-elementor-preset: partial write unexpectedly matched legacy Elementor conversion\n" );
		exit( 1 );
	}

	fwrite( STDOUT, "large-doc-fallback-elementor-preset: ok\n" );
}
