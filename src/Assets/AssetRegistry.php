<?php
/**
 * Vite asset registration.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Assets;

use DocSyncWP\Admin\AdminPage;
use JsonException;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues compiled admin assets from Vite's manifest.
 */
final class AssetRegistry {
	private const ENTRY = 'resources/js/admin/main.tsx';

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
	 * Constructor.
	 *
	 * @param string $plugin_path Absolute plugin directory path.
	 * @param string $plugin_url  Absolute plugin directory URL.
	 * @param string $version     Plugin version.
	 */
	public function __construct( string $plugin_path, string $plugin_url, string $version ) {
		$this->plugin_path = trailingslashit( $plugin_path );
		$this->plugin_url  = trailingslashit( $plugin_url );
		$this->version     = $version;
	}

	/**
	 * Enqueue the admin React application on the plugin screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueueAdminApp( string $hook ): void {
		if ( AdminPage::HOOK_SUFFIX !== $hook || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$entry = $this->getManifestEntry();

		if ( null === $entry || empty( $entry['file'] ) || ! is_string( $entry['file'] ) ) {
			return;
		}

		$script_path = $this->plugin_path . 'build/' . ltrim( $entry['file'], '/' );

		if ( ! file_exists( $script_path ) ) {
			return;
		}

		$handle = 'docsync-wp-admin';

		wp_enqueue_script(
			$handle,
			$this->plugin_url . 'build/' . ltrim( $entry['file'], '/' ),
			array( 'wp-element' ),
			$this->assetVersion( $script_path ),
			true
		);

		wp_add_inline_script(
			$handle,
			'window.DocSyncWPAdmin = ' . wp_json_encode( $this->adminConfig() ) . ';',
			'before'
		);

		foreach ( $this->getStylesheetFiles( $entry ) as $index => $css_file ) {
			$style_path = $this->plugin_path . 'build/' . ltrim( $css_file, '/' );

			if ( ! file_exists( $style_path ) ) {
				continue;
			}

			wp_enqueue_style(
				0 === $index ? $handle : $handle . '-' . (string) $index,
				$this->plugin_url . 'build/' . ltrim( $css_file, '/' ),
				array(),
				$this->assetVersion( $style_path )
			);
		}
	}

	/**
	 * Render an admin notice when the Vite build is missing.
	 */
	public function renderMissingBuildNotice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if (
			! $screen
			|| AdminPage::HOOK_SUFFIX !== $screen->id
			|| ! current_user_can( 'manage_options' )
			|| $this->hasBuild()
		) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php
				echo wp_kses(
					sprintf(
						/* translators: %s: command to build frontend assets. */
						__( 'DocSync WP admin assets are not built yet. Run %s before using this screen.', 'docsync-wp' ),
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
	 */
	public function hasBuild(): bool {
		$entry = $this->getManifestEntry();

		return null !== $entry
			&& ! empty( $entry['file'] )
			&& is_string( $entry['file'] )
			&& file_exists( $this->plugin_path . 'build/' . ltrim( $entry['file'], '/' ) );
	}

	/**
	 * Get the manifest entry for the admin app.
	 *
	 * @return array<string,mixed>|null
	 */
	private function getManifestEntry(): ?array {
		$manifest = $this->readManifest();

		if ( isset( $manifest[ self::ENTRY ] ) && is_array( $manifest[ self::ENTRY ] ) ) {
			return $manifest[ self::ENTRY ];
		}

		foreach ( $manifest as $entry ) {
			if (
				is_array( $entry )
				&& ! empty( $entry['isEntry'] )
				&& isset( $entry['src'] )
				&& self::ENTRY === $entry['src']
			) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Get stylesheet files referenced by the manifest.
	 *
	 * @param array<string,mixed> $entry Main JavaScript manifest entry.
	 * @return array<int,string>
	 */
	private function getStylesheetFiles( array $entry ): array {
		$css_files = array();

		if ( ! empty( $entry['css'] ) && is_array( $entry['css'] ) ) {
			foreach ( $entry['css'] as $css_file ) {
				if ( is_string( $css_file ) ) {
					$css_files[] = $css_file;
				}
			}
		}

		foreach ( $this->readManifest() as $manifest_entry ) {
			if (
				is_array( $manifest_entry )
				&& isset( $manifest_entry['file'] )
				&& is_string( $manifest_entry['file'] )
				&& str_ends_with( $manifest_entry['file'], '.css' )
			) {
				$css_files[] = $manifest_entry['file'];
			}
		}

		return array_values( array_unique( $css_files ) );
	}

	/**
	 * Read Vite's build manifest.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function readManifest(): array {
		$manifest_path = $this->plugin_path . 'build/manifest.json';

		if ( ! is_readable( $manifest_path ) ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local build manifest.
		$contents = file_get_contents( $manifest_path );

		if ( false === $contents ) {
			return array();
		}

		try {
			$manifest = json_decode( $contents, true, 512, JSON_THROW_ON_ERROR );
		} catch ( JsonException $exception ) {
			return array();
		}

		return is_array( $manifest ) ? $manifest : array();
	}

	/**
	 * Build the config exposed to the admin app.
	 *
	 * @return array<string,string>
	 */
	private function adminConfig(): array {
		return array(
			'restUrl'   => esc_url_raw( rest_url( 'docsync-wp/v1' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'pluginUrl' => esc_url_raw( $this->plugin_url ),
			'version'   => $this->version,
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
