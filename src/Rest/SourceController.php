<?php
/**
 * REST controller for post-linked Google Docs sources.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Google\DocumentIdParser;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles source attach, sync, detach, and listing routes.
 */
final class SourceController {
	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Sync service.
	 *
	 * @var SyncService
	 */
	private SyncService $sync_service;

	/**
	 * Document ID parser.
	 *
	 * @var DocumentIdParser
	 */
	private DocumentIdParser $document_id_parser;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository $source_repository Source repository.
	 * @param SyncService      $sync_service      Sync service.
	 * @param DocumentIdParser $document_id_parser Document ID parser.
	 */
	public function __construct(
		SourceRepository $source_repository,
		SyncService $sync_service,
		DocumentIdParser $document_id_parser
	) {
		$this->source_repository  = $source_repository;
		$this->sync_service       = $sync_service;
		$this->document_id_parser = $document_id_parser;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/sources',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listSources' ),
					'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createSource' ),
					'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/sources/(?P<postId>[\d]+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'detachSource' ),
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/sources/(?P<postId>[\d]+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'syncSource' ),
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
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
	 * List linked sources for editable enabled post types.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listSources( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = get_current_user_id();
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );

		if ( '' !== $post_type ) {
			$allowed = $this->validateEditablePostType( $post_type, $user_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$post_types = array( $post_type );
		} else {
			$post_types = array_values(
				array_filter(
					$this->source_repository->getEnabledPostTypes(),
					function ( string $enabled_post_type ) use ( $user_id ): bool {
						return $this->source_repository->userCanEditPostType( $enabled_post_type, $user_id );
					}
				)
			);
		}

		return rest_ensure_response(
			array(
				'sources' => $this->source_repository->listSources( $post_types, $user_id ),
			)
		);
	}

	/**
	 * Attach a source to an existing post or create a new synced draft.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->getRequestParams( $request );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$file_id = $this->parseFileId( $params );

		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$target = isset( $params['target'] ) && is_array( $params['target'] ) ? $params['target'] : null;

		if ( null === $target ) {
			return new WP_Error(
				'docsync_wp_invalid_source_target',
				__( 'DocSync WP requires a source target.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		$mode          = sanitize_key( (string) ( $target['mode'] ?? '' ) );
		$export_format = isset( $params['exportFormat'] ) ? sanitize_key( (string) $params['exportFormat'] ) : 'markdown';
		$user_id       = get_current_user_id();

		if ( 'existing' === $mode ) {
			$post_id = absint( $target['postId'] ?? 0 );
			$allowed = $this->validateEditablePost( $post_id, $user_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$result = $this->sync_service->attachSource( $post_id, $user_id, $file_id, $export_format );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		if ( 'new' === $mode ) {
			$post_type = sanitize_key( (string) ( $target['postType'] ?? 'post' ) );
			$allowed   = $this->validateCreatablePostType( $post_type, $user_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$result = $this->sync_service->createDraftFromSource( $user_id, $file_id, $post_type, $export_format );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$response = rest_ensure_response( $result );
			$response->set_status( 201 );

			return $response;
		}

		return new WP_Error(
			'docsync_wp_invalid_source_target',
			__( 'DocSync WP received an unsupported source target mode.', 'docsync-wp' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Sync a linked source now.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function syncSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'postId' ) );
		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$result = $this->sync_service->syncPost( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Detach a source from a post without changing post content.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function detachSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'postId' ) );
		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( null === $this->source_repository->getSource( $post_id ) ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'docsync-wp' ),
				array( 'status' => 404 )
			);
		}

		$deleted = $this->source_repository->deleteSource( $post_id );

		return rest_ensure_response(
			array(
				'postId'  => $post_id,
				'deleted' => $deleted,
			)
		);
	}

	/**
	 * Get request parameters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>|WP_Error
	 */
	private function getRequestParams( WP_REST_Request $request ): array|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'docsync_wp_invalid_source_payload',
				__( 'DocSync WP source requests require a JSON object.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		return $params;
	}

	/**
	 * Parse a source file ID from request params.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return string|WP_Error
	 */
	private function parseFileId( array $params ): string|WP_Error {
		$file_id = $params['fileId'] ?? '';

		return $this->document_id_parser->parse( $file_id, 'file_id' );
	}

	/**
	 * Validate post edit access.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return true|WP_Error
	 */
	private function validateEditablePost( int $post_id, int $user_id ): true|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'docsync_wp_invalid_post',
				__( 'DocSync WP could not find that post.', 'docsync-wp' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->source_repository->isPostTypeEnabled( $post->post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'DocSync WP is not enabled for this post type.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to edit this post.', 'docsync-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate post type listing access.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   User ID.
	 * @return true|WP_Error
	 */
	private function validateEditablePostType( string $post_type, int $user_id ): true|WP_Error {
		if ( ! $this->source_repository->isPostTypeEnabled( $post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'DocSync WP is not enabled for this post type.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->source_repository->userCanEditPostType( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to edit this post type.', 'docsync-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate post type creation access.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   User ID.
	 * @return true|WP_Error
	 */
	private function validateCreatablePostType( string $post_type, int $user_id ): true|WP_Error {
		if ( ! $this->source_repository->isPostTypeEnabled( $post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'DocSync WP is not enabled for this post type.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->source_repository->userCanCreateSyncedPost( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to create this post type.', 'docsync-wp' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
