<?php
/**
 * Tracks progressive Docs API HTML build flushes.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Provides progress estimates and flush cadence for Docs API fallback builds.
 */
final class DocsApiHtmlBuildProgress {
	/**
	 * Count top-level structural elements for progress estimates.
	 *
	 * @param array<int,array<string,mixed>> $parts Document parts.
	 */
	public static function countElements( array $parts ): int {
		$count = 0;

		foreach ( $parts as $part ) {
			$content = isset( $part['body']['content'] ) && is_array( $part['body']['content'] ) ? $part['body']['content'] : array();
			$count  += count( array_filter( $content, 'is_array' ) );
		}

		return max( 1, $count );
	}

	/**
	 * Flush accumulated HTML at regular intervals.
	 *
	 * @param callable|null $flush_callback Partial flush callback.
	 * @param string        $html           Accumulated HTML.
	 * @param int           $rendered       Rendered element count.
	 * @param int           $total          Total renderable elements.
	 * @return true|WP_Error
	 */
	public static function flushPartialHtml( ?callable $flush_callback, string $html, int $rendered, int $total ): true|WP_Error {
		if ( null === $flush_callback || ( 0 !== $rendered % 20 && $rendered < $total ) ) {
			return true;
		}

		$flushed = $flush_callback( $html, $rendered, $total );

		return is_wp_error( $flushed ) ? $flushed : true;
	}
}
