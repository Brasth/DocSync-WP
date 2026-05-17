<?php
/**
 * Main plugin bootstrap.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP;

use DocSyncWP\Admin\AdminPage;
use DocSyncWP\Assets\AssetRegistry;
use DocSyncWP\Auth\TokenStore;
use DocSyncWP\Rest\RestServiceProvider;
use DocSyncWP\Rest\SettingsController;
use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\SourceRepository;

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
	 * @param TokenStore          $token_store       Token store service.
	 * @param SourceRepository    $source_repository Source repository service.
	 */
	public function __construct(
		AdminPage $admin_page,
		AssetRegistry $assets,
		RestServiceProvider $rest,
		TokenStore $token_store,
		SourceRepository $source_repository
	) {
		$this->admin_page        = $admin_page;
		$this->assets            = $assets;
		$this->rest              = $rest;
		$this->token_store       = $token_store;
		$this->source_repository = $source_repository;
	}

	/**
	 * Boot the plugin.
	 */
	public static function boot(): void {
		$encryption        = new EncryptionService();
		$settings          = new SettingsRepository( $encryption );
		$token_store       = new TokenStore( $encryption );
		$source_repository = new SourceRepository( $settings );

		$plugin = new self(
			new AdminPage(),
			new AssetRegistry(
				DOCSYNC_WP_PATH,
				DOCSYNC_WP_URL,
				DOCSYNC_WP_VERSION,
				$settings
			),
			new RestServiceProvider(
				new SettingsController( $settings )
			),
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

		$this->rest->register();
	}
}
