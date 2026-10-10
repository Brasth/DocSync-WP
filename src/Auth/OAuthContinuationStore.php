<?php
/**
 * Persistent, owner-bound Google OAuth continuations.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Auth;

use DocSyncWP\Settings\SettingsRepository;
use WP_Error;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stores where an OAuth round trip should resume once Google redirects back.
 *
 * Records live in user meta keyed by the SHA-256 of the continuation ID, are
 * bound to their owner and the OAuth configuration generation, expire after a
 * fixed TTL, and only ever resolve to an allowlisted plugin admin page.
 */
final class OAuthContinuationStore {
	public const META_KEY     = '_docsync_wp_oauth_continuations';
	public const TTL_SECONDS  = HOUR_IN_SECONDS;
	public const MAX_PER_USER = 5;

	public const SCOPE_SET_READONLY   = 'readonly';
	public const SCOPE_SET_DRIVE_FILE = 'driveFile';

	public const RESUME_KIND_IMPORT   = 'import';
	public const RESUME_KIND_MATCHING = 'matching';

	/**
	 * Allowlisted return targets mapped to plugin admin page slugs.
	 */
	private const RETURN_PAGES = array(
		'sources' => 'brasth-document-sync-for-google-docs-sources',
		'setup'   => 'brasth-document-sync-for-google-docs',
	);

	/**
	 * Option names that index resumable records by owner (contracts storage section).
	 */
	private const RESUME_INDEX_OPTIONS = array(
		self::RESUME_KIND_IMPORT   => 'docsync_wp_import_session_index',
		self::RESUME_KIND_MATCHING => 'docsync_wp_match_job_index',
	);

