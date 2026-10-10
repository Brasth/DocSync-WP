<?php
/**
 * Owner-bound import session records.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores import sessions as non-autoloaded options with an expiry index.
 *
 * Every write is a compare-and-swap against the exact serialized option row
 * read earlier, with option caches invalidated afterwards, so concurrent
 * requests and cron workers can never overwrite each other's changes. A
 * write that loses the race returns `docsync_wp_import_session_conflict` and
 * the caller re-reads and reapplies its change. The per-session lease uses a
 * random token so only its holder can renew or release it.
 */
final class ImportSessionRepository {
	public const MAX_OPEN_SESSIONS = 3;
	public const TTL_SECONDS       = DAY_IN_SECONDS;
	public const OPTION_PREFIX     = 'docsync_wp_import_session_';
	public const INDEX_OPTION      = 'docsync_wp_import_session_index';
	public const LOCK_PREFIX       = 'docsync_wp_import_lock_';
	public const LOCK_TTL_SECONDS  = 300;
	public const VERSION           = 1;

	public const STATUS_OPEN       = 'open';
	public const STATUS_COMMITTING = 'committing';
	public const STATUS_COMMITTED  = 'committed';
	public const STATUS_CANCELLED  = 'cancelled';
	public const STATUS_EXPIRED    = 'expired';

	private const CAS_ATTEMPTS      = 8;
	private const SESSION_PATTERN   = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/';
	private const FILE_ID_PATTERN   = '/^f_[a-f0-9]{16}$/';
	private const GOOGLE_ID_PATTERN = '/^[A-Za-z0-9_-]{10,200}$/';

	/**
	 * Exact serialized rows read by this request, keyed by session ID.
	 *
	 * @var array<string,string>
	 */
	private array $rows = array();

	/**
	 * Lease tokens held by this request, keyed by session ID.
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
	 * Create a session for a user, enforcing the open-session limit.
	 *
	 * The limit is checked and the index entry added in one compare-and-swap,
	 * so two concurrent requests can never both pass the limit.
	 *
	 * @param int $user_id Owner user ID.
	 * @return array<string,mixed>|WP_Error Stored session record.
	 */
	public function create( int $user_id ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return $this->notFoundError();
		}

		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$now       = time();
			$raw_index = $this->readRawOption( self::INDEX_OPTION );
			$index     = $this->decodeIndex( $raw_index );

			if ( $this->countOpenSessions( $index, $user_id, $now ) >= self::MAX_OPEN_SESSIONS ) {
				return new WP_Error(
					'docsync_wp_import_session_limit',
					__( 'You already have 3 open imports. Finish or cancel one, then start another.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 409 )
				);
			}

			$session_id = wp_generate_uuid4();
			$session    = array(
				'version'         => self::VERSION,
				'sessionId'       => $session_id,
				'ownerUserId'     => $user_id,
				'status'          => self::STATUS_OPEN,
				'createdAt'       => $now,
				'updatedAt'       => $now,
				'expiresAt'       => $now + self::TTL_SECONDS,
				'storageMode'     => '',
				'files'           => array(),
				'result'          => null,
				'commit'          => null,
				'cleanupPending'  => false,
				'cleanupAttempts' => 0,
				'nextCleanupAt'   => 0,
			);

			if ( ! $this->insertOptionIfAbsent( $this->optionName( $session_id ), $session ) ) {
				continue;
			}

			$index[ $session_id ] = $this->indexEntry( $session );
			$stored_index         = null === $raw_index
				? $this->insertOptionIfAbsent( self::INDEX_OPTION, $index )
				: $this->replaceOptionIfUnchanged( self::INDEX_OPTION, $raw_index, $index );

			if ( $stored_index ) {
				$this->rows[ $session_id ] = (string) $this->readRawOption( $this->optionName( $session_id ) );

				return $session;
			}

			$this->deleteOptionIfUnchanged( $this->optionName( $session_id ), (string) maybe_serialize( $session ) );
		}

