<?php
/**
 * REST controller for Google document inspection.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Google\DocumentIdParser;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Sync\SourceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Google document REST endpoints.
 */
final class DocumentController {
	private const MAX_PAGE_SIZE = 50;

	/**
	 * Additive camelCase `/drive/items` filters and their allowed values.
	 */
	private const DRIVE_ITEM_FILTERS = array(
		'location' => array( 'myDrive', 'sharedWithMe', 'sharedDrive', 'recent', 'starred' ),
		'owner'    => array( 'any', 'me', 'others' ),
		'linked'   => array( 'any', 'linked', 'unlinked' ),
	);

	/**
	 * Document ID parser.
	 *
	 * @var DocumentIdParser
	 */
	private DocumentIdParser $document_id_parser;

	/**
	 * Drive client.
	 *
	 * @var DriveClient
	 */
	private DriveClient $drive_client;

	/**
	 * Source repository for linked-status lookups, injected by the REST provider.
	 *
	 * @var SourceRepository|null
	 */
	private ?SourceRepository $source_repository = null;

	/**
	 * Constructor.
	 *
	 * @param DocumentIdParser $document_id_parser Document ID parser.
	 * @param DriveClient      $drive_client        Drive client.
	 */
	public function __construct( DocumentIdParser $document_id_parser, DriveClient $drive_client ) {
		$this->document_id_parser = $document_id_parser;
		$this->drive_client       = $drive_client;
	}

	/**
	 * Expose the injected Drive services so additive providers reuse the same instances.
	 *
	 * @return array{documentIdParser:DocumentIdParser,driveClient:DriveClient}
	 */
	public function getDependencies(): array {
		return array(
			'documentIdParser' => $this->document_id_parser,
			'driveClient'      => $this->drive_client,
		);
	}

