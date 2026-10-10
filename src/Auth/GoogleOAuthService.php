<?php
/**
 * Google OAuth flow and token refresh.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Auth;

use DocSyncWP\Rest\RestServiceProvider;
use DocSyncWP\Settings\SettingsRepository;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Google authorization URLs, callbacks, and access token refreshes.
 */
final class GoogleOAuthService {
	public const DRIVE_READONLY_SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
	public const DRIVE_FILE_SCOPE     = 'https://www.googleapis.com/auth/drive.file';
	public const CONTINUATION_ACTION  = 'docsync_wp_oauth_continuation_consumed';

	private const AUTH_ENDPOINT           = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const TOKEN_ENDPOINT          = 'https://oauth2.googleapis.com/token';
	private const STATE_TRANSIENT_PREFIX  = 'docsync_wp_oauth_state_';
	private const STATE_TTL_SECONDS       = 600;
	private const TOKEN_EXPIRY_SKEW       = 60;
	private const REQUEST_TIMEOUT_SECONDS = 15;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Token store.
	 *
	 * @var TokenStore
	 */
	private TokenStore $token_store;

	/**
	 * OAuth continuation store.
	 *
	 * @var OAuthContinuationStore
	 */
	private OAuthContinuationStore $continuations;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository          $settings      Settings repository.
	 * @param TokenStore                  $token_store   Token store.
	 * @param OAuthContinuationStore|null $continuations Optional continuation store; defaults to one backed by the same settings.
	 */
	public function __construct( SettingsRepository $settings, TokenStore $token_store, ?OAuthContinuationStore $continuations = null ) {
		$this->settings      = $settings;
		$this->token_store   = $token_store;
		$this->continuations = $continuations ?? new OAuthContinuationStore( $settings );
	}

	/**
	 * Create a Google authorization URL and persist short-lived state.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error
	 */
	public function createAuthorizationUrl( int $user_id ): string|WP_Error {
		return $this->buildAuthorizationUrl( $user_id, self::DRIVE_READONLY_SCOPE, '' );
	}

	/**
	 * Create a Google authorization URL that resumes an allowlisted admin screen.
	 *
	 * The readonly scope stays the baseline. The `driveFile` scope set adds
	 * `drive.file` incrementally and is only requested through this opt-in.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $scope_set   `readonly` or `driveFile`.
	 * @param string $return_to   `sources` or `setup`.
	 * @param string $resume_kind `import`, `matching`, or empty.
	 * @param string $resume_id   Session or job ID owned by the user, or empty.
	 * @return array{authUrl:string,continuationId:string,expiresAt:string}|WP_Error
	 */
	public function createContinuationAuthorization( int $user_id, string $scope_set, string $return_to, string $resume_kind, string $resume_id ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return $this->notLoggedInError();
		}

		$credentials = $this->getCredentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$continuation = $this->continuations->create( $user_id, $scope_set, $return_to, $resume_kind, $resume_id );

		if ( is_wp_error( $continuation ) ) {
			return $continuation;
		}

		$scope = OAuthContinuationStore::SCOPE_SET_DRIVE_FILE === $scope_set
			? self::DRIVE_READONLY_SCOPE . ' ' . self::DRIVE_FILE_SCOPE
			: self::DRIVE_READONLY_SCOPE;

		$auth_url = $this->buildAuthorizationUrl( $user_id, $scope, $continuation['continuationId'] );

		if ( is_wp_error( $auth_url ) ) {
			$this->continuations->consume( $continuation['continuationId'], $user_id );

			return $auth_url;
		}

