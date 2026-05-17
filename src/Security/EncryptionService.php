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
	private const VERSION = 1;

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
				__( 'DocSync WP cannot store sensitive values because OpenSSL encryption is unavailable.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		try {
			$iv = random_bytes( 12 );
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'docsync_wp_encryption_iv_failed',
				__( 'DocSync WP could not generate secure encryption data.', 'docsync-wp' ),
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
				__( 'DocSync WP could not encrypt the sensitive value.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$payload = wp_json_encode(
			array(
				'v'      => self::VERSION,
				'cipher' => self::CIPHER,
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary encryption fields must be encoded for JSON storage.
				'iv'     => base64_encode( $iv ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary encryption fields must be encoded for JSON storage.
				'tag'    => base64_encode( $tag ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary encryption fields must be encoded for JSON storage.
				'data'   => base64_encode( $ciphertext ),
			)
		);

		if ( false === $payload ) {
			return new WP_Error(
				'docsync_wp_encryption_payload_failed',
				__( 'DocSync WP could not prepare encrypted data for storage.', 'docsync-wp' ),
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
				__( 'DocSync WP cannot read sensitive values because OpenSSL encryption is unavailable.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || ! $this->isValidPayload( $decoded ) ) {
			return new WP_Error(
				'docsync_wp_decryption_payload_invalid',
				__( 'DocSync WP could not read the encrypted value payload.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary encryption fields are encoded for JSON storage.
		$iv = base64_decode( $decoded['iv'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary encryption fields are encoded for JSON storage.
		$tag = base64_decode( $decoded['tag'], true );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary encryption fields are encoded for JSON storage.
		$ciphertext = base64_decode( $decoded['data'], true );

		if ( false === $iv || false === $tag || false === $ciphertext ) {
			return new WP_Error(
				'docsync_wp_decryption_payload_invalid',
				__( 'DocSync WP could not decode the encrypted value payload.', 'docsync-wp' ),
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
				__( 'DocSync WP could not decrypt the sensitive value.', 'docsync-wp' ),
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
			&& self::VERSION === $payload['v']
			&& self::CIPHER === $payload['cipher']
			&& is_string( $payload['iv'] )
			&& is_string( $payload['tag'] )
			&& is_string( $payload['data'] );
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
