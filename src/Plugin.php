<?php
/**
 * Main plugin bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP;

use DocSyncWP\Admin\AdminPage;
use DocSyncWP\Admin\PostListActions;
use DocSyncWP\Admin\PostSyncMetaBox;
use DocSyncWP\Assets\AssetRegistry;
use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Auth\TokenStore;
use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Google\DocumentIdParser;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Rest\DocumentController;
use DocSyncWP\Rest\OAuthController;
use DocSyncWP\Rest\RestServiceProvider;
use DocSyncWP\Rest\SettingsController;
use DocSyncWP\Rest\SourceController;
use DocSyncWP\Rest\SyncLogController;
use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\ContentConverter;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncLock;
use DocSyncWP\Sync\SyncService;

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
	 * Constructor.
	 *
	 * @param AdminPage           $admin_page        Admin page service.
	 * @param AssetRegistry       $assets            Asset registry service.
	 * @param RestServiceProvider $rest              REST service provider.
	 * @param PostSyncMetaBox     $post_sync_meta_box Post sync meta box service.
	 * @param PostListActions     $post_list_actions Post list table actions service.
	 * @param SyncCron            $sync_cron         Sync cron service.
	 * @param TokenStore          $token_store       Token store service.
	 * @param SourceRepository    $source_repository Source repository service.
	 */
	public function __construct(
		AdminPage $admin_page,
		AssetRegistry $assets,
		RestServiceProvider $rest,
		PostSyncMetaBox $post_sync_meta_box,
		PostListActions $post_list_actions,
		SyncCron $sync_cron,
		TokenStore $token_store,
		SourceRepository $source_repository
	) {
		$this->admin_page         = $admin_page;
		$this->assets             = $assets;
		$this->rest               = $rest;
		$this->post_sync_meta_box = $post_sync_meta_box;
		$this->post_list_actions  = $post_list_actions;
		$this->sync_cron          = $sync_cron;
		$this->token_store        = $token_store;
		$this->source_repository  = $source_repository;
	}

	/**
	 * Boot the plugin.
	 */
	public static function boot(): void {
		$encryption         = new EncryptionService();
		$settings           = new SettingsRepository( $encryption );
		$token_store        = new TokenStore( $encryption );
		$source_repository  = new SourceRepository( $settings );
		$google_oauth       = new GoogleOAuthService( $settings, $token_store );
		$drive_client       = new DriveClient( $google_oauth );
		$document_id_parser = new DocumentIdParser();
		$sync_service       = new SyncService(
			$source_repository,
			$drive_client,
			new ContentConverter(),
			new SyncLock()
		);

		$plugin = new self(
			new AdminPage(),
			new AssetRegistry(
				DOCSYNC_WP_PATH,
				DOCSYNC_WP_URL,
				DOCSYNC_WP_VERSION,
				$settings
			),
			new RestServiceProvider(
				new SettingsController( $settings ),
				new OAuthController( $google_oauth, $token_store ),
				new DocumentController(
					$document_id_parser,
					$drive_client
				),
				new SourceController(
					$source_repository,
					$sync_service,
					$document_id_parser
				),
				new SyncLogController( $source_repository )
			),
			new PostSyncMetaBox( $source_repository ),
			new PostListActions( $source_repository ),
			new SyncCron( $settings, $source_repository, $sync_service ),
			$token_store,
			$source_repository
		);

		$plugin->register();
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		load_plugin_textdomain(
			'docsync-wp',
			false,
			dirname( DOCSYNC_WP_BASENAME ) . '/languages'
		);

		add_action( 'admin_menu', array( $this->admin_page, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this->assets, 'enqueueAdminApp' ) );
		add_action( 'admin_notices', array( $this->assets, 'renderMissingBuildNotice' ) );

		$this->post_sync_meta_box->register();
		$this->post_list_actions->register();
		$this->sync_cron->register();
		$this->rest->register();
	}
}
