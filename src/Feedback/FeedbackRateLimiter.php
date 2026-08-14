<?php
/**
 * Rate limits feedback submissions.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Feedback;

defined( 'ABSPATH' ) || exit;

/**
 * Applies short-lived user and IP limits before a report reaches the Worker.
 */
final class FeedbackRateLimiter {
	private const IP_LIMIT         = 20;
	private const WINDOW_SECONDS   = HOUR_IN_SECONDS;
	private const USER_LIMIT       = 5;
	private const TRANSIENT_PREFIX = 'docsync_wp_feedback_rate_';

	/**
	 * Consume one submission slot for a WordPress user and request IP.
	 *
	 * IP values are hashed before they are used as transient keys and are not
	 * persisted as feedback data.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $ip      Request IP address.
	 */
	public function consume( int $user_id, string $ip ): bool {
		$user_key   = $this->key( 'user', (string) $user_id );
		$ip_key     = $this->key( 'ip', $ip );
		$user_count = $this->count( $user_key );
		$ip_count   = $this->count( $ip_key );

		if ( $user_count >= self::USER_LIMIT || $ip_count >= self::IP_LIMIT ) {
			return false;
		}

		set_transient( $user_key, $user_count + 1, self::WINDOW_SECONDS );
		set_transient( $ip_key, $ip_count + 1, self::WINDOW_SECONDS );

		return true;
	}

	/**
	 * Read a bounded transient counter.
	 *
	 * @param string $key Rate-limit transient key.
	 */
	private function count( string $key ): int {
		$value = get_transient( $key );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * Build a non-identifying transient key.
	 *
	 * @param string $scope Counter scope.
	 * @param string $value Scope value.
	 */
	private function key( string $scope, string $value ): string {
		return self::TRANSIENT_PREFIX . hash( 'sha256', wp_salt( 'auth' ) . '|' . $scope . '|' . $value );
	}
}
