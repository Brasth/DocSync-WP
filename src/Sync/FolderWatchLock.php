<?php
/**
 * Per-folder-watch import lock.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents overlapping import ticks for the same watch.
 */
final class FolderWatchLock {
	private const OPTION_PREFIX    = 'docsync_wp_folder_lock_';
	private const LOCK_TTL_SECONDS = 1800;

	/**
	 * Acquire a lock for a watch.
	 *
	 * @param string $watch_id Watch ID.
	 */
	public function acquire( string $watch_id ): bool {
		$key = $this->key( $watch_id );

		if ( '' === $key ) {
			return false;
		}

		$now     = time();
		$expires = $this->read( $key );

		if ( null === $expires ) {
			return add_option( $key, (string) ( $now + self::LOCK_TTL_SECONDS ), '', false );
		}

		if ( absint( $expires ) > $now ) {
			return false;
		}

		update_option( $key, (string) ( $now + self::LOCK_TTL_SECONDS ), false );

		return true;
	}

	/**
	 * Release a watch lock.
	 *
	 * @param string $watch_id Watch ID.
	 */
	public function release( string $watch_id ): void {
		$key = $this->key( $watch_id );

		if ( '' !== $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Option key for a watch lock.
	 *
	 * @param string $watch_id Watch ID.
	 */
	private function key( string $watch_id ): string {
		$watch_id = sanitize_key( $watch_id );

		return '' === $watch_id ? '' : self::OPTION_PREFIX . $watch_id;
	}

	/**
	 * Read a lock expiry.
	 *
	 * @param string $key Option name.
	 */
	private function read( string $key ): ?string {
		$value = get_option( $key, null );

		return is_scalar( $value ) ? (string) $value : null;
	}
}
