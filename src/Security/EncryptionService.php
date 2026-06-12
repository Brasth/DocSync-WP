<?php
/**
 * Encryption utilities for sensitive plugin values.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Security;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts and decrypts sensitive values using WordPress salts.
 */
final class EncryptionService {
	private const CIPHER  = 'aes-256-gcm';
	private const VERSION = 2;

	/**
	 * Whether the runtime can encrypt values.
	 */
	public function isAvailable(): bool {
		return extension_loaded( 'openssl' )
			&& function_exists( 'openssl_encrypt' )
			&& function_exists( 'openssl_decrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true )
			&& '' !== $this->getKeyMaterial();
	}

	/**
	 * Encrypt plaintext.
	 *
	 * @param string $plaintext Plaintext value.
	 * @return string|WP_Error
	 */
	public function encrypt( string $plaintext ): string|WP_Error {
		if ( '' === $plaintext ) {
			return '';
		}

		if ( ! $this->isAvailable() ) {
			return new WP_Error(
				'docsync_wp_encryption_unavailable',
				__( 'Brasth Document Sync cannot store sensitive values because OpenSSL encryption is unavailable.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'docsync_wp_encryption_iv_failed',
				__( 'Brasth Document Sync could not generate secure encryption data.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER,
			$this->getKey(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			16
		);

		if ( false === $ciphertext || '' === $tag ) {
			return new WP_Error(
				'docsync_wp_encryption_failed',
				__( 'Brasth Document Sync could not encrypt the sensitive value.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$payload = wp_json_encode(
			array(
				'v'      => self::VERSION,
				'cipher' => self::CIPHER,
				'iv'     => $this->encodeBinary( $iv ),
				'tag'    => $this->encodeBinary( $tag ),
				'data'   => $this->encodeBinary( $ciphertext ),
			)
		);

		if ( false === $payload ) {
			return new WP_Error(
				'docsync_wp_encryption_payload_failed',
				__( 'Brasth Document Sync could not prepare encrypted data for storage.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $payload;
	}

	/**
	 * Decrypt an encrypted payload.
	 *
	 * @param string $payload Stored encrypted payload.
	 * @return string|WP_Error
	 */
	public function decrypt( string $payload ): string|WP_Error {
		if ( '' === $payload ) {
			return '';
		}

		if ( ! $this->isAvailable() ) {
			return new WP_Error(
				'docsync_wp_decryption_unavailable',
				__( 'Brasth Document Sync cannot read sensitive values because OpenSSL encryption is unavailable.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || ! $this->isValidPayload( $decoded ) ) {
			return new WP_Error(
				'docsync_wp_decryption_payload_invalid',
				__( 'Brasth Document Sync could not read the encrypted value payload.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$version    = absint( $decoded['v'] );
		$iv         = $this->decodeBinary( $decoded['iv'], $version );
		$tag        = $this->decodeBinary( $decoded['tag'], $version );
		$ciphertext = $this->decodeBinary( $decoded['data'], $version );

		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return new WP_Error(
				'docsync_wp_decryption_payload_invalid',
				__( 'Brasth Document Sync could not decode the encrypted value payload.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$this->getKey(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			return new WP_Error(
				'docsync_wp_decryption_failed',
				__( 'Brasth Document Sync could not decrypt the sensitive value.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $plaintext;
	}

	/**
	 * Validate a decoded encrypted payload.
	 *
	 * @param array<mixed> $payload Decoded payload.
	 */
	private function isValidPayload( array $payload ): bool {
		return isset( $payload['v'], $payload['cipher'], $payload['iv'], $payload['tag'], $payload['data'] )
			&& in_array( $payload['v'], array( 1, self::VERSION ), true )
			&& self::CIPHER === $payload['cipher']
			&& is_string( $payload['iv'] )
			&& is_string( $payload['tag'] )
			&& is_string( $payload['data'] );
	}

	/**
	 * Encode binary data for JSON storage.
	 *
	 * @param string $value Binary value.
	 */
	private function encodeBinary( string $value ): string {
		return bin2hex( $value );
	}

	/**
	 * Decode binary data from JSON storage.
	 *
	 * @param string $value Encoded binary value.
	 * @param int    $version Payload schema version.
	 * @return string|false
	 */
	private function decodeBinary( string $value, int $version ): string|false {
		if ( 1 === $version ) {
			return $this->decodeLegacyBinary( $value );
		}

		return hex2bin( $value );
	}

	/**
	 * Decode legacy binary fields that were stored before hex encoding.
	 *
	 * @param string $value Legacy encoded value.
	 * @return string|false
	 */
	private function decodeLegacyBinary( string $value ): string|false {
		if ( ! function_exists( 'sodium_base642bin' ) || ! defined( 'SODIUM_BASE64_VARIANT_ORIGINAL' ) ) {
			return false;
		}

		try {
			return sodium_base642bin( $value, SODIUM_BASE64_VARIANT_ORIGINAL );
		} catch ( Throwable $exception ) {
			return false;
		}
	}

	/**
	 * Get the binary encryption key.
	 */
	private function getKey(): string {
		return hash( 'sha256', $this->getKeyMaterial(), true );
	}

	/**
	 * Get stable key material from WordPress salts.
	 */
	private function getKeyMaterial(): string {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'auth' )
				. wp_salt( 'secure_auth' )
				. wp_salt( 'logged_in' )
				. wp_salt( 'nonce' );
		}

		$constants = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		$material = '';

		foreach ( $constants as $constant ) {
			if ( defined( $constant ) ) {
				$material .= (string) constant( $constant );
			}
		}

		return $material;
	}
}
