<?php
/**
 * Replaces {{pattern: name}} paragraph blocks in converted block markup.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Pure placeholder replacement. Name resolution is injected so it can be tested without WordPress.
 */
final class PatternPlaceholderParser {
	private const PATTERN = '/<!-- wp:paragraph(?: \{[^}]*\})? --><p[^>]*>\s*\{\{\s*pattern\s*:\s*([^<{}]+?)\s*\}\}\s*<\/p><!-- \/wp:paragraph -->/i';

	/**
	 * Replace placeholder paragraphs.
	 *
	 * @param string                   $markup   Converted block markup.
	 * @param callable(string):?string $resolver Returns replacement block markup for a name, or null when unknown.
	 * @return array{markup:string,unresolved:array<int,string>}
	 */
	public static function replace( string $markup, callable $resolver ): array {
		if ( false === stripos( $markup, '{{' ) ) {
			return array(
				'markup'     => $markup,
				'unresolved' => array(),
			);
		}

		$unresolved = array();

		$replaced = preg_replace_callback(
			self::PATTERN,
			static function ( array $placeholder ) use ( $resolver, &$unresolved ): string {
				$name        = trim( html_entity_decode( wp_strip_all_tags( $placeholder[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$replacement = '' !== $name ? $resolver( $name ) : null;

				if ( null === $replacement ) {
					$unresolved[] = $name;
					return '';
				}

				return $replacement;
			},
			$markup
		);

		return array(
			'markup'     => is_string( $replaced ) ? $replaced : $markup,
			'unresolved' => $unresolved,
		);
	}
}
