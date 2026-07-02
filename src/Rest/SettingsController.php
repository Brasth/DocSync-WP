<?php
/**
 * REST controller for Brasth Document Sync settings.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Settings\SettingsRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles settings read and update requests.
 */
final class SettingsController {
	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSettings' ),
					'permission_callback' => array( RestPermissions::class, 'canManageSettings' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateSettings' ),
					'permission_callback' => array( RestPermissions::class, 'canManageSettings' ),
				),
			)
		);
	}

	/**
	 * Get public-safe settings.
	 */
	public function getSettings(): WP_REST_Response {
		return rest_ensure_response( $this->formatSettingsResponse() );
	}

	/**
	 * Update settings.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateSettings( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'docsync_wp_invalid_settings_payload',
				__( 'Brasth Document Sync settings must be submitted as an object.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$mapped = $this->mapRequestSettings( $params );

		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		$saved = $this->settings->save( $mapped );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return rest_ensure_response( $this->formatSettingsResponse() );
	}

	/**
	 * Map camelCase REST input to internal snake_case keys.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return array<string,mixed>|WP_Error
	 */
	private function mapRequestSettings( array $params ): array|WP_Error {
		$allowed_keys = array(
			'clientId',
			'clientSecret',
			'scopeMode',
			'enabledPostTypes',
			'defaultPostStatus',
			'defaultExportFormat',
			'defaultLayoutPreset',
			'syncInterval',
			'connectionMode',
			'elementorSyncEnabled',
			'telemetryEnabled',
			'telemetryPromptDismissed',
		);

		$unknown_keys = array_diff( array_keys( $params ), $allowed_keys );

		if ( array() !== $unknown_keys ) {
			return new WP_Error(
				'docsync_wp_unknown_settings',
				__( 'Brasth Document Sync received unknown settings.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$mapped = array();

		if ( array_key_exists( 'clientId', $params ) ) {
			$mapped['client_id'] = $params['clientId'];
		}

		if ( array_key_exists( 'clientSecret', $params ) ) {
			$mapped['client_secret'] = $params['clientSecret'];
		}

		if ( array_key_exists( 'scopeMode', $params ) ) {
			$mapped['scope_mode'] = $params['scopeMode'];
		}

		if ( array_key_exists( 'enabledPostTypes', $params ) ) {
			$mapped['enabled_post_types'] = $params['enabledPostTypes'];
		}

		if ( array_key_exists( 'defaultPostStatus', $params ) ) {
			$mapped['default_post_status'] = $params['defaultPostStatus'];
		}

		if ( array_key_exists( 'defaultExportFormat', $params ) ) {
			$mapped['default_export_format'] = $params['defaultExportFormat'];
		}

		if ( array_key_exists( 'defaultLayoutPreset', $params ) ) {
			$mapped['default_layout_preset'] = $params['defaultLayoutPreset'];
		}

		if ( array_key_exists( 'syncInterval', $params ) ) {
			$mapped['sync_interval'] = $params['syncInterval'];
		}

		if ( array_key_exists( 'connectionMode', $params ) ) {
			$mapped['connection_mode'] = $params['connectionMode'];
		}

		if ( array_key_exists( 'elementorSyncEnabled', $params ) ) {
			$mapped['elementor_sync_enabled'] = $params['elementorSyncEnabled'];
		}

		if ( array_key_exists( 'telemetryEnabled', $params ) ) {
			$mapped['telemetry_enabled'] = $params['telemetryEnabled'];
		}

		if ( array_key_exists( 'telemetryPromptDismissed', $params ) ) {
			$mapped['telemetry_prompt_dismissed'] = $params['telemetryPromptDismissed'];
		}

		return $mapped;
	}

	/**
	 * Format public-safe settings for REST.
	 *
	 * @return array<string,mixed>
	 */
	private function formatSettingsResponse(): array {
		$settings = $this->settings->getPublicSettings();

		return array(
			'clientId'                        => $settings['client_id'],
			'scopeMode'                       => $settings['scope_mode'],
			'enabledPostTypes'                => $settings['enabled_post_types'],
			'defaultPostStatus'               => $settings['default_post_status'],
			'defaultExportFormat'             => $settings['default_export_format'],
			'defaultLayoutPreset'             => $settings['default_layout_preset'],
			'syncInterval'                    => $settings['sync_interval'],
			'connectionMode'                  => $settings['connection_mode'],
			'elementorSyncEnabled'            => $settings['elementor_sync_enabled'],
			'telemetryEnabled'                => $settings['telemetry_enabled'],
			'telemetryPromptDismissed'        => $settings['telemetry_prompt_dismissed'],
			'hasClientId'                     => $settings['has_client_id'],
			'hasClientSecret'                 => $settings['has_client_secret'],
			'hasRequiredSettings'             => $settings['has_required_settings'],
			'availablePostTypes'              => $this->settings->getAvailablePostTypes(),
			'availableLayoutPresets'          => $this->settings->getAvailableLayoutPresets(),
			'availableElementorLayoutPresets' => $this->settings->getAvailableElementorLayoutPresets(),
		);
	}
}
