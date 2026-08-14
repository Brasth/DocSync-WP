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
	 * Operational workspace controller.
	 *
	 * @var WorkspaceController
	 */
	private WorkspaceController $workspace_controller;

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
	 * Feedback controller.
	 *
	 * @var FeedbackController
	 */
	private FeedbackController $feedback_controller;

	/**
	 * Constructor.
	 *
	 * @param SettingsController  $settings_controller  Settings controller.
	 * @param WorkspaceController $workspace_controller Operational workspace controller.
	 * @param OAuthController     $oauth_controller     OAuth controller.
	 * @param DocumentController  $document_controller  Document controller.
	 * @param SourceController    $source_controller    Source controller.
	 * @param SyncLogController   $sync_log_controller  Sync log controller.
	 * @param FeedbackController  $feedback_controller  Feedback controller.
	 */
	public function __construct(
		SettingsController $settings_controller,
		WorkspaceController $workspace_controller,
		OAuthController $oauth_controller,
		DocumentController $document_controller,
		SourceController $source_controller,
		SyncLogController $sync_log_controller,
		FeedbackController $feedback_controller
	) {
		$this->settings_controller  = $settings_controller;
		$this->workspace_controller = $workspace_controller;
		$this->oauth_controller     = $oauth_controller;
		$this->document_controller  = $document_controller;
		$this->source_controller    = $source_controller;
		$this->sync_log_controller  = $sync_log_controller;
		$this->feedback_controller  = $feedback_controller;
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
		$this->workspace_controller->registerRoutes( self::NAMESPACE );
		$this->oauth_controller->registerRoutes( self::NAMESPACE );
		$this->document_controller->registerRoutes( self::NAMESPACE );
		$this->source_controller->registerRoutes( self::NAMESPACE );
		$this->sync_log_controller->registerRoutes( self::NAMESPACE );
		$this->feedback_controller->registerRoutes( self::NAMESPACE );
	}
}
