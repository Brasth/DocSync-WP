<?php
// Configure DocSync WP site OAuth credentials from a Google client JSON file.

use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$json_path = $args[0] ?? '';

if ( ! is_string( $json_path ) || '' === $json_path || ! is_readable( $json_path ) ) {
	fwrite( STDERR, "OAuth JSON path is not readable.\n" );
	exit( 1 );
}

$raw = file_get_contents( $json_path );

if ( ! is_string( $raw ) || '' === $raw ) {
	fwrite( STDERR, "OAuth JSON file is empty.\n" );
	exit( 1 );
}

$parsed = json_decode( $raw, true );

if ( ! is_array( $parsed ) || ! isset( $parsed['web'] ) || ! is_array( $parsed['web'] ) ) {
	fwrite( STDERR, "OAuth JSON must contain a web client definition.\n" );
	exit( 1 );
}

$client_id     = isset( $parsed['web']['client_id'] ) ? sanitize_text_field( (string) $parsed['web']['client_id'] ) : '';
$client_secret = isset( $parsed['web']['client_secret'] ) ? sanitize_text_field( (string) $parsed['web']['client_secret'] ) : '';

if ( '' === $client_id || '' === $client_secret ) {
	fwrite( STDERR, "OAuth JSON is missing client_id or client_secret.\n" );
	exit( 1 );
}

$settings = new SettingsRepository(
	new EncryptionService(),
	new LayoutPresetRegistry(),
	new ElementorPresetRegistry()
);

$saved = $settings->save(
	array(
		'client_id'     => $client_id,
		'client_secret' => $client_secret,
	)
);

if ( is_wp_error( $saved ) ) {
	fwrite( STDERR, $saved->get_error_message() . "\n" );
	exit( 1 );
}

echo "ok\n";
