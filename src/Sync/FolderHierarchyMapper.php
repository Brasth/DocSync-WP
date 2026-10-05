<?php
/**
 * Pure helpers for mapping Drive folder paths to page hierarchy.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Splits inventory folder paths and builds stable container node keys.
 */
final class FolderHierarchyMapper {
	public const MAX_DEPTH = 3;

	/**
	 * Folder names from an inventory path such as "Handbook / Policies".
	 *
	 * @param string $path Folder path relative to the watched folder (empty for the folder itself).
	 * @return array<int,string>
	 */
	public static function segments( string $path ): array {
		$segments = array_values(
			array_filter(
				array_map( 'trim', explode( ' / ', $path ) ),
				static fn ( string $segment ): bool => '' !== $segment
			)
		);

		return array_slice( $segments, 0, self::MAX_DEPTH );
	}

	/**
	 * Stable key for one container page.
	 *
	 * @param string            $watch_id Watch ID.
	 * @param array<int,string> $segments Folder names from the watched folder down to this container.
	 */
	public static function nodeKey( string $watch_id, array $segments ): string {
		return md5( $watch_id . '|' . implode( '/', array_map( 'strtolower', $segments ) ) );
	}

	/**
	 * Whether hierarchy applies to a watch input.
	 *
	 * @param string $requested          Requested structure.
	 * @param bool   $include_subfolders Whether subfolders are watched.
	 * @param bool   $hierarchical_type  Whether the target post type is hierarchical.
	 */
	public static function resolveStructure( string $requested, bool $include_subfolders, bool $hierarchical_type ): string {
		return 'hierarchy' === $requested && $include_subfolders && $hierarchical_type ? 'hierarchy' : 'flat';
	}
}
