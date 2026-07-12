<?php
/**
 * Writes Elementor data and supporting meta to a post.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

use WP_Error;

use function is_wp_error;

defined( 'ABSPATH' ) || exit;

/**
 * Updates a post to be rendered by Elementor from generated data.
 */
final class PostUpdater {
	/**
	 * Compatibility checker.
	 *
	 * @var CompatibilityChecker
	 */
	private CompatibilityChecker $compatibility;

	/**
	 * Constructor.
	 *
	 * @param CompatibilityChecker|null $compatibility Compatibility checker.
	 */
	public function __construct( ?CompatibilityChecker $compatibility = null ) {
		$this->compatibility = $compatibility ?? new CompatibilityChecker();
	}

	/**
	 * Store Elementor data and supporting meta on a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $json    Elementor JSON data.
	 * @return bool|WP_Error
	 */
	public function update( int $post_id, string $json ): bool|WP_Error {
		if ( '' === trim( $json ) ) {
			return new WP_Error(
				'docsync_wp_empty_elementor_data',
				__( 'Brasth Document Sync could not generate Elementor data for this post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$post = get_post( $post_id );

		if ( null === $post ) {
			return new WP_Error(
				'docsync_wp_invalid_post',
				__( 'Brasth Document Sync cannot update Elementor data for an invalid post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', $this->templateType( $post->post_type ) );

		$version = $this->compatibility->getElementorVersion();

		if ( '' !== $version ) {
			update_post_meta( $post_id, '_elementor_version', $version );
		}

		$pro_version = $this->compatibility->getElementorProVersion();

		if ( '' !== $pro_version ) {
			update_post_meta( $post_id, '_elementor_pro_version', $pro_version );
		}

		delete_post_meta( $post_id, '_elementor_css' );
		$this->clearCache( $post_id );

		return true;
	}

	/**
	 * Clear Elementor CSS caches so the new layout is rendered.
	 *
	 * Tries to delete only the synced post's CSS file before falling back to
	 * the global Elementor cache clear.
	 *
	 * @param int $post_id Post ID.
	 */
	private function clearCache( int $post_id ): void {
		if ( ! $this->compatibility->isElementorActive() ) {
			return;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( ! method_exists( $plugin, 'files_manager' ) ) {
			return;
		}

		$files_manager = $plugin->files_manager;

		if ( class_exists( '\Elementor\Core\Files\CSS\Post_CSS' ) ) {
			try {
				$post_css = \Elementor\Core\Files\CSS\Post_CSS::create( $post_id );

				if ( method_exists( $post_css, 'delete' ) ) {
					$post_css->delete();
					return;
				}
			} catch ( \Throwable $e ) {
				// Fall through to global cache clear.
				unset( $e );
			}
		}

		if ( method_exists( $files_manager, 'clear_cache' ) ) {
			$files_manager->clear_cache();
		}
	}

	/**
	 * Elementor template type for a WordPress post type.
	 *
	 * @param string $post_type Post type.
	 */
	private function templateType( string $post_type ): string {
		return 'page' === $post_type ? 'wp-page' : 'wp-post';
	}
}
