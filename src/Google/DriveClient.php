<?php
/**
 * Google Drive API client.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use DocSyncWP\Auth\GoogleOAuthService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Google Drive metadata and exports Google Docs content.
 */
final class DriveClient {
	public const GOOGLE_DOC_MIME_TYPE = 'application/vnd.google-apps.document';

	private const API_BASE_URL             = 'https://www.googleapis.com/drive/v3';
	private const FOLDER_MIME_TYPE         = 'application/vnd.google-apps.folder';
	private const METADATA_FIELDS          = 'id,name,mimeType,modifiedTime,version,webViewLink,size,quotaBytesUsed,capabilities/canDownload';
	private const DOCUMENT_LIST_FIELDS     = 'nextPageToken,incompleteSearch,files(id,name,mimeType,modifiedTime,version,webViewLink,size,quotaBytesUsed,capabilities/canDownload)';
	private const DRIVE_ITEM_LIST_FIELDS   = 'nextPageToken,incompleteSearch,files(id,name,mimeType,modifiedTime,version,webViewLink,iconLink,size,quotaBytesUsed,capabilities/canDownload)';
	private const SHARED_DRIVE_LIST_FIELDS = 'nextPageToken,drives(id,name)';
	private const HTML_ZIP_MIME_TYPE       = 'application/zip';
	private const REQUEST_TIMEOUT_SECONDS  = 20;
	private const MAX_EXPORT_BYTES         = 10485760;
	private const LARGE_DOC_WARNING_BYTES  = 8388608;
	private const DEFAULT_LIST_PAGE_SIZE   = 20;
	private const MAX_LIST_PAGE_SIZE       = 50;

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
	 * Get metadata for a Google Docs file.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id Google Drive file ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getMetadata( int $user_id, string $file_id ): array|WP_Error {
		$response = $this->request(
			$user_id,
			add_query_arg(
				array(
					'fields'            => self::METADATA_FIELDS,
					'supportsAllDrives' => 'true',
				),
				self::API_BASE_URL . '/files/' . rawurlencode( $file_id )
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( self::GOOGLE_DOC_MIME_TYPE !== $response['mimeType'] ) {
			return new WP_Error(
				'docsync_wp_non_google_doc',
				__( 'Brasth Document Sync can only inspect Google Docs documents.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $this->formatDocumentMetadata( $response );
	}

	/**
	 * Get a Drive folder or Google Doc as a browser item.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id Drive file ID or `root`.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getDriveItem( int $user_id, string $file_id ): array|WP_Error {
		$file_id = trim( $file_id );

		if ( '' === $file_id || 'root' === $file_id ) {
			return array(
				'fileId'      => 'root',
				'name'        => __( 'My Drive', 'brasth-document-sync-for-google-docs' ),
				'mimeType'    => self::FOLDER_MIME_TYPE,
				'itemType'    => 'folder',
				'webViewLink' => '',
				'selectable'  => false,
			);
		}

		$response = $this->requestJson(
			$user_id,
			add_query_arg(
				array(
					'fields'            => 'id,name,mimeType,modifiedTime,version,webViewLink,iconLink,size,quotaBytesUsed,capabilities/canDownload',
					'supportsAllDrives' => 'true',
				),
				self::API_BASE_URL . '/files/' . rawurlencode( $file_id )
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! $this->hasDriveItemFields( $response ) ) {
			return $this->badGoogleResponseError();
		}

		$item = $this->formatDriveItemResponse( $response );

		if ( ! in_array( $item['mimeType'], array( self::FOLDER_MIME_TYPE, self::GOOGLE_DOC_MIME_TYPE ), true ) ) {
			return new WP_Error(
				'docsync_wp_unsupported_drive_item',
				__( 'Brasth Document Sync can only open Google Drive folders and Google Docs.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $item;
	}

	/**
	 * List shared drives visible to the connected Google account.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $page_token Optional Drive pagination token.
	 * @param int    $page_size  Requested page size.
	 * @return array{drives:array<int,array<string,string>>,nextPageToken:string}|WP_Error
	 */
	public function listSharedDrives( int $user_id, string $page_token = '', int $page_size = self::MAX_LIST_PAGE_SIZE ): array|WP_Error {
		$page_size = min( self::MAX_LIST_PAGE_SIZE, max( 1, $page_size ) );
		$args      = array(
			'fields'   => self::SHARED_DRIVE_LIST_FIELDS,
			'pageSize' => $page_size,
		);

		if ( '' !== $page_token ) {
			$args['pageToken'] = $page_token;
		}

		$response = $this->requestJson(
			$user_id,
			add_query_arg(
				$args,
				self::API_BASE_URL . '/drives'
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['drives'] ) || ! is_array( $response['drives'] ) ) {
			return $this->badGoogleResponseError();
		}

		$drives = array();

		foreach ( $response['drives'] as $drive ) {
			if (
				! is_array( $drive )
				|| ! isset( $drive['id'], $drive['name'] )
				|| ! is_scalar( $drive['id'] )
				|| ! is_scalar( $drive['name'] )
			) {
				continue;
			}

			$drives[] = array(
				'driveId' => sanitize_text_field( (string) $drive['id'] ),
				'name'    => sanitize_text_field( (string) $drive['name'] ),
			);
		}

		return array(
			'drives'        => $drives,
			'nextPageToken' => isset( $response['nextPageToken'] ) && is_scalar( $response['nextPageToken'] ) ? sanitize_text_field( (string) $response['nextPageToken'] ) : '',
		);
	}

	/**
	 * List folders and Google Docs in a Drive folder.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $folder_id  Current Drive folder ID.
	 * @param string $drive_id   Optional shared drive ID.
	 * @param string $search     Optional name search scoped to the folder.
	 * @param string $page_token Optional Drive pagination token.
	 * @param int    $page_size  Requested page size.
	 * @return array{items:array<int,array<string,mixed>>,nextPageToken:string,incompleteSearch:bool,folderId:string,driveId:string}|WP_Error
	 */
	public function listDriveItems( int $user_id, string $folder_id = 'root', string $drive_id = '', string $search = '', string $page_token = '', int $page_size = self::DEFAULT_LIST_PAGE_SIZE ): array|WP_Error {
		$page_size = min( self::MAX_LIST_PAGE_SIZE, max( 1, $page_size ) );
		$drive_id  = trim( $drive_id );
		$folder_id = '' === trim( $folder_id ) ? 'root' : trim( $folder_id );

		if ( '' !== $drive_id && 'root' === $folder_id ) {
			$folder_id = $drive_id;
		}

		$query  = "'" . $this->escapeDriveQueryValue( $folder_id ) . "' in parents and trashed = false and (mimeType = '" . self::FOLDER_MIME_TYPE . "' or mimeType = '" . self::GOOGLE_DOC_MIME_TYPE . "')";
		$search = trim( $search );

		if ( '' !== $search ) {
			$query .= " and name contains '" . $this->escapeDriveQueryValue( $search ) . "'";
		}

		$args = $this->driveListArgs( $drive_id );
		$args = array_merge(
			$args,
			array(
				'fields'   => self::DRIVE_ITEM_LIST_FIELDS,
				'orderBy'  => 'name_natural',
				'pageSize' => $page_size,
				'q'        => $query,
				'spaces'   => 'drive',
			)
		);

		if ( '' !== $page_token ) {
			$args['pageToken'] = $page_token;
		}

		$response = $this->requestJson(
			$user_id,
			add_query_arg(
				$args,
				self::API_BASE_URL . '/files'
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['files'] ) || ! is_array( $response['files'] ) ) {
			return $this->badGoogleResponseError();
		}

		$items = array();

		foreach ( $response['files'] as $file ) {
			if ( ! is_array( $file ) || ! $this->hasDriveItemFields( $file ) ) {
				continue;
			}

			$item = $this->formatDriveItemResponse( $file );

			if ( ! in_array( $item['mimeType'], array( self::FOLDER_MIME_TYPE, self::GOOGLE_DOC_MIME_TYPE ), true ) ) {
				continue;
			}

			$items[] = $item;
		}

		$this->sortDriveItems( $items );

		return array(
			'items'            => $items,
			'nextPageToken'    => isset( $response['nextPageToken'] ) && is_scalar( $response['nextPageToken'] ) ? sanitize_text_field( (string) $response['nextPageToken'] ) : '',
			'incompleteSearch' => ! empty( $response['incompleteSearch'] ),
			'folderId'         => sanitize_text_field( $folder_id ),
			'driveId'          => sanitize_text_field( $drive_id ),
		);
	}

	/**
	 * List Google Docs visible to the connected Google account.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $search     Optional name search.
	 * @param string $page_token Optional Drive pagination token.
	 * @param int    $page_size  Requested page size.
	 * @return array{documents:array<int,array<string,mixed>>,nextPageToken:string,incompleteSearch:bool}|WP_Error
	 */
	public function listGoogleDocs( int $user_id, string $search = '', string $page_token = '', int $page_size = self::DEFAULT_LIST_PAGE_SIZE ): array|WP_Error {
		$page_size = min( self::MAX_LIST_PAGE_SIZE, max( 1, $page_size ) );
		$query     = "mimeType = '" . self::GOOGLE_DOC_MIME_TYPE . "' and trashed = false";
		$search    = trim( $search );

		if ( '' !== $search ) {
			$query .= " and name contains '" . $this->escapeDriveQueryValue( $search ) . "'";
		}

		$args = array(
			'fields'   => self::DOCUMENT_LIST_FIELDS,
			'orderBy'  => 'modifiedTime desc,name',
			'pageSize' => $page_size,
			'q'        => $query,
			'spaces'   => 'drive',
		);

		if ( '' !== $page_token ) {
			$args['pageToken'] = $page_token;
		}

		$response = $this->requestJson(
			$user_id,
			add_query_arg(
				$args,
				self::API_BASE_URL . '/files'
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['files'] ) || ! is_array( $response['files'] ) ) {
			return $this->badGoogleResponseError();
		}

		$documents = array();

		foreach ( $response['files'] as $file ) {
			if ( ! is_array( $file ) || ! $this->hasMetadataFields( $file ) ) {
				continue;
			}

			$metadata = $this->formatMetadataResponse( $file );

			if ( self::GOOGLE_DOC_MIME_TYPE !== $metadata['mimeType'] ) {
				continue;
			}

			$documents[] = $this->formatDocumentMetadata( $metadata );
		}

		return array(
			'documents'        => $documents,
			'nextPageToken'    => isset( $response['nextPageToken'] ) && is_scalar( $response['nextPageToken'] ) ? sanitize_text_field( (string) $response['nextPageToken'] ) : '',
			'incompleteSearch' => ! empty( $response['incompleteSearch'] ),
		);
	}

	/**
	 * Export a Google Docs file as an HTML ZIP package.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id Google Drive file ID.
	 * @return string|WP_Error
	 */
	public function exportHtmlZip( int $user_id, string $file_id ): string|WP_Error {
		return $this->exportFile( $user_id, $file_id, self::HTML_ZIP_MIME_TYPE );
	}

	/**
	 * Export a Google Docs file.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $file_id   Google Drive file ID.
	 * @param string $mime_type Export MIME type.
	 * @return string|WP_Error
	 */
	private function exportFile( int $user_id, string $file_id, string $mime_type ): string|WP_Error {
		$access_token = $this->oauth->getAccessToken( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'mimeType' => $mime_type,
				),
				self::API_BASE_URL . '/files/' . rawurlencode( $file_id ) . '/export'
			),
			array(
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => $mime_type,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transientGoogleFailureError();
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status >= 200 && $status < 300 ) {
			if ( strlen( $body ) > self::MAX_EXPORT_BYTES ) {
				return $this->exportTooLargeError();
			}

			return $body;
		}

		return $this->mapGoogleError( $status, $body );
	}

	/**
	 * Perform an authenticated JSON request.
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     Request URL.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( int $user_id, string $url ): array|WP_Error {
		$data = $this->requestJson( $user_id, $url );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( ! $this->hasMetadataFields( $data ) ) {
			return $this->badGoogleResponseError();
		}

		return $this->formatMetadataResponse( $data );
	}

	/**
	 * Perform an authenticated JSON request.
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     Request URL.
	 * @return array<string,mixed>|WP_Error
	 */
	private function requestJson( int $user_id, string $url ): array|WP_Error {
		$access_token = $this->oauth->getAccessToken( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transientGoogleFailureError();
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return $this->mapGoogleError( $status, $body );
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return $this->badGoogleResponseError();
		}

		return $data;
	}

	/**
	 * Format a Drive metadata object for internal use.
	 *
	 * @param array<mixed> $data Metadata response.
	 * @return array<string,mixed>
	 */
	private function formatMetadataResponse( array $data ): array {
		return array(
			'id'                => sanitize_text_field( (string) $data['id'] ),
			'name'              => sanitize_text_field( (string) $data['name'] ),
			'mimeType'          => sanitize_text_field( (string) $data['mimeType'] ),
			'modifiedTime'      => sanitize_text_field( (string) $data['modifiedTime'] ),
			'version'           => sanitize_text_field( (string) $data['version'] ),
			'webViewLink'       => esc_url_raw( (string) $data['webViewLink'] ),
			'syncCompatibility' => $this->buildSyncCompatibility( $data ),
		);
	}

	/**
	 * Format a Drive file as a browser item.
	 *
	 * @param array<mixed> $data Drive file response.
	 * @return array<string,mixed>
	 */
	private function formatDriveItemResponse( array $data ): array {
		$mime_type = sanitize_text_field( (string) $data['mimeType'] );
		$is_folder = self::FOLDER_MIME_TYPE === $mime_type;
		$item      = array(
			'fileId'       => sanitize_text_field( (string) $data['id'] ),
			'name'         => sanitize_text_field( (string) $data['name'] ),
			'mimeType'     => $mime_type,
			'itemType'     => $is_folder ? 'folder' : 'document',
			'modifiedTime' => sanitize_text_field( (string) $data['modifiedTime'] ),
			'webViewLink'  => isset( $data['webViewLink'] ) && is_scalar( $data['webViewLink'] ) ? esc_url_raw( (string) $data['webViewLink'] ) : '',
			'selectable'   => ! $is_folder && false !== $this->getCanDownload( $data ),
		);

		if ( isset( $data['iconLink'] ) && is_scalar( $data['iconLink'] ) ) {
			$item['iconLink'] = esc_url_raw( (string) $data['iconLink'] );
		}

		if ( isset( $data['version'] ) && is_scalar( $data['version'] ) ) {
			$item['version'] = sanitize_text_field( (string) $data['version'] );
		}

		if ( ! $is_folder ) {
			$item['syncCompatibility'] = $this->buildSyncCompatibility( $data );
		}

		return $item;
	}

	/**
	 * Format Drive metadata for REST responses.
	 *
	 * @param array<string,mixed> $metadata Internal metadata.
	 * @return array<string,mixed>
	 */
	private function formatDocumentMetadata( array $metadata ): array {
		return array(
			'fileId'            => $metadata['id'],
			'name'              => $metadata['name'],
			'mimeType'          => $metadata['mimeType'],
			'modifiedTime'      => $metadata['modifiedTime'],
			'version'           => $metadata['version'],
			'webViewLink'       => $metadata['webViewLink'],
			'syncCompatibility' => $metadata['syncCompatibility'],
		);
	}

	/**
	 * Build document sync compatibility metadata.
	 *
	 * @param array<string,mixed> $data Drive file metadata.
	 * @return array{canDownload:bool|null,sizeBytes:int|null,quotaBytesUsed:int|null,warningCode:string|null,warningMessage:string}
	 */
	private function buildSyncCompatibility( array $data ): array {
		$can_download     = $this->getCanDownload( $data );
		$size_bytes       = $this->metadataInteger( $data['size'] ?? null );
		$quota_bytes_used = $this->metadataInteger( $data['quotaBytesUsed'] ?? null );
		$warning_code     = null;
		$warning_message  = '';

		if ( false === $can_download ) {
			$warning_code    = 'download_blocked';
			$warning_message = __( 'Google says this Doc cannot be downloaded by the connected account. Adjust sharing or choose another Doc before linking.', 'brasth-document-sync-for-google-docs' );
		} elseif (
			( null !== $size_bytes && $size_bytes >= self::LARGE_DOC_WARNING_BYTES )
			|| ( null !== $quota_bytes_used && $quota_bytes_used >= self::LARGE_DOC_WARNING_BYTES )
		) {
			$warning_code    = 'large_doc_possible';
			$warning_message = __( 'This Doc may exceed Google\'s 10 MB export limit. Brasth Document Sync will use the large-doc fallback if needed.', 'brasth-document-sync-for-google-docs' );
		}

		return array(
			'canDownload'    => $can_download,
			'sizeBytes'      => $size_bytes,
			'quotaBytesUsed' => $quota_bytes_used,
			'warningCode'    => $warning_code,
			'warningMessage' => $warning_message,
		);
	}

	/**
	 * Read the Drive canDownload capability.
	 *
	 * @param array<string,mixed> $data Drive file metadata.
	 */
	private function getCanDownload( array $data ): ?bool {
		if ( ! isset( $data['capabilities'] ) || ! is_array( $data['capabilities'] ) ) {
			return null;
		}

		return isset( $data['capabilities']['canDownload'] ) && is_bool( $data['capabilities']['canDownload'] )
			? $data['capabilities']['canDownload']
			: null;
	}

	/**
	 * Parse a non-negative Drive metadata integer.
	 *
	 * @param mixed $value Raw metadata value.
	 */
	private function metadataInteger( mixed $value ): ?int {
		if ( ! is_scalar( $value ) ) {
			return null;
		}

		$value = trim( (string) $value );

		return '' !== $value && ctype_digit( $value ) ? (int) $value : null;
	}

	/**
	 * Escape a user search term for the Drive query language.
	 *
	 * @param string $value Raw query value.
	 */
	private function escapeDriveQueryValue( string $value ): string {
		return str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), $value );
	}

	/**
	 * Build files.list arguments for My Drive or one shared drive.
	 *
	 * @param string $drive_id Optional shared drive ID.
	 * @return array<string,string>
	 */
	private function driveListArgs( string $drive_id ): array {
		if ( '' === $drive_id ) {
			return array(
				'corpora' => 'user',
			);
		}

		return array(
			'corpora'                   => 'drive',
			'driveId'                   => $drive_id,
			'includeItemsFromAllDrives' => 'true',
			'supportsAllDrives'         => 'true',
		);
	}

	/**
	 * Map Google API error responses to stable REST errors.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	private function mapGoogleError( int $status, string $body ): WP_Error {
		if ( 429 === $status || $status >= 500 ) {
			return $this->transientGoogleFailureError();
		}

		if ( $this->isExportTooLargeResponse( $body ) ) {
			return $this->exportTooLargeError();
		}

		if ( in_array( $status, array( 401, 403, 404 ), true ) ) {
			return new WP_Error(
				'docsync_wp_access_denied',
				__( 'Brasth Document Sync cannot access this Google Doc. Reconnect Google Drive or choose a document your account can open, then try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return $this->badGoogleResponseError();
	}

	/**
	 * Whether a decoded metadata response has all required fields.
	 *
	 * @param array<mixed> $data Metadata response.
	 */
	private function hasMetadataFields( array $data ): bool {
		foreach ( array( 'id', 'name', 'mimeType', 'modifiedTime', 'version', 'webViewLink' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_scalar( $data[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a Drive item response has all required browser fields.
	 *
	 * @param array<mixed> $data Drive file response.
	 */
	private function hasDriveItemFields( array $data ): bool {
		foreach ( array( 'id', 'name', 'mimeType', 'modifiedTime' ) as $field ) {
			if ( ! isset( $data[ $field ] ) || ! is_scalar( $data[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sort Drive browser rows with folders first, then by natural name.
	 *
	 * @param array<int,array<string,mixed>> $items Drive items.
	 */
	private function sortDriveItems( array &$items ): void {
		usort(
			$items,
			static function ( array $first, array $second ): int {
				if ( $first['itemType'] !== $second['itemType'] ) {
					return 'folder' === $first['itemType'] ? -1 : 1;
				}

				$name_compare = strnatcasecmp( (string) $first['name'], (string) $second['name'] );

				if ( 0 !== $name_compare ) {
					return $name_compare;
				}

				return strcmp( (string) $first['fileId'], (string) $second['fileId'] );
			}
		);
	}

	/**
	 * Whether a Google export response indicates the Drive export size limit.
	 *
	 * @param string $body Response body.
	 */
	private function isExportTooLargeResponse( string $body ): bool {
		$data = json_decode( $body, true );

		if ( is_array( $data ) ) {
			$encoded = wp_json_encode( $data );
			$body    = is_string( $encoded ) ? $encoded : $body;
		}

		$body = strtolower( $body );

		return str_contains( $body, 'exportsize' )
			|| str_contains( $body, 'too large' )
			|| str_contains( $body, 'file too large' );
	}

	/**
	 * Too-large export error.
	 */
	private function exportTooLargeError(): WP_Error {
		return new WP_Error(
			'docsync_wp_export_too_large',
			__( 'This Google Doc is too large to export for Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 413 )
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
			array( 'status' => 503 )
		);
	}
}
