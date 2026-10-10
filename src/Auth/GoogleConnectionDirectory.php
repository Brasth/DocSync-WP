<?php
/**
 * Read-only directory of operator Google connection states.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Auth;

use DocSyncWP\Rest\RestPermissions;
use DocSyncWP\Security\EncryptionService;
use WP_Error;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Lists current-site operators and classifies stored Google connections.
 *
 * Counts and pages use RestPermissions capabilities, not role names. Token
 * state is local only: no Google requests, tokens, account emails, or raw errors.
 */
final class GoogleConnectionDirectory {
	public const STATE_CONNECTED          = 'connected';
	public const STATE_NOT_CONNECTED      = 'not_connected';
	public const STATE_RECONNECT_REQUIRED = 'reconnect_required';

	private const DEFAULT_USER_BATCH_SIZE          = 100;
	private const MAX_PER_PAGE                     = 50;
	private const ACCESS_TOKEN_EXPIRY_SKEW_SECONDS = 60;

	/**
	 * Encryption service.
	 *
	 * @var EncryptionService
	 */
	private EncryptionService $encryption;

	/**
	 * Users loaded per query and meta-cache batch.
	 *
	 * @var int
	 */
	private int $user_batch_size;

	/**
	 * Constructor.
	 *
	 * @param EncryptionService $encryption      Encryption service.
	 * @param int               $user_batch_size User query batch size.
	 */
	public function __construct( EncryptionService $encryption, int $user_batch_size = self::DEFAULT_USER_BATCH_SIZE ) {
		$this->encryption      = $encryption;
		$this->user_batch_size = max( 1, $user_batch_size );
	}

	/**
	 * Summarize every eligible operator and return one stable page.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Page size. Values above 50 are clamped.
	 * @return array{summary:array{connected:int,notConnected:int,reconnectRequired:int},users:array<int,array{userId:int,displayName:string,state:string}>,page:int,perPage:int,total:int}
	 */
	public function listConnections( int $page, int $per_page ): array {
		$page     = max( 1, $page );
		$per_page = min( self::MAX_PER_PAGE, max( 1, $per_page ) );
		$eligible = $this->eligibleOperators();
		$offset   = ( $page - 1 ) * $per_page;

		return array(
			'summary' => $this->summarize( $eligible ),
			'users'   => array_values( array_slice( $eligible, $offset, $per_page ) ),
			'page'    => $page,
			'perPage' => $per_page,
			'total'   => count( $eligible ),
		);
	}

	/**
	 * Eligible operators ordered by display name, then user ID.
	 *
	 * @return array<int,array{userId:int,displayName:string,state:string}>
	 */
	private function eligibleOperators(): array {
		$eligible    = array();
		$offset      = 0;
		$batch_count = $this->user_batch_size;

		while ( $batch_count === $this->user_batch_size ) {
			$query = new WP_User_Query(
				array(
					'blog_id'     => get_current_blog_id(),
					'number'      => $this->user_batch_size,
					'offset'      => $offset,
					'orderby'     => array(
						'display_name' => 'ASC',
						'ID'           => 'ASC',
					),
					'order'       => 'ASC',
					'count_total' => false,
					'fields'      => array( 'ID', 'display_name' ),
				)
			);
			$batch = $query->get_results();

			if ( ! is_array( $batch ) || array() === $batch ) {
				break;
			}

			$batch_count = count( $batch );
			$offset     += $batch_count;
			$this->collectEligible( $batch, $eligible );
		}

		usort(
			$eligible,
			static function ( array $left, array $right ): int {
				$name = strnatcasecmp( (string) $left['displayName'], (string) $right['displayName'] );

				if ( 0 !== $name ) {
					return $name;
				}

				return $left['userId'] <=> $right['userId'];
			}
		);

		return $eligible;
	}

	/**
	 * Keep capability-eligible users from one query batch.
	 *
	 * User meta is primed for the whole batch before capability checks so
	 * WordPress does not load capabilities one user at a time.
	 *
	 * @param array<int,object>                                            $batch    User rows.
	 * @param array<int,array{userId:int,displayName:string,state:string}> $eligible Collected operators.
	 */
	private function collectEligible( array $batch, array &$eligible ): void {
		$user_ids = array();

		foreach ( $batch as $user ) {
			$user_id = isset( $user->ID ) ? (int) $user->ID : 0;

			if ( $user_id > 0 ) {
				$user_ids[] = $user_id;
			}
		}

		if ( array() !== $user_ids ) {
			update_meta_cache( 'user', $user_ids );
		}

		foreach ( $batch as $user ) {
			$user_id = isset( $user->ID ) ? (int) $user->ID : 0;

			if ( $user_id <= 0 || ! RestPermissions::userCanUseDocSync( $user_id ) ) {
				continue;
			}

			$eligible[] = array(
				'userId'      => $user_id,
				'displayName' => sanitize_text_field( isset( $user->display_name ) ? (string) $user->display_name : '' ),
				'state'       => $this->connectionState( $user_id ),
			);
		}
	}

