<?php
/**
 * REST controller for Google OAuth.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Auth\GoogleOAuthService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Google OAuth REST endpoints.
 */
final class OAuthController {
	/**
	 * Google OAuth service.
	 *
	 * @var GoogleOAuthService
	 */
	private GoogleOAuthService $oauth;

	/**
	 * Constructor.
	 *
	 * @param GoogleOAuthService $oauth Google OAuth service.
	 */
	public function __construct( GoogleOAuthService $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/oauth/google/url',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getAuthorizationUrl' ),
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/oauth/google/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handleCallback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Permission callback for authenticated REST routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public function canUseAuthenticatedRest( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'You must be logged in to use DocSync WP.', 'docsync-wp' ),
				array( 'status' => 401 )
			);
		}

		$nonce = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce ) {
			$nonce = (string) $request->get_param( '_wpnonce' );
		}

		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'docsync_wp_rest_nonce_required',
				__( 'DocSync WP requires a valid REST nonce.', 'docsync-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Return a Google authorization URL.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function getAuthorizationUrl(): WP_REST_Response|WP_Error {
		$auth_url = $this->oauth->createAuthorizationUrl( get_current_user_id() );

		if ( is_wp_error( $auth_url ) ) {
			return $auth_url;
		}

		return rest_ensure_response(
			array(
				'authUrl' => esc_url_raw( $auth_url ),
			)
		);
	}

	/**
	 * Handle Google OAuth callback and redirect to the admin app.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handleCallback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$error = (string) $request->get_param( 'error' );
		$code  = '' === $error ? (string) $request->get_param( 'code' ) : '';

		$return_url = $this->oauth->handleCallback(
			(string) $request->get_param( 'state' ),
			$code
		);

		if ( is_wp_error( $return_url ) ) {
			return $return_url;
		}

		return new WP_REST_Response(
			null,
			302,
			array(
				'Location' => $return_url,
			)
		);
	}
}
