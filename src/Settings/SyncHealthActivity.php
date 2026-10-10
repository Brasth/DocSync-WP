<?php
/**
 * Bounded sync activity totals for the health snapshot.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Settings;

use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Counts stored terminal sync events without treating progress rows as jobs.
 *
 * Traversal stops at 500 accessible sources or when the source list ends.
 * A full retained window sets the limited flag and the scan continues.
 * Due cron counts include only sources returned by that scan.
 */
final class SyncHealthActivity {
	private const MAX_SOURCES                = 500;
	private const SOURCE_PAGE_SIZE           = 100;
	private const RETAINED_EVENTS_PER_SOURCE = 50;
	private const REPORT_EVENT_LIMIT         = 20;
	private const WINDOW_DAYS                = 7;
	private const UNKNOWN_TOKEN              = 'unknown';

	/**
	 * Status values SyncService writes onto a sync event.
	 *
	 * @var array<int,string>
	 */
	private const KNOWN_STATUSES = array(
		SyncService::STATUS_LINKED,
		SyncService::STATUS_SYNCING,
		SyncService::STATUS_SYNCED,
		SyncService::STATUS_SKIPPED,
		SyncService::STATUS_ERROR,
	);

	/**
	 * Step literals SyncService stores. Unknown steps become "unknown".
	 *
	 * @var array<int,string>
	 */
	private const KNOWN_STEPS = array(
		'checking_google',
		'complete',
		'converting',
		'error',
		'exporting',
		'importing',
		'large_doc_fallback',
		'large_doc_partial_import',
		'linked',
		'queued',
		'updating_post',
	);

	/**
	 * Error codes SyncService can store. A blank code stays blank.
	 *
	 * @var array<int,string>
	 */
	private const KNOWN_ERROR_CODES = array(
		'docsync_wp_background_sync_failed',
		'docsync_wp_create_post_failed',
		'docsync_wp_drive_download_blocked',
		'docsync_wp_export_too_large',
		'docsync_wp_image_import_partial',
		'docsync_wp_invalid_export_format',
		'docsync_wp_partial_update_post_failed',
		'docsync_wp_source_changed',
		'docsync_wp_source_not_found',
		'docsync_wp_source_syncing',
		'docsync_wp_sync_locked',
		'docsync_wp_update_post_failed',
	);

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository $sources Source repository.
	 */
	public function __construct( SourceRepository $sources ) {
		$this->sources = $sources;
	}

	/**
	 * Build activity totals and the redacted recent-event report.
	 *
	 * @param int $user_id Current user ID.
	 * @param int $now     Unix timestamp used for the reporting window.
	 * @return array{activity:array{completed:int,failed:int,medianSeconds:int|float|null,waiting:int,limited:bool,days:array<int,array{date:string,completed:int,failed:int}>},reportEvents:array<int,array{timestamp:string,status:string,step:string,errorCode:string}>}
	 */
	public function build( int $user_id, int $now ): array {
		$days      = $this->emptyDays( $now );
		$day_index = array();

		foreach ( $days as $index => $day ) {
			$day_index[ $day['date'] ] = $index;
		}

		$durations           = array();
		$report              = array();
		$accessible_post_ids = array();
		$limited             = false;
		$seen                = 0;
		$page                = 1;

		// Retention may set $limited. Only the source cap or the last page stops the scan.
		while ( $seen < self::MAX_SOURCES ) {
			$batch = $this->sources->listSourcesPage(
				$this->sources->getEnabledPostTypes(),
				$user_id,
				self::SOURCE_PAGE_SIZE,
				$page
			);
			$rows  = isset( $batch['sources'] ) && is_array( $batch['sources'] ) ? $batch['sources'] : array();

			if ( array() === $rows ) {
				break;
			}

			$page_capped = false;

			foreach ( $rows as $source ) {
				if ( $seen >= self::MAX_SOURCES ) {
					$page_capped = true;
					break;
				}

				++$seen;
				$post_id = is_array( $source ) && isset( $source['postId'] ) ? absint( $source['postId'] ) : 0;

				if ( $post_id > 0 ) {
					$accessible_post_ids[ $post_id ] = true;
					$this->consumeSource( $post_id, $days, $day_index, $durations, $report, $limited );
				}
			}

			$has_more = ! empty( $batch['has_more'] );

			if ( $page_capped || ( $seen >= self::MAX_SOURCES && $has_more ) ) {
				$limited = true;
			}

			if ( $page_capped || $seen >= self::MAX_SOURCES || ! $has_more ) {
				break;
			}

			++$page;
		}

		$completed = 0;
		$failed    = 0;

		foreach ( $days as $day ) {
			$completed += $day['completed'];
			$failed    += $day['failed'];
		}

		return array(
			'activity'     => array(
				'completed'     => $completed,
				'failed'        => $failed,
				'medianSeconds' => $this->median( $durations ),
				'waiting'       => $this->dueSourceCronEvents( $accessible_post_ids ),
				'limited'       => $limited,
				'days'          => $days,
			),
			'reportEvents' => $this->reportEvents( $report ),
		);
	}

