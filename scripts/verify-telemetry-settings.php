<?php
/**
 * Verify telemetry settings and cron behavior without a full WordPress test suite.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Telemetry\TelemetryCron;
use DocSyncWP\Telemetry\TelemetryService;

define( 'ABSPATH', __DIR__ . '/../' );
define( 'WEEK_IN_SECONDS', 604800 );

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
	public function __construct( string $code, mixed ...$args ) {
		unset( $args );

		$this->code = $code;
	}

	/**
	 * Get the error code.
	 */
	public function get_error_code(): string {
		return $this->code;
	}
}

/**
 * Translation stub.
 *
 * @param string $text Text.
 * @param string $domain Text domain.
 */
function __( string $text, string $domain = 'default' ): string {
	unset( $domain );

	return $text;
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

/**
 * WordPress error helper stub.
 *
 * @param mixed $value Value.
 */
function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

/**
 * Generate deterministic UUIDs for this verification.
 */
function wp_generate_uuid4(): string {
	static $index = 0;

	$index++;

	return sprintf( '00000000-0000-4000-8000-%012d', $index );
}

/**
 * Option getter stub.
 *
 * @param string $name Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['docsync_wp_test_options'][ $name ] ?? $default;
}

/**
 * Option updater stub.
 *
 * @param string $name Option name.
 * @param mixed  $value Option value.
 */
function update_option( string $name, mixed $value, mixed ...$args ): bool {
	unset( $args );

	$GLOBALS['docsync_wp_test_options'][ $name ] = $value;

	return true;
}

/**
 * Post status stub.
 *
 * @return array<int,string>
 */
function get_post_stati( mixed ...$args ): array {
	unset( $args );

	return array( 'draft', 'publish' );
}

/**
 * Post types stub.
 *
 * @return array<string,object>
 */
function get_post_types( mixed ...$args ): array {
	unset( $args );

	return array(
		'post' => (object) array(
			'label'   => 'Posts',
			'labels'  => (object) array( 'singular_name' => 'Post' ),
			'public'  => true,
			'_builtin' => true,
		),
		'page' => (object) array(
			'label'   => 'Pages',
			'labels'  => (object) array( 'singular_name' => 'Page' ),
			'public'  => true,
			'_builtin' => true,
		),
	);
}

/**
 * Post type object stub.
 *
 * @param string $post_type Post type.
 */
function get_post_type_object( string $post_type ): ?object {
	$post_types = get_post_types();

	return $post_types[ $post_type ] ?? null;
}

/**
 * Cron schedule getter stub.
 *
 * @param string $hook Hook.
 */
function wp_get_schedule( string $hook ): string|false {
	return $GLOBALS['docsync_wp_test_cron'][ $hook ]['schedule'] ?? false;
}

/**
 * Cron next timestamp stub.
 *
 * @param string $hook Hook.
 */
function wp_next_scheduled( string $hook ): int|false {
	return $GLOBALS['docsync_wp_test_cron'][ $hook ]['timestamp'] ?? false;
}

/**
 * Cron schedule stub.
 *
 * @param int    $timestamp Timestamp.
 * @param string $schedule Schedule.
 * @param string $hook Hook.
 */
function wp_schedule_event( int $timestamp, string $schedule, string $hook ): bool {
	$GLOBALS['docsync_wp_test_cron'][ $hook ] = array(
		'schedule'  => $schedule,
		'timestamp' => $timestamp,
	);

	return true;
}

/**
 * Cron unschedule stub.
 *
 * @param int    $timestamp Timestamp.
 * @param string $hook Hook.
 */
function wp_unschedule_event( int $timestamp, string $hook ): bool {
	if ( isset( $GLOBALS['docsync_wp_test_cron'][ $hook ] ) && $timestamp === $GLOBALS['docsync_wp_test_cron'][ $hook ]['timestamp'] ) {
		unset( $GLOBALS['docsync_wp_test_cron'][ $hook ] );
	}

	return true;
}

require_once __DIR__ . '/../src/Security/EncryptionService.php';
require_once __DIR__ . '/../src/Sync/Elementor/Preset/ElementorPresetBlueprint.php';
require_once __DIR__ . '/../src/Sync/Elementor/Preset/ElementorPresetRegistry.php';
require_once __DIR__ . '/../src/Sync/Layout/LayoutBlueprint.php';
require_once __DIR__ . '/../src/Sync/Layout/LayoutPresetRegistry.php';
require_once __DIR__ . '/../src/Settings/SettingsRepository.php';
require_once __DIR__ . '/../src/Telemetry/TelemetryService.php';
require_once __DIR__ . '/../src/Telemetry/TelemetryCron.php';

$GLOBALS['docsync_wp_test_options'] = array();
$GLOBALS['docsync_wp_test_cron']    = array();

$settings = new SettingsRepository( new EncryptionService() );

assert_same( false, $settings->getPublicSettings()['telemetry_enabled'], 'telemetry defaults off' );
assert_same( false, $settings->getPublicSettings()['telemetry_prompt_dismissed'], 'telemetry prompt starts visible' );
assert_same( '', $settings->getTelemetrySiteId(), 'site id is absent before opt-in' );

$unknown = $settings->save( array( 'telemetryEnabled' => true ) );
assert_true( is_wp_error( $unknown ), 'internal settings reject REST-style telemetry key' );
assert_same( 'docsync_wp_unknown_settings', $unknown->get_error_code(), 'unknown key error is returned' );

$unknown_prompt = $settings->save( array( 'telemetryPromptDismissed' => true ) );
assert_true( is_wp_error( $unknown_prompt ), 'internal settings reject REST-style prompt key' );
assert_same( 'docsync_wp_unknown_settings', $unknown_prompt->get_error_code(), 'unknown prompt key error is returned' );

$dismissed = $settings->save( array( 'telemetry_prompt_dismissed' => true ) );
assert_false( is_wp_error( $dismissed ), 'telemetry prompt dismissal saves' );
assert_same( true, $settings->getPublicSettings()['telemetry_prompt_dismissed'], 'public settings expose prompt dismissal boolean' );
assert_same( false, $settings->getPublicSettings()['telemetry_enabled'], 'dismissing the prompt does not enable telemetry' );
assert_same( '', $settings->getTelemetrySiteId(), 'dismissing the prompt does not generate a site id' );

$enabled = $settings->save(
	array(
		'telemetry_enabled'          => true,
		'telemetry_prompt_dismissed' => true,
	)
);
assert_false( is_wp_error( $enabled ), 'telemetry opt-in saves' );
assert_same( true, $settings->getPublicSettings()['telemetry_enabled'], 'public settings expose opt-in boolean' );
assert_same( true, $settings->getPublicSettings()['telemetry_prompt_dismissed'], 'prompt remains dismissed after opt-in' );
assert_false( array_key_exists( 'telemetry_site_id', $settings->getPublicSettings() ), 'public settings never expose site id' );
assert_same( '00000000-0000-4000-8000-000000000001', $settings->getTelemetrySiteId(), 'site id is generated on opt-in' );

$enabled_again = $settings->save( array( 'sync_interval' => 'daily' ) );
assert_false( is_wp_error( $enabled_again ), 'unrelated saves keep working' );
assert_same( '00000000-0000-4000-8000-000000000001', $settings->getTelemetrySiteId(), 'site id is preserved while enabled' );

$cron = new TelemetryCron( $settings, new TelemetryService( $settings ) );
$cron->syncSchedule();
assert_same( 'weekly', wp_get_schedule( TelemetryCron::HOOK ), 'cron schedules weekly when enabled' );

$disabled = $settings->save( array( 'telemetry_enabled' => false ) );
assert_false( is_wp_error( $disabled ), 'telemetry opt-out saves' );
assert_same( false, $settings->getPublicSettings()['telemetry_enabled'], 'public settings expose disabled state' );
assert_same( true, $settings->getPublicSettings()['telemetry_prompt_dismissed'], 'prompt stays dismissed after opt-out' );
assert_same( '', $settings->getTelemetrySiteId(), 'site id is removed on opt-out' );

$cron->syncSchedule();
assert_same( false, wp_next_scheduled( TelemetryCron::HOOK ), 'cron unschedules when disabled' );

echo "Telemetry settings checks passed.\n";

/**
 * Assert strict equality.
 *
 * @param mixed  $expected Expected value.
 * @param mixed  $actual Actual value.
 * @param string $message Failure message.
 */
function assert_same( mixed $expected, mixed $actual, string $message ): void {
	if ( $expected !== $actual ) {
		fail( $message );
	}
}

/**
 * Assert true.
 *
 * @param bool   $actual Actual value.
 * @param string $message Failure message.
 */
function assert_true( bool $actual, string $message ): void {
	assert_same( true, $actual, $message );
}

/**
 * Assert false.
 *
 * @param bool   $actual Actual value.
 * @param string $message Failure message.
 */
function assert_false( bool $actual, string $message ): void {
	assert_same( false, $actual, $message );
}

/**
 * Fail the verification.
 *
 * @param string $message Failure message.
 */
function fail( string $message ): never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}
