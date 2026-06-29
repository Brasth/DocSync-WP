<?php
/**
 * Plugin Name:       Brasth Document Sync for Google Docs
 * Description:       Sync Google Docs into WordPress posts and pages with layout presets, self-managed Google OAuth, and optional Elementor layout support.
 * Version:           1.1.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Brasth
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       brasth-document-sync-for-google-docs
 * Domain Path:       /languages
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'DOCSYNC_WP_VERSION', '1.1.1' );
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
				__( 'Brasth Document Sync requires PHP %1$s or newer and WordPress %3$s or newer. Current versions are PHP %2$s and WordPress %4$s.', 'brasth-document-sync-for-google-docs' ),
				DOCSYNC_WP_MINIMUM_PHP_VERSION,
				PHP_VERSION,
				DOCSYNC_WP_MINIMUM_WP_VERSION,
				(string) $wp_version
			)
		)
	);
}

/**
 * Check whether the current admin screen belongs to this plugin.
 */
function docsync_wp_is_plugin_admin_context(): bool {
	$plugin_page = $GLOBALS['plugin_page'] ?? '';

	if ( in_array(
		$plugin_page,
		array(
			'brasth-document-sync-for-google-docs',
			'brasth-document-sync-for-google-docs-sources',
			'brasth-document-sync-for-google-docs-logs',
		),
		true
	) ) {
		return true;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	return null !== $screen && 'plugins' === $screen->id;
}

/**
 * Render a notice when Composer dependencies have not been installed.
 */
function docsync_wp_render_missing_autoload_notice(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	if ( ! docsync_wp_is_plugin_admin_context() ) {
		return;
	}

	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo wp_kses(
				sprintf(
					/* translators: %s: command to install dependencies. */
					__( 'Brasth Document Sync could not find Composer autoloading. Run %s in the plugin directory before using the plugin.', 'brasth-document-sync-for-google-docs' ),
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
 * Add suggested privacy policy content for Brasth Document Sync data handling.
 */
function docsync_wp_add_privacy_policy_content(): void {
	if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
		return;
	}

	$content = wp_kses_post(
		wpautop(
			__(
				'Brasth Document Sync lets authorized WordPress users connect their own Google account to import Google Docs content into WordPress. The site owner supplies a Google OAuth client ID and client secret. Brasth Document Sync stores those site credentials, per-user Google access and refresh tokens, connected Google account email addresses, linked Google document metadata, source sync status, diagnostic sync events, and imported attachment metadata in the WordPress database.

When a user connects Google, Brasth Document Sync sends OAuth authorization and token refresh requests to Google. During browsing and sync, Brasth Document Sync sends connected-user access tokens, Google Drive file IDs, folder IDs, shared-drive IDs, Drive search text, pagination tokens, and document export or read requests to the Google Drive API and Google Docs API. Google can return account email, OAuth tokens, document titles, document metadata, document export content, document structure, and image content URLs used to import media into WordPress.

Imported Google Docs images are stored in the WordPress Media Library. Synced posts, pages, imported media, and linked post metadata remain on the site until a user with sufficient permission changes or deletes them. Uninstall removes plugin settings, encrypted user Google tokens, and scheduled cron events. Linked post metadata is retained by default unless full Brasth Document Sync uninstall cleanup is enabled. Synced posts and imported media are not deleted automatically.

Google provides the OAuth, Drive, and Docs services used by this plugin. Google Privacy Policy: https://policies.google.com/privacy. Google API Services User Data Policy: https://developers.google.com/terms/api-services-user-data-policy. Google APIs Terms of Service: https://developers.google.com/terms.',
				'brasth-document-sync-for-google-docs'
			)
		)
	);

	wp_add_privacy_policy_content( 'Brasth Document Sync', $content );
}

/**
 * Activation callback.
 */
function docsync_wp_activate(): void {
	if ( ! docsync_wp_runtime_is_supported() ) {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( DOCSYNC_WP_BASENAME );

		wp_die(
			esc_html__(
				'Brasth Document Sync requires PHP 8.1 or newer and WordPress 6.4 or newer.',
				'brasth-document-sync-for-google-docs'
			),
			esc_html__( 'Brasth Document Sync activation error', 'brasth-document-sync-for-google-docs' ),
			array( 'back_link' => true )
		);
	}

	if ( false === get_option( 'docsync_wp_settings', false ) ) {
		add_option(
			'docsync_wp_settings',
			array(
				'default_layout_preset' => 'clean_article',
			),
			'',
			false
		);
	}
}
register_activation_hook( __FILE__, 'docsync_wp_activate' );

/**
 * Deactivation callback.
 */
function docsync_wp_deactivate(): void {
	$autoload = DOCSYNC_WP_PATH . 'vendor/autoload.php';

	if ( file_exists( $autoload ) ) {
		require_once $autoload;
	}

	if ( class_exists( DocSyncWP\Cron\SyncCron::class ) ) {
		DocSyncWP\Cron\SyncCron::unschedule();
	} else {
		wp_clear_scheduled_hook( 'docsync_wp_sync_sources' );
		wp_clear_scheduled_hook( 'docsync_wp_sync_source' );
	}
}
register_deactivation_hook( __FILE__, 'docsync_wp_deactivate' );

if ( ! docsync_wp_runtime_is_supported() ) {
	add_action( 'admin_notices', 'docsync_wp_render_runtime_notice' );
	return;
}

$docsync_wp_autoload = DOCSYNC_WP_PATH . 'vendor/autoload.php';

add_action( 'admin_init', 'docsync_wp_add_privacy_policy_content' );

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
