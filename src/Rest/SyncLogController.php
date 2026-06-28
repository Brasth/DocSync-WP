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
	private const MAX_PAGE_SIZE = 100;

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
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
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
					'search'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'status'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'step'     => array(
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

		register_rest_route(
			$rest_namespace,
			'/sync-log',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'clearEntries' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				'args'                => array(
					'post_id' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
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
		$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status   = sanitize_key( (string) $request->get_param( 'status' ) );
		$step     = sanitize_key( (string) $request->get_param( 'step' ) );
		$per_page = $this->clampPositiveInt( $request->get_param( 'per_page' ), 50, self::MAX_PAGE_SIZE );
		$page     = $this->clampPositiveInt( $request->get_param( 'page' ), 1, PHP_INT_MAX );

		if ( '' !== $level && ! in_array( $level, $this->source_repository->getSyncEventLevels(), true ) ) {
			return new WP_Error(
				'docsync_wp_invalid_sync_log_level',
				__( 'Brasth Document Sync received an unsupported sync log level filter.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			$this->source_repository->listSyncEvents(
				$this->source_repository->getEnabledPostTypes(),
				$user_id,
				$post_id,
				$level,
				$per_page,
				$page,
				$search,
				$status,
				$step
			)
		);
	}

	/**
	 * Clear stored diagnostic entries.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function clearEntries( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$post_id = absint( $request->get_param( 'post_id' ) );

		$cleared = $this->source_repository->clearSyncEvents(
			$this->source_repository->getEnabledPostTypes(),
			$user_id,
			$post_id
		);

		if ( is_wp_error( $cleared ) ) {
			return $cleared;
		}

		return rest_ensure_response(
			array(
				'cleared' => $cleared,
			)
		);
	}

	/**
	 * Clamp a positive integer request value.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $fallback Fallback value.
	 * @param int   $maximum  Maximum value.
	 */
	private function clampPositiveInt( mixed $value, int $fallback, int $maximum ): int {
		$number = absint( $value );

		if ( $number <= 0 ) {
			$number = $fallback;
		}

		return max( 1, min( $maximum, $number ) );
	}
}
