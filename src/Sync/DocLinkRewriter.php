<?php
/**
 * Rewrites Google Doc links to WordPress permalinks.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Pure href rewriting. Permalink lookup is injected so it can be tested without WordPress.
 */
final class DocLinkRewriter {
	private const PATTERN = '/(\shref\s*=\s*)(["\'])https?:\/\/docs\.google\.com\/document\/d\/([A-Za-z0-9_-]+)[^"\']*\2/i';

	/**
	 * Replace links to Docs that exist as published WordPress pages.
	 *
	 * @param string                   $html   HTML fragment.
	 * @param callable(string):?string $lookup Returns a permalink for a Google file ID, or null.
	 */
	public static function rewrite( string $html, callable $lookup ): string {
		if ( false === stripos( $html, 'docs.google.com/document/d/' ) ) {
			return $html;
		}

		$rewritten = preg_replace_callback(
			self::PATTERN,
			static function ( array $link ) use ( $lookup ): string {
				$permalink = $lookup( $link[3] );

				if ( null === $permalink || '' === $permalink ) {
					return $link[0];
				}

				return $link[1] . $link[2] . htmlspecialchars( $permalink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . $link[2];
			},
			$html
		);

		return is_string( $rewritten ) ? $rewritten : $html;
	}
}
