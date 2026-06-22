<?php
/**
 * Detects Elementor plugin state and post-level Elementor usage.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Checks whether Elementor is available and how a post is built.
 */
final class CompatibilityChecker {
	/**
	 * Whether Elementor is installed and active.
	 */
	public function isElementorActive(): bool {
		return class_exists( '\Elementor\Plugin' );
	}

	/**
	 * Whether a post was built with Elementor.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isBuiltWithElementor( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );

		if ( 'builder' === $edit_mode ) {
			return true;
		}

		if ( ! $this->isElementorActive() ) {
			return false;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( ! method_exists( $plugin, 'documents' ) ) {
			return false;
		}

		$document = $plugin->documents->get( $post_id );

		if ( null === $document ) {
			return false;
		}

		return method_exists( $document, 'is_built_with_elementor' )
			&& $document->is_built_with_elementor();
	}

	/**
	 * Whether the site uses Elementor containers by default.
	 *
	 * Falls back to true when detection is not possible because containers are
	 * the modern default and have been active by default since Elementor 3.8+.
	 */
	public function usesContainers(): bool {
		if ( ! $this->isElementorActive() ) {
			return true;
		}

		$plugin = \Elementor\Plugin::instance();

		if ( ! method_exists( $plugin, 'experiments' ) ) {
			return true;
		}

		$active = $plugin->experiments->is_feature_active( 'container' );

		if ( true === $active ) {
			return true;
		}

		if ( false === $active ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether an existing Elementor post uses containers.
	 *
	 * Inspects the stored `_elementor_data` structure. If the post has no
	 * Elementor data yet, falls back to the site default.
	 *
	 * @param int $post_id Post ID.
	 */
	public function postUsesContainers( int $post_id ): bool {
		$data = $this->getElementorData( $post_id );

		if ( ! is_array( $data ) || array() === $data ) {
			return $this->usesContainers();
		}

		foreach ( $data as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['elType'] ) ) {
				continue;
			}

			$type = (string) $element['elType'];

			if ( 'container' === $type ) {
				return true;
			}

			if ( 'section' === $type ) {
				return false;
			}
		}

		return $this->usesContainers();
	}

	/**
	 * Get the installed Elementor version if available.
	 */
	public function getElementorVersion(): string {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			return (string) ELEMENTOR_VERSION;
		}

		return '';
	}

	/**
	 * Get the installed Elementor Pro version if available.
	 */
	public function getElementorProVersion(): string {
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return (string) ELEMENTOR_PRO_VERSION;
		}

		return '';
	}

	/**
	 * Decode the stored Elementor data for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function getElementorData( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}
}