	/**
	 * Inject the source repository used for the `/drive/items` linked status and filter.
	 *
	 * @param SourceRepository $source_repository Source repository.
	 */
	public function setSourceRepository( SourceRepository $source_repository ): void {
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
			'/drive/shared-drives',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listSharedDrives' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/drive/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listDriveItems' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/documents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listDocuments' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/documents/inspect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inspectDocument' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * List shared drives available to the connected Google account.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listSharedDrives( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$drives = $this->drive_client->listSharedDrives(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
			$this->pageSize( $request, self::MAX_PAGE_SIZE )
		);

		if ( is_wp_error( $drives ) ) {
			return $drives;
		}

		return rest_ensure_response( $drives );
	}

	/**
	 * List folders and Google Docs in a Google Drive folder.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listDriveItems( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( $this->hasDriveItemFilters( $request ) ) {
			return $this->searchDriveItems( $request );
		}

		$items = $this->drive_client->listDriveItems(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'folder_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'drive_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'search' ) ),
			sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
			$this->pageSize( $request, 20 )
		);

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		return rest_ensure_response( $items );
	}

	/**
	 * Run the additive location, global search, owner, and linked filters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function searchDriveItems( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$filters = array();

		foreach ( self::DRIVE_ITEM_FILTERS as $name => $allowed ) {
			$value = $request->get_param( $name );

			if ( null === $value || '' === $value ) {
				$filters[ $name ] = $allowed[0];
				continue;
			}

			if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
				return $this->invalidDriveFilterError();
			}

			$filters[ $name ] = $value;
		}

		$global_search = $this->parseBooleanParam( $request->get_param( 'globalSearch' ) );

		if ( null === $global_search ) {
			return $this->invalidDriveFilterError();
		}

		if ( 'any' !== $filters['linked'] && null === $this->source_repository ) {
			return new WP_Error(
				'docsync_wp_linked_filter_unavailable',
				__( 'Brasth Document Sync cannot filter Google Docs by linked status right now.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$user_id = get_current_user_id();
		$result  = $this->drive_client->searchDriveItems(
			$user_id,
			array(
				'location'     => $filters['location'],
				'folderId'     => sanitize_text_field( (string) $request->get_param( 'folder_id' ) ),
				'driveId'      => sanitize_text_field( (string) $request->get_param( 'drive_id' ) ),
				'search'       => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'globalSearch' => $global_search,
				'owner'        => $filters['owner'],
				'pageToken'    => sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
				'pageSize'     => $this->pageSize( $request, 20 ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( null !== $this->source_repository ) {
			$result['items'] = $this->withLinkedStatus( $result['items'], $user_id, $filters['linked'] );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Add linked status to document items and apply the per-page linked filter.
	 *
	 * `linkedPostId` is exposed only when the caller can edit that post, so the
	 * response never reveals inaccessible WordPress posts. Folders always remain
	 * so the browser can still navigate.
	 *
	 * @param array<int,array<string,mixed>> $items   Drive items.
	 * @param int                            $user_id Current user ID.
	 * @param string                         $linked  `any`, `linked`, or `unlinked`.
	 * @return array<int,array<string,mixed>>
	 */
	private function withLinkedStatus( array $items, int $user_id, string $linked ): array {
		$filtered = array();

		foreach ( $items as $item ) {
			if ( 'document' !== ( $item['itemType'] ?? '' ) ) {
				$filtered[] = $item;
				continue;
			}

			$post_id   = $this->source_repository->findPostIdByGoogleFileId( (string) $item['fileId'] );
			$is_linked = null !== $post_id;

			if ( ( 'linked' === $linked && ! $is_linked ) || ( 'unlinked' === $linked && $is_linked ) ) {
				continue;
			}

			$item['linked']       = $is_linked;
			$item['linkedPostId'] = $is_linked && user_can( $user_id, 'edit_post', $post_id ) ? $post_id : null;
			$filtered[]           = $item;
		}

		return $filtered;
	}

	/**
	 * Whether a `/drive/items` request uses any additive filter.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function hasDriveItemFilters( WP_REST_Request $request ): bool {
		foreach ( array_merge( array_keys( self::DRIVE_ITEM_FILTERS ), array( 'globalSearch' ) ) as $name ) {
			if ( $request->has_param( $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Parse an optional boolean query parameter.
	 *
	 * @param mixed $value Raw value.
	 * @return bool|null Null when invalid.
	 */
	private function parseBooleanParam( mixed $value ): ?bool {
		if ( null === $value || is_bool( $value ) ) {
			return (bool) $value;
		}

		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = strtolower( trim( (string) $value ) );

		if ( in_array( $value, array( '1', 'true' ), true ) ) {
			return true;
		}

		if ( in_array( $value, array( '0', 'false', '' ), true ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Invalid Drive filter error.
	 */
	private function invalidDriveFilterError(): WP_Error {
		return new WP_Error(
			'docsync_wp_invalid_drive_query',
			__( 'Brasth Document Sync received an unsupported Drive location or filter.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * List Google Docs available to the connected account.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listDocuments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$documents = $this->drive_client->listGoogleDocs(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'search' ) ),
			sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
			$this->pageSize( $request, 20 )
		);

		if ( is_wp_error( $documents ) ) {
			return $documents;
		}

		return rest_ensure_response( $documents );
	}

	/**
	 * Inspect a Google Docs document by URL or file ID.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function inspectDocument( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'docsync_wp_invalid_document_payload',
				__( 'Brasth Document Sync document inspection requires a JSON object.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$unknown_keys = array_diff( array_keys( $params ), array( 'document', 'source' ) );

		if ( array() !== $unknown_keys ) {
			return new WP_Error(
				'docsync_wp_unknown_document_fields',
				__( 'Brasth Document Sync received unknown document inspection fields.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$file_id = $this->document_id_parser->parse(
			$params['document'] ?? '',
			isset( $params['source'] ) ? (string) $params['source'] : ''
		);

		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$metadata = $this->drive_client->getMetadata( get_current_user_id(), $file_id );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		return rest_ensure_response( $metadata );
	}

	/**
	 * Clamp Google list page size at the controller boundary.
	 *
	 * @param WP_REST_Request $request  REST request.
	 * @param int             $fallback Fallback page size.
	 */
	private function pageSize( WP_REST_Request $request, int $fallback ): int {
		$page_size = absint( $request->get_param( 'page_size' ) );

		if ( $page_size <= 0 ) {
			$page_size = $fallback;
		}

		return max( 1, min( self::MAX_PAGE_SIZE, $page_size ) );
	}
}
