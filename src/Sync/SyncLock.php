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
	private const OPTION_PREFIX    = 'docsync_wp_sync_lock_';
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

		$key     = $this->key( $post_id );
		$now     = time();
		$expires = $this->readOptionValue( $key );

		if ( null === $expires ) {
			return add_option( $key, (string) ( $now + self::LOCK_TTL_SECONDS ), '', false );
		}

		if ( absint( $expires ) > $now ) {
			return false;
		}

		global $wpdb;

		$updated = $wpdb->update(
			$wpdb->options,
			array(
				'option_value' => (string) ( $now + self::LOCK_TTL_SECONDS ),
				'autoload'     => 'no',
			),
			array(
				'option_name'  => $key,
				'option_value' => (string) $expires,
			),
			array( '%s', '%s' ),
			array( '%s', '%s' )
		);

		if ( 1 === $updated ) {
			$this->clearOptionCache( $key );
			return true;
		}

		return false;
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

		delete_option( $this->key( $post_id ) );
	}

	/**
	 * Build the transient key for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	private function key( int $post_id ): string {
		return self::OPTION_PREFIX . $post_id;
	}

	/**
	 * Read an option directly so compare-and-swap does not use stale cache.
	 *
	 * @param string $key Option name.
	 */
	private function readOptionValue( string $key ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$key
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Clear option caches after direct SQL compare-and-swap.
	 *
	 * @param string $key Option name.
	 */
	private function clearOptionCache( string $key ): void {
		wp_cache_delete( $key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
