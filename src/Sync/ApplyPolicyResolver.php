<?php
/**
 * Decides whether a scheduled Doc change is applied, held for review, or skipped.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Pure policy logic for review-before-overwrite.
 */
final class ApplyPolicyResolver {
	public const POLICIES = array( 'auto', 'review', 'manual' );

	public const APPLY = 'apply';
	public const HOLD  = 'hold';
	public const SKIP  = 'skip';

	private const LIVE_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * Effective policy for a source.
	 *
	 * @param string $override    Source override ('' means use site default).
	 * @param string $site_policy Site default for live posts: auto or review.
	 * @param string $post_status WordPress post status.
	 */
	public static function effective( string $override, string $site_policy, string $post_status ): string {
		if ( in_array( $override, self::POLICIES, true ) ) {
			return $override;
		}

		if ( ! in_array( $post_status, self::LIVE_STATUSES, true ) ) {
			return 'auto';
		}

		return in_array( $site_policy, array( 'auto', 'review' ), true ) ? $site_policy : 'auto';
	}

	/**
	 * Decide what a scheduled sync should do.
	 *
	 * @param string $policy         Effective policy.
	 * @param bool   $remote_changed Whether the Google Doc changed since the last sync.
	 * @param bool   $local_edits    Whether WordPress edits exist inside the synced content.
	 */
	public static function decide( string $policy, bool $remote_changed, bool $local_edits ): string {
		if ( 'manual' === $policy ) {
			return self::SKIP;
		}

		if ( ! $remote_changed ) {
			return self::APPLY;
		}

		if ( 'review' === $policy ) {
			return self::HOLD;
		}

		return $local_edits ? self::HOLD : self::APPLY;
	}
}
