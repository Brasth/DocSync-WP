<?php
/**
 * Durable storage for bulk-linking match jobs.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Matching;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores match jobs as non-autoloaded options with an expiry index and leases.
 *
 * Each job lives in `docsync_wp_match_job_{jobId}`. The index option maps
 * every job to its owner, expiry, status, and cleanup schedule; it is changed
 * only with compare-and-swap writes against the exact stored row, so
 * concurrent requests never lose each other's entries and the active-job limit
 * holds under races. Leases use the same compare-and-swap pattern with a
 * random token, so only the holder can renew or release one.
 */
final class MatchSessionRepository {
	public const TTL_SECONDS     = DAY_IN_SECONDS;
	public const MAX_ACTIVE_JOBS = 2;

	public const OPTION_PREFIX  = 'docsync_wp_match_job_';
	public const INDEX_OPTION   = 'docsync_wp_match_job_index';
	public const LOCK_PREFIX    = 'docsync_wp_match_job_lock_';
	public const RECORD_VERSION = 1;

	private const LOCK_TTL_SECONDS      = 300;
	private const STALLED_AFTER_SECONDS = 1800;
	private const INDEX_WRITE_ATTEMPTS  = 8;
	private const ACTIVE_STATUSES       = array( 'queued', 'listing', 'running' );
	private const JOB_ID_PATTERN        = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/';

	/**
	 * Lease tokens held by this process, keyed by job ID.
	 *
	 * @var array<string,string>
	 */
	private array $lock_tokens = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Create and index a job owned by the user.
	 *
	 * `$input` holds the job state prepared by the matching service. Identity,
	 * timestamps, status, and cleanup fields are assigned here. The caller may
	 * have at most two active (queued, listing, or running) jobs; the check and
	 * the index insert are one compare-and-swap write.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $input   Initial job state.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( int $user_id, array $input ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'You must be logged in before linking posts to Google Docs.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		$now = time();
		$job = array_merge(
			$input,
			array(
				'version'           => self::RECORD_VERSION,
				'jobId'             => wp_generate_uuid4(),
				'ownerUserId'       => $user_id,
				'status'            => 'queued',
				'createdAt'         => $now,
				'updatedAt'         => $now,
				'expiresAt'         => $now + self::TTL_SECONDS,
				'googleTemporaries' => array(),
				'cleanupPending'    => false,
				'cleanupAttempts'   => 0,
				'nextCleanupAt'     => 0,
			)
		);

		$name = $this->optionName( (string) $job['jobId'] );

		if ( ! $this->insertOptionIfAbsent( $name, $job ) || $this->readFreshOption( $name ) !== $job ) {
			$this->deleteOptionRow( $name );

			return $this->storageError();
		}

		$indexed = $this->mutateIndex(
			function ( array $index ) use ( $job, $user_id, $now ): array|WP_Error {
				$active = 0;

				foreach ( $index as $entry ) {
					if ( $entry['ownerUserId'] === $user_id && $this->isActiveEntry( $entry, $now ) ) {
						++$active;
					}
				}

				if ( $active >= self::MAX_ACTIVE_JOBS ) {
					return new WP_Error(
						'docsync_wp_matching_job_limit',
						__( 'You already have two linking jobs in progress. Wait for one to finish, then try again.', 'brasth-document-sync-for-google-docs' ),
						array( 'status' => 409 )
					);
				}

				$index[ (string) $job['jobId'] ] = $this->indexEntry( $job );

				return $index;
			}
		);

		if ( true !== $indexed ) {
			$this->deleteOptionRow( $name );

			return is_wp_error( $indexed ) ? $indexed : $this->storageError();
		}

		return $job;
	}

