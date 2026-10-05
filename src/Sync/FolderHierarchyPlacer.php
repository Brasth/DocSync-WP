<?php
/**
 * Creates container pages for Drive subfolders and parents synced posts under them.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * WordPress glue for FolderHierarchyMapper.
 */
final class FolderHierarchyPlacer {
	public const META_NODE = '_docsync_wp_folder_node';

	/**
	 * Parent a post under container pages that mirror its Drive subfolders.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $watch   Watch record.
	 * @param string              $path    Inventory folder path for the Doc.
	 */
	public function place( int $post_id, array $watch, string $path ): void {
		$segments = FolderHierarchyMapper::segments( $path );

		if ( array() === $segments ) {
			return;
		}

		$watch_id  = (string) ( $watch['id'] ?? '' );
		$post_type = sanitize_key( (string) ( $watch['postType'] ?? 'page' ) );
		$status    = 'publish' === ( $watch['postStatus'] ?? 'draft' ) ? 'publish' : 'draft';
		$owner     = absint( $watch['ownerUserId'] ?? 0 );
		$parent_id = 0;

		$total = count( $segments );

		for ( $depth = 1; $depth <= $total; $depth++ ) {
			$parent_id = $this->ensureContainer( $watch_id, array_slice( $segments, 0, $depth ), $post_type, $status, $owner, $parent_id );

			if ( $parent_id <= 0 ) {
				return;
			}
		}

		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_parent' => $parent_id,
			)
		);
	}

	/**
	 * Find or create one container page.
	 *
	 * @param string            $watch_id  Watch ID.
	 * @param array<int,string> $segments  Folder names down to this container.
	 * @param string            $post_type Post type.
	 * @param string            $status    Post status for new containers.
	 * @param int               $owner     Author user ID.
	 * @param int               $parent_id Parent container ID.
	 */
	private function ensureContainer( string $watch_id, array $segments, string $post_type, string $status, int $owner, int $parent_id ): int {
		$key      = FolderHierarchyMapper::nodeKey( $watch_id, $segments );
		$existing = get_posts(
			array(
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => self::META_NODE,
						'value' => $key,
					),
				),
				'no_found_rows'  => true,
				'post_status'    => 'any',
				'post_type'      => $post_type,
				'posts_per_page' => 1,
			)
		);

		if ( isset( $existing[0] ) ) {
			return absint( $existing[0] );
		}

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_author'  => $owner,
					'post_content' => 'page' === $post_type ? '<!-- wp:page-list /-->' : '',
					'post_parent'  => $parent_id,
					'post_status'  => $status,
					'post_title'   => (string) end( $segments ),
					'post_type'    => $post_type,
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) || $post_id <= 0 ) {
			return 0;
		}

		update_post_meta( (int) $post_id, self::META_NODE, $key );

		return (int) $post_id;
	}
}
