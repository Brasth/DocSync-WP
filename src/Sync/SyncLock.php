<?php
/**
 * Per-post sync lock.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents duplicate sync runs for the same post.
 */
final class SyncLock {
	private const TRANSIENT_PREFIX = 'docsync_wp_sync_lock_';
	private const LOCK_TTL_SECONDS = 300;

	/**
	 * Acquire a lock for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function acquire( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$key = $this->key( $post_id );

		if ( false !== get_transient( $key ) ) {
			return false;
		}

		return set_transient( $key, (string) time(), self::LOCK_TTL_SECONDS );
	}

	/**
	 * Release a post lock.
	 *
	 * @param int $post_id Post ID.
	 */
	public function release( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		delete_transient( $this->key( $post_id ) );
	}

	/**
	 * Build the transient key for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function key( int $post_id ): string {
		return self::TRANSIENT_PREFIX . $post_id;
	}
}
