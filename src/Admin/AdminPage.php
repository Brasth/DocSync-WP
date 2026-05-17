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

	public const HOOK_SUFFIX = 'toplevel_page_docsync-wp';

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
	}

	/**
	 * Render the React mount point.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access DocSync WP.', 'docsync-wp' ),
				esc_html__( 'Permission denied', 'docsync-wp' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap docsync-wp-admin-page">
			<div id="docsync-wp-admin-root"></div>
			<noscript>
				<?php esc_html_e( 'DocSync WP requires JavaScript in the WordPress admin.', 'docsync-wp' ); ?>
			</noscript>
		</div>
		<?php
	}
}
