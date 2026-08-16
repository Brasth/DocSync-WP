<?php
/**
 * Main plugin bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP;

defined( 'ABSPATH' ) || exit;

use DocSyncWP\Admin\AdminPage;
use DocSyncWP\Admin\PostListActions;
use DocSyncWP\Admin\PostSyncMetaBox;
use DocSyncWP\Assets\AssetRegistry;
use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Auth\TokenStore;
use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Feedback\FeedbackRateLimiter;
use DocSyncWP\Feedback\FeedbackService;
use DocSyncWP\Google\DocumentIdParser;
use DocSyncWP\Google\DocsClient;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Google\DriveFolderInventory;
use DocSyncWP\Rest\DocumentController;
use DocSyncWP\Rest\FeedbackController;
use DocSyncWP\Rest\FolderWatchController;
use DocSyncWP\Rest\OAuthController;
use DocSyncWP\Rest\RestServiceProvider;
use DocSyncWP\Rest\SettingsController;
use DocSyncWP\Rest\SourceController;
use DocSyncWP\Rest\SyncLogController;
use DocSyncWP\Rest\WorkspaceController;
use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\DocsApiHtmlBuilder;
use DocSyncWP\Sync\DocsApiHtmlImporter;
use DocSyncWP\Sync\DocsApiImageImporter;
use DocSyncWP\Sync\DocsApiInlineRenderer;
use DocSyncWP\Sync\DocsApiParagraphRenderer;
use DocSyncWP\Sync\Elementor\CompatibilityChecker;
use DocSyncWP\Sync\Elementor\DataConverter as ElementorDataConverter;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetConversionService;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\Elementor\LayoutBuilder as ElementorLayoutBuilder;
use DocSyncWP\Sync\Elementor\PostUpdater as ElementorPostUpdater;
use DocSyncWP\Sync\Elementor\SyncDecider as ElementorSyncDecider;
use DocSyncWP\Sync\Elementor\WidgetFactory as ElementorWidgetFactory;
use DocSyncWP\Sync\HtmlDocumentImageRewriter;
use DocSyncWP\Sync\HtmlToBlockContentConverter;
use DocSyncWP\Sync\HtmlZipImporter;
use DocSyncWP\Sync\HtmlZipPackageExtractor;
use DocSyncWP\Sync\Layout\LayoutConversionService;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use DocSyncWP\Sync\MediaAssetImporter;
use DocSyncWP\Sync\FolderWatchRepository;
use DocSyncWP\Sync\FolderWatchService;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncLock;
use DocSyncWP\Sync\SyncService;
use DocSyncWP\Telemetry\TelemetryCron;
use DocSyncWP\Telemetry\TelemetryService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers plugin services.
 */
final class Plugin {
	/**
	 * Admin page service.
	 *
	 * @var AdminPage
	 */
	private AdminPage $admin_page;

	/**
	 * Asset registry service.
	 *
	 * @var AssetRegistry
	 */
	private AssetRegistry $assets;

	/**
	 * REST service provider.
	 *
	 * @var RestServiceProvider
	 */
	private RestServiceProvider $rest;

	/**
	 * Post edit meta box service.
	 *
	 * @var PostSyncMetaBox
	 */
	private PostSyncMetaBox $post_sync_meta_box;

	/**
	 * Post list table actions service.
	 *
	 * @var PostListActions
	 */
	private PostListActions $post_list_actions;

	/**
	 * Sync cron service.
	 *
	 * @var SyncCron
	 */
	private SyncCron $sync_cron;

	/**
	 * Telemetry cron service.
	 *
	 * @var TelemetryCron
	 */
	private TelemetryCron $telemetry_cron;

	/**
	 * Token store service.
	 *
	 * @var TokenStore
	 */
	private TokenStore $token_store;

	/**
	 * Source repository service.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Folder watch service.
	 *
	 * @var FolderWatchService
	 */
	private FolderWatchService $folder_watch_service;

	/**
	 * Constructor.
	 *
	 * @param AdminPage           $admin_page        Admin page service.
	 * @param AssetRegistry       $assets            Asset registry service.
	 * @param RestServiceProvider $rest              REST service provider.
	 * @param PostSyncMetaBox     $post_sync_meta_box Post sync meta box service.
	 * @param PostListActions     $post_list_actions Post list table actions service.
	 * @param SyncCron            $sync_cron         Sync cron service.
	 * @param TelemetryCron       $telemetry_cron    Telemetry cron service.
	 * @param TokenStore          $token_store          Token store service.
	 * @param SourceRepository    $source_repository    Source repository service.
	 * @param FolderWatchService  $folder_watch_service Folder watch service.
	 */
	public function __construct(
		AdminPage $admin_page,
		AssetRegistry $assets,
		RestServiceProvider $rest,
		PostSyncMetaBox $post_sync_meta_box,
		PostListActions $post_list_actions,
		SyncCron $sync_cron,
		TelemetryCron $telemetry_cron,
		TokenStore $token_store,
		SourceRepository $source_repository,
		FolderWatchService $folder_watch_service
	) {
		$this->admin_page           = $admin_page;
		$this->assets               = $assets;
		$this->rest                 = $rest;
		$this->post_sync_meta_box   = $post_sync_meta_box;
		$this->post_list_actions    = $post_list_actions;
		$this->sync_cron            = $sync_cron;
		$this->telemetry_cron       = $telemetry_cron;
		$this->token_store          = $token_store;
		$this->source_repository    = $source_repository;
		$this->folder_watch_service = $folder_watch_service;
	}

