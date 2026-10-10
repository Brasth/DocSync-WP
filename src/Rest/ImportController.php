<?php
/**
 * REST controller for private DOCX, PPTX, and PDF import sessions.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Import\ImportCommitter;
use DocSyncWP\Import\ImportService;
use DocSyncWP\Import\ImportSessionRepository;
use DocSyncWP\Import\UploadValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Handles `/imports/sessions*` routes.
 *
 * Every route uses the shared authenticated-REST permission callback and
 * additionally requires `upload_files`. Session and asset ownership is
 * checked by ImportService; other users get 404. Private assets are streamed
 * with `Cache-Control: private, no-store` and are never exposed as public
 * URLs.
 */
final class ImportController {
	private const SESSION_ROUTE = '/imports/sessions/(?P<sessionId>[a-f0-9-]{36})';
	private const FILE_ROUTE    = self::SESSION_ROUTE . '/files/(?P<fileId>f_[a-f0-9]{16})';
	private const RESERVED_ARGS = array( '_locale', '_wpnonce', '_envelope', '_fields', '_method', '_embed' );

	/**
	 * Import service.
	 *
	 * @var ImportService
	 */
	private ImportService $imports;

	/**
	 * Import committer.
	 *
	 * @var ImportCommitter
	 */
	private ImportCommitter $committer;

	/**
	 * Constructor.
	 *
	 * @param ImportService   $imports   Import service.
	 * @param ImportCommitter $committer Import committer.
	 */
	public function __construct( ImportService $imports, ImportCommitter $committer ) {
		$this->imports   = $imports;
		$this->committer = $committer;
	}

