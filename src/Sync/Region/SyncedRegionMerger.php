<?php
/**
 * Locates the previously synced block run inside current post content.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Region;

defined( 'ABSPATH' ) || exit;

/**
 * Pure comparison of baseline fingerprints against the current content's fingerprints.
 */
final class SyncedRegionMerger {
	public const MERGED      = 'merged';
	public const NO_BASELINE = 'no_baseline';
	public const CONFLICT    = 'conflict';

	/**
	 * Locate the baseline run.
	 *
	 * @param array<int,string> $baseline Fingerprints stored after the last sync.
	 * @param array<int,string> $current  Fingerprints of the current top-level blocks.
	 * @return array{status:string,prefix:int,suffix:int}
	 */
	public static function locate( array $baseline, array $current ): array {
		$length = count( $baseline );

		if ( 0 === $length ) {
			return array(
				'status' => self::NO_BASELINE,
				'prefix' => 0,
				'suffix' => 0,
			);
		}

		$total = count( $current );

		for ( $start = 0; $start + $length <= $total; $start++ ) {
			if ( array_slice( $current, $start, $length ) === array_values( $baseline ) ) {
				return array(
					'status' => self::MERGED,
					'prefix' => $start,
					'suffix' => $total - $start - $length,
				);
			}
		}

		return array(
			'status' => self::CONFLICT,
			'prefix' => 0,
			'suffix' => 0,
		);
	}
}