	/**
	 * Boot the plugin.
	 */
	public static function boot(): void {
		$encryption                  = new EncryptionService();
		$layout_presets              = new LayoutPresetRegistry();
		$elementor_presets           = new ElementorPresetRegistry();
		$settings                    = new SettingsRepository( $encryption, $layout_presets, $elementor_presets );
		$token_store                 = new TokenStore( $encryption );
		$source_repository           = new SourceRepository( $settings, $layout_presets, $elementor_presets );
		$google_oauth                = new GoogleOAuthService( $settings, $token_store );
		$telemetry_service           = new TelemetryService( $settings );
		$drive_client                = new DriveClient( $google_oauth );
		$docs_client                 = new DocsClient( $google_oauth );
		$folder_inventory            = new DriveFolderInventory( $drive_client );
		$folder_watch_repository     = new FolderWatchRepository();
		$document_id_parser          = new DocumentIdParser();
		$media_assets                = new MediaAssetImporter();
		$elementor_checker           = new CompatibilityChecker();
		$elementor_decider           = new ElementorSyncDecider( $settings, $source_repository, $elementor_checker );
		$elementor_data              = new ElementorDataConverter(
			new ElementorWidgetFactory(),
			new ElementorLayoutBuilder( $elementor_checker )
		);
		$elementor_presets_converter = new ElementorPresetConversionService(
			new ElementorWidgetFactory(),
			new ElementorLayoutBuilder( $elementor_checker ),
			$elementor_presets
		);
		$elementor_updater           = new ElementorPostUpdater( $elementor_checker );
		$sync_service                = new SyncService(
			$source_repository,
			$drive_client,
			new HtmlZipImporter(
				new HtmlZipPackageExtractor(),
				new HtmlDocumentImageRewriter( $media_assets )
			),
			new DocsApiHtmlImporter(
				$docs_client,
				new DocsApiHtmlBuilder(
					new DocsApiParagraphRenderer(
						new DocsApiInlineRenderer(
							new DocsApiImageImporter( $docs_client, $media_assets )
						)
					)
				)
			),
			new LayoutConversionService( $settings, new HtmlToBlockContentConverter(), $layout_presets ),
			new SyncLock(),
			$elementor_decider,
			$elementor_data,
			$elementor_updater,
			$elementor_presets_converter
		);
		$folder_watch_service        = new FolderWatchService(
			$folder_watch_repository,
			$folder_inventory,
			$drive_client,
			$source_repository,
			$sync_service,
			$settings
		);

		$plugin = new self(
			new AdminPage( $settings, $source_repository ),
			new AssetRegistry(
				DOCSYNC_WP_PATH,
				DOCSYNC_WP_URL,
				DOCSYNC_WP_VERSION,
				$settings
			),
			new RestServiceProvider(
				new SettingsController( $settings, $token_store ),
				new WorkspaceController( $settings, $source_repository, $elementor_checker, $folder_watch_service ),
				new OAuthController( $google_oauth, $token_store ),
				new DocumentController(
					$document_id_parser,
					$drive_client
				),
				new SourceController(
					$source_repository,
					$sync_service,
					$document_id_parser,
					$layout_presets,
					$elementor_presets
				),
				new SyncLogController( $source_repository ),
				new FeedbackController( new FeedbackService(), new FeedbackRateLimiter() ),
				new FolderWatchController(
					$folder_watch_service,
					$folder_inventory,
					$source_repository,
					$layout_presets,
					$elementor_presets
				)
			),
			new PostSyncMetaBox( $source_repository, $settings, $sync_service->getElementorDecider() ),
			new PostListActions( $source_repository, $sync_service->getElementorDecider() ),
			new SyncCron( $settings, $source_repository, $sync_service ),
			new TelemetryCron( $settings, $telemetry_service ),
			$token_store,
			$source_repository,
			$folder_watch_service
		);

		$plugin->register();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		load_plugin_textdomain(
			'brasth-document-sync-for-google-docs',
			false,
			dirname( DOCSYNC_WP_BASENAME ) . '/languages'
		);

		add_action( 'admin_menu', array( $this->admin_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this->assets, 'enqueueAdminApp' ) );
		add_action( 'admin_notices', array( $this->assets, 'renderMissingBuildNotice' ) );

		$this->post_sync_meta_box->register();
		$this->post_list_actions->register();
		$this->sync_cron->register();
		$this->folder_watch_service->register();
		$this->telemetry_cron->register();
		$this->rest->register();
	}
}
