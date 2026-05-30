<?php
/**
 * REST controller for sync status logs.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Sync\SourceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes source status entries for support visibility.
 */
final class SyncLogController {
	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository $source_repository Source repository.
	 */
	public function __construct( SourceRepository $source_repository ) {
		$this->source_repository = $source_repository;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/sync-log',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listEntries' ),
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
				'args'                => array(
					'post_id'  => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'level'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'page'     => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
					'per_page' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
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
	 * List latest status entries.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listEntries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id  = get_current_user_id();
		$post_id  = absint( $request->get_param( 'post_id' ) );
		$level    = sanitize_key( (string) $request->get_param( 'level' ) );
		$per_page = absint( $request->get_param( 'per_page' ) );
		$page     = absint( $request->get_param( 'page' ) );

		if ( $per_page <= 0 ) {
			$per_page = 50;
		}

		if ( $page <= 0 ) {
			$page = 1;
		}

		return rest_ensure_response(
			$this->source_repository->listSyncEvents(
				$this->source_repository->getEnabledPostTypes(),
				$user_id,
				$post_id,
				$level,
				$per_page,
				$page
			)
		);
	}
}
