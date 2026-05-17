<?php
/**
 * Plugin Name:       DocSync WP
 * Description:       Document synchronization tools for WordPress.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            DocSync WP Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       docsync-wp
 * Domain Path:       /languages
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'DOCSYNC_WP_VERSION', '0.1.0' );
define( 'DOCSYNC_WP_MINIMUM_PHP_VERSION', '8.1' );
define( 'DOCSYNC_WP_MINIMUM_WP_VERSION', '6.4' );
define( 'DOCSYNC_WP_FILE', __FILE__ );
define( 'DOCSYNC_WP_PATH', plugin_dir_path( __FILE__ ) );
define( 'DOCSYNC_WP_URL', plugin_dir_url( __FILE__ ) );
define( 'DOCSYNC_WP_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check whether the current runtime meets plugin requirements.
 */
function docsync_wp_runtime_is_supported(): bool {
	global $wp_version;

	return version_compare( PHP_VERSION, DOCSYNC_WP_MINIMUM_PHP_VERSION, '>=' )
		&& version_compare( (string) $wp_version, DOCSYNC_WP_MINIMUM_WP_VERSION, '>=' );
}

/**
 * Render a notice when runtime requirements are not met.
 */
function docsync_wp_render_runtime_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	global $wp_version;

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: required PHP version, 2: current PHP version, 3: required WordPress version, 4: current WordPress version. */
				__( 'DocSync WP requires PHP %1$s or newer and WordPress %3$s or newer. Current versions are PHP %2$s and WordPress %4$s.', 'docsync-wp' ),
				DOCSYNC_WP_MINIMUM_PHP_VERSION,
				PHP_VERSION,
				DOCSYNC_WP_MINIMUM_WP_VERSION,
				(string) $wp_version
			)
		)
	);
}

/**
 * Render a notice when Composer dependencies have not been installed.
 */
function docsync_wp_render_missing_autoload_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: command to install dependencies. */
					__( 'DocSync WP could not find Composer autoloading. Run %s in the plugin directory before using the plugin.', 'docsync-wp' ),
					'<code>composer install</code>'
				),
				array(
					'code' => array(),
				)
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Activation callback.
 */
function docsync_wp_activate(): void {
	if ( docsync_wp_runtime_is_supported() ) {
		return;
	}

	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	deactivate_plugins( DOCSYNC_WP_BASENAME );

	wp_die(
		esc_html__(
			'DocSync WP requires PHP 8.1 or newer and WordPress 6.4 or newer.',
			'docsync-wp'
		),
		esc_html__( 'DocSync WP activation error', 'docsync-wp' ),
		array( 'back_link' => true )
	);
}
register_activation_hook( __FILE__, 'docsync_wp_activate' );

if ( ! docsync_wp_runtime_is_supported() ) {
	add_action( 'admin_notices', 'docsync_wp_render_runtime_notice' );
	return;
}

$docsync_wp_autoload = DOCSYNC_WP_PATH . 'vendor/autoload.php';

if ( ! file_exists( $docsync_wp_autoload ) ) {
	add_action( 'admin_notices', 'docsync_wp_render_missing_autoload_notice' );
	return;
}

require_once $docsync_wp_autoload;

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( DocSyncWP\Plugin::class ) ) {
			add_action( 'admin_notices', 'docsync_wp_render_missing_autoload_notice' );
			return;
		}

		DocSyncWP\Plugin::boot();
	}
);
