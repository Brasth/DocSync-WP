<?php
/**
 * Maps Google OAuth failures to stable admin error codes.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Auth;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes OAuth callback and token-exchange failures for the Setup UI.
 */
final class OAuthConnectError {
	public const QUERY_ARG = 'docsync_oauth_error';

	public const CODE_INVALID_CREDENTIALS = 'oauth_invalid_credentials';
	public const CODE_REDIRECT_MISMATCH   = 'oauth_redirect_mismatch';
	public const CODE_ACCESS_DENIED       = 'oauth_access_denied';
	public const CODE_INSUFFICIENT_SCOPE  = 'oauth_insufficient_scope';
	public const CODE_TOKEN_EXPIRED       = 'oauth_token_expired';
	public const CODE_NETWORK             = 'oauth_network';
	public const CODE_UNKNOWN             = 'oauth_unknown';

	/**
	 * Build an admin redirect URL carrying a connect error code.
	 *
	 * @param string   $return_url Validated admin return URL.
	 * @param string   $code       Stable error code for the React UI.
	 * @param string[] $extra      Optional extra query args (detail, field, etc.).
	 */
	public static function appendToReturnUrl( string $return_url, string $code, array $extra = array() ): string {
		$args = array_merge(
			array(
				self::QUERY_ARG => sanitize_key( $code ),
			),
			$extra
		);

		return add_query_arg( $args, $return_url );
	}

	/**
	 * Map a Google authorization error or plugin WP_Error to a stable code.
	 *
	 * @param string        $google_error Google `error` query param when present.
	 * @param WP_Error|null $plugin_error Plugin error from token exchange.
	 */
	public static function resolveCode( string $google_error, ?WP_Error $plugin_error = null ): string {
		$google_error = sanitize_key( $google_error );

		if ( 'access_denied' === $google_error ) {
			return self::CODE_ACCESS_DENIED;
		}

		if ( null !== $plugin_error ) {
			$data         = $plugin_error->get_error_data();
			$google_token = is_array( $data ) && isset( $data['google_error'] ) ? sanitize_key( (string) $data['google_error'] ) : '';

			if ( in_array( $google_token, array( 'invalid_client', 'unauthorized_client' ), true ) ) {
				return self::CODE_INVALID_CREDENTIALS;
			}

			if ( 'redirect_uri_mismatch' === $google_token ) {
				return self::CODE_REDIRECT_MISMATCH;
			}

			if ( 'invalid_scope' === $google_token ) {
				return self::CODE_INSUFFICIENT_SCOPE;
			}

			if ( in_array( $plugin_error->get_error_code(), array( 'docsync_wp_not_connected', 'docsync_wp_google_reconnect_required' ), true ) ) {
				return self::CODE_TOKEN_EXPIRED;
			}

			if ( 'docsync_wp_google_transient_failure' === $plugin_error->get_error_code() ) {
				return self::CODE_NETWORK;
			}
		}

		if ( '' !== $google_error ) {
			return self::CODE_UNKNOWN;
		}

		return self::CODE_UNKNOWN;
	}
}