		return $this->storageError();
	}

	/**
	 * Read a live session owned by the user.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $user_id    Caller user ID.
	 * @return array<string,mixed>|WP_Error 404 when missing, foreign, or a cleanup record; 410 when expired.
	 */
	public function get( string $session_id, int $user_id ): array|WP_Error {
		$session = $this->getForWorker( $session_id );

		if (
			null === $session
			|| $user_id <= 0
			|| absint( $session['ownerUserId'] ) !== $user_id
			|| ! empty( $session['cleanupPending'] )
			|| self::STATUS_CANCELLED === $session['status']
		) {
			return $this->notFoundError();
		}

		if ( self::STATUS_EXPIRED === $session['status'] || absint( $session['expiresAt'] ) <= time() ) {
			return new WP_Error(
				'docsync_wp_import_session_expired',
				__( 'This import expired after 24 hours. Its files were removed; start a new import.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 410 )
			);
		}

		return $session;
	}

	/**
	 * Read a session for cron workers and cleanup, without an owner check.
	 *
	 * @param string $session_id Session ID.
	 * @return array<string,mixed>|null
	 */
	public function getForWorker( string $session_id ): ?array {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) ) {
			return null;
		}

		$raw = $this->readRawOption( $this->optionName( $session_id ) );

		if ( null === $raw ) {
			unset( $this->rows[ $session_id ] );

			return null;
		}

		$session = maybe_unserialize( $raw );

		if ( ! is_array( $session ) || ! $this->isValidRecord( $session, $session_id ) ) {
			return null;
		}

		$this->rows[ $session_id ] = $raw;

		return $session;
	}

	/**
	 * Save a session read earlier in this request.
	 *
	 * The write succeeds only while the stored row is still the exact row this
	 * request read; otherwise it returns `docsync_wp_import_session_conflict`.
	 *
	 * @param array<string,mixed> $session Session record.
	 * @return bool|WP_Error
	 */
	public function save( array $session ): bool|WP_Error {
		$session_id = isset( $session['sessionId'] ) && is_string( $session['sessionId'] ) ? $session['sessionId'] : '';

		if ( ! $this->isValidRecord( $session, $session_id ) ) {
			return $this->storageError();
		}

		$name     = $this->optionName( $session_id );
		$expected = $this->rows[ $session_id ] ?? $this->readRawOption( $name );

		if ( null === $expected ) {
			return $this->notFoundError();
		}

		$session['updatedAt'] = time();
		$previous             = maybe_unserialize( $expected );

		if ( maybe_serialize( $session ) === $expected ) {
			return true;
		}

		if ( ! $this->replaceOptionIfUnchanged( $name, $expected, $session ) ) {
			unset( $this->rows[ $session_id ] );

			return new WP_Error(
				'docsync_wp_import_session_conflict',
				__( 'This import changed while it was being saved. Try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		$this->rows[ $session_id ] = (string) maybe_serialize( $session );

		if ( ! is_array( $previous ) || $this->indexEntry( $previous ) !== $this->indexEntry( $session ) ) {
			$this->updateIndex(
				static function ( array $index ) use ( $session_id, $session ): array {
					$index[ $session_id ] = array(
						'ownerUserId'    => absint( $session['ownerUserId'] ),
						'expiresAt'      => absint( $session['expiresAt'] ),
						'cleanupPending' => ! empty( $session['cleanupPending'] ),
						'nextCleanupAt'  => absint( $session['nextCleanupAt'] ?? 0 ),
					);

					return $index;
				}
			);
		}

		return true;
	}

	/**
	 * Delete a session record, its index entry, and its lease.
	 *
	 * The record row is deleted only while it is still the exact row this
	 * request last read, so an ID added concurrently (for example by a
	 * converter callback) is never lost; the call then returns false and the
	 * caller re-reads the record.
	 *
	 * @param string $session_id Session ID.
	 */
	public function delete( string $session_id ): bool {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) ) {
			return false;
		}

		$name     = $this->optionName( $session_id );
		$expected = $this->rows[ $session_id ] ?? $this->readRawOption( $name );

		unset( $this->rows[ $session_id ] );

		if ( null !== $expected && ! $this->deleteOptionIfUnchanged( $name, $expected ) && null !== $this->readRawOption( $name ) ) {
			return false;
		}

		$removed = $this->updateIndex(
			static function ( array $index ) use ( $session_id ): array {
				unset( $index[ $session_id ] );

				return $index;
			}
		);

		$this->unlock( $session_id );

		return $removed && null === $this->readRawOption( $name );
	}

	/**
	 * Open or committing sessions owned by a user, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function listOpenForUser( int $user_id ): array {
		$now      = time();
		$sessions = array();

		foreach ( $this->decodeIndex( $this->readRawOption( self::INDEX_OPTION ) ) as $session_id => $entry ) {
			if ( $entry['ownerUserId'] !== $user_id || $entry['cleanupPending'] || $entry['expiresAt'] <= $now ) {
				continue;
			}

			$session = $this->getForWorker( $session_id );

			if ( null !== $session && in_array( $session['status'], array( self::STATUS_OPEN, self::STATUS_COMMITTING ), true ) ) {
				$sessions[] = $session;
			}
		}

		usort(
			$sessions,
			static function ( array $a, array $b ): int {
				return absint( $b['createdAt'] ) <=> absint( $a['createdAt'] );
			}
		);

		return $sessions;
	}

	/**
	 * Sessions past their expiry that cleanup has not handled yet.
	 *
	 * @param int $now Current timestamp.
	 * @return array<int,array{sessionId:string,ownerUserId:int}>
	 */
	public function listExpired( int $now ): array {
		$expired = array();

		foreach ( $this->decodeIndex( $this->readRawOption( self::INDEX_OPTION ) ) as $session_id => $entry ) {
			if ( ! $entry['cleanupPending'] && $entry['expiresAt'] <= $now ) {
				$expired[] = array(
					'sessionId'   => $session_id,
					'ownerUserId' => $entry['ownerUserId'],
				);
			}
		}

		return $expired;
	}

	/**
	 * Cleanup records whose next retry is due.
	 *
	 * @param int $now Current timestamp.
	 * @return array<int,array{sessionId:string,ownerUserId:int}>
	 */
	public function listCleanupDue( int $now ): array {
		$due = array();

		foreach ( $this->decodeIndex( $this->readRawOption( self::INDEX_OPTION ) ) as $session_id => $entry ) {
			if ( $entry['cleanupPending'] && $entry['nextCleanupAt'] <= $now ) {
				$due[] = array(
					'sessionId'   => $session_id,
					'ownerUserId' => $entry['ownerUserId'],
				);
			}
		}

		return $due;
	}

	/**
	 * Persist one app-created Google file ID in a file's cleanup set at once.
	 *
	 * Called by the converter callback right after Drive returns the ID and
	 * before any Docs or Slides read. The caller already holds the session
	 * lease; this write still uses compare-and-swap so concurrent option or
	 * render updates are never lost. The ID is kept even when the session is
	 * no longer open, so cleanup can always find it.
	 *
	 * @param string $session_id     Session ID.
	 * @param string $file_id        Session file ID.
	 * @param string $google_file_id Created Google file ID.
	 * @return bool|WP_Error
	 */
	public function addGoogleTemporary( string $session_id, string $file_id, string $google_file_id ): bool|WP_Error {
		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) || 1 !== preg_match( self::GOOGLE_ID_PATTERN, $google_file_id ) ) {
			return $this->storageError();
		}

		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$session = $this->getForWorker( $session_id );

			if ( null === $session ) {
				return $this->notFoundError();
			}

			$index = $this->fileIndex( $session, $file_id );

			if ( null === $index ) {
				return $this->notFoundError();
			}

			$temporaries = isset( $session['files'][ $index ]['googleTemporaries'] ) && is_array( $session['files'][ $index ]['googleTemporaries'] )
				? array_values( array_map( 'strval', $session['files'][ $index ]['googleTemporaries'] ) )
				: array();

			if ( in_array( $google_file_id, $temporaries, true ) ) {
				return true;
			}

			$temporaries[]                                   = $google_file_id;
			$session['files'][ $index ]['googleTemporaries'] = $temporaries;

			$saved = $this->save( $session );

			if ( true === $saved ) {
				return true;
			}

			if ( is_wp_error( $saved ) && 'docsync_wp_import_session_conflict' !== $saved->get_error_code() ) {
				return $saved;
			}
		}

		return $this->storageError();
	}

	/**
	 * Acquire or renew the session lease.
	 *
	 * A missing lease is created with an insert that fails when the row
	 * exists; an expired lease is replaced only while its stored row is the
	 * exact one read here. Calling lock() again while this request holds the
	 * lease renews it for another 300 seconds.
	 *
	 * @param string $session_id Session ID.
	 */
	public function lock( string $session_id ): bool {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) ) {
			return false;
		}

		$name    = self::LOCK_PREFIX . $session_id;
		$raw     = $this->readRawOption( $name );
		$current = null !== $raw ? maybe_unserialize( $raw ) : null;
		$held    = $this->lock_tokens[ $session_id ] ?? '';
		$lease   = array(
			'token'     => '' !== $held ? $held : $this->newToken(),
			'expiresAt' => time() + self::LOCK_TTL_SECONDS,
		);

		if ( null === $raw ) {
			$acquired = $this->insertOptionIfAbsent( $name, $lease );
		} elseif ( '' !== $held && is_array( $current ) && ( $current['token'] ?? '' ) === $held ) {
			$acquired = $lease === $current || $this->replaceOptionIfUnchanged( $name, $raw, $lease );
		} elseif ( is_array( $current ) && absint( $current['expiresAt'] ?? 0 ) > time() ) {
			return false;
		} else {
			$acquired = $this->replaceOptionIfUnchanged( $name, $raw, $lease );
		}

		if ( $acquired ) {
			$this->lock_tokens[ $session_id ] = $lease['token'];

			return true;
		}

		unset( $this->lock_tokens[ $session_id ] );

		return false;
	}

	/**
	 * Release the session lease if this request still holds it.
	 *
	 * @param string $session_id Session ID.
	 */
	public function unlock( string $session_id ): void {
		$token = $this->lock_tokens[ $session_id ] ?? '';

		unset( $this->lock_tokens[ $session_id ] );

		if ( '' === $token ) {
			return;
		}

		$name = self::LOCK_PREFIX . $session_id;
		$raw  = $this->readRawOption( $name );

		if ( null === $raw ) {
			return;
		}

		$current = maybe_unserialize( $raw );

		if ( is_array( $current ) && ( $current['token'] ?? '' ) === $token ) {
			$this->deleteOptionIfUnchanged( $name, $raw );
		}
	}

	/**
	 * Count a user's live open or committing sessions.
	 *
	 * @param array<string,array{ownerUserId:int,expiresAt:int,cleanupPending:bool,nextCleanupAt:int}> $index   Index.
	 * @param int                                                                                      $user_id User ID.
	 * @param int                                                                                      $now     Current timestamp.
	 */
	private function countOpenSessions( array $index, int $user_id, int $now ): int {
		$count = 0;

		foreach ( $index as $session_id => $entry ) {
			if ( $entry['ownerUserId'] !== $user_id || $entry['cleanupPending'] || $entry['expiresAt'] <= $now ) {
				continue;
			}

			$session = $this->getForWorker( $session_id );

			if ( null !== $session && in_array( $session['status'], array( self::STATUS_OPEN, self::STATUS_COMMITTING ), true ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Apply a change to the index with compare-and-swap retries.
	 *
	 * @param callable $change Receives and returns the decoded index.
	 */
	private function updateIndex( callable $change ): bool {
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$raw     = $this->readRawOption( self::INDEX_OPTION );
			$current = $this->decodeIndex( $raw );
			$next    = $change( $current );

			if ( $next === $current ) {
				return true;
			}

			if ( null === $raw ) {
				$written = array() === $next || $this->insertOptionIfAbsent( self::INDEX_OPTION, $next );
			} elseif ( array() === $next ) {
				$written = $this->deleteOptionIfUnchanged( self::INDEX_OPTION, $raw );
			} else {
				$written = $this->replaceOptionIfUnchanged( self::INDEX_OPTION, $raw, $next );
			}

			if ( $written ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Decode and validate the stored index.
	 *
	 * @param string|null $raw Serialized index row.
	 * @return array<string,array{ownerUserId:int,expiresAt:int,cleanupPending:bool,nextCleanupAt:int}>
	 */
	private function decodeIndex( ?string $raw ): array {
		$index = null !== $raw ? maybe_unserialize( $raw ) : array();
		$valid = array();

		if ( ! is_array( $index ) ) {
			return $valid;
		}

		foreach ( $index as $session_id => $entry ) {
			if ( ! is_string( $session_id ) || 1 !== preg_match( self::SESSION_PATTERN, $session_id ) || ! is_array( $entry ) ) {
				continue;
			}

			$valid[ $session_id ] = array(
				'ownerUserId'    => absint( $entry['ownerUserId'] ?? 0 ),
				'expiresAt'      => absint( $entry['expiresAt'] ?? 0 ),
				'cleanupPending' => ! empty( $entry['cleanupPending'] ),
				'nextCleanupAt'  => absint( $entry['nextCleanupAt'] ?? 0 ),
			);
		}

		return $valid;
	}

	/**
	 * Index entry for a session record.
	 *
	 * @param array<string,mixed> $session Session record.
	 * @return array{ownerUserId:int,expiresAt:int,cleanupPending:bool,nextCleanupAt:int}
	 */
	private function indexEntry( array $session ): array {
		return array(
			'ownerUserId'    => absint( $session['ownerUserId'] ?? 0 ),
			'expiresAt'      => absint( $session['expiresAt'] ?? 0 ),
			'cleanupPending' => ! empty( $session['cleanupPending'] ),
			'nextCleanupAt'  => absint( $session['nextCleanupAt'] ?? 0 ),
		);
	}

	/**
	 * Whether a decoded record has the stored session shape.
	 *
	 * @param array<string,mixed> $session    Record.
	 * @param string              $session_id Expected session ID.
	 */
	private function isValidRecord( array $session, string $session_id ): bool {
		return 1 === preg_match( self::SESSION_PATTERN, $session_id )
			&& self::VERSION === ( $session['version'] ?? null )
			&& ( $session['sessionId'] ?? null ) === $session_id
			&& absint( $session['ownerUserId'] ?? 0 ) > 0
			&& in_array( $session['status'] ?? null, array( self::STATUS_OPEN, self::STATUS_COMMITTING, self::STATUS_COMMITTED, self::STATUS_CANCELLED, self::STATUS_EXPIRED ), true )
			&& absint( $session['expiresAt'] ?? 0 ) > 0
			&& isset( $session['files'] )
			&& is_array( $session['files'] )
			&& array_is_list( $session['files'] );
	}

	/**
	 * Position of a file in a session record.
	 *
	 * @param array<string,mixed> $session Session.
	 * @param string              $file_id File ID.
	 */
	private function fileIndex( array $session, string $file_id ): ?int {
		foreach ( $session['files'] as $index => $file ) {
			if ( is_array( $file ) && ( $file['fileId'] ?? '' ) === $file_id ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Session option name.
	 *
	 * @param string $session_id Session ID.
	 */
	private function optionName( string $session_id ): string {
		return self::OPTION_PREFIX . $session_id;
	}

	/**
	 * Random lease token.
	 */
	private function newToken(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( Throwable $exception ) {
			return wp_generate_uuid4();
		}
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
	 * Clear option caches after a direct write.
	 *
	 * @param string $name Option name.
	 */
	private function clearOptionCache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Missing or foreign session error.
	 */
	private function notFoundError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_session_not_found',
			__( 'Brasth Document Sync could not find this import.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Storage failure error.
	 */
	private function storageError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_session_storage_failed',
			__( 'Brasth Document Sync could not save this import. Try again.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 500 )
		);
	}
}
