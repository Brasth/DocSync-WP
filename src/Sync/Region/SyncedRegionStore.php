<?php
/**
 * Keeps WordPress-side blocks around the synced block run.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Region;

defined( 'ABSPATH' ) || exit;

/**
 * Stores fingerprints of the blocks written by the last sync and merges new content around local blocks.
 */
final class SyncedRegionStore {
	public const META_BASELINE = '_docsync_wp_region_baseline';

	private const MAX_BLOCKS = 2000;

	/**
	 * Fingerprints stored after the last sync.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,string>
	 */
	public function getBaseline( int $post_id ): array {
		$stored  = get_post_meta( $post_id, self::META_BASELINE, true );
		$decoded = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;

		return is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : array();
	}

	/**
	 * Whether the synced run no longer matches the stored baseline (edited, reordered, or deleted in WordPress).
	 *
	 * @param int $post_id Post ID.
	 */
	public function hasLocalEdits( int $post_id ): bool {
		$baseline = $this->getBaseline( $post_id );

		if ( array() === $baseline ) {
			return false;
		}

		$blocks = $this->meaningfulBlocks( (string) get_post_field( 'post_content', $post_id ) );

		return SyncedRegionMerger::CONFLICT === SyncedRegionMerger::locate( $baseline, array_map( array( BlockFingerprint::class, 'of' ), $blocks ) )['status'];
	}

	/**
	 * Plan the content to write: new synced blocks with surrounding local blocks kept when possible.
	 *
	 * @param string            $current  Current post content.
	 * @param array<int,string> $baseline Stored fingerprints.
	 * @param string            $new_content      Newly converted synced content.
	 * @return array{content:string,status:string,prefix:int,suffix:int}
	 */
	public function plan( string $current, array $baseline, string $new_content ): array {
		$blocks   = $this->meaningfulBlocks( $current );
		$location = SyncedRegionMerger::locate( $baseline, array_map( array( BlockFingerprint::class, 'of' ), $blocks ) );

		if ( SyncedRegionMerger::MERGED !== $location['status'] || ( 0 === $location['prefix'] && 0 === $location['suffix'] ) ) {
			return array(
				'content' => $new_content,
				'status'  => $location['status'],
				'prefix'  => 0,
				'suffix'  => 0,
			);
		}

		$count  = count( $blocks );
		$pieces = array_map( 'serialize_block', array_slice( $blocks, 0, $location['prefix'] ) );

		$pieces[] = $new_content;

		foreach ( array_slice( $blocks, $count - $location['suffix'] ) as $block ) {
			$pieces[] = serialize_block( $block );
		}

		return array(
			'content' => implode( "\n\n", array_filter( $pieces, static fn ( string $piece ): bool => '' !== trim( $piece ) ) ),
			'status'  => SyncedRegionMerger::MERGED,
			'prefix'  => $location['prefix'],
			'suffix'  => $location['suffix'],
		);
	}

	/**
	 * Store the synced run as saved by WordPress (after save-time filters).
	 *
	 * @param int $post_id Post ID.
	 * @param int $prefix  Local blocks kept before the synced run.
	 * @param int $suffix  Local blocks kept after the synced run.
	 */
	public function saveBaseline( int $post_id, int $prefix, int $suffix ): void {
		$blocks = $this->meaningfulBlocks( (string) get_post_field( 'post_content', $post_id ) );
		$run    = array_slice( array_map( array( BlockFingerprint::class, 'of' ), $blocks ), $prefix, max( 0, count( $blocks ) - $prefix - $suffix ) );

		if ( array() === $run || count( $run ) > self::MAX_BLOCKS ) {
			delete_post_meta( $post_id, self::META_BASELINE );
			return;
		}

		update_post_meta( $post_id, self::META_BASELINE, wp_json_encode( $run ) );
	}

	/**
	 * Top-level blocks without whitespace-only filler.
	 *
	 * @param string $content Post content.
	 * @return array<int,array<string,mixed>>
	 */
	private function meaningfulBlocks( string $content ): array {
		return array_values(
			array_filter(
				parse_blocks( $content ),
				static fn ( array $block ): bool => ! BlockFingerprint::isWhitespaceOnly( $block )
			)
		);
	}
}
