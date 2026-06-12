<?php
/**
 * Shared REST permission checks.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes common Brasth Document Sync REST authentication checks.
 */
final class RestPermissions {
	/**
	 * Permission callback for logged-in Brasth Document Sync REST users.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function canUseAuthenticatedRest( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'You must be logged in to use Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		return self::verifyRestNonce( $request );
	}

	/**
	 * Permission callback for site-level settings routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function canManageSettings( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to manage Brasth Document Sync settings.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return self::verifyRestNonce( $request );
	}

	/**
	 * Verify the normal WordPress REST nonce.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	private static function verifyRestNonce( WP_REST_Request $request ): bool|WP_Error {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'docsync_wp_rest_nonce_required',
				__( 'Brasth Document Sync requires a valid REST nonce.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