	/**
	 * Register import routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		$permission = array( RestPermissions::class, 'canUseAuthenticatedRest' );

		register_rest_route(
			$rest_namespace,
			'/imports/sessions',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listSessions' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createSession' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::SESSION_ROUTE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getSession' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteSession' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::SESSION_ROUTE . '/files',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'addFiles' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$rest_namespace,
			self::FILE_ROUTE . '/options',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'updateFileOptions' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$rest_namespace,
			self::FILE_ROUTE . '/preview',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getFilePreview' ),
				'permission_callback' => $permission,
			)
		);

		register_rest_route(
			$rest_namespace,
			self::FILE_ROUTE . '/assets/(?P<assetId>a_[a-f0-9]{16})',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getFileAsset' ),
					'permission_callback' => $permission,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'putFileAsset' ),
					'permission_callback' => $permission,
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			self::SESSION_ROUTE . '/commit',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'commitSession' ),
				'permission_callback' => $permission,
			)
		);
	}

	/**
	 * List the caller's open sessions.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listSessions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return new WP_REST_Response( array( 'sessions' => $this->imports->listSessions( get_current_user_id() ) ), 200 );
	}

	/**
	 * Create a session.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createSession( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return $this->respond( $this->imports->createSession( get_current_user_id() ), 201 );
	}

	/**
	 * Read one session.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getSession( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return $this->respond( $this->imports->getSession( $this->sessionId( $request ), get_current_user_id() ), 200 );
	}

	/**
	 * Cancel a session.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deleteSession( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return $this->respond( $this->imports->cancelSession( $this->sessionId( $request ), get_current_user_id() ), 200 );
	}

	/**
	 * Upload files (multipart field `files[]`).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function addFiles( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$uploads = $this->normalizeUploads( $request->get_file_params() );

		if ( null === $uploads ) {
			return new WP_Error(
				'docsync_wp_import_invalid_upload',
				__( 'Upload between 1 and 20 files in the files[] field.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $this->respond( $this->imports->addFiles( $this->sessionId( $request ), get_current_user_id(), $uploads ), 201 );
	}

	/**
	 * Update per-file options.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateFileOptions( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array( 'title', 'target', 'layoutPreset', 'docx', 'pptx', 'pdf' ) );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return $this->respond(
			$this->imports->updateOptions( $this->sessionId( $request ), $this->fileId( $request ), get_current_user_id(), $this->bodyParams( $request ) ),
			200
		);
	}

	/**
	 * Read a file's canonical document and rendered preview.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getFilePreview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		return $this->respond( $this->imports->getPreview( $this->sessionId( $request ), $this->fileId( $request ), get_current_user_id() ), 200 );
	}

	/**
	 * Stream a private asset to its owner.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getFileAsset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array() );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$asset = $this->imports->readAsset( $this->sessionId( $request ), $this->fileId( $request ), (string) $request->get_param( 'assetId' ), get_current_user_id() );

		if ( is_wp_error( $asset ) ) {
			return $asset;
		}

		$bytes    = $asset['bytes'];
		$response = new WP_REST_Response( null, 200 );

		$response->header( 'Content-Type', $asset['mimeType'] );
		$response->header( 'Content-Length', (string) strlen( $bytes ) );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'Content-Disposition', 'inline' );

		$stream = static function ( $served, $result, $served_request ) use ( &$stream, $request, $bytes ) {
			if ( $served_request !== $request ) {
				return $served;
			}

			remove_filter( 'rest_pre_serve_request', $stream, 10 );
			file_put_contents( 'php://output', $bytes );

			return true;
		};

		add_filter( 'rest_pre_serve_request', $stream, 10, 3 );

		return $response;
	}

	/**
	 * Accept a browser-rendered PDF page or crop PNG.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function putFileAsset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array(), false );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$content_type = strtolower( trim( (string) strtok( (string) $request->get_header( 'Content-Type' ), ';' ) ) );
		$body         = (string) $request->get_body();

		if ( 'image/png' !== $content_type || '' === $body || strlen( $body ) > UploadValidator::MAX_PNG_BYTES ) {
			return new WP_Error(
				'docsync_wp_import_invalid_png',
				__( 'Send the rendered page as a PNG image of at most 8 MB.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $this->respond(
			$this->imports->storeRenderedAsset( $this->sessionId( $request ), $this->fileId( $request ), (string) $request->get_param( 'assetId' ), get_current_user_id(), $body ),
			200
		);
	}

	/**
	 * Commit a session; the work finishes asynchronously.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function commitSession( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$denied = $this->guard( $request, array( 'idempotencyKey', 'files' ) );

		if ( is_wp_error( $denied ) ) {
			return $denied;
		}

		$params  = $this->bodyParams( $request );
		$session = $this->committer->commit(
			$this->sessionId( $request ),
			get_current_user_id(),
			isset( $params['files'] ) && is_array( $params['files'] ) ? $params['files'] : array(),
			isset( $params['idempotencyKey'] ) && is_string( $params['idempotencyKey'] ) ? $params['idempotencyKey'] : ''
		);

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		return new WP_REST_Response( $session, ImportSessionRepository::STATUS_COMMITTED === $session['status'] ? 200 : 202 );
	}

	/**
	 * Require `upload_files` and reject unknown request fields.
	 *
	 * @param WP_REST_Request   $request      REST request.
	 * @param array<int,string> $allowed_keys Allowed body keys.
	 * @param bool              $check_body   Whether to inspect the body for fields.
	 * @return true|WP_Error
	 */
	private function guard( WP_REST_Request $request, array $allowed_keys, bool $check_body = true ): bool|WP_Error {
		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'docsync_wp_import_forbidden',
				__( 'You need permission to upload files to import documents.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		$fields = array_keys( (array) $request->get_query_params() );

		if ( $check_body ) {
			$fields = array_merge( $fields, array_keys( $this->bodyParams( $request ) ) );
		}

		$unknown = array_diff( $fields, array_merge( $allowed_keys, self::RESERVED_ARGS ) );

		if ( array() !== $unknown ) {
			return new WP_Error(
				'docsync_wp_import_invalid_request',
				__( 'Brasth Document Sync received unsupported import request fields.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * JSON (or form) body parameters.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	private function bodyParams( WP_REST_Request $request ): array {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Normalize the `files` upload field into a list of single-file entries.
	 *
	 * @param array<string,mixed> $file_params Request file params.
	 * @return array<int,array<string,mixed>>|null
	 */
	private function normalizeUploads( array $file_params ): ?array {
		if ( array() !== array_diff( array_keys( $file_params ), array( 'files' ) ) ) {
			return null;
		}

		$field = $file_params['files'] ?? null;

		if ( ! is_array( $field ) || ! isset( $field['name'], $field['tmp_name'], $field['error'] ) ) {
			return null;
		}

		if ( ! is_array( $field['name'] ) ) {
			$field = array_map(
				static function ( $value ): array {
					return array( $value );
				},
				$field
			);
		}

		$uploads = array();

		foreach ( array_keys( (array) $field['name'] ) as $index ) {
			$uploads[] = array(
				'name'     => (string) ( $field['name'][ $index ] ?? '' ),
				'type'     => (string) ( $field['type'][ $index ] ?? '' ),
				'tmp_name' => (string) ( $field['tmp_name'][ $index ] ?? '' ),
				'error'    => (int) ( $field['error'][ $index ] ?? UPLOAD_ERR_NO_FILE ),
				'size'     => (int) ( $field['size'][ $index ] ?? 0 ),
			);
		}

		return array() === $uploads || count( $uploads ) > UploadValidator::MAX_FILES ? null : $uploads;
	}

	/**
	 * Session ID route parameter.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function sessionId( WP_REST_Request $request ): string {
		return (string) $request->get_param( 'sessionId' );
	}

	/**
	 * File ID route parameter.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	private function fileId( WP_REST_Request $request ): string {
		return (string) $request->get_param( 'fileId' );
	}

	/**
	 * Wrap a service result.
	 *
	 * @param array<string,mixed>|WP_Error $result Result.
	 * @param int                          $status Success status.
	 * @return WP_REST_Response|WP_Error
	 */
	private function respond( array|WP_Error $result, int $status ): WP_REST_Response|WP_Error {
		return is_wp_error( $result ) ? $result : new WP_REST_Response( $result, $status );
	}
}
