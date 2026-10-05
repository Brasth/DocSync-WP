<?php
/**
 * Stable fingerprints for parsed Gutenberg blocks.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Region;

defined( 'ABSPATH' ) || exit;

/**
 * Hashes block name, attributes, and normalized inner HTML, ignoring whitespace differences.
 */
final class BlockFingerprint {
	private const SEMANTIC_ATTRIBUTES = array( 'level', 'ordered', 'ref', 'slug' );

	/**
	 * Fingerprint one parsed block (including inner blocks).
	 *
	 * Deliberately tolerant of what the block editor rewrites on save (wrapper classes, attribute
	 * order, markup normalization): the hash covers block name, semantic attributes, visible text,
	 * link and image targets, and inner blocks. Real content edits still change it.
	 *
	 * @param array<string,mixed> $block Parsed block as returned by parse_blocks().
	 */
	public static function of( array $block ): string {
		$attrs = array();

		if ( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
			foreach ( self::SEMANTIC_ATTRIBUTES as $name ) {
				if ( array_key_exists( $name, $block['attrs'] ) ) {
					$attrs[ $name ] = $block['attrs'][ $name ];
				}
			}
		}

		self::sortRecursive( $attrs );

		$inner = array();

		if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $child ) {
				if ( is_array( $child ) && ! self::isWhitespaceOnly( $child ) ) {
					$inner[] = self::of( $child );
				}
			}
		}

		$html = (string) ( $block['innerHTML'] ?? '' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

		preg_match_all( '/\s(?:href|src)\s*=\s*["\']([^"\']*)["\']/i', $html, $targets );

		return md5(
			(string) ( $block['blockName'] ?? '' )
			. '|' . (string) wp_json_encode( $attrs )
			. '|' . $text
			. '|' . implode( ',', array_map( static fn ( string $target ): string => html_entity_decode( $target, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $targets[1] ) )
			. '|' . implode( ',', $inner )
		);
	}

	/**
	 * Whether a block is only whitespace between real blocks.
	 *
	 * @param array<string,mixed> $block Parsed block.
	 */
	public static function isWhitespaceOnly( array $block ): bool {
		return empty( $block['blockName'] ) && '' === trim( (string) ( $block['innerHTML'] ?? '' ) );
	}

	/**
	 * Sort associative arrays by key so attribute order never changes the hash.
	 *
	 * @param array<mixed> $value Value to sort in place.
	 */
	private static function sortRecursive( array &$value ): void {
		ksort( $value );

		foreach ( $value as &$item ) {
			if ( is_array( $item ) ) {
				self::sortRecursive( $item );
			}
		}
	}
}
