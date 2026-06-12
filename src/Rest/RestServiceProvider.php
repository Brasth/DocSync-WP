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
 * Registers Brasth Document Sync REST routes.
 */
final class RestServiceProvider {
	public const NAMESPACE = 'brasth-document-sync-for-google-docs/v1';

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
	 * Source controller.
	 *
	 * @var SourceController
	 */
	private SourceController $source_controller;

	/**
	 * Sync log controller.
	 *
	 * @var SyncLogController
	 */
	private SyncLogController $sync_log_controller;

	/**
	 * Constructor.
	 *
	 * @param SettingsController $settings_controller Settings controller.
	 * @param OAuthController    $oauth_controller    OAuth controller.
	 * @param DocumentController $document_controller Document controller.
	 * @param SourceController   $source_controller   Source controller.
	 * @param SyncLogController  $sync_log_controller Sync log controller.
	 */
	public function __construct(
		SettingsController $settings_controller,
		OAuthController $oauth_controller,
		DocumentController $document_controller,
		SourceController $source_controller,
		SyncLogController $sync_log_controller
	) {
		$this->settings_controller = $settings_controller;
		$this->oauth_controller    = $oauth_controller;
		$this->document_controller = $document_controller;
		$this->source_controller   = $source_controller;
		$this->sync_log_controller = $sync_log_controller;
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
		$this->source_controller->registerRoutes( self::NAMESPACE );
		$this->sync_log_controller->registerRoutes( self::NAMESPACE );
	}
}
