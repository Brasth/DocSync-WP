<?php
/**
 * Reconciles folder-watch pending IDs after an edit.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Pure pending-list math so exclude/include cannot duplicate posts.
 */
final class FolderWatchReconciler {
	/**
	 * Rebuild pending IDs from inventory + excludes + already-linked Docs.
	 *
	 * Newly excluded IDs leave pending. Newly included in-scope IDs re-enter
	 * pending only when they are not already linked to a WordPress post.
	 *
	 * @param array<int,string> $pending           Current pending file IDs.
	 * @param array<int,string> $excluded          Excluded file IDs.
	 * @param array<int,string> $in_scope_file_ids Selectable Docs in the watched tree.
	 * @param array<int,string> $linked_file_ids   File IDs already linked to a post.
	 * @return array<int,string>
	 */
	public function reconcilePending(
		array $pending,
		array $excluded,
		array $in_scope_file_ids,
		array $linked_file_ids
	): array {
		$excluded_set = array_fill_keys( $this->sanitizeIds( $excluded ), true );
		$linked_set   = array_fill_keys( $this->sanitizeIds( $linked_file_ids ), true );
		$in_scope     = $this->sanitizeIds( $in_scope_file_ids );
		$in_scope_set = array_fill_keys( $in_scope, true );
		$next         = array();

		foreach ( $this->sanitizeIds( $pending ) as $file_id ) {
			if ( isset( $in_scope_set[ $file_id ] ) && ! isset( $excluded_set[ $file_id ] ) && ! isset( $linked_set[ $file_id ] ) ) {
				$next[] = $file_id;
			}
		}

		$pending_set = array_fill_keys( $next, true );

		foreach ( $in_scope as $file_id ) {
			if ( isset( $excluded_set[ $file_id ] ) || isset( $linked_set[ $file_id ] ) || isset( $pending_set[ $file_id ] ) ) {
				continue;
			}

			$next[]                  = $file_id;
			$pending_set[ $file_id ] = true;
		}

		return array_values( $next );
	}

	/**
	 * Normalize a list of Drive file IDs.
	 *
	 * @param array<int,mixed> $ids Raw IDs.
	 * @return array<int,string>
	 */
	private function sanitizeIds( array $ids ): array {
		$clean = array();

		foreach ( $ids as $id ) {
			if ( ! is_scalar( $id ) ) {
				continue;
			}

			$id = sanitize_text_field( (string) $id );

			if ( '' !== $id ) {
				$clean[] = $id;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}