		return array(
			'authUrl'        => $auth_url,
			'continuationId' => $continuation['continuationId'],
			'expiresAt'      => $continuation['expiresAt'],
		);
	}

	/**
	 * Whether an OAuth scope string includes the optional Drive file scope.
	 *
	 * @param string $scope OAuth scope string from Google.
	 */
	public static function hasDriveFileScope( string $scope ): bool {
		$scopes = preg_split( '/\s+/', trim( $scope ) );

		if ( ! is_array( $scopes ) ) {
			return false;
		}

		return in_array( self::DRIVE_FILE_SCOPE, $scopes, true );
	}

	/**
	 * Whether a user's stored Google token includes the Drive file scope.
	 *
	 * @param int $user_id User ID.
	 */
	public function userHasDriveFileScope( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$token = $this->token_store->get( $user_id );

		if ( ! is_array( $token ) ) {
			return false;
		}

		return self::hasDriveFileScope( (string) ( $token['scope'] ?? '' ) );
	}

	/**
	 * Build a Google authorization URL and persist short-lived state.
	 *
	 * @param int    $user_id         User ID.
	 * @param string $scope           Space-separated scopes to request.
	 * @param string $continuation_id Optional continuation ID carried in server-side state.
	 * @return string|WP_Error
	 */
	private function buildAuthorizationUrl( int $user_id, string $scope, string $continuation_id ): string|WP_Error {
		if ( $user_id <= 0 ) {
			return $this->notLoggedInError();
		}

		$credentials = $this->getCredentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$state = $this->generateState();

		if ( is_wp_error( $state ) ) {
			return $state;
		}

		$state_data = array(
			'user_id'                        => $user_id,
			'return_url'                     => admin_url( 'admin.php?page=brasth-document-sync-for-google-docs' ),
			'created_at'                     => time(),
			'oauth_configuration_generation' => $this->settings->getOAuthConfigurationGeneration(),
		);

		if ( '' !== $continuation_id ) {
			$state_data['continuation_id'] = $continuation_id;
		}

		set_transient( $this->stateTransientKey( $state ), $state_data, self::STATE_TTL_SECONDS );

		return add_query_arg(
			array(
				'client_id'              => $credentials['client_id'],
				'redirect_uri'           => $this->getRedirectUri(),
				'response_type'          => 'code',
				'scope'                  => $scope,
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
			),
			self::AUTH_ENDPOINT
		);
	}

	/**
	 * Exchange an OAuth callback code for stored user tokens.
	 *
	 * @param string $state OAuth state.
	 * @param string $code  Authorization code.
	 * @return string|WP_Error Admin return URL.
	 */
	public function handleCallback( string $state, string $code ): string|WP_Error {
		$state_data = $this->consumeState( $state );

		if ( is_wp_error( $state_data ) ) {
			return $state_data;
		}

		if ( $this->settings->getOAuthConfigurationGeneration() !== $state_data['oauth_configuration_generation'] ) {
			return $this->invalidStateError();
		}

		$code = sanitize_text_field( $code );

		if ( '' === $code ) {
			return $this->withContinuationFailure(
				new WP_Error(
					'docsync_wp_bad_google_response',
					__( 'Google did not return an authorization code.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 400 )
				),
				$state_data
			);
		}

		$credentials = $this->getCredentials();

		if ( is_wp_error( $credentials ) ) {
			return $this->withContinuationFailure( $credentials, $state_data );
		}

		$token = $this->requestToken(
			array(
				'client_id'     => $credentials['client_id'],
				'client_secret' => $credentials['client_secret'],
				'code'          => $code,
				'grant_type'    => 'authorization_code',
				'redirect_uri'  => $this->getRedirectUri(),
			)
		);

		if ( is_wp_error( $token ) ) {
			return $this->withContinuationFailure( $token, $state_data );
		}

		$saved = $this->saveTokenResponse( absint( $state_data['user_id'] ), $token );

		if ( is_wp_error( $saved ) ) {
			return $this->withContinuationFailure( $saved, $state_data );
		}

		$return_url = wp_validate_redirect(
			(string) $state_data['return_url'],
			admin_url( 'admin.php?page=brasth-document-sync-for-google-docs' )
		);

		if ( '' === $state_data['continuation_id'] ) {
			return $return_url;
		}

		return $this->resumeContinuation( $state_data, $return_url );
	}

	/**
	 * Consume a successful callback's continuation and build its resume URL.
	 *
	 * Invalid continuations fall back to the default callback redirect and never resume.
	 *
	 * @param array<string,mixed> $state_data Consumed OAuth state.
	 * @param string              $fallback   Default callback redirect.
	 */
	private function resumeContinuation( array $state_data, string $fallback ): string {
		$user_id      = absint( $state_data['user_id'] );
		$continuation = $this->continuations->consume( (string) $state_data['continuation_id'], $user_id );

		if ( is_wp_error( $continuation ) ) {
			return add_query_arg( 'docsync_oauth', 'continuation_invalid', $fallback );
		}

		$args = array( 'docsync_oauth' => 'connected' );

		if ( OAuthContinuationStore::SCOPE_SET_DRIVE_FILE === $continuation['scopeSet'] && ! $this->userHasDriveFileScope( $user_id ) ) {
			$args['docsync_oauth_scope'] = 'drive_file_denied';
		}

		$redirect = $this->allowlistedRedirect( add_query_arg( $args, $continuation['returnUrl'] ) );

		if ( '' === $redirect ) {
			return add_query_arg( 'docsync_oauth', 'continuation_invalid', $fallback );
		}

		/**
		 * Fires once after an OAuth continuation is consumed by a successful callback.
		 *
		 * @param int                 $user_id      Owner user ID.
		 * @param array<string,mixed> $continuation Consumed continuation: returnUrl, scopeSet, resumeKind, resumeId.
		 */
		do_action( self::CONTINUATION_ACTION, $user_id, $continuation );

		return $redirect;
	}

	/**
	 * Attach a consumed continuation's allowlisted page to a callback failure.
	 *
	 * The OAuth state is single use, so the failure redirect cannot read it again.
	 * The continuation is consumed here and never resumed; only its validated
	 * page and resume identifiers are kept so the UI can reopen the same screen.
	 *
	 * @param WP_Error            $error      Callback failure.
	 * @param array<string,mixed> $state_data Consumed OAuth state.
	 */
	private function withContinuationFailure( WP_Error $error, array $state_data ): WP_Error {
		if ( '' === (string) ( $state_data['continuation_id'] ?? '' ) ) {
			return $error;
		}

		$continuation = $this->continuations->consume( (string) $state_data['continuation_id'], absint( $state_data['user_id'] ) );

		if ( is_wp_error( $continuation ) ) {
			return $error;
		}

		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array();

		$data['continuation_return_url'] = $continuation['returnUrl'];
		$error->add_data( $data );

		return $error;
	}

	/**
	 * Validate a redirect as a same-site plugin admin URL.
	 *
	 * @param string $url Candidate redirect.
	 * @return string Empty when invalid.
	 */
	private function allowlistedRedirect( string $url ): string {
		$validated = wp_validate_redirect( $url, '' );

		return '' !== $validated && str_starts_with( $validated, admin_url() ) ? $validated : '';
	}

	/**
	 * Get a valid access token for a user, refreshing if needed.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error
	 */
	public function getAccessToken( int $user_id ): string|WP_Error {
		$token = $this->token_store->get( $user_id );

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		if ( null === $token || '' === $token['access_token'] ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'Connect a Google account before inspecting documents.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::hasRequiredScope( (string) $token['scope'] ) ) {
			return new WP_Error(
				'docsync_wp_google_reconnect_required',
				__( 'Reconnect Google Drive so Brasth Document Sync can browse and sync Google Docs with Drive read-only access.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		if ( absint( $token['expires_at'] ) > time() + self::TOKEN_EXPIRY_SKEW ) {
			return (string) $token['access_token'];
		}

		if ( '' === $token['refresh_token'] ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'Reconnect Google Drive so Brasth Document Sync can refresh access.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		return $this->refreshAccessToken( $user_id, $token );
	}

	/**
	 * Refresh a user's access token.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $current Current token record.
	 * @return string|WP_Error
	 */
	private function refreshAccessToken( int $user_id, array $current ): string|WP_Error {
		$credentials = $this->getCredentials();

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		$token = $this->requestToken(
			array(
				'client_id'     => $credentials['client_id'],
				'client_secret' => $credentials['client_secret'],
				'grant_type'    => 'refresh_token',
				'refresh_token' => (string) $current['refresh_token'],
			)
		);

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		if ( empty( $token['refresh_token'] ) ) {
			$token['refresh_token'] = (string) $current['refresh_token'];
		}

		if ( empty( $token['scope'] ) ) {
			$token['scope'] = (string) $current['scope'];
		}

		if ( empty( $token['google_account_email'] ) ) {
			$token['google_account_email'] = (string) $current['google_account_email'];
		}

		if ( empty( $token['connected_at'] ) ) {
			$token['connected_at'] = (string) $current['connected_at'];
		}

		$saved = $this->saveTokenResponse( $user_id, $token );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return (string) $token['access_token'];
	}

	/**
	 * Persist a token endpoint response.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $token   Token endpoint response.
	 * @return bool|WP_Error
	 */
	private function saveTokenResponse( int $user_id, array $token ): bool|WP_Error {
		if ( empty( $token['access_token'] ) || ! is_string( $token['access_token'] ) ) {
			return new WP_Error(
				'docsync_wp_bad_google_response',
				__( 'Google did not return an access token.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		$existing = $this->token_store->get( $user_id );

		if ( is_wp_error( $existing ) ) {
			return $existing;
		}

		$refresh_token = isset( $token['refresh_token'] ) && is_string( $token['refresh_token'] )
			? $token['refresh_token']
			: ( is_array( $existing ) ? (string) $existing['refresh_token'] : '' );

		if ( '' === $refresh_token ) {
			return new WP_Error(
				'docsync_wp_bad_google_response',
				__( 'Google did not return a refresh token. Reconnect and approve offline access.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		$expires_in = isset( $token['expires_in'] ) ? absint( $token['expires_in'] ) : 3600;
		$scope      = isset( $token['scope'] ) && is_string( $token['scope'] ) ? $token['scope'] : self::DRIVE_READONLY_SCOPE;

		return $this->token_store->save(
			$user_id,
			array(
				'access_token'         => $token['access_token'],
				'refresh_token'        => $refresh_token,
				'expires_at'           => time() + max( 60, $expires_in - self::TOKEN_EXPIRY_SKEW ),
				'scope'                => $scope,
				'google_account_email' => isset( $token['google_account_email'] ) ? (string) $token['google_account_email'] : '',
				'connected_at'         => isset( $token['connected_at'] ) ? (string) $token['connected_at'] : current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Whether an OAuth scope string includes the scope required by this version.
	 *
	 * @param string $scope OAuth scope string from Google.
	 */
	public static function hasRequiredScope( string $scope ): bool {
		$scopes = preg_split( '/\s+/', trim( $scope ) );

		if ( ! is_array( $scopes ) ) {
			return false;
		}

		return in_array( self::DRIVE_READONLY_SCOPE, $scopes, true );
	}

	/**
	 * Exchange or refresh a token through Google's token endpoint.
	 *
	 * @param array<string,string> $request_body Request body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function requestToken( array $request_body ): array|WP_Error {
		$response = wp_remote_post(
			self::TOKEN_ENDPOINT,
			array(
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => array(
					'Accept' => 'application/json',
				),
				'body'    => $request_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'docsync_wp_google_transient_failure',
				__( 'Google is temporarily unavailable. Try again shortly.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 503 )
			);
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status >= 500 || 429 === $status ) {
			return new WP_Error(
				'docsync_wp_google_transient_failure',
				__( 'Google is temporarily unavailable. Try again shortly.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 503 )
			);
		}

		if (
			400 === $status
			&& isset( $request_body['grant_type'] )
			&& 'refresh_token' === $request_body['grant_type']
			&& is_array( $data )
			&& isset( $data['error'] )
			&& 'invalid_grant' === $data['error']
		) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'Reconnect Google Drive so Brasth Document Sync can refresh access.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		if ( $status < 200 || $status >= 300 || ! is_array( $data ) ) {
			$google_error = is_array( $data ) && isset( $data['error'] ) ? sanitize_key( (string) $data['error'] ) : '';

			return new WP_Error(
				'docsync_wp_bad_google_response',
				__( 'Google returned an unexpected OAuth response.', 'brasth-document-sync-for-google-docs' ),
				array(
					'status'       => 502,
					'google_error' => $google_error,
				)
			);
		}

		return $data;
	}

	/**
	 * Redirect URL after OAuth failure, preserving safe admin context for the Setup UI.
	 *
	 * @param string        $state        OAuth state from the callback.
	 * @param string        $google_error Google `error` query param when present.
	 * @param WP_Error|null $plugin_error Optional token-exchange failure.
	 */
	public function buildFailureRedirect( string $state, string $google_error = '', ?WP_Error $plugin_error = null ): string {
		$state_data = $this->consumeState( $state );
		$return_url = is_wp_error( $state_data )
			? admin_url( 'admin.php?page=brasth-document-sync-for-google-docs' )
			: wp_validate_redirect(
				(string) $state_data['return_url'],
				admin_url( 'admin.php?page=brasth-document-sync-for-google-docs' )
			);

		if ( ! is_wp_error( $state_data ) && '' !== $state_data['continuation_id'] ) {
			$continuation = $this->continuations->consume( $state_data['continuation_id'], $state_data['user_id'] );

			if ( ! is_wp_error( $continuation ) ) {
				$return_url = $continuation['returnUrl'];
			}
		} elseif ( null !== $plugin_error ) {
			$plugin_data = $plugin_error->get_error_data();
			$resume_url  = is_array( $plugin_data ) ? $this->allowlistedRedirect( (string) ( $plugin_data['continuation_return_url'] ?? '' ) ) : '';

			if ( '' !== $resume_url ) {
				$return_url = $resume_url;
			}
		}

		$code  = OAuthConnectError::resolveCode( $google_error, $plugin_error );
		$extra = array();

		if ( OAuthConnectError::CODE_UNKNOWN === $code ) {
			$detail = '' !== $google_error ? $google_error : ( null !== $plugin_error ? $plugin_error->get_error_code() : '' );

			if ( '' !== $detail ) {
				$extra['docsync_oauth_detail'] = sanitize_key( $detail );
			}
		}

		return OAuthConnectError::appendToReturnUrl( $return_url, $code, $extra );
	}

	/**
	 * Get configured Google OAuth credentials.
	 *
	 * @return array{client_id:string,client_secret:string}|WP_Error
	 */
	private function getCredentials(): array|WP_Error {
		$settings      = $this->settings->get();
		$client_id     = isset( $settings['client_id'] ) ? sanitize_text_field( (string) $settings['client_id'] ) : '';
		$client_secret = $this->settings->getClientSecret();

		if ( is_wp_error( $client_secret ) ) {
			return $client_secret;
		}

		if ( '' === $client_id || '' === $client_secret ) {
			return new WP_Error(
				'docsync_wp_google_credentials_missing',
				__( 'Configure the Google OAuth client ID and client secret before connecting.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}

	/**
	 * Consume an OAuth state transient.
	 *
	 * @param string $state OAuth state.
	 * @return array{user_id:int,return_url:string,created_at:int,oauth_configuration_generation:int,continuation_id:string}|WP_Error
	 */
	private function consumeState( string $state ): array|WP_Error {
		$state = sanitize_text_field( $state );

		if ( '' === $state ) {
			return $this->invalidStateError();
		}

		$key        = $this->stateTransientKey( $state );
		$state_data = get_transient( $key );

		delete_transient( $key );

		if (
			! is_array( $state_data )
			|| empty( $state_data['user_id'] )
			|| empty( $state_data['return_url'] )
			|| empty( $state_data['created_at'] )
			|| ! array_key_exists( 'oauth_configuration_generation', $state_data )
		) {
			return $this->invalidStateError();
		}

		$continuation_id = isset( $state_data['continuation_id'] ) && is_string( $state_data['continuation_id'] )
			&& 1 === preg_match( '/^[a-f0-9]{48}$/', $state_data['continuation_id'] )
			? $state_data['continuation_id']
			: '';

		return array(
			'user_id'                        => absint( $state_data['user_id'] ),
			'return_url'                     => esc_url_raw( (string) $state_data['return_url'] ),
			'created_at'                     => absint( $state_data['created_at'] ),
			'oauth_configuration_generation' => absint( $state_data['oauth_configuration_generation'] ),
			'continuation_id'                => $continuation_id,
		);
	}

	/**
	 * Create an OAuth state value.
	 *
	 * @return string|WP_Error
	 */
	private function generateState(): string|WP_Error {
		try {
			return bin2hex( random_bytes( 24 ) );
		} catch ( \Throwable $exception ) {
			return new WP_Error(
				'docsync_wp_oauth_state_failed',
				__( 'Brasth Document Sync could not create secure Google OAuth state.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Get the callback redirect URI registered with Google.
	 */
	private function getRedirectUri(): string {
		return esc_url_raw( rest_url( RestServiceProvider::NAMESPACE . '/oauth/google/callback' ) );
	}

	/**
	 * Convert raw state to a transient key.
	 *
	 * @param string $state Raw OAuth state.
	 */
	private function stateTransientKey( string $state ): string {
		return self::STATE_TRANSIENT_PREFIX . hash( 'sha256', $state );
	}

	/**
	 * Not-logged-in error.
	 */
	private function notLoggedInError(): WP_Error {
		return new WP_Error(
			'docsync_wp_not_connected',
			__( 'You must be logged in before connecting Google Drive.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Invalid state error.
	 */
	private function invalidStateError(): WP_Error {
		return new WP_Error(
			'docsync_wp_invalid_oauth_state',
			__( 'The Google OAuth state is invalid or expired. Start the connection again.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
