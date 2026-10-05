<?php
/**
 * Unwraps Google redirect links in exported Google Docs HTML.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces https://www.google.com/url?q=TARGET hrefs with TARGET.
 */
final class HtmlGoogleRedirectLinkCleaner {
	private const REDIRECT_HOSTS  = array( 'google.com', 'www.google.com' );
	private const ALLOWED_SCHEMES = array( 'http', 'https', 'mailto', 'tel' );
	private const HREF_PATTERN    = '/(\shref\s*=\s*)(["\'])(.*?)\2/is';

	/**
	 * Unwrap Google redirect hrefs. Anything unrecognized is left untouched.
	 *
	 * @param string $html HTML fragment.
	 */
	public function clean( string $html ): string {
		if ( false === stripos( $html, 'google.com/url' ) ) {
			return $html;
		}

		$cleaned = preg_replace_callback(
			self::HREF_PATTERN,
			function ( array $href_match ): string {
				$target = $this->unwrap( $href_match[3] );

				if ( null === $target ) {
					return $href_match[0];
				}

				return $href_match[1] . $href_match[2] . htmlspecialchars( $target, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . $href_match[2];
			},
			$html
		);

		return is_string( $cleaned ) ? $cleaned : $html;
	}

	/**
	 * Return the redirect target for a wrapped href value, or null.
	 *
	 * @param string $href Raw href attribute value (may contain HTML entities).
	 */
	private function unwrap( string $href ): ?string {
		$decoded = html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$parts   = wp_parse_url( $decoded );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'], $parts['path'], $parts['query'] ) ) {
			return null;
		}

		if (
			! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			|| ! in_array( strtolower( $parts['host'] ), self::REDIRECT_HOSTS, true )
			|| '/url' !== $parts['path']
		) {
			return null;
		}

		parse_str( $parts['query'], $query );

		if ( ! isset( $query['q'] ) || ! is_string( $query['q'] ) || '' === $query['q'] ) {
			return null;
		}

		if ( str_contains( $query['q'], "\0" ) ) {
			return null;
		}

		$target_scheme = wp_parse_url( $query['q'], PHP_URL_SCHEME );

		if ( ! is_string( $target_scheme ) || ! in_array( strtolower( $target_scheme ), self::ALLOWED_SCHEMES, true ) ) {
			return null;
		}

		return $query['q'];
	}
}
