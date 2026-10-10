<?php
/**
 * Hourly cleanup for import sessions, matching jobs, and short-lived records.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Auth\OAuthContinuationStore;
use DocSyncWP\Matching\MatchingService;
use DocSyncWP\Matching\MatchSessionRepository;
use DocSyncWP\Sync\SourceBatchService;

defined( 'ABSPATH' ) || exit;

/**
 * Expires sessions and jobs and retries cleanup records with backoff.
 *
 * A session past its 24-hour expiry loses its private bytes at once. Every
 * Google file still in a file's cleanup set is trashed through
 * ImportService::trashTemporaries (app-created, unlinked files only). The
 * record is deleted only when no ID remains; otherwise it is kept as a
 * cleanup record with `cleanupPending`, invisible to session routes, and
 * retried after 1 hour, doubling up to 24 hours.
 */
final class ImportCleanup {
	public const HOOK = 'docsync_wp_import_cleanup';

	private const BACKOFF_BASE_SECONDS = HOUR_IN_SECONDS;
	private const BACKOFF_MAX_SECONDS  = DAY_IN_SECONDS;
	private const CAS_ATTEMPTS         = 8;

	/**
	 * Session repository.
	 *
	 * @var ImportSessionRepository
	 */
	private ImportSessionRepository $sessions;

	/**
	 * Private asset store.
	 *
	 * @var PrivateAssetStore
	 */
	private PrivateAssetStore $assets;

	/**
	 * Import service.
	 *
	 * @var ImportService
	 */
	private ImportService $imports;

	/**
	 * Matching job repository.
	 *
	 * @var MatchSessionRepository
	 */
	private MatchSessionRepository $match_jobs;

	/**
	 * Matching service.
	 *
	 * @var MatchingService
	 */
	private MatchingService $matching;

	/**
	 * OAuth continuation store.
	 *
	 * @var OAuthContinuationStore
	 */
	private OAuthContinuationStore $continuations;

	/**
	 * Source batch service.
	 *
	 * @var SourceBatchService
	 */
	private SourceBatchService $source_batch;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionRepository $sessions      Session repository.
	 * @param PrivateAssetStore       $assets        Private asset store.
	 * @param ImportService           $imports       Import service.
	 * @param MatchSessionRepository  $match_jobs    Matching job repository.
	 * @param MatchingService         $matching      Matching service.
	 * @param OAuthContinuationStore  $continuations OAuth continuation store.
	 * @param SourceBatchService      $source_batch  Source batch service.
	 */
	public function __construct(
		ImportSessionRepository $sessions,
		PrivateAssetStore $assets,
		ImportService $imports,
		MatchSessionRepository $match_jobs,
		MatchingService $matching,
		OAuthContinuationStore $continuations,
		SourceBatchService $source_batch
	) {
		$this->sessions      = $sessions;
		$this->assets        = $assets;
		$this->imports       = $imports;
		$this->match_jobs    = $match_jobs;
		$this->matching      = $matching;
		$this->continuations = $continuations;
		$this->source_batch  = $source_batch;
	}

	/**
	 * Schedule the hourly event on init and run it on the hook.
	 */
	public function register(): void {
		add_action(
			'init',
			static function (): void {
				if ( false === wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HOOK );
				}
			}
		);
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Run every cleanup pass.
	 */
	public function run(): void {
		$now = time();

		foreach ( array_merge( $this->sessions->listExpired( $now ), $this->sessions->listCleanupDue( $now ) ) as $entry ) {
			$session = $this->sessions->getForWorker( (string) $entry['sessionId'] );

			if ( null !== $session ) {
				$this->cleanupSession( $session );
			}
		}

		foreach ( array_merge( $this->match_jobs->listExpired( $now ), $this->match_jobs->listCleanupDue( $now ) ) as $entry ) {
			$job = $this->match_jobs->getForWorker( (string) $entry['jobId'] );

			if ( null !== $job ) {
				$this->matching->expireJob( $job );
			}
		}

		$live = array();

		foreach ( array_merge( $this->sessions->listExpired( PHP_INT_MAX ), $this->sessions->listCleanupDue( PHP_INT_MAX ) ) as $entry ) {
			$live[] = (string) $entry['sessionId'];
		}

		$this->assets->purgeOrphans( $live );
		$this->continuations->purgeExpired();
		$this->source_batch->purgeExpired();
	}

	/**
	 * Purge a session's bytes and trash its remaining Google temporaries.
	 *
	 * The record is deleted only when no ID remains; otherwise it is saved
	 * with `cleanupPending` and the next backoff time. A session that is
	 * still live (open or committing before expiry, without a cleanup flag)
	 * is left alone, and so is one whose lease another worker holds.
	 *
	 * @param array<string,mixed> $session Stored session.
	 */
	public function cleanupSession( array $session ): void {
		$session_id = (string) ( $session['sessionId'] ?? '' );
		$now        = time();

		if ( empty( $session['cleanupPending'] ) && absint( $session['expiresAt'] ?? 0 ) > $now ) {
			return;
		}

		if ( ! $this->sessions->lock( $session_id ) ) {
			return;
		}

		$deleted = false;

		try {
			$this->assets->deleteSession( $session_id );

			$current = $this->sessions->getForWorker( $session_id );

			if ( null === $current ) {
				return;
			}

			$ids = array();

			foreach ( $current['files'] as $file ) {
				$ids = array_merge( $ids, array_map( 'strval', (array) ( $file['googleTemporaries'] ?? array() ) ) );
			}

			$owner    = absint( $current['ownerUserId'] );
			$resolved = array_values( array_diff( $ids, $this->imports->trashTemporaries( $owner, $ids ) ) );

			for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
				$record = $this->sessions->getForWorker( $session_id );

				if ( null === $record ) {
					$deleted = true;

					return;
				}

				$remaining = false;

				foreach ( $record['files'] as $index => $file ) {
					$record['files'][ $index ]['googleTemporaries'] = array_values( array_diff( (array) ( $file['googleTemporaries'] ?? array() ), $resolved ) );
					$remaining                                      = $remaining || array() !== $record['files'][ $index ]['googleTemporaries'];
				}

				if ( ! $remaining ) {
					$saved = $this->sessions->save( $record );

					if ( true === $saved && $this->sessions->delete( $session_id ) ) {
						$deleted = true;

						return;
					}
				} else {
					$attempts = absint( $record['cleanupAttempts'] ?? 0 ) + 1;

					if ( ! in_array( $record['status'], array( ImportSessionRepository::STATUS_CANCELLED, ImportSessionRepository::STATUS_EXPIRED ), true ) ) {
						$record['status'] = ImportSessionRepository::STATUS_EXPIRED;
					}

					$record['cleanupPending']  = true;
					$record['cleanupAttempts'] = $attempts;
					$record['nextCleanupAt']   = $now + min( self::BACKOFF_MAX_SECONDS, self::BACKOFF_BASE_SECONDS * ( 2 ** min( 10, $attempts - 1 ) ) );

					$saved = $this->sessions->save( $record );

					if ( true === $saved ) {
						return;
					}
				}

				if ( is_wp_error( $saved ) && 'docsync_wp_import_session_conflict' !== $saved->get_error_code() ) {
					return;
				}
			}
		} finally {
			if ( ! $deleted ) {
				$this->sessions->unlock( $session_id );
			}
		}
	}

	/**
	 * Clear the hourly event (deactivation and uninstall).
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
