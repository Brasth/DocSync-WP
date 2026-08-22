<?php
/**
 * Records WP-Cron liveness for admin health warnings.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Cron;

defined( 'ABSPATH' ) || exit;

/**
 * Shared last-run stamp written by source and folder-watch ticks.
 */
final class CronHeartbeat {
	public const OPTION_NAME = 'docsync_wp_last_cron_run_at';

	private const MIN_STALL_SECONDS = 2 * HOUR_IN_SECONDS;

	/**
	 * Interval lengths used to compute the stall window.
	 *
	 * @var array<string,int>
	 */
	private const INTERVAL_SECONDS = array(
		'hourly'     => HOUR_IN_SECONDS,
		'twicedaily' => 12 * HOUR_IN_SECONDS,
		'daily'      => DAY_IN_SECONDS,
		'weekly'     => 7 * DAY_IN_SECONDS,
	);

	/**
	 * Mark a successful plugin cron tick.
	 *
	 * @param int|null $at Unix timestamp. Defaults to now.
	 */
	public function mark( ?int $at = null ): void {
		update_option( self::OPTION_NAME, $at ?? time(), false );
	}

	/**
	 * Safe workspace snapshot. Never includes secrets or watch IDs.
	 *
	 * @param array<int,string> $active_intervals Resolved cron interval keys.
	 * @param int|null          $now              Unix timestamp. Defaults to now.
	 * @return array{lastRunAt:string,stalled:bool}
	 */
	public function snapshot( array $active_intervals, ?int $now = null ): array {
		$now   = $now ?? time();
		$last  = absint( get_option( self::OPTION_NAME, 0 ) );
		$short = $this->shortestActiveSeconds( $active_intervals );

		$stalled = false;

		if ( $last > 0 && $short > 0 ) {
			$threshold = max( self::MIN_STALL_SECONDS, 2 * $short );
			$stalled   = ( $now - $last ) > $threshold;
		}

		return array(
			'lastRunAt' => $last > 0 ? gmdate( 'c', $last ) : '',
			'stalled'   => $stalled,
		);
	}

	/**
	 * Shortest active interval in seconds, or 0 when none are scheduled.
	 *
	 * @param array<int,string> $active_intervals Resolved cron interval keys.
	 */
	private function shortestActiveSeconds( array $active_intervals ): int {
		$shortest = 0;

		foreach ( $active_intervals as $interval ) {
			$interval = sanitize_key( (string) $interval );

			if ( ! isset( self::INTERVAL_SECONDS[ $interval ] ) ) {
				continue;
			}

			$seconds = self::INTERVAL_SECONDS[ $interval ];

			if ( 0 === $shortest || $seconds < $shortest ) {
				$shortest = $seconds;
			}
		}

		return $shortest;
	}
}
