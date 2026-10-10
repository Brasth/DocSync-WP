<?php
/**
 * REST API service provider.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Journey2ServiceProvider;

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
	 * Folder watch controller.
	 *
	 * @var FolderWatchController
	 */
	private FolderWatchController $folder_watch_controller;

	/**
	 * Constructor.
	 *
	 * @param SettingsController    $settings_controller  Settings controller.
	 * @param WorkspaceController   $workspace_controller Operational workspace controller.
	 * @param OAuthController       $oauth_controller     OAuth controller.
	 * @param DocumentController    $document_controller  Document controller.
	 * @param SourceController      $source_controller    Source controller.
	 * @param SyncLogController     $sync_log_controller  Sync log controller.
	 * @param FeedbackController    $feedback_controller     Feedback controller.
	 * @param FolderWatchController $folder_watch_controller Folder watch controller.
	 */
	public function __construct(
		SettingsController $settings_controller,
		WorkspaceController $workspace_controller,
		OAuthController $oauth_controller,
		DocumentController $document_controller,
		SourceController $source_controller,
		SyncLogController $sync_log_controller,
		FeedbackController $feedback_controller,
		FolderWatchController $folder_watch_controller
	) {
		$this->settings_controller     = $settings_controller;
		$this->workspace_controller    = $workspace_controller;
		$this->oauth_controller        = $oauth_controller;
		$this->document_controller     = $document_controller;
		$this->source_controller       = $source_controller;
		$this->sync_log_controller     = $sync_log_controller;
		$this->feedback_controller     = $feedback_controller;
		$this->folder_watch_controller = $folder_watch_controller;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Journey 2 is wired here, synchronously at plugin load and before
	 * `rest_api_init`, so every request sees the injected collaborators. When
	 * Journey 2 is absent or not ready, the legacy routes behave as before.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );

		$dependencies = $this->getDependencies();

		$this->document_controller->setSourceRepository( $dependencies['sourceRepository'] );

		if ( ! class_exists( Journey2ServiceProvider::class ) ) {
			return;
		}

		$journey2 = new Journey2ServiceProvider( $dependencies );
		$journey2->register();

		if ( ! $journey2->isReady() ) {
			return;
		}

		$source_batch      = $journey2->getSourceBatch();
		$import_provenance = $journey2->getImportProvenance();

		if ( null !== $source_batch ) {
			$this->source_controller->setSourceBatch( $source_batch );
		}

		if ( null !== $import_provenance ) {
			$this->workspace_controller->setImportProvenance( $import_provenance );
		}
	}

	/**
	 * Shared service instances the controllers were built with.
	 *
	 * Merges the source, document, and OAuth controller maps; nothing is constructed here.
	 *
	 * @return array<string,object>
	 */
	public function getDependencies(): array {
		return array_merge(
			$this->source_controller->getDependencies(),
			$this->document_controller->getDependencies(),
			$this->oauth_controller->getDependencies()
		);
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
		$this->folder_watch_controller->registerRoutes( self::NAMESPACE );
	}
}
