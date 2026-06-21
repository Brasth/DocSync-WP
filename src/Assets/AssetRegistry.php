<?php
/**
 * Vite asset registration.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Assets;

use DocSyncWP\Admin\AdminPage;
use DocSyncWP\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues compiled admin assets from Vite manifests.
 */
final class AssetRegistry {
	private const ADMIN_ENTRY        = 'resources/js/admin/entries/admin-entry.tsx';
	private const POST_SYNC_ENTRY    = 'resources/js/admin/entries/post-sync-entry.tsx';
	private const ADMIN_MANIFEST     = 'manifest.json';
	private const POST_SYNC_MANIFEST = 'manifest.post-sync.json';
	private const ADMIN_HANDLE       = 'docsync-wp-admin';
	private const POST_SYNC_HANDLE   = 'docsync-wp-post-sync';
	private const POST_SYNC_HOOKS    = array( 'post.php', 'post-new.php', 'edit.php' );

	/**
	 * Absolute plugin directory path.
	 *
	 * @var string
	 */
	private string $plugin_path;

	/**
	 * Absolute plugin directory URL.
	 *
	 * @var string
	 */
	private string $plugin_url;

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param string             $plugin_path Absolute plugin directory path.
	 * @param string             $plugin_url  Absolute plugin directory URL.
	 * @param string             $version     Plugin version.
	 * @param SettingsRepository $settings    Settings repository.
	 */
	public function __construct( string $plugin_path, string $plugin_url, string $version, SettingsRepository $settings ) {
		$this->plugin_path = trailingslashit( $plugin_path );
		$this->plugin_url  = trailingslashit( $plugin_url );
		$this->version     = $version;
		$this->settings    = $settings;
	}

