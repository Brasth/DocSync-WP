<?php
/**
 * Backfills next_sync_at for existing linked sources.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Cron;

use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SourceScheduleResolver;

defined( 'ABSPATH' ) || exit;

/**
 * One-time paged upgrade for schedule meta.
 */
final class ScheduleBackfill {
	public const HOOK        = 'docsync_wp_backfill_next_sync';
	public const DONE_OPTION = 'docsync_wp_next_sync_backfill_done';
	private const PAGE_SIZE  = 200;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Schedule resolver.
	 *
	 * @var SourceScheduleResolver
	 */
	private SourceScheduleResolver $schedule;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository       $sources  Source repository.
	 * @param SourceScheduleResolver $schedule Schedule resolver.
	 */
	public function __construct( SourceRepository $sources, SourceScheduleResolver $schedule ) {
		$this->sources  = $sources;
		$this->schedule = $schedule;
	}

	/**
	 * Register upgrade hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'maybeSchedule' ) );
		add_action( self::HOOK, array( $this, 'runPage' ) );
	}

	/**
	 * Queue the first page when backfill is unfinished.
	 */
	public function maybeSchedule(): void {
		if ( (bool) get_option( self::DONE_OPTION, false ) ) {
			return;
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}

	/**
	 * Backfill one page of sources missing next_sync_at.
	 */
	public function runPage(): void {
		$post_ids = $this->sources->listPostIdsMissingNextSync( self::PAGE_SIZE );

		if ( array() === $post_ids ) {
			update_option( self::DONE_OPTION, 1, false );
			return;
		}

		$now = current_time( 'mysql', true );

		foreach ( $post_ids as $post_id ) {
			$source = $this->sources->getSource( $post_id );

			if ( null === $source ) {
				continue;
			}

			$interval = $this->schedule->resolveInterval( $source );

			if ( 'off' === $interval ) {
				$source['next_sync_at'] = '';
			} else {
				$from                   = '' !== (string) $source['last_synced_at'] ? (string) $source['last_synced_at'] : $now;
				$source['next_sync_at'] = SourceScheduleResolver::nextSyncAt( $from, $interval );
			}

			$this->sources->saveSource( $post_id, $source );
		}

		if ( count( $post_ids ) >= self::PAGE_SIZE ) {
			wp_schedule_single_event( time() + 30, self::HOOK );
			return;
		}

		update_option( self::DONE_OPTION, 1, false );
	}

	/**
	 * Clear backfill events on uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
