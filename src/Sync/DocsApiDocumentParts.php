<?php
/**
 * Extracts Google Docs API document parts.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Handles root and tabbed Docs API document structures.
 */
final class DocsApiDocumentParts {
	/**
	 * Get root and tab document parts.
	 *
	 * @param array<string,mixed> $document Docs API document.
	 * @return array<int,array<string,mixed>>
	 */
	public function parts( array $document ): array {
		if ( isset( $document['tabs'] ) && is_array( $document['tabs'] ) ) {
			return $this->tabParts( $document['tabs'] );
		}

		return array( $document );
	}

	/**
	 * Build render context for one document part.
	 *
	 * @param array<string,mixed> $part Document or tab document.
	 * @param array<string,mixed> $root Root document.
	 * @return array{inlineObjects:array<mixed>,lists:array<mixed>}
	 */
	public function context( array $part, array $root ): array {
		$root_inline_objects = isset( $root['inlineObjects'] ) && is_array( $root['inlineObjects'] ) ? $root['inlineObjects'] : array();
		$root_lists          = isset( $root['lists'] ) && is_array( $root['lists'] ) ? $root['lists'] : array();

		return array(
			'inlineObjects' => isset( $part['inlineObjects'] ) && is_array( $part['inlineObjects'] ) ? $part['inlineObjects'] : $root_inline_objects,
			'lists'         => isset( $part['lists'] ) && is_array( $part['lists'] ) ? $part['lists'] : $root_lists,
		);
	}

	/**
	 * Recursively collect tab document parts.
	 *
	 * @param array<int,array<string,mixed>> $tabs Docs tabs.
	 * @return array<int,array<string,mixed>>
	 */
	private function tabParts( array $tabs ): array {
		$parts = array();

		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}

			if ( isset( $tab['documentTab'] ) && is_array( $tab['documentTab'] ) ) {
				$parts[] = $tab['documentTab'];
			}

			if ( isset( $tab['childTabs'] ) && is_array( $tab['childTabs'] ) ) {
				$parts = array_merge( $parts, $this->tabParts( $tab['childTabs'] ) );
			}
		}

		return $parts;
	}
}
