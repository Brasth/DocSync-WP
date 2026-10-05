<?php
/**
 * Pure grouping and dedupe logic for sync failure digests.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Notifications;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which failures are recorded and who receives them.
 */
final class FailureDigestPlanner {
	public const MAX_PENDING = 200;

	/**
	 * Add a failure to the pending list, ignoring repeats of the same post and code.
	 *
	 * @param array<int,array{post_id:int,code:string,at:int}> $pending Pending failures.
	 * @param int                                              $post_id Post ID.
	 * @param string                                           $code    Error code.
	 * @param int                                              $now     Unix timestamp.
	 * @return array<int,array{post_id:int,code:string,at:int}>
	 */
	public static function record( array $pending, int $post_id, string $code, int $now ): array {
		foreach ( $pending as $entry ) {
			if ( $entry['post_id'] === $post_id && $entry['code'] === $code ) {
				return $pending;
			}
		}

		$pending[] = array(
			'post_id' => $post_id,
			'code'    => $code,
			'at'      => $now,
		);

		return array_slice( $pending, -self::MAX_PENDING );
	}

	/**
	 * Group pending failures by recipient user ID.
	 *
	 * @param array<int,array{post_id:int,code:string,at:int}> $pending    Pending failures.
	 * @param string                                           $mode       owners_and_admin|admin|off.
	 * @param array<int,int>                                   $admin_ids  Administrator user IDs.
	 * @param callable(int):int                                $owner_of   Returns sync owner user ID for a post, 0 when unknown.
	 * @param callable(int,int):bool                           $can_access Whether a user may edit the post's source.
	 * @return array<int,array<int,array{post_id:int,code:string,at:int}>>
	 */
	public static function plan( array $pending, string $mode, array $admin_ids, callable $owner_of, callable $can_access ): array {
		if ( 'off' === $mode ) {
			return array();
		}

		$plan = array();

		foreach ( $pending as $entry ) {
			$recipients = $admin_ids;

			if ( 'owners_and_admin' === $mode ) {
				$owner = $owner_of( $entry['post_id'] );

				if ( $owner > 0 ) {
					$recipients[] = $owner;
				}
			}

			foreach ( array_unique( $recipients ) as $user_id ) {
				if ( ! $can_access( $user_id, $entry['post_id'] ) ) {
					continue;
				}

				$plan[ $user_id ][] = $entry;
			}
		}

		return $plan;
	}
}
