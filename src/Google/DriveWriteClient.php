<?php
/**
 * Google Drive write client for app-created conversions.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use DocSyncWP\Auth\GoogleOAuthService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, finds, and trashes only files this plugin created through the optional `drive.file` scope.
 *
 * Every created file carries `appProperties.docsyncWpCreated = "1"`. The import
 * folder carries `appProperties.docsyncWpFolder = "imports"` instead, so it can
 * never be trashed. Nothing is ever deleted permanently.
 */
final class DriveWriteClient {
	public const GOOGLE_SLIDES_MIME_TYPE = 'application/vnd.google-apps.presentation';
	public const IMPORT_FOLDER_NAME      = 'Imported from WordPress';
	public const IMPORT_FOLDER_META_KEY  = '_docsync_wp_import_folder_id';
	public const APP_CREATED_PROPERTY    = 'docsyncWpCreated';
	public const APP_FOLDER_PROPERTY     = 'docsyncWpFolder';
	public const APP_SESSION_PROPERTY    = 'docsyncWpSession';
	public const IMPORT_FOLDER_VALUE     = 'imports';
	public const DOCX_MIME_TYPE          = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	public const PPTX_MIME_TYPE          = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
	public const MAX_UPLOAD_BYTES        = 26214400;

	private const API_BASE_URL            = 'https://www.googleapis.com/drive/v3';
	private const UPLOAD_URL              = 'https://www.googleapis.com/upload/drive/v3/files';
	private const FOLDER_MIME_TYPE        = 'application/vnd.google-apps.folder';
	private const HTML_MIME_TYPE          = 'text/html';
	private const FILE_FIELDS             = 'id,name,mimeType,webViewLink,version';
	private const GUARD_FIELDS            = 'id,mimeType,trashed,appProperties';
	private const REQUEST_TIMEOUT_SECONDS = 20;
	private const UPLOAD_TIMEOUT_SECONDS  = 120;
	private const FILE_ID_PATTERN         = '/^[A-Za-z0-9_-]{10,200}$/';
	private const SESSION_ID_PATTERN      = '/^[a-f0-9-]{36}$/';

	/**
	 * Allowed conversions: uploaded Office MIME type => Google editor MIME type.
	 */
	private const CONVERSIONS = array(
		self::DOCX_MIME_TYPE => DriveClient::GOOGLE_DOC_MIME_TYPE,
		self::PPTX_MIME_TYPE => self::GOOGLE_SLIDES_MIME_TYPE,
	);

	/**
	 * OAuth service.
	 *
	 * @var GoogleOAuthService
	 */
	private GoogleOAuthService $oauth;

