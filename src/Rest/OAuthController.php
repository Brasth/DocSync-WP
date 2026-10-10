<?php
/**
 * REST controller for Google OAuth.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Auth\OAuthContinuationStore;
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
	private const CONTINUATION_PARAMS = array( 'scopeSet', 'returnTo', 'resumeKind', 'resumeId' );

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
	 * Expose the injected OAuth services so additive providers reuse the same instances.
	 *
	 * @return array{googleOAuth:GoogleOAuthService,tokenStore:TokenStore}
	 */
	public function getDependencies(): array {
		return array(
			'googleOAuth' => $this->oauth,
			'tokenStore'  => $this->token_store,
		);
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
	 * Without continuation parameters the legacy `{authUrl}` response is unchanged.
	 * With them, an owner-bound continuation is created and returned as well.
	 *
	 * @param WP_REST_Request|null $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getAuthorizationUrl( ?WP_REST_Request $request = null ): WP_REST_Response|WP_Error {
		if ( null !== $request && $this->hasContinuationParams( $request ) ) {
			return $this->getContinuationAuthorizationUrl( $request );
		}

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
					'connected'         => false,
					'hasRequiredScope'  => false,
					'requiredScope'     => GoogleOAuthService::DRIVE_READONLY_SCOPE,
					'hasDriveFileScope' => false,
					'driveFileScope'    => GoogleOAuthService::DRIVE_FILE_SCOPE,
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
				'hasDriveFileScope'  => GoogleOAuthService::hasDriveFileScope( (string) $token['scope'] ),
				'driveFileScope'     => GoogleOAuthService::DRIVE_FILE_SCOPE,
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
		$state = (string) $request->get_param( 'state' );
		$error = (string) $request->get_param( 'error' );

		if ( '' !== $error ) {
			$return_url = $this->oauth->buildFailureRedirect( $state, $error );

			return new WP_REST_Response(
				null,
				302,
				array(
					'Location' => $return_url,
				)
			);
		}

		$code       = (string) $request->get_param( 'code' );
		$return_url = $this->oauth->handleCallback( $state, $code );

		if ( is_wp_error( $return_url ) ) {
			$redirect = $this->oauth->buildFailureRedirect( $state, '', $return_url );

			return new WP_REST_Response(
				null,
				302,
				array(
					'Location' => $redirect,
				)
			);
		}

		return new WP_REST_Response(
			null,
			302,
			array(
				'Location' => $return_url,
			)
		);
	}

	/**
	 * Create an authorization URL with an allowlisted resume continuation.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function getContinuationAuthorizationUrl( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$unknown = array_filter(
			array_keys( $request->get_query_params() ),
			static function ( $key ): bool {
				$key = (string) $key;

				return 'rest_route' !== $key && ! str_starts_with( $key, '_' ) && ! in_array( $key, self::CONTINUATION_PARAMS, true );
			}
		);

		if ( array() !== $unknown ) {
			return $this->invalidContinuationParamsError();
		}

		$values = array();

		foreach ( self::CONTINUATION_PARAMS as $param ) {
			$value = $request->get_param( $param );

			if ( null !== $value && ! is_string( $value ) ) {
				return $this->invalidContinuationParamsError();
			}

			$values[ $param ] = null === $value ? '' : trim( $value );
		}

		if ( '' === $values['scopeSet'] ) {
			$values['scopeSet'] = OAuthContinuationStore::SCOPE_SET_READONLY;
		}

		if ( '' === $values['returnTo'] || ( '' === $values['resumeKind'] ) !== ( '' === $values['resumeId'] ) ) {
			return $this->invalidContinuationParamsError();
		}

		$result = $this->oauth->createContinuationAuthorization(
			get_current_user_id(),
			$values['scopeSet'],
			$values['returnTo'],
			$values['resumeKind'],
			$values['resumeId']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'authUrl'        => esc_url_raw( $result['authUrl'] ),
				'continuationId' => $result['continuationId'],
				'expiresAt'      => $result['expiresAt'],
			)
		);
	}

	/**
	 * Whether the request carries any continuation parameter.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function hasContinuationParams( WP_REST_Request $request ): bool {
		foreach ( self::CONTINUATION_PARAMS as $param ) {
			if ( $request->has_param( $param ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Invalid continuation parameters error.
	 */
	private function invalidContinuationParamsError(): WP_Error {
		return new WP_Error(
			'docsync_wp_oauth_invalid_continuation',
			__( 'Brasth Document Sync received an unsupported Google connection return target.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