	/**
	 * Enqueue the right admin bundle for the current screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueueAdminApp( string $hook ): void {
		$plugin_page = $GLOBALS['plugin_page'] ?? '';

		if ( in_array( $plugin_page, array( AdminPage::MENU_SLUG, AdminPage::SOURCES_MENU_SLUG, AdminPage::LOGS_MENU_SLUG ), true ) && current_user_can( 'manage_options' ) ) {
			$this->enqueueEntry( self::ADMIN_ENTRY, self::ADMIN_MANIFEST, self::ADMIN_HANDLE );
			return;
		}

		if ( $this->shouldEnqueuePostSyncEntry( $hook ) ) {
			$this->enqueueEntry( self::POST_SYNC_ENTRY, self::POST_SYNC_MANIFEST, self::POST_SYNC_HANDLE );
		}
	}

	/**
	 * Render an admin notice when a required Vite build is missing.
	 */
	public function renderMissingBuildNotice(): void {
		$plugin_page = $GLOBALS['plugin_page'] ?? '';

		$is_admin_app_screen = in_array(
			$plugin_page,
			array( AdminPage::MENU_SLUG, AdminPage::SOURCES_MENU_SLUG, AdminPage::LOGS_MENU_SLUG ),
			true
		) && current_user_can( 'manage_options' );

		$is_post_sync_screen = false;

		if ( ! $is_admin_app_screen ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

			$is_post_sync_screen = null !== $screen
				&& in_array( $screen->base, array( 'post', 'edit' ), true )
				&& is_user_logged_in()
				&& ! empty( $screen->post_type )
				&& in_array( (string) $screen->post_type, $this->settings->getEnabledPostTypes(), true );
		}

		if ( ! $is_admin_app_screen && ! $is_post_sync_screen ) {
			return;
		}

		$missing_entry = null;

		if ( $is_admin_app_screen && ! $this->hasBuild( self::ADMIN_ENTRY, self::ADMIN_MANIFEST ) ) {
			$missing_entry = 'Brasth Document Sync admin';
		}

		if ( $is_post_sync_screen && ! $this->hasBuild( self::POST_SYNC_ENTRY, self::POST_SYNC_MANIFEST ) ) {
			$missing_entry = 'Brasth Document Sync post actions';
		}

		if ( null === $missing_entry ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: 1: asset group, 2: command to build frontend assets. */
						__( '%1$s assets are not built yet. Run %2$s before using this screen.', 'brasth-document-sync-for-google-docs' ),
						esc_html( $missing_entry ),
						'<code>pnpm build</code>'
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
	 * Whether the configured Vite entry is built and readable.
	 *
	 * @param string $entry_name    Vite entry name.
	 * @param string $manifest_file Manifest file name.
	 */
	public function hasBuild( string $entry_name = self::ADMIN_ENTRY, string $manifest_file = self::ADMIN_MANIFEST ): bool {
		$entry = $this->getManifestEntry( $entry_name, $manifest_file );

		return null !== $entry
			&& ! empty( $entry['file'] )
			&& is_string( $entry['file'] )
			&& file_exists( $this->plugin_path . 'build/' . ltrim( $entry['file'], '/' ) );
	}

	/**
	 * Enqueue a Vite manifest entry.
	 *
	 * @param string $entry_name    Vite entry name.
	 * @param string $manifest_file Manifest file name.
	 * @param string $handle        WordPress script handle.
	 */
	private function enqueueEntry( string $entry_name, string $manifest_file, string $handle ): void {
		$entry = $this->getManifestEntry( $entry_name, $manifest_file );

		if ( null === $entry || empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
			return;
		}

		$script_path = $this->plugin_path . 'build/' . ltrim( $entry['file'], '/' );

		if ( ! file_exists( $script_path ) ) {
			return;
		}

		wp_enqueue_script(
			$handle,
			$this->plugin_url . 'build/' . ltrim( $entry['file'], '/' ),
			$this->scriptDependencies( $handle ),
			$this->assetVersion( $script_path ),
			true
		);

		wp_add_inline_script(
			$handle,
			'window.DocSyncWPAdmin = ' . wp_json_encode( $this->adminConfig() ) . ';',
			'before'
		);

		if ( wp_style_is( 'wp-components', 'registered' ) ) {
			wp_enqueue_style( 'wp-components' );
		}

		foreach ( $this->getStylesheetFiles( $entry, $manifest_file ) as $index => $css_file ) {
			$style_path = $this->plugin_path . 'build/' . ltrim( $css_file, '/' );

			if ( ! file_exists( $style_path ) ) {
				continue;
			}

			wp_enqueue_style(
				0 === $index ? $handle : $handle . '-' . (string) $index,
				$this->plugin_url . 'build/' . ltrim( $css_file, '/' ),
				wp_style_is( 'wp-components', 'enqueued' ) ? array( 'wp-components' ) : array(),
				$this->assetVersion( $style_path )
			);
		}
	}

	/**
	 * Get WordPress script dependencies for a bundle.
	 *
	 * @param string $handle Script handle.
	 * @return array<int,string>
	 */
	private function scriptDependencies( string $handle ): array {
		$dependencies = array( 'wp-a11y', 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-url' );

		if ( self::POST_SYNC_HANDLE === $handle ) {
			$dependencies[] = 'wp-data';
		}

		return $dependencies;
	}

	/**
	 * Whether to load post sync controls on the current admin screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	private function shouldEnqueuePostSyncEntry( string $hook ): bool {
		if ( ! in_array( $hook, self::POST_SYNC_HOOKS, true ) || ! is_user_logged_in() ) {
			return false;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || empty( $screen->post_type ) ) {
			return false;
		}

		return in_array( (string) $screen->post_type, $this->settings->getEnabledPostTypes(), true );
	}

	/**
	 * Get a manifest entry.
	 *
	 * @param string $entry_name    Vite entry name.
	 * @param string $manifest_file Manifest file name.
	 * @return array<string,mixed>|null
	 */
	private function getManifestEntry( string $entry_name, string $manifest_file ): ?array {
		$manifest = $this->readManifest( $manifest_file );

		if ( isset( $manifest[ $entry_name ] ) && is_array( $manifest[ $entry_name ] ) ) {
			return $manifest[ $entry_name ];
		}

		foreach ( $manifest as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['isEntry'] ) && isset( $entry['src'] ) && $entry_name === $entry['src'] ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Get stylesheet files referenced by the manifest.
	 *
	 * @param array<string,mixed> $entry         JavaScript manifest entry.
	 * @param string              $manifest_file Manifest file name.
	 * @return array<int,string>
	 */
	private function getStylesheetFiles( array $entry, string $manifest_file ): array {
		$css_files = array();

		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $css_file ) {
				if ( is_string( $css_file ) ) {
					$css_files[] = $css_file;
				}
			}
		}

		foreach ( $this->readManifest( $manifest_file ) as $manifest_entry ) {
			if ( is_array( $manifest_entry ) && isset( $manifest_entry['file'] ) && is_string( $manifest_entry['file'] ) && str_ends_with( $manifest_entry['file'], '.css' ) ) {
				$css_files[] = $manifest_entry['file'];
			}
		}

		return array_values( array_unique( $css_files ) );
	}

	/**
	 * Read a Vite build manifest.
	 *
	 * @param string $manifest_file Manifest file name.
	 * @return array<string,array<string,mixed>>
	 */
	private function readManifest( string $manifest_file ): array {
		$manifest_path = $this->plugin_path . 'build/' . $manifest_file;

		if ( ! is_readable( $manifest_path ) ) {
			return array();
		}

		$manifest = wp_json_file_decode( $manifest_path, array( 'associative' => true ) );

		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Build the config exposed to admin scripts.
	 *
	 * @return array<string,mixed>
	 */
	private function adminConfig(): array {
		$settings = $this->settings->getPublicSettings();

		return array(
			'restUrl'             => esc_url_raw( rest_url( 'brasth-document-sync-for-google-docs/v1' ) ),
			'nonce'               => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'           => esc_url_raw( $this->plugin_url ),
			'version'             => $this->version,
			'currentUserId'       => get_current_user_id(),
			'clientId'            => $settings['client_id'],
			'connectionMode'      => $settings['connection_mode'],
			'enabledPostTypes'    => $settings['enabled_post_types'],
			'availablePostTypes'  => $this->settings->getAvailablePostTypes(),
			'defaultExportFormat' => $settings['default_export_format'],
			'syncInterval'        => $settings['sync_interval'],
			'hasClientId'         => (bool) $settings['has_client_id'],
			'hasClientSecret'     => (bool) $settings['has_client_secret'],
			'hasRequiredSettings' => (bool) $settings['has_required_settings'],
		);
	}

	/**
	 * Version assets by mtime, falling back to the plugin version.
	 *
	 * @param string $path Absolute asset path.
	 */
	private function assetVersion( string $path ): string {
		$mtime = filemtime( $path );

		return false === $mtime ? $this->version : (string) $mtime;
	}
}
