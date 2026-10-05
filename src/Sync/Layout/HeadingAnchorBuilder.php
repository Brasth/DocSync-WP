<?php
/**
 * Adds heading anchors and a table of contents to converted block markup.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Pure markup post-processor for the Documentation preset.
 */
final class HeadingAnchorBuilder {
	public const MIN_HEADINGS_FOR_TOC = 3;

	private const HEADING = '/<!-- wp:heading(?: (\{[^}]*\}))? --><h([23])>(.*?)<\/h\2><!-- \/wp:heading -->/s';

	/**
	 * Add anchors to H2/H3 headings and prepend a table of contents for 3+ H2 headings.
	 *
	 * @param string $markup Converted block markup.
	 */
	public static function apply( string $markup ): string {
		$used    = array();
		$toc     = array();
		$updated = preg_replace_callback(
			self::HEADING,
			static function ( array $heading ) use ( &$used, &$toc ): string {
				$level = (int) $heading[2];
				$text  = trim( html_entity_decode( wp_strip_all_tags( $heading[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$slug  = self::unique( self::slugify( $text ), $used );
				$attrs = '' !== $heading[1] ? json_decode( $heading[1], true ) : array();
				$attrs = is_array( $attrs ) ? $attrs : array();

				$attrs['anchor'] = $slug;

				if ( 2 === $level ) {
					$toc[] = array(
						'slug' => $slug,
						'text' => $text,
					);
				}

				return '<!-- wp:heading ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' --><h' . $level . ' id="' . htmlspecialchars( $slug, ENT_QUOTES ) . '">' . $heading[3] . '</h' . $level . '><!-- /wp:heading -->';
			},
			$markup
		);

		if ( ! is_string( $updated ) ) {
			return $markup;
		}

		if ( count( $toc ) < self::MIN_HEADINGS_FOR_TOC ) {
			return $updated;
		}

		$items = '';

		foreach ( $toc as $entry ) {
			$items .= '<li><a href="#' . htmlspecialchars( $entry['slug'], ENT_QUOTES ) . '">' . htmlspecialchars( $entry['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '</a></li>';
		}

		return '<!-- wp:list --><ul>' . $items . '</ul><!-- /wp:list -->' . $updated;
	}

	/**
	 * Lowercase letters and digits joined by dashes; Unicode letters are kept.
	 *
	 * @param string $text Heading text.
	 */
	public static function slugify( string $text ): string {
		$slug = trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', '-', mb_strtolower( $text ) ), '-' );

		return '' !== $slug ? $slug : 'section';
	}

	/**
	 * Make a slug unique within the document.
	 *
	 * @param string             $slug Slug.
	 * @param array<string,bool> $used Used slugs.
	 */
	private static function unique( string $slug, array &$used ): string {
		$candidate = $slug;
		$suffix    = 2;

		while ( isset( $used[ $candidate ] ) ) {
			$candidate = $slug . '-' . $suffix;
			++$suffix;
		}

		$used[ $candidate ] = true;

		return $candidate;
	}
}
