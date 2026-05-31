<?php
/**
 * REST controller for Google OAuth.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Auth\TokenStore;
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
	 * Token store.
	 *
	 * @var TokenStore
	 */
	private TokenStore $token_store;

	/**
	 * Constructor.
	 *
	 * @param GoogleOAuthService $oauth       Google OAuth service.
	 * @param TokenStore         $token_store Token store.
	 */
	public function __construct( GoogleOAuthService $oauth, TokenStore $token_store ) {
		$this->oauth       = $oauth;
		$this->token_store = $token_store;
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
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
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

		register_rest_route(
			$rest_namespace,
			'/oauth/google/account',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getAccount' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'disconnectAccount' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
			)
		);
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
	 * Get the current user's Google account connection status.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function getAccount(): WP_REST_Response|WP_Error {
		$token = $this->token_store->get( get_current_user_id() );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		if ( null === $token ) {
			return rest_ensure_response(
				array(
					'connected'        => false,
					'hasRequiredScope' => false,
					'requiredScope'    => GoogleOAuthService::DRIVE_READONLY_SCOPE,
				)
			);
		}

		$has_required_scope = GoogleOAuthService::hasRequiredScope( (string) $token['scope'] );

		return rest_ensure_response(
			array(
				'connected'          => true,
				'googleAccountEmail' => $token['google_account_email'],
				'scope'              => $token['scope'],
				'connectedAt'        => $token['connected_at'],
				'expiresAt'          => $token['expires_at'],
				'hasRequiredScope'   => $has_required_scope,
				'requiredScope'      => GoogleOAuthService::DRIVE_READONLY_SCOPE,
			)
		);
	}

	/**
	 * Disconnect the current user's Google account.
	 */
	public function disconnectAccount(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'disconnected' => $this->token_store->delete( get_current_user_id() ),
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