	/**
	 * Read a job owned by the user.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|WP_Error 404 for missing or foreign jobs, 410 once expired.
	 */
	public function get( string $job_id, int $user_id ): array|WP_Error {
		$job = $this->getForWorker( $job_id );

		if ( null === $job || $user_id <= 0 || absint( $job['ownerUserId'] ) !== $user_id ) {
			return new WP_Error(
				'docsync_wp_matching_job_not_found',
				__( 'Brasth Document Sync could not find this linking job.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( 'expired' === $job['status'] || absint( $job['expiresAt'] ) <= time() ) {
			return new WP_Error(
				'docsync_wp_matching_job_expired',
				__( 'This linking job has expired. Start a new one.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 410 )
			);
		}

		return $job;
	}

	/**
	 * Read a job without an owner check (cron and cleanup only).
	 *
	 * @param string $job_id Job ID.
	 * @return array<string,mixed>|null
	 */
	public function getForWorker( string $job_id ): ?array {
		if ( 1 !== preg_match( self::JOB_ID_PATTERN, $job_id ) ) {
			return null;
		}

		$job = $this->readFreshOption( $this->optionName( $job_id ) );

		if (
			! is_array( $job )
			|| self::RECORD_VERSION !== ( $job['version'] ?? null )
			|| ( $job['jobId'] ?? null ) !== $job_id
			|| absint( $job['ownerUserId'] ?? 0 ) <= 0
			|| ! is_string( $job['status'] ?? null )
		) {
			return null;
		}

		return $job;
	}

	/**
	 * Persist a job and its index entry, confirming the stored value.
	 *
	 * Callers hold the job lease. `updatedAt` is set here.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return bool|WP_Error
	 */
	public function save( array $job ): bool|WP_Error {
		$job_id = isset( $job['jobId'] ) && is_string( $job['jobId'] ) ? $job['jobId'] : '';

		if ( 1 !== preg_match( self::JOB_ID_PATTERN, $job_id ) || absint( $job['ownerUserId'] ?? 0 ) <= 0 ) {
			return $this->storageError();
		}

		if ( isset( $this->lock_tokens[ $job_id ] ) && ! $this->holdsLease( $job_id ) ) {
			unset( $this->lock_tokens[ $job_id ] );

			return new WP_Error(
				'docsync_wp_matching_job_busy',
				__( 'This linking job is busy. Wait a moment, then try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		$job['updatedAt'] = time();
		$name             = $this->optionName( $job_id );

		$this->readFreshOption( $name );

		if ( ! update_option( $name, $job, false ) && $this->readFreshOption( $name ) !== $job ) {
			return $this->storageError();
		}

		$entry   = $this->indexEntry( $job );
		$indexed = $this->mutateIndex(
			static function ( array $index ) use ( $job_id, $entry ): array {
				$index[ $job_id ] = $entry;

				return $index;
			}
		);

		return true === $indexed ? true : $this->storageError();
	}

	/**
	 * Delete a job record, its expired or own lease, and its index entry.
	 *
	 * @param string $job_id Job ID.
	 * @return bool True when no job record remains.
	 */
	public function delete( string $job_id ): bool {
		if ( 1 !== preg_match( self::JOB_ID_PATTERN, $job_id ) ) {
			return false;
		}

		$name = $this->optionName( $job_id );

		delete_option( $name );

		$lock_name = $this->lockName( $job_id );
		$lock_raw  = $this->readRawOption( $lock_name );

		if ( null !== $lock_raw ) {
			$lock = maybe_unserialize( $lock_raw );

			if ( $this->lockExpiry( $lock ) <= time() || ( is_array( $lock ) && isset( $this->lock_tokens[ $job_id ] ) && ( $lock['token'] ?? null ) === $this->lock_tokens[ $job_id ] ) ) {
				$this->deleteOptionIfUnchanged( $lock_name, $lock_raw );
			}
		}

		unset( $this->lock_tokens[ $job_id ] );

		$this->mutateIndex(
			static function ( array $index ) use ( $job_id ): array {
				unset( $index[ $job_id ] );

				return $index;
			}
		);

		return null === $this->readRawOption( $name );
	}

	/**
	 * Expired jobs not yet handled by cleanup.
	 *
	 * @param int $now Current time.
	 * @return array<int,array{jobId:string,ownerUserId:int}>
	 */
	public function listExpired( int $now ): array {
		$jobs = array();

		foreach ( $this->readIndex() as $job_id => $entry ) {
			if ( ! $entry['cleanupPending'] && $entry['expiresAt'] <= $now ) {
				$jobs[] = array(
					'jobId'       => $job_id,
					'ownerUserId' => $entry['ownerUserId'],
				);
			}
		}

		return $jobs;
	}

	/**
	 * Cleanup records whose next retry is due.
	 *
	 * @param int $now Current time.
	 * @return array<int,array{jobId:string,ownerUserId:int}>
	 */
	public function listCleanupDue( int $now ): array {
		$jobs = array();

		foreach ( $this->readIndex() as $job_id => $entry ) {
			if ( $entry['cleanupPending'] && $entry['nextCleanupAt'] <= $now ) {
				$jobs[] = array(
					'jobId'       => $job_id,
					'ownerUserId' => $entry['ownerUserId'],
				);
			}
		}

		return $jobs;
	}

	/**
	 * Acquire the job lease, or renew it when this process already holds it.
	 *
	 * A missing lease is created with an insert that fails when the row exists;
	 * an expired lease is replaced, and a held lease renewed, only while its
	 * stored row is still the exact bytes read here.
	 *
	 * @param string $job_id Job ID.
	 * @return bool False when another process holds the lease or this process lost it.
	 */
	public function lock( string $job_id ): bool {
		if ( 1 !== preg_match( self::JOB_ID_PATTERN, $job_id ) ) {
			return false;
		}

		$name = $this->lockName( $job_id );
		$raw  = $this->readRawOption( $name );
		$held = $this->lock_tokens[ $job_id ] ?? null;

		if ( null !== $held ) {
			$current = null === $raw ? null : maybe_unserialize( $raw );

			if ( ! is_array( $current ) || ( $current['token'] ?? null ) !== $held ) {
				unset( $this->lock_tokens[ $job_id ] );

				return false;
			}

			$renewed = array(
				'token'     => $held,
				'expiresAt' => time() + self::LOCK_TTL_SECONDS,
			);

			return $renewed === $current || $this->replaceOptionIfUnchanged( $name, (string) $raw, $renewed );
		}

		$lock = array(
			'token'     => wp_generate_uuid4(),
			'expiresAt' => time() + self::LOCK_TTL_SECONDS,
		);

		if ( null === $raw ) {
			$acquired = $this->insertOptionIfAbsent( $name, $lock );
		} elseif ( $this->lockExpiry( maybe_unserialize( $raw ) ) > time() ) {
			return false;
		} else {
			$acquired = $this->replaceOptionIfUnchanged( $name, $raw, $lock );
		}

		if ( $acquired ) {
			$this->lock_tokens[ $job_id ] = $lock['token'];
		}

		return $acquired;
	}

	/**
	 * Release the job lease if this process still holds it.
	 *
	 * @param string $job_id Job ID.
	 */
	public function unlock( string $job_id ): void {
		$token = $this->lock_tokens[ $job_id ] ?? null;

		unset( $this->lock_tokens[ $job_id ] );

		if ( null === $token ) {
			return;
		}

		$name = $this->lockName( $job_id );
		$raw  = $this->readRawOption( $name );

		if ( null === $raw ) {
			return;
		}

		$current = maybe_unserialize( $raw );

		if ( is_array( $current ) && ( $current['token'] ?? null ) === $token ) {
			$this->deleteOptionIfUnchanged( $name, $raw );
		}
	}

	/**
	 * Whether the stored, unexpired lease still carries this process's token.
	 *
	 * A worker whose lease lapsed and was taken over must not overwrite the job.
	 *
	 * @param string $job_id Job ID.
	 */
	private function holdsLease( string $job_id ): bool {
		$raw = $this->readRawOption( $this->lockName( $job_id ) );

		if ( null === $raw ) {
			return false;
		}

		$lease = maybe_unserialize( $raw );

		return is_array( $lease )
			&& ( $lease['token'] ?? null ) === $this->lock_tokens[ $job_id ]
			&& $this->lockExpiry( $lease ) > time();
	}

	/**
	 * Whether an index entry counts toward the active-job limit.
	 *
	 * Jobs whose worker made no progress for 30 minutes no longer block new jobs.
	 *
	 * @param array{ownerUserId:int,expiresAt:int,status:string,updatedAt:int,cleanupPending:bool,nextCleanupAt:int} $entry Index entry.
	 * @param int                                                                                                    $now   Current time.
	 */
	private function isActiveEntry( array $entry, int $now ): bool {
		return in_array( $entry['status'], self::ACTIVE_STATUSES, true )
			&& $entry['expiresAt'] > $now
			&& $entry['updatedAt'] > $now - self::STALLED_AFTER_SECONDS;
	}

	/**
	 * Index entry for a job.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array{ownerUserId:int,expiresAt:int,status:string,updatedAt:int,cleanupPending:bool,nextCleanupAt:int}
	 */
	private function indexEntry( array $job ): array {
		return array(
			'ownerUserId'    => absint( $job['ownerUserId'] ?? 0 ),
			'expiresAt'      => absint( $job['expiresAt'] ?? 0 ),
			'status'         => is_string( $job['status'] ?? null ) ? $job['status'] : '',
			'updatedAt'      => absint( $job['updatedAt'] ?? 0 ),
			'cleanupPending' => true === ( $job['cleanupPending'] ?? false ),
			'nextCleanupAt'  => absint( $job['nextCleanupAt'] ?? 0 ),
		);
	}

	/**
	 * Read and validate the job index.
	 *
	 * @return array<string,array{ownerUserId:int,expiresAt:int,status:string,updatedAt:int,cleanupPending:bool,nextCleanupAt:int}>
	 */
	private function readIndex(): array {
		$raw = $this->readRawOption( self::INDEX_OPTION );

		return null === $raw ? array() : $this->sanitizeIndex( maybe_unserialize( $raw ) );
	}

	/**
	 * Keep only well-formed index entries.
	 *
	 * @param mixed $index Stored index.
	 * @return array<string,array{ownerUserId:int,expiresAt:int,status:string,updatedAt:int,cleanupPending:bool,nextCleanupAt:int}>
	 */
	private function sanitizeIndex( mixed $index ): array {
		$valid = array();

		if ( ! is_array( $index ) ) {
			return $valid;
		}

		foreach ( $index as $job_id => $entry ) {
			if ( is_string( $job_id ) && 1 === preg_match( self::JOB_ID_PATTERN, $job_id ) && is_array( $entry ) && absint( $entry['ownerUserId'] ?? 0 ) > 0 ) {
				$valid[ $job_id ] = $this->indexEntry( $entry );
			}
		}

		return $valid;
	}

	/**
	 * Apply a change to the index with compare-and-swap writes, retrying on races.
	 *
	 * @param callable $mutator Receives the current index; returns the new index or a WP_Error.
	 * @return bool|WP_Error True when the change is stored, false when every attempt raced.
	 */
	private function mutateIndex( callable $mutator ): bool|WP_Error {
		for ( $attempt = 0; $attempt < self::INDEX_WRITE_ATTEMPTS; $attempt++ ) {
			$raw     = $this->readRawOption( self::INDEX_OPTION );
			$current = null === $raw ? array() : $this->sanitizeIndex( maybe_unserialize( $raw ) );
			$next    = $mutator( $current );

			if ( is_wp_error( $next ) ) {
				return $next;
			}

			if ( $next === $current && ( null === $raw || maybe_serialize( $current ) === $raw ) ) {
				return true;
			}

			if ( null === $raw ) {
				$stored = $this->insertOptionIfAbsent( self::INDEX_OPTION, $next );
			} elseif ( array() === $next ) {
				$stored = $this->deleteOptionIfUnchanged( self::INDEX_OPTION, $raw );
			} else {
				$stored = $this->replaceOptionIfUnchanged( self::INDEX_OPTION, $raw, $next );
			}

			if ( $stored ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Job option name.
	 *
	 * @param string $job_id Job ID.
	 */
	private function optionName( string $job_id ): string {
		return self::OPTION_PREFIX . $job_id;
	}

	/**
	 * Lease option name.
	 *
	 * @param string $job_id Job ID.
	 */
	private function lockName( string $job_id ): string {
		return self::LOCK_PREFIX . $job_id;
	}

	/**
	 * Expiry timestamp of a stored lease; malformed values count as expired.
	 *
	 * @param mixed $lock Stored lease.
	 */
	private function lockExpiry( mixed $lock ): int {
		return is_array( $lock ) ? absint( $lock['expiresAt'] ?? 0 ) : 0;
	}

	/**
	 * Read the stored option row directly, without option caches or filters.
	 *
	 * @param string $name Option name.
	 * @return string|null Serialized stored value, or null when the row is missing.
	 */
	private function readRawOption( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Insert a non-autoloaded option only when no row with that name exists.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True when this call created the row.
	 */
	private function insertOptionIfAbsent( string $name, mixed $value ): bool {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				maybe_serialize( $value ),
				'no'
			)
		);

		$this->clearOptionCache( $name );

		return 1 === $inserted;
	}

	/**
	 * Replace an option only while its stored row equals the expected bytes.
	 *
	 * @param string $name     Option name.
	 * @param string $expected Serialized value read earlier.
	 * @param mixed  $value    New value.
	 * @return bool True when this call replaced the expected row.
	 */
	private function replaceOptionIfUnchanged( string $name, string $expected, mixed $value ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
				maybe_serialize( $value ),
				$name,
				$expected
			)
		);

		$this->clearOptionCache( $name );

		return 1 === $updated;
	}

	/**
	 * Delete an option only while its stored row equals the expected bytes.
	 *
	 * @param string $name     Option name.
	 * @param string $expected Serialized value read earlier.
	 * @return bool True when this call deleted the expected row.
	 */
	private function deleteOptionIfUnchanged( string $name, string $expected ): bool {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
				$name,
				$expected
			)
		);

		$this->clearOptionCache( $name );

		return 1 === $deleted;
	}

	/**
	 * Delete an option row this process just created, whatever it holds.
	 *
	 * @param string $name Option name.
	 */
	private function deleteOptionRow( string $name ): void {
		delete_option( $name );
		$this->clearOptionCache( $name );
	}

	/**
	 * Clear option caches after a direct compare-and-swap write.
	 *
	 * @param string $name Option name.
	 */
	private function clearOptionCache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Read an option from the database, bypassing values cached by this request.
	 *
	 * @param string $name Option name.
	 * @return mixed Null when the option does not exist.
	 */
	private function readFreshOption( string $name ): mixed {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return get_option( $name, null );
	}

	/**
	 * Job state could not be saved.
	 */
	private function storageError(): WP_Error {
		return new WP_Error(
			'docsync_wp_matching_storage_failed',
			__( 'Brasth Document Sync could not save this linking job. Try again.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 500 )
		);
	}
}