	/**
	 * Count connection states across every eligible operator.
	 *
	 * @param array<int,array{userId:int,displayName:string,state:string}> $eligible Eligible operators.
	 * @return array{connected:int,notConnected:int,reconnectRequired:int}
	 */
	private function summarize( array $eligible ): array {
		$summary = array(
			'connected'         => 0,
			'notConnected'      => 0,
			'reconnectRequired' => 0,
		);

		foreach ( $eligible as $operator ) {
			if ( self::STATE_CONNECTED === $operator['state'] ) {
				++$summary['connected'];
			} elseif ( self::STATE_NOT_CONNECTED === $operator['state'] ) {
				++$summary['notConnected'];
			} elseif ( self::STATE_RECONNECT_REQUIRED === $operator['state'] ) {
				++$summary['reconnectRequired'];
			}
		}

		return $summary;
	}

	/**
	 * Classify one stored connection without contacting Google.
	 *
	 * Expired access is still connected when a refresh token and the required
	 * scope are readable. Unreadable token material is reconnect-required.
	 *
	 * @param int $user_id User ID.
	 */
	private function connectionState( int $user_id ): string {
		$record = get_user_meta( $user_id, TokenStore::META_KEY, true );

		if ( $this->tokenRecordIsMissing( $record ) ) {
			return self::STATE_NOT_CONNECTED;
		}

		if ( ! is_array( $record ) || $this->tokenRecordIsMalformed( $record ) ) {
			return self::STATE_RECONNECT_REQUIRED;
		}

		$scope   = isset( $record['scope'] ) && is_string( $record['scope'] ) ? $record['scope'] : '';
		$refresh = $this->plaintextToken( $record, 'encrypted_refresh_token' );
		$access  = $this->plaintextToken( $record, 'encrypted_access_token' );

		if ( is_wp_error( $refresh ) || is_wp_error( $access ) ) {
			return self::STATE_RECONNECT_REQUIRED;
		}

		$has_required_scope = GoogleOAuthService::hasRequiredScope( $scope );
		$has_refresh        = '' !== $refresh;
		$access_is_current  = $this->accessTokenIsCurrent( $record, $access );

		if ( $has_required_scope && ( $has_refresh || $access_is_current ) ) {
			return self::STATE_CONNECTED;
		}

		if ( ! $has_required_scope && ! $has_refresh && '' === $access ) {
			return self::STATE_NOT_CONNECTED;
		}

		return self::STATE_RECONNECT_REQUIRED;
	}

	/**
	 * Whether stored meta is absent rather than a broken connection.
	 *
	 * @param mixed $record User meta value.
	 */
	private function tokenRecordIsMissing( mixed $record ): bool {
		return array() === $record || false === $record || null === $record || ( is_string( $record ) && '' === $record );
	}

	/**
	 * Whether a stored array is not a token record.
	 *
	 * Empty token strings are a real blank record. Any other nonempty shape is
	 * reconnect-required so a corrupted value is not reported as disconnected.
	 *
	 * @param array<mixed> $record Stored user meta.
	 */
	private function tokenRecordIsMalformed( array $record ): bool {
		foreach ( array( 'encrypted_refresh_token', 'encrypted_access_token' ) as $field ) {
			if ( ! array_key_exists( $field, $record ) || ! is_string( $record[ $field ] ) ) {
				return true;
			}
		}

		return array_key_exists( 'scope', $record ) && ! is_string( $record['scope'] );
	}

	/**
	 * Whether a decrypted access token is still inside the local freshness window.
	 *
	 * @param array<string,mixed> $record Stored token record.
	 * @param string              $access Decrypted access token.
	 */
	private function accessTokenIsCurrent( array $record, string $access ): bool {
		if ( '' === $access ) {
			return false;
		}

		$expires_at = isset( $record['expires_at'] ) ? absint( $record['expires_at'] ) : 0;

		return $expires_at > time() + self::ACCESS_TOKEN_EXPIRY_SKEW_SECONDS;
	}

	/**
	 * Decrypt one stored token field.
	 *
	 * @param array<string,mixed> $record Stored token record.
	 * @param string              $field  Encrypted field name.
	 * @return string|WP_Error
	 */
	private function plaintextToken( array $record, string $field ): string|WP_Error {
		$payload = $record[ $field ] ?? '';

		if ( ! is_string( $payload ) ) {
			return new WP_Error(
				'docsync_wp_token_unreadable',
				__( 'Brasth Document Sync could not read a stored Google connection.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $this->encryption->decrypt( $payload );
	}
}
