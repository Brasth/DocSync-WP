<?php
/**
 * Persists Drive folder watches.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Stores folder watch records in a site option.
 */
final class FolderWatchRepository {
	public const OPTION_NAME = 'docsync_wp_folder_watches';
	public const MAX_WATCHES = 10;

	/**
	 * List every stored watch.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$watches = array();

		foreach ( $stored as $watch ) {
			if ( is_array( $watch ) && isset( $watch['id'] ) && is_string( $watch['id'] ) && '' !== $watch['id'] ) {
				$watches[] = $watch;
			}
		}

		return $watches;
	}

	/**
	 * Get one watch by ID.
	 *
	 * @param string $watch_id Watch ID.
	 * @return array<string,mixed>|null
	 */
	public function get( string $watch_id ): ?array {
		$watch_id = sanitize_key( $watch_id );

		foreach ( $this->all() as $watch ) {
			if ( sanitize_key( (string) $watch['id'] ) === $watch_id ) {
				return $watch;
			}
		}

		return null;
	}

	/**
	 * Find a watch for the same owner and folder.
	 *
	 * @param int    $owner_user_id Owner user ID.
	 * @param string $folder_id     Folder ID.
	 * @param string $drive_id      Shared drive ID.
	 * @return array<string,mixed>|null
	 */
	public function findByFolder( int $owner_user_id, string $folder_id, string $drive_id ): ?array {
		foreach ( $this->all() as $watch ) {
			if (
				absint( $watch['ownerUserId'] ?? 0 ) === $owner_user_id
				&& (string) ( $watch['folderId'] ?? '' ) === $folder_id
				&& (string) ( $watch['driveId'] ?? '' ) === $drive_id
			) {
				return $watch;
			}
		}

		return null;
	}

	/**
	 * Save a watch, replacing the same ID when present.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 */
	public function save( array $watch ): bool {
		$id = isset( $watch['id'] ) ? sanitize_key( (string) $watch['id'] ) : '';

		if ( '' === $id ) {
			return false;
		}

		$watch['id'] = $id;
		$watches     = $this->all();
		$replaced    = false;

		foreach ( $watches as $index => $current ) {
			if ( sanitize_key( (string) $current['id'] ) === $id ) {
				$watches[ $index ] = $watch;
				$replaced          = true;
				break;
			}
		}

		if ( ! $replaced ) {
			if ( count( $watches ) >= self::MAX_WATCHES ) {
				return false;
			}

			$watches[] = $watch;
		}

		$payload = array_values( $watches );
		$stored  = get_option( self::OPTION_NAME, array() );

		if ( is_array( $stored ) && $this->watchesEqual( $stored, $payload ) ) {
			return true;
		}

		return update_option( self::OPTION_NAME, $payload, false );
	}

	/**
	 * Whether two stored watch lists are identical.
	 *
	 * @param array<int,mixed> $left  Left list.
	 * @param array<int,mixed> $right Right list.
	 */
	private function watchesEqual( array $left, array $right ): bool {
		return wp_json_encode( $left ) === wp_json_encode( $right );
	}

	/**
	 * Delete a watch.
	 *
	 * @param string $watch_id Watch ID.
	 */
	public function delete( string $watch_id ): bool {
		$watch_id = sanitize_key( $watch_id );
		$watches  = array_values(
			array_filter(
				$this->all(),
				static function ( array $watch ) use ( $watch_id ): bool {
					return sanitize_key( (string) $watch['id'] ) !== $watch_id;
				}
			)
		);

		return update_option( self::OPTION_NAME, $watches, false );
	}

	/**
	 * Delete every watch.
	 */
	public function deleteAll(): void {
		delete_option( self::OPTION_NAME );
	}
}