	/**
	 * Read one source's retained events into the totals.
	 *
	 * @param int                                                    $post_id   Source post ID. Not copied out.
	 * @param array<int,array{date:string,completed:int,failed:int}> $days Day buckets.
	 * @param array<string,int>                                      $day_index Date to bucket index.
	 * @param array<int,int>                                         $durations Matched durations in seconds.
	 * @param array<int,array<string,mixed>>                         $report    Report candidates.
	 * @param bool                                                   $limited   Truncation flag.
	 */
	private function consumeSource( int $post_id, array &$days, array $day_index, array &$durations, array &$report, bool &$limited ): void {
		$events = $this->sources->getSyncEvents( $post_id );

		if ( array() === $events ) {
			return;
		}

		$this->noteRetention( $events, $days, $limited );

		$parsed    = array();
		$starts    = array();
		$terminals = array();

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}

			$safe = $this->safeEvent( $event );

			if ( null === $safe ) {
				continue;
			}

			$parsed[] = $safe;

			if ( SyncService::STATUS_SYNCING === $safe['status'] && null !== $safe['started'] && $safe['started'] === $safe['unix'] ) {
				$starts[ $safe['started'] ] = true;
			}
		}

		foreach ( $parsed as $safe ) {
			$report[] = $safe;
			$key      = $this->terminalKey( $safe );

			if ( null === $key ) {
				continue;
			}

			if ( ! isset( $terminals[ $key ] ) || $this->terminalIsLater( $safe, $terminals[ $key ] ) ) {
				$terminals[ $key ] = $safe;
			}
		}

		foreach ( $terminals as $safe ) {
			$this->countTerminal( $safe, $starts, $days, $day_index, $durations );
		}
	}

	/**
	 * One terminal outcome per started timestamp, or per terminal timestamp when the start is missing.
	 *
	 * @param array<string,mixed> $safe Parsed event.
	 */
	private function terminalKey( array $safe ): ?string {
		$status = (string) $safe['status'];

		if ( SyncService::STATUS_SYNCED !== $status && SyncService::STATUS_ERROR !== $status ) {
			return null;
		}

		if ( is_int( $safe['started'] ) ) {
			return 'started:' . $safe['started'];
		}

		return 'terminal:' . (int) $safe['unix'];
	}

	/**
	 * Whether the candidate finished after the event already chosen for this run.
	 *
	 * @param array<string,mixed> $candidate Later candidate.
	 * @param array<string,mixed> $current   Event currently kept.
	 */
	private function terminalIsLater( array $candidate, array $current ): bool {
		$by_time = ( (int) $candidate['unix'] ) <=> ( (int) $current['unix'] );

		if ( 0 !== $by_time ) {
			return $by_time > 0;
		}

		return strcmp( (string) $candidate['tie'], (string) $current['tie'] ) > 0;
	}

	/**
	 * Flag the snapshot when retained history may hide events inside the window.
	 *
	 * @param array<int,mixed>                                       $events  Stored events.
	 * @param array<int,array{date:string,completed:int,failed:int}> $days Day buckets.
	 * @param bool                                                   $limited Truncation flag.
	 */
	private function noteRetention( array $events, array $days, bool &$limited ): void {
		if ( $limited || count( $events ) < self::RETAINED_EVENTS_PER_SOURCE || ! isset( $days[0]['date'] ) ) {
			return;
		}

		$window_start = strtotime( $days[0]['date'] . ' 00:00:00 UTC' );
		$oldest       = null;
		$unparsed     = false;

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				$unparsed = true;
				continue;
			}

			$unix = $this->unixTimestamp( isset( $event['timestamp'] ) ? (string) $event['timestamp'] : '' );

			if ( null === $unix ) {
				$unparsed = true;
				continue;
			}

			if ( null === $oldest || $unix < $oldest ) {
				$oldest = $unix;
			}
		}

		if ( $unparsed || null === $oldest || ! is_int( $window_start ) || $oldest > $window_start ) {
			$limited = true;
		}
	}

	/**
	 * Count a terminal success or error that falls on a reported day.
	 *
	 * @param array<string,mixed>                                    $safe       Parsed event.
	 * @param array<int,bool>                                        $starts     Start timestamps for this source.
	 * @param array<int,array{date:string,completed:int,failed:int}> $days Day buckets.
	 * @param array<string,int>                                      $day_index  Date to bucket index.
	 * @param array<int,int>                                         $durations  Matched durations in seconds.
	 */
	private function countTerminal( array $safe, array $starts, array &$days, array $day_index, array &$durations ): void {
		$date = (string) $safe['date'];

		if ( ! isset( $day_index[ $date ] ) ) {
			return;
		}

		$index  = $day_index[ $date ];
		$status = (string) $safe['status'];

		if ( SyncService::STATUS_SYNCED === $status ) {
			++$days[ $index ]['completed'];
		} elseif ( SyncService::STATUS_ERROR === $status ) {
			++$days[ $index ]['failed'];
		} else {
			return;
		}

		$started = $safe['started'];

		if ( ! is_int( $started ) || ! isset( $starts[ $started ] ) ) {
			return;
		}

		$duration = (int) $safe['unix'] - $started;

		if ( $duration >= 0 ) {
			$durations[] = $duration;
		}
	}

	/**
	 * Keep the allowlisted event fields and a sort key.
	 *
	 * @param array<string,mixed> $event Stored event.
	 * @return array<string,mixed>|null
	 */
	private function safeEvent( array $event ): ?array {
		$unix = $this->unixTimestamp( isset( $event['timestamp'] ) ? (string) $event['timestamp'] : '' );

		if ( null === $unix ) {
			return null;
		}

		$started = $this->unixTimestamp( isset( $event['syncStartedAt'] ) ? (string) $event['syncStartedAt'] : '' );
		$tie     = isset( $event['eventId'] ) ? sanitize_key( (string) $event['eventId'] ) : '';

		return array(
			'unix'      => $unix,
			'date'      => gmdate( 'Y-m-d', $unix ),
			'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z', $unix ),
			'status'    => $this->knownToken( (string) ( $event['status'] ?? '' ), self::KNOWN_STATUSES, false ),
			'step'      => $this->knownToken( (string) ( $event['step'] ?? '' ), self::KNOWN_STEPS, false ),
			'errorCode' => $this->knownToken( (string) ( $event['errorCode'] ?? '' ), self::KNOWN_ERROR_CODES, true ),
			'started'   => $started,
			'tie'       => $tie,
		);
	}

	/**
	 * Keep a finite plugin token. Blank error codes stay blank; other unknowns become "unknown".
	 *
	 * @param string            $value             Raw stored value.
	 * @param array<int,string> $allowed           Known plugin values.
	 * @param bool              $blank_stays_blank Whether an empty value is preserved.
	 */
	private function knownToken( string $value, array $allowed, bool $blank_stays_blank ): string {
		$value = trim( $value );

		if ( '' === $value ) {
			return $blank_stays_blank ? '' : self::UNKNOWN_TOKEN;
		}

		return in_array( $value, $allowed, true ) ? $value : self::UNKNOWN_TOKEN;
	}

	/**
	 * Last 20 events, newest first, with private fields removed.
	 *
	 * @param array<int,array<string,mixed>> $report Report candidates.
	 * @return array<int,array{timestamp:string,status:string,step:string,errorCode:string}>
	 */
	private function reportEvents( array $report ): array {
		usort(
			$report,
			static function ( array $left, array $right ): int {
				$by_time = ( (int) $right['unix'] ) <=> ( (int) $left['unix'] );

				if ( 0 !== $by_time ) {
					return $by_time;
				}

				return strcmp( (string) $right['tie'], (string) $left['tie'] );
			}
		);

		$public = array();

		foreach ( array_slice( $report, 0, self::REPORT_EVENT_LIMIT ) as $event ) {
			$public[] = array(
				'timestamp' => (string) $event['timestamp'],
				'status'    => (string) $event['status'],
				'step'      => (string) $event['step'],
				'errorCode' => (string) $event['errorCode'],
			);
		}

		return $public;
	}

	/**
	 * Seven UTC dates ending today.
	 *
	 * @param int $now Unix timestamp.
	 * @return array<int,array{date:string,completed:int,failed:int}>
	 */
	private function emptyDays( int $now ): array {
		$days = array();

		for ( $offset = self::WINDOW_DAYS - 1; $offset >= 0; $offset-- ) {
			$days[] = array(
				'date'      => gmdate( 'Y-m-d', $now - ( $offset * DAY_IN_SECONDS ) ),
				'completed' => 0,
				'failed'    => 0,
			);
		}

		return $days;
	}

	/**
	 * Median of matched start-to-terminal durations.
	 *
	 * @param array<int,int> $samples Durations in seconds.
	 */
	private function median( array $samples ): int|float|null {
		if ( array() === $samples ) {
			return null;
		}

		sort( $samples, SORT_NUMERIC );

		$count  = count( $samples );
		$middle = intdiv( $count, 2 );

		if ( 1 === $count % 2 ) {
			return (int) $samples[ $middle ];
		}

		$value = ( $samples[ $middle - 1 ] + $samples[ $middle ] ) / 2;

		if ( floor( $value ) === $value ) {
			return (int) $value;
		}

		return $value;
	}

	/**
	 * Count due single-source sync events whose post is in the scanned accessible set.
	 *
	 * Sources beyond the 500 cap are absent here. The limited flag already records that cap.
	 *
	 * @param array<int,bool> $accessible_post_ids Post IDs from listSourcesPage.
	 */
	private function dueSourceCronEvents( array $accessible_post_ids ): int {
		if ( array() === $accessible_post_ids || ! function_exists( 'wp_get_ready_cron_jobs' ) ) {
			return 0;
		}

		$ready = wp_get_ready_cron_jobs();

		if ( ! is_array( $ready ) ) {
			return 0;
		}

		$waiting = 0;

		foreach ( $ready as $hooks ) {
			if ( ! is_array( $hooks ) || ! isset( $hooks[ SyncCron::SOURCE_HOOK ] ) || ! is_array( $hooks[ SyncCron::SOURCE_HOOK ] ) ) {
				continue;
			}

			foreach ( $hooks[ SyncCron::SOURCE_HOOK ] as $event ) {
				if ( ! is_array( $event ) || ! isset( $event['args'] ) || ! is_array( $event['args'] ) ) {
					continue;
				}

				$post_id = isset( $event['args'][0] ) ? absint( $event['args'][0] ) : 0;

				if ( $post_id > 0 && isset( $accessible_post_ids[ $post_id ] ) ) {
					++$waiting;
				}
			}
		}

		return $waiting;
	}

	/**
	 * Parse a UTC timestamp emitted by this plugin.
	 *
	 * @param string $value Stored timestamp.
	 */
	private function unixTimestamp( string $value ): ?int {
		$value = trim( $value );

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:Z|\+00:00)?$/', $value, $matches ) ) {
			return null;
		}

		$year   = (int) $matches[1];
		$month  = (int) $matches[2];
		$day    = (int) $matches[3];
		$hour   = (int) $matches[4];
		$minute = (int) $matches[5];
		$second = (int) $matches[6];

		if ( $month < 1 || $month > 12 || $day < 1 || $day > 31 || $hour > 23 || $minute > 59 || $second > 59 ) {
			return null;
		}

		$unix = gmmktime( $hour, $minute, $second, $month, $day, $year );

		if ( false === $unix ) {
			return null;
		}

		$normalized = sprintf( '%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second );

		if ( gmdate( 'Y-m-d H:i:s', $unix ) !== $normalized ) {
			return null;
		}

		return $unix;
	}
}
