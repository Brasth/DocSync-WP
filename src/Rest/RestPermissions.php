<?php
/**
 * Shared REST permission checks.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Settings\SettingsRepository;
use WP_Error;
use WP_Post_Type;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes common Brasth Document Sync REST authentication checks.
 */
final class RestPermissions {
	private const MUTATING_METHODS = array( 'POST', 'PUT', 'PATCH', 'DELETE' );

	/**
	 * Permission callback for logged-in Brasth Document Sync REST users.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function canUseAuthenticatedRest( WP_REST_Request $request ): bool|WP_Error {
		$authenticated = self::requireLoggedIn();

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

		$nonce = self::verifyRestNonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! self::currentUserCanUseDocSync() ) {
			return self::forbiddenUseError();
		}

		return true;
	}

	/**
	 * Permission callback for site-level settings routes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	public static function canManageSettings( WP_REST_Request $request ): bool|WP_Error {
		$authenticated = self::requireLoggedIn();

		if ( is_wp_error( $authenticated ) ) {
			return $authenticated;
		}

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
	 * Whether the current user can use DocSync on at least one enabled target.
	 */
	public static function currentUserCanUseDocSync(): bool {
		return self::userCanUseDocSync( get_current_user_id() );
	}

	/**
	 * Whether the current user can use DocSync for a specific enabled post type.
	 *
	 * @param string $post_type Post type.
	 */
	public static function currentUserCanUsePostType( string $post_type ): bool {
		return self::userCanUsePostType( get_current_user_id(), $post_type );
	}

	/**
	 * Whether a user can use DocSync outside site settings.
	 *
	 * Administrators can always use the plugin. Other users must be able to edit
	 * or create at least one enabled target post type.
	 *
	 * @param int $user_id User ID.
	 */
	public static function userCanUseDocSync( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		foreach ( self::enabledPostTypes() as $post_type ) {
			if ( self::userCanEditOrCreatePostType( $user_id, $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a user can use DocSync for a specific enabled post type.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $post_type Post type.
	 */
	public static function userCanUsePostType( int $user_id, string $post_type ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		$post_type = sanitize_key( $post_type );

		return in_array( $post_type, self::enabledPostTypes(), true )
			&& self::userCanEditOrCreatePostType( $user_id, $post_type );
	}

	/**
	 * Verify the normal WordPress REST nonce.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return bool|WP_Error
	 */
	private static function verifyRestNonce( WP_REST_Request $request ): bool|WP_Error {
		$method      = strtoupper( $request->get_method() );
		$is_mutating = in_array( $method, self::MUTATING_METHODS, true );
		$nonce       = (string) $request->get_header( 'X-WP-Nonce' );

		if ( '' === $nonce && ! $is_mutating && 'GET' === $method ) {
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

	/**
	 * Require a logged-in WordPress user.
	 *
	 * @return true|WP_Error
	 */
	private static function requireLoggedIn(): true|WP_Error {
		if ( is_user_logged_in() ) {
			return true;
		}

		return new WP_Error(
			'docsync_wp_not_connected',
			__( 'You must be logged in to use Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Shared forbidden error for logged-in users without target capabilities.
	 */
	private static function forbiddenUseError(): WP_Error {
		return new WP_Error(
			'docsync_wp_forbidden',
			__( 'You do not have permission to use Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Get enabled post types without needing a controller instance.
	 *
	 * @return array<int,string>
	 */
	private static function enabledPostTypes(): array {
		$stored = get_option( SettingsRepository::OPTION_NAME, array() );
		$value  = is_array( $stored ) && isset( $stored['enabled_post_types'] ) ? $stored['enabled_post_types'] : array( 'post' );

		if ( ! is_array( $value ) ) {
			$value = array( 'post' );
		}

		$post_types = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( mixed $post_type ): string {
							return is_string( $post_type ) ? sanitize_key( $post_type ) : '';
						},
						$value
					)
				)
			)
		);

		if ( ! in_array( 'post', $post_types, true ) && null !== get_post_type_object( 'post' ) ) {
			array_unshift( $post_types, 'post' );
		}

		$post_types = array_values(
			array_filter(
				$post_types,
				array( self::class, 'isSupportedPostType' )
			)
		);

		return array() === $post_types ? array( 'post' ) : $post_types;
	}

	/**
	 * Whether a user can edit or create posts for a post type.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $post_type Post type.
	 */
	private static function userCanEditOrCreatePostType( int $user_id, string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		$edit_capability   = $post_type_object->cap->edit_posts ?? 'edit_posts';
		$create_capability = $post_type_object->cap->create_posts ?? $edit_capability;

		return ( 'do_not_allow' !== $edit_capability && user_can( $user_id, $edit_capability ) )
			|| ( 'do_not_allow' !== $create_capability && user_can( $user_id, $create_capability ) );
	}

	/**
	 * Whether a post type is supported by DocSync.
	 *
	 * @param string $post_type Post type.
	 */
	private static function isSupportedPostType( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return true;
		}

		return ! empty( $post_type_object->public ) && empty( $post_type_object->_builtin );
	}
}
