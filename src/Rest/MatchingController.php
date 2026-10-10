<?php
/**
 * REST routes for bulk linking posts to Google Docs.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Matching\MatchingService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `/matching/jobs*`: create, read, compare, attach-only commit, and create-doc.
 *
 * Every route requires the authenticated DocSync REST permission; ownership
 * and post access are checked by the matching service, which answers 404 for
 * jobs that belong to someone else.
 */
final class MatchingController {
	private const JOB_ROUTE         = '/matching/jobs/(?P<jobId>[a-f0-9-]{36})';
	private const ALLOWED_META_ARGS = array( 'rest_route' );

	/**
	 * Matching service.
	 *
	 * @var MatchingService
	 */
	private MatchingService $matching;

	/**
	 * Constructor.
	 *
	 * @param MatchingService $matching Matching service.
	 */
	public function __construct( MatchingService $matching ) {
		$this->matching = $matching;
	}

	/**
	 * Register the matching routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/matching/jobs',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createJob' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::JOB_ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getJob' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::JOB_ROUTE . '/compare',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'compare' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::JOB_ROUTE . '/commit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'commit' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::JOB_ROUTE . '/create-doc',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createDoc' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * POST /matching/jobs: queue a bulk-linking job (202).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createJob( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->jsonBody( $request, array( 'posts', 'scope' ), array( 'posts', 'scope' ) );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$job = $this->matching->createJob( get_current_user_id(), $params );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return new WP_REST_Response( $job, 202 );
	}

	/**
	 * GET /matching/jobs/{jobId}: job progress and rows.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getJob( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$unknown = $this->rejectUnknownQuery( $request, array() );

		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$job = $this->matching->getJob( $this->jobId( $request ), get_current_user_id() );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		return rest_ensure_response( $job );
	}

	/**
	 * GET /matching/jobs/{jobId}/compare?postId=&fileId=: side-by-side comparison.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function compare( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$unknown = $this->rejectUnknownQuery( $request, array( 'postId', 'fileId' ) );

		if ( is_wp_error( $unknown ) ) {
			return $unknown;
		}

		$post_id = $request->get_param( 'postId' );
		$file_id = $request->get_param( 'fileId' );

		if ( ! is_string( $post_id ) || ! ctype_digit( $post_id ) || absint( $post_id ) <= 0 || ! is_string( $file_id ) || '' === $file_id ) {
			return $this->invalidInputError();
		}

		$comparison = $this->matching->compare( $this->jobId( $request ), get_current_user_id(), absint( $post_id ), $file_id );

		if ( is_wp_error( $comparison ) ) {
			return $comparison;
		}

		return rest_ensure_response( $comparison );
	}

	/**
	 * POST /matching/jobs/{jobId}/commit: attach-only commit of chosen pairs.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function commit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->jsonBody( $request, array( 'idempotencyKey', 'pairs' ), array( 'idempotencyKey', 'pairs' ) );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		if ( ! is_string( $params['idempotencyKey'] ) || ! is_array( $params['pairs'] ) ) {
			return $this->invalidInputError();
		}

		$result = $this->matching->commit( $this->jobId( $request ), get_current_user_id(), $params['pairs'], $params['idempotencyKey'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * POST /matching/jobs/{jobId}/create-doc: create a Google Doc from a post (201).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createDoc( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->jsonBody( $request, array( 'idempotencyKey', 'postId', 'folderId' ), array( 'idempotencyKey', 'postId' ) );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$post_id   = $params['postId'];
		$folder_id = $params['folderId'] ?? '';

		if (
			! is_string( $params['idempotencyKey'] )
			|| ( ! is_int( $post_id ) && ! ( is_string( $post_id ) && ctype_digit( $post_id ) ) )
			|| ! is_string( $folder_id )
		) {
			return $this->invalidInputError();
		}

		$result = $this->matching->createDoc( $this->jobId( $request ), get_current_user_id(), absint( $post_id ), $folder_id, $params['idempotencyKey'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 201 );
	}

	/**
	 * JSON body with only allowed keys and every required key present.
	 *
	 * @param WP_REST_Request   $request  REST request.
	 * @param array<int,string> $allowed  Allowed keys.
	 * @param array<int,string> $required Required keys.
	 * @return array<string,mixed>|WP_Error
	 */
	private function jsonBody( WP_REST_Request $request, array $allowed, array $required ): array|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) || array() !== array_diff( array_keys( $params ), $allowed ) ) {
			return $this->invalidInputError();
		}

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $params ) ) {
				return $this->invalidInputError();
			}
		}

		return $params;
	}

	/**
	 * Reject query parameters other than the allowed ones.
	 *
	 * WordPress REST meta parameters (names starting with `_`, and `rest_route`
	 * on sites without pretty permalinks) are always allowed.
	 *
	 * @param WP_REST_Request   $request REST request.
	 * @param array<int,string> $allowed Allowed parameter names.
	 * @return bool|WP_Error
	 */
	private function rejectUnknownQuery( WP_REST_Request $request, array $allowed ): bool|WP_Error {
		foreach ( array_keys( (array) $request->get_query_params() ) as $name ) {
			$name = (string) $name;

			if ( in_array( $name, $allowed, true ) || in_array( $name, self::ALLOWED_META_ARGS, true ) || str_starts_with( $name, '_' ) ) {
				continue;
			}

			return $this->invalidInputError();
		}

		return true;
	}

	/**
	 * Job ID from the route.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function jobId( WP_REST_Request $request ): string {
		$job_id = $request->get_param( 'jobId' );

		return is_string( $job_id ) ? $job_id : '';
	}

	/**
	 * Malformed request.
	 */
	private function invalidInputError(): WP_Error {
		return new WP_Error(
			'docsync_wp_matching_invalid_input',
			__( 'Brasth Document Sync received an invalid linking request.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