	/**
	 * Constructor.
	 *
	 * @param GoogleOAuthService $oauth OAuth service.
	 */
	public function __construct( GoogleOAuthService $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * Whether the user granted the optional `drive.file` scope.
	 *
	 * @param int $user_id User ID.
	 */
	public function hasWriteScope( int $user_id ): bool {
		return $this->oauth->userHasDriveFileScope( $user_id );
	}

	/**
	 * Find or create My Drive / Imported from WordPress.
	 *
	 * The stored folder ID is verified with a fresh `files.get` before each use and
	 * recreated when it is missing, trashed, or no longer tagged as the import folder.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error Folder ID.
	 */
	public function ensureImportFolder( int $user_id ): string|WP_Error {
		$scope = $this->requireWriteScope( $user_id );

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$stored = get_user_meta( $user_id, self::IMPORT_FOLDER_META_KEY, true );

		if ( is_string( $stored ) && 1 === preg_match( self::FILE_ID_PATTERN, $stored ) ) {
			$folder = $this->getGuardMetadata( $user_id, $stored );

			if ( ! is_wp_error( $folder ) && $this->isImportFolder( $folder ) ) {
				return $stored;
			}

			if ( is_wp_error( $folder ) && ! $this->isResolvedError( $folder ) ) {
				return $folder;
			}
		}

		$folder_id = $this->findImportFolder( $user_id );

		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		if ( '' === $folder_id ) {
			$created = $this->requestJson(
				$user_id,
				'POST',
				add_query_arg(
					array(
						'fields'            => 'id,mimeType',
						'supportsAllDrives' => 'true',
					),
					self::API_BASE_URL . '/files'
				),
				array(
					'name'          => self::IMPORT_FOLDER_NAME,
					'mimeType'      => self::FOLDER_MIME_TYPE,
					'parents'       => array( 'root' ),
					'appProperties' => array( self::APP_FOLDER_PROPERTY => self::IMPORT_FOLDER_VALUE ),
				)
			);

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$folder_id = isset( $created['id'] ) && is_string( $created['id'] ) ? $created['id'] : '';

			if ( 1 !== preg_match( self::FILE_ID_PATTERN, $folder_id ) ) {
				return $this->badGoogleResponseError();
			}
		}

		update_user_meta( $user_id, self::IMPORT_FOLDER_META_KEY, $folder_id );

		return $folder_id;
	}

	/**
	 * Upload an Office file into the import folder and let Google convert it.
	 *
	 * The created file is tagged `docsyncWpCreated=1` and with its import session.
	 * If Google returns a file that is not the expected editor type, the file is
	 * trashed at once; a failed trash is reported in `data.googleTemporaries`.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $file_path   Local plaintext path of the upload.
	 * @param string $source_mime DOCX or PPTX MIME type.
	 * @param string $target_mime Google Docs or Google Slides MIME type.
	 * @param string $name        File name in Drive.
	 * @param string $session_id  Import session ID.
	 * @return array{fileId:string,name:string,mimeType:string,webViewLink:string,version:string}|WP_Error
	 */
	public function uploadForConversion( int $user_id, string $file_path, string $source_mime, string $target_mime, string $name, string $session_id ): array|WP_Error {
		if ( ! isset( self::CONVERSIONS[ $source_mime ] ) || self::CONVERSIONS[ $source_mime ] !== $target_mime ) {
			return new WP_Error(
				'docsync_wp_google_conversion_unsupported',
				__( 'Brasth Document Sync can only convert Word files to Google Docs and PowerPoint files to Google Slides.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( 1 !== preg_match( self::SESSION_ID_PATTERN, $session_id ) ) {
			return new WP_Error(
				'docsync_wp_import_session_not_found',
				__( 'Brasth Document Sync could not find this import session.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		$scope = $this->requireWriteScope( $user_id );

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$bytes = $this->readLocalFile( $file_path );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$folder_id = $this->ensureImportFolder( $user_id );

		if ( is_wp_error( $folder_id ) ) {
			return $folder_id;
		}

		$file = $this->resumableUpload(
			$user_id,
			array(
				'name'          => $this->sanitizeName( $name ),
				'mimeType'      => $target_mime,
				'parents'       => array( $folder_id ),
				'appProperties' => array(
					self::APP_CREATED_PROPERTY => '1',
					self::APP_SESSION_PROPERTY => $session_id,
				),
			),
			$bytes,
			$source_mime
		);

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return $this->assertCreatedType( $user_id, $file, $target_mime );
	}

	/**
	 * Create a Google Doc from rendered post HTML.
	 *
	 * @param int    $user_id          User ID.
	 * @param string $html             Sanitized post HTML.
	 * @param string $name             Doc name.
	 * @param string $parent_folder_id Destination folder; '' uses the import folder.
	 * @return array{fileId:string,name:string,mimeType:string,webViewLink:string,version:string}|WP_Error
	 */
	public function createDocumentFromHtml( int $user_id, string $html, string $name, string $parent_folder_id = '' ): array|WP_Error {
		$scope = $this->requireWriteScope( $user_id );

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		if ( '' === trim( $html ) || strlen( $html ) > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error(
				'docsync_wp_google_create_invalid',
				__( 'Brasth Document Sync could not create a Google Doc from empty or oversized content.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$parent_folder_id = trim( $parent_folder_id );

		if ( '' === $parent_folder_id ) {
			$parent_folder_id = $this->ensureImportFolder( $user_id );

			if ( is_wp_error( $parent_folder_id ) ) {
				return $parent_folder_id;
			}
		} elseif ( 'root' !== $parent_folder_id && 1 !== preg_match( self::FILE_ID_PATTERN, $parent_folder_id ) ) {
			return new WP_Error(
				'docsync_wp_invalid_drive_folder',
				__( 'Choose a valid Google Drive folder.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$file = $this->resumableUpload(
			$user_id,
			array(
				'name'          => $this->sanitizeName( $name ),
				'mimeType'      => DriveClient::GOOGLE_DOC_MIME_TYPE,
				'parents'       => array( $parent_folder_id ),
				'appProperties' => array( self::APP_CREATED_PROPERTY => '1' ),
			),
			$html,
			self::HTML_MIME_TYPE . '; charset=UTF-8'
		);

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return $this->assertCreatedType( $user_id, $file, DriveClient::GOOGLE_DOC_MIME_TYPE );
	}

	/**
	 * Move one app-created file to the Drive trash.
	 *
	 * A fresh `files.get` checks the app tag immediately before `files.update`.
	 * Already-trashed files return true. Missing files and files the plugin did not
	 * create return a WP_Error with `data.resolved = true`, so callers can stop
	 * retrying without ever trashing user originals or the import folder.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id Drive file ID.
	 * @return bool|WP_Error
	 */
	public function trashAppCreatedFile( int $user_id, string $file_id ): bool|WP_Error {
		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
			return $this->resolvedError(
				'docsync_wp_google_file_not_found',
				__( 'Google Drive could not find this file.', 'brasth-document-sync-for-google-docs' ),
				404
			);
		}

		$scope = $this->requireWriteScope( $user_id );

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$file = $this->getGuardMetadata( $user_id, $file_id );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! empty( $file['trashed'] ) ) {
			return true;
		}

		if ( ! $this->isAppCreatedFile( $file ) ) {
			return $this->resolvedError(
				'docsync_wp_google_file_not_app_created',
				__( 'Brasth Document Sync only moves files it created to the Google Drive trash.', 'brasth-document-sync-for-google-docs' ),
				409
			);
		}

		$trashed = $this->requestJson(
			$user_id,
			'PATCH',
			add_query_arg(
				array(
					'fields'            => 'id,trashed',
					'supportsAllDrives' => 'true',
				),
				self::API_BASE_URL . '/files/' . rawurlencode( $file_id )
			),
			array( 'trashed' => true )
		);

		if ( is_wp_error( $trashed ) ) {
			return $trashed;
		}

		return true;
	}

	/**
	 * Require the optional write scope before any Drive write.
	 *
	 * @param int $user_id User ID.
	 * @return bool|WP_Error
	 */
	private function requireWriteScope( int $user_id ): bool|WP_Error {
		if ( $this->hasWriteScope( $user_id ) ) {
			return true;
		}

		return $this->writeScopeRequiredError();
	}

	/**
	 * Look up an existing app-created import folder in My Drive.
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error Folder ID, or '' when none exists.
	 */
	private function findImportFolder( int $user_id ): string|WP_Error {
		$query    = "mimeType = '" . self::FOLDER_MIME_TYPE . "' and trashed = false and 'root' in parents and appProperties has { key='" . self::APP_FOLDER_PROPERTY . "' and value='" . self::IMPORT_FOLDER_VALUE . "' }";
		$response = $this->requestJson(
			$user_id,
			'GET',
			add_query_arg(
				array(
					'corpora'  => 'user',
					'fields'   => 'files(' . self::GUARD_FIELDS . ')',
					'orderBy'  => 'createdTime',
					'pageSize' => 10,
					'q'        => $query,
					'spaces'   => 'drive',
				),
				self::API_BASE_URL . '/files'
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['files'] ) || ! is_array( $response['files'] ) ) {
			return $this->badGoogleResponseError();
		}

		foreach ( $response['files'] as $file ) {
			if ( is_array( $file ) && $this->isImportFolder( $file ) && is_string( $file['id'] ?? null ) && 1 === preg_match( self::FILE_ID_PATTERN, $file['id'] ) ) {
				return $file['id'];
			}
		}

		return '';
	}

	/**
	 * Fresh metadata used by the folder and trash guards.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id File ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function getGuardMetadata( int $user_id, string $file_id ): array|WP_Error {
		$file = $this->requestJson(
			$user_id,
			'GET',
			add_query_arg(
				array(
					'fields'            => self::GUARD_FIELDS,
					'supportsAllDrives' => 'true',
				),
				self::API_BASE_URL . '/files/' . rawurlencode( $file_id )
			)
		);

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		if ( ! isset( $file['id'], $file['mimeType'] ) || ! is_string( $file['id'] ) || ! is_string( $file['mimeType'] ) ) {
			return $this->badGoogleResponseError();
		}

		return $file;
	}

	/**
	 * Whether metadata describes the live, app-created import folder.
	 *
	 * @param array<string,mixed> $file Guard metadata.
	 */
	private function isImportFolder( array $file ): bool {
		$properties = isset( $file['appProperties'] ) && is_array( $file['appProperties'] ) ? $file['appProperties'] : array();

		return self::FOLDER_MIME_TYPE === ( $file['mimeType'] ?? '' )
			&& empty( $file['trashed'] )
			&& self::IMPORT_FOLDER_VALUE === ( $properties[ self::APP_FOLDER_PROPERTY ] ?? '' );
	}

	/**
	 * Whether metadata describes a trashable app-created file (never a folder).
	 *
	 * @param array<string,mixed> $file Guard metadata.
	 */
	private function isAppCreatedFile( array $file ): bool {
		$properties = isset( $file['appProperties'] ) && is_array( $file['appProperties'] ) ? $file['appProperties'] : array();

		return self::FOLDER_MIME_TYPE !== ( $file['mimeType'] ?? '' )
			&& ! isset( $properties[ self::APP_FOLDER_PROPERTY ] )
			&& '1' === ( $properties[ self::APP_CREATED_PROPERTY ] ?? '' );
	}

	/**
	 * Verify a created file has the expected editor type.
	 *
	 * @param int                 $user_id     User ID.
	 * @param array<string,mixed> $file        Created file response.
	 * @param string              $target_mime Expected Google editor MIME type.
	 * @return array{fileId:string,name:string,mimeType:string,webViewLink:string,version:string}|WP_Error
	 */
	private function assertCreatedType( int $user_id, array $file, string $target_mime ): array|WP_Error {
		$file_id = isset( $file['id'] ) && is_string( $file['id'] ) ? $file['id'] : '';

		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
			return $this->badGoogleResponseError();
		}

		$created_mime = isset( $file['mimeType'] ) && is_string( $file['mimeType'] ) ? $file['mimeType'] : '';

		if ( $created_mime === $target_mime ) {
			return array(
				'fileId'      => $file_id,
				'name'        => sanitize_text_field( (string) ( $file['name'] ?? '' ) ),
				'mimeType'    => $target_mime,
				'webViewLink' => esc_url_raw( (string) ( $file['webViewLink'] ?? '' ) ),
				'version'     => sanitize_text_field( (string) ( $file['version'] ?? '' ) ),
			);
		}

		$trashed = $this->trashAppCreatedFile( $user_id, $file_id );

		return new WP_Error(
			'docsync_wp_google_conversion_failed',
			__( 'Google could not convert this file.', 'brasth-document-sync-for-google-docs' ),
			array(
				'status'            => 502,
				'googleTemporaries' => true === $trashed || ( is_wp_error( $trashed ) && $this->isResolvedError( $trashed ) ) ? array() : array( $file_id ),
			)
		);
	}

	/**
	 * Upload bytes with Drive's resumable protocol (single PUT after the session starts).
	 *
	 * @param int                 $user_id      User ID.
	 * @param array<string,mixed> $metadata     File metadata.
	 * @param string              $bytes        Content bytes.
	 * @param string              $content_type Content MIME type.
	 * @return array<string,mixed>|WP_Error Created file response.
	 */
	private function resumableUpload( int $user_id, array $metadata, string $bytes, string $content_type ): array|WP_Error {
		$access_token = $this->oauth->getAccessToken( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$session = wp_remote_post(
			add_query_arg(
				array(
					'uploadType'        => 'resumable',
					'fields'            => self::FILE_FIELDS,
					'supportsAllDrives' => 'true',
				),
				self::UPLOAD_URL
			),
			array(
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization'           => 'Bearer ' . $access_token,
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Type'   => $content_type,
					'X-Upload-Content-Length' => (string) strlen( $bytes ),
				),
				'body'    => (string) wp_json_encode( $metadata ),
			)
		);

		if ( is_wp_error( $session ) ) {
			return $this->transientGoogleFailureError();
		}

		$status = absint( wp_remote_retrieve_response_code( $session ) );

		if ( $status < 200 || $status >= 300 ) {
			return $this->mapGoogleError( $status, wp_remote_retrieve_body( $session ) );
		}

		$location = wp_remote_retrieve_header( $session, 'location' );
		$location = is_array( $location ) ? (string) reset( $location ) : (string) $location;

		if ( 'https' !== wp_parse_url( $location, PHP_URL_SCHEME ) || 'www.googleapis.com' !== wp_parse_url( $location, PHP_URL_HOST ) ) {
			return $this->badGoogleResponseError();
		}

		$response = wp_remote_request(
			$location,
			array(
				'method'  => 'PUT',
				'timeout' => self::UPLOAD_TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => $content_type,
				),
				'body'    => $bytes,
			)
		);

		return $this->decodeResponse( $response );
	}

	/**
	 * Perform an authenticated JSON Drive request.
	 *
	 * @param int                      $user_id User ID.
	 * @param string                   $method  HTTP method.
	 * @param string                   $url     Request URL.
	 * @param array<string,mixed>|null $body    Optional JSON body.
	 * @return array<string,mixed>|WP_Error
	 */
	private function requestJson( int $user_id, string $method, string $url, ?array $body = null ): array|WP_Error {
		$access_token = $this->oauth->getAccessToken( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT_SECONDS,
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=UTF-8';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		return $this->decodeResponse( wp_remote_request( $url, $args ) );
	}

	/**
	 * Decode a Drive HTTP response.
	 *
	 * @param array<string,mixed>|WP_Error $response HTTP response.
	 * @return array<string,mixed>|WP_Error
	 */
	private function decodeResponse( array|WP_Error $response ): array|WP_Error {
		if ( is_wp_error( $response ) ) {
			return $this->transientGoogleFailureError();
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return $this->mapGoogleError( $status, $body );
		}

		$data = json_decode( $body, true );

		return is_array( $data ) ? $data : $this->badGoogleResponseError();
	}

	/**
	 * Read a local upload through the WordPress direct filesystem.
	 *
	 * @param string $file_path Local path.
	 * @return string|WP_Error
	 */
	private function readLocalFile( string $file_path ): string|WP_Error {
		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		$filesystem = new \WP_Filesystem_Direct( null );
		$size       = '' !== $file_path && $filesystem->is_readable( $file_path ) ? absint( $filesystem->size( $file_path ) ) : 0;

		if ( $size <= 0 || $size > self::MAX_UPLOAD_BYTES ) {
			return new WP_Error(
				'docsync_wp_import_invalid_file',
				__( 'Brasth Document Sync could not read this upload for conversion.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$bytes = $filesystem->get_contents( $file_path );

		$read_size = is_string( $bytes ) ? strlen( $bytes ) : -1;

		if ( $read_size !== $size ) {
			return new WP_Error(
				'docsync_wp_import_invalid_file',
				__( 'Brasth Document Sync could not read this upload for conversion.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $bytes;
	}

	/**
	 * Sanitize a Drive file name.
	 *
	 * @param string $name Raw name.
	 */
	private function sanitizeName( string $name ): string {
		$name = trim( sanitize_text_field( $name ) );
		$name = '' === $name ? __( 'Untitled', 'brasth-document-sync-for-google-docs' ) : $name;

		return function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 255 ) : substr( $name, 0, 255 );
	}

	/**
	 * Map Drive error responses to stable plugin errors.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private function mapGoogleError( int $status, string $body ): WP_Error {
		$reason = $this->errorReason( $body );

		if ( 429 === $status || $status >= 500 || in_array( $reason, array( 'ratelimitexceeded', 'userratelimitexceeded' ), true ) ) {
			return $this->transientGoogleFailureError();
		}

		if ( 403 === $status && in_array( $reason, array( 'insufficientpermissions', 'insufficientscopes', 'access_token_scope_insufficient' ), true ) ) {
			return $this->writeScopeRequiredError();
		}

		if ( 403 === $status && 'storagequotaexceeded' === $reason ) {
			return new WP_Error(
				'docsync_wp_google_storage_quota',
				__( 'Your Google Drive is full. Free up space, then try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 507 )
			);
		}

		if ( 404 === $status ) {
			return $this->resolvedError(
				'docsync_wp_google_file_not_found',
				__( 'Google Drive could not find this file.', 'brasth-document-sync-for-google-docs' ),
				404
			);
		}

		if ( 401 === $status ) {
			return new WP_Error(
				'docsync_wp_google_reconnect_required',
				__( 'Reconnect Google Drive so Brasth Document Sync can create files in your Drive.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		if ( in_array( $status, array( 400, 403 ), true ) ) {
			return new WP_Error(
				'docsync_wp_access_denied',
				__( 'Google Drive refused this change. Check that your account can add files to this folder, then try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return $this->badGoogleResponseError();
	}

	/**
	 * Lowercased first Google error reason.
	 *
	 * @param string $body Response body.
	 */
	private function errorReason( string $body ): string {
		$data  = json_decode( $body, true );
		$error = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();

		if ( isset( $error['errors'][0]['reason'] ) && is_string( $error['errors'][0]['reason'] ) ) {
			return strtolower( $error['errors'][0]['reason'] );
		}

		if ( isset( $error['details'] ) && is_array( $error['details'] ) ) {
			foreach ( $error['details'] as $detail ) {
				if ( is_array( $detail ) && isset( $detail['reason'] ) && is_string( $detail['reason'] ) ) {
					return strtolower( $detail['reason'] );
				}
			}
		}

		return '';
	}

	/**
	 * Whether a trash or lookup error means the ID needs no further cleanup.
	 *
	 * @param WP_Error $error Error.
	 */
	private function isResolvedError( WP_Error $error ): bool {
		$data = $error->get_error_data();

		return is_array( $data ) && ! empty( $data['resolved'] );
	}

	/**
	 * Error that marks a cleanup ID as resolved.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function resolvedError( string $code, string $message, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status'   => $status,
				'resolved' => true,
			)
		);
	}

	/**
	 * Missing `drive.file` scope error with the reconnect hint.
	 */
	private function writeScopeRequiredError(): WP_Error {
		return new WP_Error(
			'docsync_wp_google_write_scope_required',
			__( 'Allow Brasth Document Sync to create files in your Google Drive, then try again.', 'brasth-document-sync-for-google-docs' ),
			array(
				'status'    => 403,
				'reconnect' => array( 'scopeSet' => 'driveFile' ),
			)
		);
	}

	/**
	 * Bad Google response error.
	 */
	private function badGoogleResponseError(): WP_Error {
		return new WP_Error(
			'docsync_wp_bad_google_response',
			__( 'Google returned an unexpected Drive response.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Transient Google failure error.
	 */
	private function transientGoogleFailureError(): WP_Error {
		return new WP_Error(
			'docsync_wp_google_transient_failure',
			__( 'Google Drive is temporarily unavailable. Try again shortly.', 'brasth-document-sync-for-google-docs' ),
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
	}
}
