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
	 * OAuth controller.
	 *
	 * @var OAuthController
	 */
	private OAuthController $oauth_controller;

	/**
	 * Document controller.
	 *
	 * @var DocumentController
	 */
	private DocumentController $document_controller;

	/**
	 * Constructor.
	 *
	 * @param SettingsController $settings_controller Settings controller.
	 * @param OAuthController    $oauth_controller    OAuth controller.
	 * @param DocumentController $document_controller Document controller.
	 */
	public function __construct(
		SettingsController $settings_controller,
		OAuthController $oauth_controller,
		DocumentController $document_controller
	) {
		$this->settings_controller = $settings_controller;
		$this->oauth_controller    = $oauth_controller;
		$this->document_controller = $document_controller;
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
		$this->oauth_controller->registerRoutes( self::NAMESPACE );
		$this->document_controller->registerRoutes( self::NAMESPACE );
	}
}
