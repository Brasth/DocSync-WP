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
 * Registers the DocSync WP admin screen.
 */
final class AdminPage {
	public const MENU_SLUG = 'docsync-wp';

	public const SOURCES_MENU_SLUG = 'docsync-wp-sources';

	public const HOOK_SUFFIX = 'toplevel_page_docsync-wp';

	public const SOURCES_HOOK_SUFFIX = 'docsync-wp_page_docsync-wp-sources';

	private const CAPABILITY = 'manage_options';

	/**
	 * Register the admin menu page.
	 */
	public function register(): void {
		add_menu_page(
			esc_html__( 'DocSync WP', 'docsync-wp' ),
			esc_html__( 'DocSync WP', 'docsync-wp' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' ),
			'dashicons-media-document',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'DocSync WP Setup', 'docsync-wp' ),
			esc_html__( 'Setup', 'docsync-wp' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'DocSync WP Sources', 'docsync-wp' ),
			esc_html__( 'Sources', 'docsync-wp' ),
			self::CAPABILITY,
			self::SOURCES_MENU_SLUG,
			array( $this, 'renderSources' )
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
	 * Render the React mount point.
	 *
	 * @param string $view Admin app view.
	 */
	private function renderMount( string $view ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access DocSync WP.', 'docsync-wp' ),
				esc_html__( 'Permission denied', 'docsync-wp' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap docsync-wp-admin-page">
			<div id="docsync-wp-admin-root" data-view="<?php echo esc_attr( $view ); ?>"></div>
			<noscript>
				<?php esc_html_e( 'DocSync WP requires JavaScript in the WordPress admin.', 'docsync-wp' ); ?>
			</noscript>
		</div>
		<?php
	}
}
