<?php
/**
 * Admin page registration and rendering.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Brasth Document Sync admin screen.
 */
final class AdminPage {
	public const MENU_SLUG = 'brasth-document-sync-for-google-docs';

	public const SOURCES_MENU_SLUG = 'brasth-document-sync-for-google-docs-sources';

	public const LOGS_MENU_SLUG = 'brasth-document-sync-for-google-docs-logs';

	public const HOOK_SUFFIX = 'toplevel_page_brasth-document-sync-for-google-docs';

	/**
	 * Hook suffix for the Sources submenu page.
	 *
	 * WordPress builds this from sanitize_title() of the translated top-level menu
	 * title, not from the parent menu slug. The prefix is therefore
	 * 'brasth-document-sync' in English, not 'brasth-document-sync-for-google-docs'.
	 *
	 * @internal Prefer matching against the stable SOURCES_MENU_SLUG when possible.
	 */
	public const SOURCES_HOOK_SUFFIX = 'brasth-document-sync_page_brasth-document-sync-for-google-docs-sources';

	/**
	 * Hook suffix for the Logs submenu page.
	 *
	 * See SOURCES_HOOK_SUFFIX: the prefix depends on the translated top-level menu title.
	 *
	 * @internal Prefer matching against the stable LOGS_MENU_SLUG when possible.
	 */
	public const LOGS_HOOK_SUFFIX = 'brasth-document-sync_page_brasth-document-sync-for-google-docs-logs';

	private const CAPABILITY = 'manage_options';

	/**
	 * Register the admin menu page.
	 */
	public function register(): void {
		add_menu_page(
			esc_html__( 'Brasth Document Sync', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Brasth Document Sync', 'brasth-document-sync-for-google-docs' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-media-document',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Brasth Document Sync Setup', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Setup', 'brasth-document-sync-for-google-docs' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Brasth Document Sync Sources', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Sources', 'brasth-document-sync-for-google-docs' ),
			self::CAPABILITY,
			self::SOURCES_MENU_SLUG,
			array( $this, 'renderSources' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Brasth Document Sync Logs', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Logs', 'brasth-document-sync-for-google-docs' ),
			self::CAPABILITY,
			self::LOGS_MENU_SLUG,
			array( $this, 'renderLogs' )
		);
	}

	/**
	 * Render the React mount point.
	 */
	public function render(): void {
		$this->renderMount( 'setup' );
	}

	/**
	 * Render the Sources React mount point.
	 */
	public function renderSources(): void {
		$this->renderMount( 'sources' );
	}

	/**
	 * Render the Logs React mount point.
	 */
	public function renderLogs(): void {
		$this->renderMount( 'logs' );
	}

	/**
	 * Render the React mount point.
	 *
	 * @param string $view Admin app view.
	 */
	private function renderMount( string $view ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
				esc_html__( 'Permission denied', 'brasth-document-sync-for-google-docs' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap docsync-wp-admin-page">
			<div id="docsync-wp-admin-root" data-view="<?php echo esc_attr( $view ); ?>"></div>
			<noscript>
				<?php esc_html_e( 'Brasth Document Sync requires JavaScript in the WordPress admin.', 'brasth-document-sync-for-google-docs' ); ?>
			</noscript>
		</div>
		<?php
	}
}
