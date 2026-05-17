<?php
/**
 * REST API service provider.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Registers DocSync WP REST routes.
 */
final class RestServiceProvider {
	public const NAMESPACE = 'docsync-wp/v1';

	/**
	 * Settings controller.
	 *
	 * @var SettingsController
	 */
	private SettingsController $settings_controller;

	/**
	 * Constructor.
	 *
	 * @param SettingsController $settings_controller Settings controller.
	 */
	public function __construct( SettingsController $settings_controller ) {
		$this->settings_controller = $settings_controller;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function registerRoutes(): void {
		$this->settings_controller->registerRoutes( self::NAMESPACE );
	}
}
