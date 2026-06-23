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
	private const SETUP_ENTRY               = 'resources/js/admin/entries/setup-entry.tsx';
	private const SOURCES_ENTRY             = 'resources/js/admin/entries/sources-entry.tsx';
	private const LOGS_ENTRY                = 'resources/js/admin/entries/logs-entry.tsx';
	private const POST_SYNC_ENTRY           = 'resources/js/admin/entries/post-sync-entry.tsx';
	private const DOC_SOURCE_MODAL_ENTRY    = 'resources/js/admin/entries/doc-source-modal-entry.ts';
	private const DRIVE_BROWSER_ENTRY       = 'resources/js/admin/entries/drive-browser-entry.tsx';
	private const SETUP_MANIFEST            = 'manifest.setup.json';
	private const SOURCES_MANIFEST          = 'manifest.sources.json';
	private const LOGS_MANIFEST             = 'manifest.logs.json';
	private const POST_SYNC_MANIFEST        = 'manifest.post-sync.json';
	private const DOC_SOURCE_MODAL_MANIFEST = 'manifest.doc-source-modal.json';
	private const DRIVE_BROWSER_MANIFEST    = 'manifest.drive-browser.json';
	private const SETUP_HANDLE              = 'docsync-wp-setup';
	private const SOURCES_HANDLE            = 'docsync-wp-sources';
	private const LOGS_HANDLE               = 'docsync-wp-logs';
	private const POST_SYNC_HANDLE          = 'docsync-wp-post-sync';
	private const POST_SYNC_HOOKS           = array( 'post.php', 'post-new.php', 'edit.php' );

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

		$admin_entry = $this->adminEntryForPluginPage( is_string( $plugin_page ) ? $plugin_page : '' );

		if ( null !== $admin_entry && current_user_can( 'manage_options' ) ) {
			$this->enqueueEntry( $admin_entry['entry'], $admin_entry['manifest'], $admin_entry['handle'] );
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
		$admin_entry = $this->adminEntryForPluginPage( is_string( $plugin_page ) ? $plugin_page : '' );

		$is_admin_app_screen = null !== $admin_entry && current_user_can( 'manage_options' );

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

		$missing_entries = array();

		if ( $is_admin_app_screen && null !== $admin_entry && ! $this->hasBuild( $admin_entry['entry'], $admin_entry['manifest'] ) ) {
			$missing_entries[] = $admin_entry['label'];
		}

		if ( $is_post_sync_screen && ! $this->hasBuild( self::POST_SYNC_ENTRY, self::POST_SYNC_MANIFEST ) ) {
			$missing_entries[] = 'Brasth Document Sync post actions';
		}

		if ( $is_post_sync_screen && ! $this->hasBuild( self::DRIVE_BROWSER_ENTRY, self::DRIVE_BROWSER_MANIFEST ) ) {
			$missing_entries[] = 'Brasth Document Sync Drive browser';
		}

		if ( $is_post_sync_screen && ! $this->hasBuild( self::DOC_SOURCE_MODAL_ENTRY, self::DOC_SOURCE_MODAL_MANIFEST ) ) {
			$missing_entries[] = 'Brasth Document Sync source modal';
		}

		if ( array() === $missing_entries ) {
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
						esc_html( implode( ', ', $missing_entries ) ),
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
	public function hasBuild( string $entry_name = self::SETUP_ENTRY, string $manifest_file = self::SETUP_MANIFEST ): bool {
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
			$this->scriptDependencies(),
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
	 * @return array<int,string>
	 */
	private function scriptDependencies(): array {
		return array( 'wp-a11y', 'wp-api-fetch', 'wp-components', 'wp-element', 'wp-i18n', 'wp-url' );
	}

	/**
	 * Get the Vite entry metadata for a plugin admin page.
	 *
	 * @param string $plugin_page Current plugin page slug.
	 * @return array{entry:string,manifest:string,handle:string,label:string}|null
	 */
	private function adminEntryForPluginPage( string $plugin_page ): ?array {
		if ( AdminPage::MENU_SLUG === $plugin_page ) {
			return array(
				'entry'    => self::SETUP_ENTRY,
				'manifest' => self::SETUP_MANIFEST,
				'handle'   => self::SETUP_HANDLE,
				'label'    => 'Brasth Document Sync setup',
			);
		}

		if ( AdminPage::SOURCES_MENU_SLUG === $plugin_page ) {
			return array(
				'entry'    => self::SOURCES_ENTRY,
				'manifest' => self::SOURCES_MANIFEST,
				'handle'   => self::SOURCES_HANDLE,
				'label'    => 'Brasth Document Sync sources',
			);
		}

		if ( AdminPage::LOGS_MENU_SLUG === $plugin_page ) {
			return array(
				'entry'    => self::LOGS_ENTRY,
				'manifest' => self::LOGS_MANIFEST,
				'handle'   => self::LOGS_HANDLE,
				'label'    => 'Brasth Document Sync logs',
			);
		}

		return null;
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
			'restUrl'                 => esc_url_raw( rest_url( 'brasth-document-sync-for-google-docs/v1' ) ),
			'nonce'                   => wp_create_nonce( 'wp_rest' ),
			'pluginUrl'               => esc_url_raw( $this->plugin_url ),
			'version'                 => $this->version,
			'currentUserId'           => get_current_user_id(),
			'clientId'                => $settings['client_id'],
			'connectionMode'          => $settings['connection_mode'],
			'enabledPostTypes'        => $settings['enabled_post_types'],
			'availablePostTypes'      => $this->settings->getAvailablePostTypes(),
			'defaultExportFormat'     => $settings['default_export_format'],
			'syncInterval'            => $settings['sync_interval'],
			'hasClientId'             => (bool) $settings['has_client_id'],
			'hasClientSecret'         => (bool) $settings['has_client_secret'],
			'hasRequiredSettings'     => (bool) $settings['has_required_settings'],
			'docSourceModalStyleUrls' => $this->entryStyleUrls( self::DOC_SOURCE_MODAL_ENTRY, self::DOC_SOURCE_MODAL_MANIFEST ),
			'driveBrowserScriptUrl'   => $this->entryScriptUrl( self::DRIVE_BROWSER_ENTRY, self::DRIVE_BROWSER_MANIFEST ),
			'driveBrowserStyleUrls'   => $this->entryStyleUrls( self::DRIVE_BROWSER_ENTRY, self::DRIVE_BROWSER_MANIFEST ),
		);
	}

	/**
	 * Get a built entry script URL with its asset version.
	 *
	 * @param string $entry_name    Vite entry name.
	 * @param string $manifest_file Manifest file name.
	 */
	private function entryScriptUrl( string $entry_name, string $manifest_file ): string {
		$entry = $this->getManifestEntry( $entry_name, $manifest_file );

		if ( null === $entry || empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
			return '';
		}

		return $this->buildAssetUrl( $entry['file'] );
	}

	/**
	 * Get built entry stylesheet URLs with asset versions.
	 *
	 * @param string $entry_name    Vite entry name.
	 * @param string $manifest_file Manifest file name.
	 * @return array<int,string>
	 */
	private function entryStyleUrls( string $entry_name, string $manifest_file ): array {
		$entry = $this->getManifestEntry( $entry_name, $manifest_file );

		if ( null === $entry ) {
			return array();
		}

		$urls = array();

		foreach ( $this->getStylesheetFiles( $entry, $manifest_file ) as $css_file ) {
			$url = $this->buildAssetUrl( $css_file );

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		return $urls;
	}

	/**
	 * Build a versioned URL for a built asset.
	 *
	 * @param string $asset_file Built asset file relative to build directory.
	 */
	private function buildAssetUrl( string $asset_file ): string {
		$asset_file = ltrim( $asset_file, '/' );
		$asset_path = $this->plugin_path . 'build/' . $asset_file;

		if ( ! file_exists( $asset_path ) ) {
			return '';
		}

		return add_query_arg(
			'ver',
			rawurlencode( $this->assetVersion( $asset_path ) ),
			$this->plugin_url . 'build/' . $asset_file
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
