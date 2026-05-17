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
	 * Constructor.
	 *
	 * @param AdminPage     $admin_page Admin page service.
	 * @param AssetRegistry $assets     Asset registry service.
	 */
	public function __construct( AdminPage $admin_page, AssetRegistry $assets ) {
		$this->admin_page = $admin_page;
		$this->assets     = $assets;
	}

	/**
	 * Boot the plugin.
	 */
	public static function boot(): void {
		$plugin = new self(
			new AdminPage(),
			new AssetRegistry(
				DOCSYNC_WP_PATH,
				DOCSYNC_WP_URL,
				DOCSYNC_WP_VERSION
			)
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
	}
}
