<?php
/**
 * REST controller for DocSync WP settings.
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
					'permission_callback' => array( $this, 'canManageSettings' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'updateSettings' ),
					'permission_callback' => array( $this, 'canManageSettings' ),
				),
			)
		);
	}

	/**
	 * Permission callback for settings routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function canManageSettings( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to manage DocSync WP settings.', 'docsync-wp' ),
				array( 'status' => 403 )
			);
		}

		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'docsync_wp_rest_nonce_required',
				__( 'DocSync WP requires a valid REST nonce.', 'docsync-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
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
				__( 'DocSync WP settings must be submitted as an object.', 'docsync-wp' ),
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
			'pickerApiKey',
			'pickerAppId',
			'scopeMode',
			'enabledPostTypes',
			'defaultPostStatus',
			'defaultExportFormat',
			'syncInterval',
			'connectionMode',
		);

		$unknown_keys = array_diff( array_keys( $params ), $allowed_keys );

		if ( array() !== $unknown_keys ) {
			return new WP_Error(
				'docsync_wp_unknown_settings',
				__( 'DocSync WP received unknown settings.', 'docsync-wp' ),
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

		if ( array_key_exists( 'pickerApiKey', $params ) ) {
			$mapped['picker_api_key'] = $params['pickerApiKey'];
		}

		if ( array_key_exists( 'pickerAppId', $params ) ) {
			$mapped['picker_app_id'] = $params['pickerAppId'];
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

		if ( array_key_exists( 'syncInterval', $params ) ) {
			$mapped['sync_interval'] = $params['syncInterval'];
		}

		if ( array_key_exists( 'connectionMode', $params ) ) {
			$mapped['connection_mode'] = $params['connectionMode'];
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
			'clientId'            => $settings['client_id'],
			'pickerApiKey'        => $settings['picker_api_key'],
			'pickerAppId'         => $settings['picker_app_id'],
			'scopeMode'           => $settings['scope_mode'],
			'enabledPostTypes'    => $settings['enabled_post_types'],
			'defaultPostStatus'   => $settings['default_post_status'],
			'defaultExportFormat' => $settings['default_export_format'],
			'syncInterval'        => $settings['sync_interval'],
			'connectionMode'      => $settings['connection_mode'],
			'hasClientId'         => $settings['has_client_id'],
			'hasClientSecret'     => $settings['has_client_secret'],
			'hasPickerApiKey'     => $settings['has_picker_api_key'],
			'hasPickerAppId'      => $settings['has_picker_app_id'],
			'hasPickerSettings'   => $settings['has_picker_settings'],
			'hasRequiredSettings' => $settings['has_required_settings'],
			'availablePostTypes'  => $this->settings->getAvailablePostTypes(),
		);
	}
}
