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
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles Google document REST endpoints.
 */
final class DocumentController {
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
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/drive/items',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listDriveItems' ),
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/documents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listDocuments' ),
				'permission_callback' => array( $this, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/documents/inspect',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'inspectDocument' ),
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
	 * List shared drives available to the connected Google account.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listSharedDrives( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page_size = absint( $request->get_param( 'page_size' ) );

		if ( $page_size <= 0 ) {
			$page_size = 50;
		}

		$drives = $this->drive_client->listSharedDrives(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
			$page_size
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
		$page_size = absint( $request->get_param( 'page_size' ) );

		if ( $page_size <= 0 ) {
			$page_size = 20;
		}

		$items = $this->drive_client->listDriveItems(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'folder_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'drive_id' ) ),
			sanitize_text_field( (string) $request->get_param( 'search' ) ),
			sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
			$page_size
		);

		if ( is_wp_error( $items ) ) {
			return $items;
		}

		return rest_ensure_response( $items );
	}

	/**
	 * List Google Docs available to the connected account.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listDocuments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$page_size = absint( $request->get_param( 'page_size' ) );

		if ( $page_size <= 0 ) {
			$page_size = 20;
		}

		$documents = $this->drive_client->listGoogleDocs(
			get_current_user_id(),
			sanitize_text_field( (string) $request->get_param( 'search' ) ),
			sanitize_text_field( (string) $request->get_param( 'page_token' ) ),
			$page_size
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
				__( 'DocSync WP document inspection requires a JSON object.', 'docsync-wp' ),
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
}