	private const UUID_PATTERN     = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/';
	private const CONTINUATION_ID  = '/^[a-f0-9]{48}$/';
	private const UTC_TIMESTAMP    = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
	private const PURGE_BATCH_SIZE = 200;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create a continuation for the current OAuth round trip.
	 *
	 * @param int    $user_id     Owner user ID.
	 * @param string $scope_set   `readonly` or `driveFile`.
	 * @param string $return_to   `sources` or `setup`.
	 * @param string $resume_kind `import`, `matching`, or empty.
	 * @param string $resume_id   Session or job ID owned by the user, or empty.
	 * @return array{continuationId:string,expiresAt:string}|WP_Error
	 */
	public function create( int $user_id, string $scope_set, string $return_to, string $resume_kind, string $resume_id ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'You must be logged in before connecting Google Drive.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		$valid = $this->validateInput( $user_id, $scope_set, $return_to, $resume_kind, $resume_id );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		try {
			$continuation_id = bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'docsync_wp_oauth_state_failed',
				__( 'Brasth Document Sync could not create secure Google OAuth state.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$now        = time();
		$expires_at = $now + self::TTL_SECONDS;
		$records    = $this->withoutExpired( $this->readRecords( $user_id ), $now );

		$records[ $this->recordKey( $continuation_id ) ] = array(
			'scopeSet'   => $scope_set,
			'returnTo'   => $return_to,
			'resumeKind' => $resume_kind,
			'resumeId'   => $resume_id,
			'generation' => $this->settings->getOAuthConfigurationGeneration(),
			'createdAt'  => $now,
			'expiresAt'  => $expires_at,
		);

		$records = $this->newestRecords( $records );

		if ( ! $this->writeRecords( $user_id, $records ) ) {
			return new WP_Error(
				'docsync_wp_oauth_continuation_failed',
				__( 'Brasth Document Sync could not save where to resume after connecting Google Drive.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'continuationId' => $continuation_id,
			'expiresAt'      => gmdate( 'Y-m-d\TH:i:s\Z', $expires_at ),
		);
	}

	/**
	 * Consume a continuation exactly once.
	 *
	 * The record is removed before validation, so expired, foreign, and
	 * stale-generation continuations can never be replayed. When the removal
	 * cannot be persisted the continuation is refused, and a resumable session
	 * or job is checked again so a deleted, expired, or transferred target
	 * never resumes.
	 *
	 * @param string $continuation_id Raw continuation ID.
	 * @param int    $user_id         User whose OAuth callback is completing.
	 * @return array{returnUrl:string,scopeSet:string,resumeKind:string,resumeId:string}|WP_Error
	 */
	public function consume( string $continuation_id, int $user_id ): array|WP_Error {
		if ( $user_id <= 0 || 1 !== preg_match( self::CONTINUATION_ID, $continuation_id ) ) {
			return $this->invalidContinuationError();
		}

		$now     = time();
		$records = $this->readRecords( $user_id );
		$key     = $this->recordKey( $continuation_id );
		$record  = $records[ $key ] ?? null;

		unset( $records[ $key ] );

		if ( ! $this->writeRecords( $user_id, $this->withoutExpired( $records, $now ) ) ) {
			return $this->invalidContinuationError();
		}

		if (
			! is_array( $record )
			|| ! isset( $record['generation'] )
			|| absint( $record['expiresAt'] ?? 0 ) <= $now
			|| absint( $record['generation'] ) !== $this->settings->getOAuthConfigurationGeneration()
		) {
			return $this->invalidContinuationError();
		}

		$return_to   = (string) ( $record['returnTo'] ?? '' );
		$scope_set   = (string) ( $record['scopeSet'] ?? '' );
		$resume_kind = (string) ( $record['resumeKind'] ?? '' );
		$resume_id   = (string) ( $record['resumeId'] ?? '' );

		if ( ! $this->isValidShape( $scope_set, $return_to, $resume_kind, $resume_id ) ) {
			return $this->invalidContinuationError();
		}

		if ( '' !== $resume_kind && ! $this->userOwnsResumeTarget( $user_id, $resume_kind, $resume_id ) ) {
			return $this->invalidContinuationError();
		}

		$return_url = $this->buildReturnUrl( $return_to, $resume_kind, $resume_id );

		if ( '' === $return_url ) {
			return $this->invalidContinuationError();
		}

		return array(
			'returnUrl'  => $return_url,
			'scopeSet'   => $scope_set,
			'resumeKind' => $resume_kind,
			'resumeId'   => $resume_id,
		);
	}

	/**
	 * Remove expired continuations for every user.
	 *
	 * @return int Number of removed continuation records.
	 */
	public function purgeExpired(): int {
		$removed = 0;
		$offset  = 0;
		$now     = time();

		do {
			$query      = new WP_User_Query(
				array(
					'blog_id'     => 0,
					'number'      => self::PURGE_BATCH_SIZE,
					'offset'      => $offset,
					'orderby'     => 'ID',
					'order'       => 'ASC',
					'count_total' => false,
					'fields'      => 'ID',
				)
			);
			$user_ids   = array_map( 'absint', (array) $query->get_results() );
			$batch_size = count( $user_ids );
			$offset    += $batch_size;

			if ( 0 === $batch_size ) {
				break;
			}

			update_meta_cache( 'user', $user_ids );

			foreach ( $user_ids as $user_id ) {
				$records = $this->readRecords( $user_id );

				if ( array() === $records ) {
					continue;
				}

				$kept     = $this->withoutExpired( $records, $now );
				$removed += count( $records ) - count( $kept );

				if ( count( $kept ) !== count( $records ) ) {
					$this->writeRecords( $user_id, $kept );
				}
			}
		} while ( self::PURGE_BATCH_SIZE === $batch_size );

		return $removed;
	}

	/**
	 * Delete every continuation owned by one user.
	 *
	 * @param int $user_id User ID.
	 */
	public function deleteForUser( int $user_id ): void {
		if ( $user_id > 0 ) {
			delete_user_meta( $user_id, self::META_KEY );
		}
	}

	/**
	 * Validate continuation input, including ownership of the resumable record.
	 *
	 * @param int    $user_id     Owner user ID.
	 * @param string $scope_set   Scope set.
	 * @param string $return_to   Return target.
	 * @param string $resume_kind Resume kind.
	 * @param string $resume_id   Resume ID.
	 * @return bool|WP_Error
	 */
	private function validateInput( int $user_id, string $scope_set, string $return_to, string $resume_kind, string $resume_id ): bool|WP_Error {
		if ( ! $this->isValidShape( $scope_set, $return_to, $resume_kind, $resume_id ) ) {
			return new WP_Error(
				'docsync_wp_oauth_invalid_continuation',
				__( 'Brasth Document Sync received an unsupported Google connection return target.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( '' !== $resume_kind && ! $this->userOwnsResumeTarget( $user_id, $resume_kind, $resume_id ) ) {
			return new WP_Error(
				'docsync_wp_oauth_resume_not_found',
				__( 'Brasth Document Sync could not find the work to resume after connecting Google Drive.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Whether continuation fields match the allowlists.
	 *
	 * @param string $scope_set   Scope set.
	 * @param string $return_to   Return target.
	 * @param string $resume_kind Resume kind.
	 * @param string $resume_id   Resume ID.
	 */
	private function isValidShape( string $scope_set, string $return_to, string $resume_kind, string $resume_id ): bool {
		if ( ! in_array( $scope_set, array( self::SCOPE_SET_READONLY, self::SCOPE_SET_DRIVE_FILE ), true ) ) {
			return false;
		}

		if ( ! isset( self::RETURN_PAGES[ $return_to ] ) ) {
			return false;
		}

		if ( '' === $resume_kind ) {
			return '' === $resume_id;
		}

		return isset( self::RESUME_INDEX_OPTIONS[ $resume_kind ] )
			&& 1 === preg_match( self::UUID_PATTERN, $resume_id );
	}

	/**
	 * Whether the resumable session or job exists, is unexpired, and belongs to the user.
	 *
	 * An index entry without a well-formed expiry is treated as absent.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $resume_kind Resume kind.
	 * @param string $resume_id   Session or job ID.
	 */
	private function userOwnsResumeTarget( int $user_id, string $resume_kind, string $resume_id ): bool {
		if ( $user_id <= 0 || ! isset( self::RESUME_INDEX_OPTIONS[ $resume_kind ] ) ) {
			return false;
		}

		$index = get_option( self::RESUME_INDEX_OPTIONS[ $resume_kind ], array() );
		$entry = is_array( $index ) && isset( $index[ $resume_id ] ) && is_array( $index[ $resume_id ] ) ? $index[ $resume_id ] : null;

		if ( null === $entry || ! empty( $entry['cleanupPending'] ) ) {
			return false;
		}

		$owner = $entry['ownerUserId'] ?? null;

		if ( ! ( is_int( $owner ) || ( is_string( $owner ) && ctype_digit( $owner ) ) ) || (int) $owner !== $user_id ) {
			return false;
		}

		$expires_at = $this->parseExpiry( $entry['expiresAt'] ?? null );

		return null !== $expires_at && $expires_at > time();
	}

	/**
	 * Parse an index expiry: a positive Unix timestamp or a `Y-m-d\TH:i:s\Z` UTC string.
	 *
	 * @param mixed $value Raw expiry.
	 * @return int|null Null when missing or malformed.
	 */
	private function parseExpiry( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( self::UTC_TIMESTAMP, $value ) ) {
			return null;
		}

		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone( 'UTC' ) );

		if ( false === $parsed || $parsed->format( 'Y-m-d\TH:i:s\Z' ) !== $value ) {
			return null;
		}

		return $parsed->getTimestamp();
	}

	/**
	 * Build the allowlisted same-site admin return URL.
	 *
	 * @param string $return_to   Return target.
	 * @param string $resume_kind Resume kind.
	 * @param string $resume_id   Resume ID.
	 * @return string Empty when the URL fails validation.
	 */
	private function buildReturnUrl( string $return_to, string $resume_kind, string $resume_id ): string {
		$admin_base = admin_url();
		$args       = array( 'page' => self::RETURN_PAGES[ $return_to ] );

		if ( '' !== $resume_kind ) {
			$args['docsync_resume']    = $resume_kind;
			$args['docsync_resume_id'] = $resume_id;
		}

		$url       = add_query_arg( $args, admin_url( 'admin.php' ) );
		$validated = wp_validate_redirect( $url, '' );

		if ( '' === $validated || ! str_starts_with( $validated, $admin_base ) ) {
			return '';
		}

		return $validated;
	}

	/**
	 * Read sanitized continuation records for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,array<string,mixed>>
	 */
	private function readRecords( int $user_id ): array {
		$records = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $records ) ) {
			return array();
		}

		$valid = array();

		foreach ( $records as $key => $record ) {
			if ( is_string( $key ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $key ) && is_array( $record ) ) {
				$valid[ $key ] = $record;
			}
		}

		return $valid;
	}

	/**
	 * Persist continuation records, deleting the meta when none remain.
	 *
	 * Success is confirmed by reading the stored value back without the
	 * request cache, because the meta APIs report "unchanged" and "failed"
	 * the same way.
	 *
	 * @param int                               $user_id User ID.
	 * @param array<string,array<string,mixed>> $records Records.
	 */
	private function writeRecords( int $user_id, array $records ): bool {
		if ( array() === $records ) {
			delete_user_meta( $user_id, self::META_KEY );
		} elseif ( get_user_meta( $user_id, self::META_KEY, true ) !== $records ) {
			update_user_meta( $user_id, self::META_KEY, $records );
		}

		wp_cache_delete( $user_id, 'user_meta' );

		$stored = get_user_meta( $user_id, self::META_KEY, true );

		return array() === $records ? '' === $stored : $stored === $records;
	}

	/**
	 * Drop expired records.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @param int                               $now     Current timestamp.
	 * @return array<string,array<string,mixed>>
	 */
	private function withoutExpired( array $records, int $now ): array {
		return array_filter(
			$records,
			static function ( array $record ) use ( $now ): bool {
				return absint( $record['expiresAt'] ?? 0 ) > $now;
			}
		);
	}

	/**
	 * Keep only the newest MAX_PER_USER records.
	 *
	 * @param array<string,array<string,mixed>> $records Records.
	 * @return array<string,array<string,mixed>>
	 */
	private function newestRecords( array $records ): array {
		uasort(
			$records,
			static function ( array $first, array $second ): int {
				return absint( $second['createdAt'] ?? 0 ) <=> absint( $first['createdAt'] ?? 0 );
			}
		);

		return array_slice( $records, 0, self::MAX_PER_USER, true );
	}

	/**
	 * Storage key for a raw continuation ID.
	 *
	 * @param string $continuation_id Raw continuation ID.
	 */
	private function recordKey( string $continuation_id ): string {
		return hash( 'sha256', $continuation_id );
	}

	/**
	 * Invalid continuation error.
	 */
	private function invalidContinuationError(): WP_Error {
		return new WP_Error(
			'docsync_wp_oauth_continuation_invalid',
			__( 'The Google connection return link expired or belongs to another session.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
