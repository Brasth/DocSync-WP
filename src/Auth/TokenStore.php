<?php
/**
 * Per-user Google token storage.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Auth;

use DocSyncWP\Security\EncryptionService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Stores encrypted Google OAuth tokens in user meta.
 */
final class TokenStore {
	public const META_KEY = '_docsync_wp_google_token';

	/**
	 * Encryption service.
	 *
	 * @var EncryptionService
	 */
	private EncryptionService $encryption;

	/**
	 * Constructor.
	 *
	 * @param EncryptionService $encryption Encryption service.
	 */
	public function __construct( EncryptionService $encryption ) {
		$this->encryption = $encryption;
	}

	/**
	 * Get a user's token record with decrypted tokens.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|null|WP_Error
	 */
	public function get( int $user_id ): array|null|WP_Error {
		if ( $user_id <= 0 ) {
			return null;
		}

		$record = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $record ) || array() === $record ) {
			return null;
		}

		$refresh_token = $this->encryption->decrypt( isset( $record['encrypted_refresh_token'] ) && is_string( $record['encrypted_refresh_token'] ) ? $record['encrypted_refresh_token'] : '' );

		if ( is_wp_error( $refresh_token ) ) {
			return $refresh_token;
		}

		$access_token = $this->encryption->decrypt( isset( $record['encrypted_access_token'] ) && is_string( $record['encrypted_access_token'] ) ? $record['encrypted_access_token'] : '' );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		return array(
			'refresh_token'        => $refresh_token,
			'access_token'         => $access_token,
			'expires_at'           => isset( $record['expires_at'] ) ? absint( $record['expires_at'] ) : 0,
			'scope'                => isset( $record['scope'] ) ? sanitize_text_field( (string) $record['scope'] ) : '',
			'google_account_email' => isset( $record['google_account_email'] ) ? sanitize_email( (string) $record['google_account_email'] ) : '',
			'connected_at'         => isset( $record['connected_at'] ) ? sanitize_text_field( (string) $record['connected_at'] ) : '',
		);
	}

	/**
	 * Save a user's token record, encrypting sensitive token values.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $token   Token data.
	 * @return bool|WP_Error
	 */
	public function save( int $user_id, array $token ): bool|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'docsync_wp_invalid_user',
				__( 'Brasth Document Sync cannot store Google tokens for an invalid user.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$existing = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$record = array(
			'encrypted_refresh_token' => isset( $existing['encrypted_refresh_token'] ) && is_string( $existing['encrypted_refresh_token'] ) ? $existing['encrypted_refresh_token'] : '',
			'encrypted_access_token'  => isset( $existing['encrypted_access_token'] ) && is_string( $existing['encrypted_access_token'] ) ? $existing['encrypted_access_token'] : '',
			'expires_at'              => isset( $token['expires_at'] ) ? absint( $token['expires_at'] ) : 0,
			'scope'                   => isset( $token['scope'] ) ? sanitize_text_field( (string) $token['scope'] ) : '',
			'google_account_email'    => isset( $token['google_account_email'] ) ? sanitize_email( (string) $token['google_account_email'] ) : '',
			'connected_at'            => isset( $token['connected_at'] ) ? sanitize_text_field( (string) $token['connected_at'] ) : current_time( 'mysql', true ),
		);

		if ( array_key_exists( 'refresh_token', $token ) ) {
			$encrypted_refresh_token = $this->encryption->encrypt( sanitize_text_field( (string) $token['refresh_token'] ) );

			if ( is_wp_error( $encrypted_refresh_token ) ) {
				return $encrypted_refresh_token;
			}

			$record['encrypted_refresh_token'] = $encrypted_refresh_token;
		}

		if ( array_key_exists( 'access_token', $token ) ) {
			$encrypted_access_token = $this->encryption->encrypt( sanitize_text_field( (string) $token['access_token'] ) );

			if ( is_wp_error( $encrypted_access_token ) ) {
				return $encrypted_access_token;
			}

			$record['encrypted_access_token'] = $encrypted_access_token;
		}

		return update_user_meta( $user_id, self::META_KEY, $record ) !== false;
	}

	/**
	 * Delete a user's token record.
	 *
	 * @param int $user_id User ID.
	 */
	public function delete( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		return delete_user_meta( $user_id, self::META_KEY );
	}

	/**
	 * Whether a user has a stored token record.
	 *
	 * @param int $user_id User ID.
	 */
	public function hasToken( int $user_id ): bool {
		$record = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $record ) && array() !== $record;
	}
}
