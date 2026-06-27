<?php
/**
 * REST controller for post-linked Google Docs sources.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Cron\SyncCron;
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
	private const SYNC_MODE_INLINE       = 'inline';
	private const SYNC_MODE_BACKGROUND   = 'background';
	private const SYNC_ALL_BATCH_SIZE    = 20;
	private const SYNC_ALL_SCAN_LIMIT    = 100;
	private const SYNC_ALL_MAX_SCANS     = 5;
	private const SYNC_STALE_SECONDS     = 600;
	private const EXPORT_FORMAT_HTML_ZIP = 'html_zip';
	private const MAX_PAGE_SIZE          = 100;

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
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createSource' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/sources/(?P<postId>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSource' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'updateSource' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'detachSource' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/sources/(?P<postId>[\d]+)/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getSourceContent' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/sources/sync-all',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'syncAllSources' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/sources/(?P<postId>[\d]+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'syncSource' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
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
		$search    = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$status    = sanitize_key( (string) $request->get_param( 'status' ) );

		if ( '' !== $status && ! in_array( $status, $this->sourceStatuses(), true ) ) {
			return new WP_Error(
				'docsync_wp_invalid_source_status',
				__( 'Brasth Document Sync received an unsupported source status filter.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

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

		$per_page = $this->clampPositiveInt( $request->get_param( 'per_page' ), self::MAX_PAGE_SIZE, self::MAX_PAGE_SIZE );
		$page     = $this->clampPositiveInt( $request->get_param( 'page' ), 1, PHP_INT_MAX );

		return rest_ensure_response( $this->source_repository->listSourcesPage( $post_types, $user_id, $per_page, $page, $search, $status ) );
	}

	/**
	 * Attach a source to an existing post or create a new synced draft.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->getRequestParams(
			$request,
			array( 'fileId', 'target', 'exportFormat', 'syncMode', 'elementorSync' ),
			'docsync_wp_unknown_source_fields'
		);

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
				__( 'Brasth Document Sync requires a source target.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$target_fields = $this->rejectUnknownFields(
			$target,
			array( 'mode', 'postId', 'postType' ),
			'docsync_wp_unknown_source_target_fields',
			__( 'Brasth Document Sync received unknown source target fields.', 'brasth-document-sync-for-google-docs' )
		);

		if ( is_wp_error( $target_fields ) ) {
			return $target_fields;
		}

		$mode           = sanitize_key( (string) ( $target['mode'] ?? '' ) );
		$export_format  = $this->getExportFormat( $params );
		$sync_mode      = $this->getSyncMode( $params );
		$elementor_sync = $this->getOptionalBoolean( $params, 'elementorSync' );
		$user_id        = get_current_user_id();

		if ( is_wp_error( $export_format ) ) {
			return $export_format;
		}

		if ( is_wp_error( $sync_mode ) ) {
			return $sync_mode;
		}

		if ( is_wp_error( $elementor_sync ) ) {
			return $elementor_sync;
		}

		if ( 'existing' === $mode ) {
			$post_id = $this->getTargetPostId( $target );

			if ( is_wp_error( $post_id ) ) {
				return $post_id;
			}

			$allowed = $this->validateEditablePost( $post_id, $user_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$result = $this->sync_service->attachSource( $post_id, $user_id, $file_id, $export_format, $elementor_sync );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( self::SYNC_MODE_BACKGROUND === $sync_mode ) {
				$result = $this->queueSync( $post_id, $user_id );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			return rest_ensure_response( $result );
		}

		if ( 'new' === $mode ) {
			$post_type = sanitize_key( (string) ( $target['postType'] ?? 'post' ) );
			$allowed   = $this->validateCreatablePostType( $post_type, $user_id );

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$result = $this->sync_service->createDraftFromSource(
				$user_id,
				$file_id,
				$post_type,
				$export_format,
				self::SYNC_MODE_INLINE === $sync_mode,
				$elementor_sync
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( self::SYNC_MODE_BACKGROUND === $sync_mode ) {
				$result = $this->queueSync( absint( $result['postId'] ?? 0 ), $user_id, true );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}

			$response = rest_ensure_response( $result );
			$response->set_status( 201 );

			return $response;
		}

		return new WP_Error(
			'docsync_wp_invalid_source_target',
			__( 'Brasth Document Sync received an unsupported source target mode.', 'brasth-document-sync-for-google-docs' ),
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
		$post_id = $this->getPostIdParam( $request );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );
		$params  = $this->getOptionalRequestParams(
			$request,
			array( 'syncMode' ),
			'docsync_wp_unknown_sync_fields'
		);

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$sync_mode = $this->getSyncMode( $params );

		if ( is_wp_error( $sync_mode ) ) {
			return $sync_mode;
		}

		if ( self::SYNC_MODE_BACKGROUND === $sync_mode ) {
			$result = $this->queueSync( $post_id, $user_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return rest_ensure_response( $result );
		}

		$result = $this->sync_service->syncPost( $post_id, $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get one linked source after post permission checks.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $this->getPostIdParam( $request );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$recovered = $this->recoverStaleSync( $post_id );

		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}

		$source = $this->source_repository->formatSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $source );
	}

	/**
	 * Update editable source metadata for a post.
	 *
	 * Currently supports changing the per-post Elementor sync preference.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $this->getPostIdParam( $request );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		$params = $this->getOptionalRequestParams(
			$request,
			array( 'elementorSync' ),
			'docsync_wp_unknown_source_update_fields'
		);

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$update = array( 'google_file_id' => $source['google_file_id'] );

		if ( array_key_exists( 'elementorSync', $params ) ) {
			$elementor_sync = $this->getOptionalBoolean( $params, 'elementorSync' );

			if ( is_wp_error( $elementor_sync ) ) {
				return $elementor_sync;
			}

			$update['elementor_sync'] = $elementor_sync;
		}

		if ( 1 === count( $update ) ) {
			return new WP_Error(
				'docsync_wp_no_source_update',
				__( 'Brasth Document Sync received no source fields to update.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$saved = $this->source_repository->saveSource( $post_id, $update );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$formatted = $this->source_repository->formatSource( $post_id );

		if ( null === $formatted ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $formatted );
	}

	/**
	 * Get synced post content after post permission checks.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getSourceContent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $this->getPostIdParam( $request );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$source = $this->source_repository->formatSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'docsync_wp_post_not_found',
				__( 'Brasth Document Sync could not find this post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response(
			array(
				'postId'  => $post_id,
				'content' => (string) $post->post_content,
				'source'  => $source,
			)
		);
	}

	/**
	 * Sync all editable linked sources for the current user.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function syncAllSources( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->getOptionalRequestParams(
			$request,
			array(),
			'docsync_wp_unknown_sync_all_fields'
		);

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$user_id  = get_current_user_id();
		$before   = gmdate( 'Y-m-d H:i:s', time() - 1 );
		$results  = array();
		$seen     = array();
		$has_more = false;
		$count    = 0;

		for ( $scan = 0; $scan < self::SYNC_ALL_MAX_SCANS && $count < self::SYNC_ALL_BATCH_SIZE; $scan++ ) {
			$post_ids = $this->source_repository->listDueSourcePostIdsForUser(
				$this->source_repository->getEnabledPostTypes(),
				$user_id,
				self::SYNC_ALL_SCAN_LIMIT,
				$before,
				array_keys( $seen )
			);

			if ( array() === $post_ids ) {
				$has_more = false;
				break;
			}

			$post_ids_count = count( $post_ids );
			$has_more       = self::SYNC_ALL_SCAN_LIMIT === $post_ids_count;

			foreach ( $post_ids as $index => $post_id ) {
				$post_id = absint( $post_id );

				if ( $post_id <= 0 ) {
					continue;
				}

				$seen[ $post_id ] = true;

				if ( ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) ) {
					continue;
				}

				$result = $this->queueSync( $post_id, $user_id, false, false );

				if ( is_wp_error( $result ) ) {
					$results[] = array(
						'postId'  => $post_id,
						'status'  => 'error',
						'message' => $result->get_error_message(),
					);
					++$count;

					if ( $count >= self::SYNC_ALL_BATCH_SIZE ) {
						$has_more = $has_more || $index < $post_ids_count - 1;
						break;
					}

					continue;
				}

				$results[] = $result;
				++$count;

				if ( $count >= self::SYNC_ALL_BATCH_SIZE ) {
					$has_more = $has_more || $index < $post_ids_count - 1;
					break;
				}
			}

			if ( ! $has_more ) {
				break;
			}
		}

		if ( array() !== $results ) {
			SyncCron::spawnScheduledSyncs();
		}

		return rest_ensure_response(
			array(
				'results' => $results,
				'count'   => count( $results ),
				'hasMore' => $has_more,
			)
		);
	}

	/**
	 * Detach a source from a post without changing post content.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function detachSource( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $this->getPostIdParam( $request );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$user_id = get_current_user_id();
		$allowed = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( SyncService::STATUS_SYNCING === (string) $source['sync_status'] ) {
			return new WP_Error(
				'docsync_wp_source_syncing',
				__( 'Wait for the current Google Doc sync to finish before detaching this source.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
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
	 * @param WP_REST_Request   $request      REST request.
	 * @param array<int,string> $allowed_keys Allowed payload keys.
	 * @param string            $error_code   Error code for unknown keys.
	 * @return array<string,mixed>|WP_Error
	 */
	private function getRequestParams( WP_REST_Request $request, ?array $allowed_keys = null, string $error_code = 'docsync_wp_unknown_source_fields' ): array|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'docsync_wp_invalid_source_payload',
				__( 'Brasth Document Sync source requests require a JSON object.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( null !== $allowed_keys ) {
			$allowed = $this->rejectUnknownFields(
				$params,
				$allowed_keys,
				$error_code,
				__( 'Brasth Document Sync received unknown source request fields.', 'brasth-document-sync-for-google-docs' )
			);

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		return $params;
	}

	/**
	 * Get optional request parameters.
	 *
	 * @param WP_REST_Request   $request      REST request.
	 * @param array<int,string> $allowed_keys Allowed payload keys.
	 * @param string            $error_code   Error code for unknown keys.
	 * @return array<string,mixed>|WP_Error
	 */
	private function getOptionalRequestParams( WP_REST_Request $request, ?array $allowed_keys = null, string $error_code = 'docsync_wp_unknown_source_fields' ): array|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( null !== $allowed_keys ) {
			$allowed = $this->rejectUnknownFields(
				$params,
				$allowed_keys,
				$error_code,
				__( 'Brasth Document Sync received unknown source request fields.', 'brasth-document-sync-for-google-docs' )
			);

			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}
		}

		return $params;
	}

	/**
	 * Reject payload fields outside a known route shape.
	 *
	 * @param array<string,mixed> $params       Request params.
	 * @param array<int,string>   $allowed_keys Allowed payload keys.
	 * @param string              $error_code   Error code.
	 * @param string              $message      Error message.
	 * @return true|WP_Error
	 */
	private function rejectUnknownFields( array $params, array $allowed_keys, string $error_code, string $message ): true|WP_Error {
		$unknown_keys = array_diff( array_keys( $params ), $allowed_keys );

		if ( array() === $unknown_keys ) {
			return true;
		}

		return new WP_Error(
			$error_code,
			$message,
			array( 'status' => 400 )
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

	/**
	 * Get a positive source post ID from the route.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return int|WP_Error
	 */
	private function getPostIdParam( WP_REST_Request $request ): int|WP_Error {
		$post_id = absint( $request->get_param( 'postId' ) );

		return $post_id > 0 ? $post_id : $this->invalidPostIdError();
	}

	/**
	 * Get a positive existing target post ID from source target params.
	 *
	 * @param array<string,mixed> $target Source target params.
	 * @return int|WP_Error
	 */
	private function getTargetPostId( array $target ): int|WP_Error {
		$post_id = absint( $target['postId'] ?? 0 );

		return $post_id > 0 ? $post_id : $this->invalidPostIdError();
	}

	/**
	 * Get and validate source export format.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return string|WP_Error
	 */
	private function getExportFormat( array $params ): string|WP_Error {
		$export_format = isset( $params['exportFormat'] ) ? sanitize_key( (string) $params['exportFormat'] ) : self::EXPORT_FORMAT_HTML_ZIP;

		if ( in_array( $export_format, array( self::EXPORT_FORMAT_HTML_ZIP, 'markdown' ), true ) ) {
			return self::EXPORT_FORMAT_HTML_ZIP;
		}

		return new WP_Error(
			'docsync_wp_invalid_export_format',
			__( 'Brasth Document Sync received an unsupported export format.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Get an optional boolean request field.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @param string              $key    Field key.
	 * @return bool|null|WP_Error
	 */
	private function getOptionalBoolean( array $params, string $key ): bool|null|WP_Error {
		if ( ! array_key_exists( $key, $params ) ) {
			return null;
		}

		$value = $params[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) && in_array( $value, array( 0, 1 ), true ) ) {
			return (bool) $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );

			if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
				return true;
			}

			if ( in_array( $value, array( '0', 'false', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}

		return new WP_Error(
			'docsync_wp_invalid_boolean_field',
			__( 'Brasth Document Sync received an invalid boolean field.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Invalid source post ID error.
	 */
	private function invalidPostIdError(): WP_Error {
		return new WP_Error(
			'docsync_wp_invalid_post_id',
			__( 'Brasth Document Sync requires a valid post ID.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Get the requested sync mode, defaulting to inline behavior.
	 *
	 * @param array<string,mixed> $params Request params.
	 * @return string|WP_Error
	 */
	private function getSyncMode( array $params ): string|WP_Error {
		$sync_mode = isset( $params['syncMode'] ) ? sanitize_key( (string) $params['syncMode'] ) : self::SYNC_MODE_INLINE;

		if ( in_array( $sync_mode, array( self::SYNC_MODE_INLINE, self::SYNC_MODE_BACKGROUND ), true ) ) {
			return $sync_mode;
		}

		return new WP_Error(
			'docsync_wp_invalid_sync_mode',
			__( 'Brasth Document Sync received an unsupported sync mode.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Queue a source sync and return current source state.
	 *
	 * @param int  $post_id Post ID.
	 * @param int  $user_id User ID whose Google token should run the sync.
	 * @param bool $created Whether the source was created by this request.
	 * @param bool $spawn   Whether to spawn WP-Cron immediately.
	 * @return array<string,mixed>|WP_Error
	 */
	private function queueSync( int $post_id, int $user_id, bool $created = false, bool $spawn = true ): array|WP_Error {
		$source            = $this->source_repository->getSource( $post_id );
		$sync_owner_user   = is_array( $source ) ? absint( $source['sync_owner_user_id'] ?? 0 ) : 0;
		$event_user_id     = $sync_owner_user > 0 ? $sync_owner_user : $user_id;
		$has_pending_event = SyncCron::hasScheduledSourceSync( $post_id, $event_user_id );
		$result            = $this->sync_service->markSyncQueued( $post_id, $user_id, $has_pending_event );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$already_queued = ! empty( $result['alreadyQueued'] );
		unset( $result['alreadyQueued'] );

		if ( ! $already_queued ) {
			$scheduled = SyncCron::scheduleSourceSync( $post_id, $user_id, $spawn );

			if ( is_wp_error( $scheduled ) ) {
				$this->sync_service->markSyncError( $post_id, $scheduled );
				return $scheduled;
			}
		} elseif ( $spawn ) {
			SyncCron::spawnScheduledSyncs();
		}

		if ( $created ) {
			$result['created'] = true;
		}

		return $result;
	}

	/**
	 * Convert abandoned background sync state into an actionable error.
	 *
	 * @param int $post_id Post ID.
	 * @return true|WP_Error
	 */
	private function recoverStaleSync( int $post_id ): true|WP_Error {
		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source || SyncService::STATUS_SYNCING !== (string) $source['sync_status'] ) {
			return true;
		}

		$owner_user_id = absint( $source['sync_owner_user_id'] ?? 0 );
		$has_lock      = $this->sync_service->hasActiveSyncLock( $post_id );
		$has_cron      = $owner_user_id > 0 && SyncCron::hasScheduledSourceSync( $post_id, $owner_user_id );

		if ( $has_lock || $has_cron ) {
			return true;
		}

		if ( ! $this->isStaleSyncHeartbeat( $source ) ) {
			return true;
		}

		$message = __( 'Sync stopped before completion. Retry sync, and check WP-Cron or PHP error logs if it keeps happening.', 'brasth-document-sync-for-google-docs' );
		$result  = $this->sync_service->markSyncError(
			$post_id,
			new WP_Error( 'docsync_wp_sync_stalled', $message, array( 'status' => 500 ) ),
			array(
				'hasLock'       => $has_lock,
				'hasCronEvent'  => $has_cron,
				'lastHeartbeat' => (string) ( $source['sync_updated_at'] ?? $source['sync_started_at'] ?? '' ),
				'lastStep'      => (string) ( $source['sync_step'] ?? '' ),
			)
		);

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Whether the last sync heartbeat is old enough to treat as stalled.
	 *
	 * @param array<string,mixed> $source Source metadata.
	 */
	private function isStaleSyncHeartbeat( array $source ): bool {
		$timestamp = (string) ( $source['sync_updated_at'] ?? $source['sync_started_at'] ?? '' );

		if ( '' === $timestamp ) {
			return true;
		}

		$updated_at = strtotime( $timestamp . ' UTC' );

		if ( false === $updated_at ) {
			return true;
		}

		return time() - $updated_at >= self::SYNC_STALE_SECONDS;
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
				__( 'Brasth Document Sync could not find that post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->source_repository->isPostTypeEnabled( $post->post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'Brasth Document Sync is not enabled for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to edit this post.', 'brasth-document-sync-for-google-docs' ),
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
				__( 'Brasth Document Sync is not enabled for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->source_repository->userCanEditPostType( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to edit this post type.', 'brasth-document-sync-for-google-docs' ),
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
				__( 'Brasth Document Sync is not enabled for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->source_repository->userCanCreateSyncedPost( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to create this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Source statuses available for filtering.
	 *
	 * @return array<int,string>
	 */
	private function sourceStatuses(): array {
		return array(
			SyncService::STATUS_LINKED,
			SyncService::STATUS_SYNCING,
			SyncService::STATUS_SYNCED,
			SyncService::STATUS_SKIPPED,
			SyncService::STATUS_ERROR,
		);
	}
}
