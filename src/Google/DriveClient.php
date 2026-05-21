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

	private const API_BASE_URL            = 'https://www.googleapis.com/drive/v3';
	private const FOLDER_MIME_TYPE        = 'application/vnd.google-apps.folder';
	private const METADATA_FIELDS         = 'id,name,mimeType,modifiedTime,version,webViewLink';
	private const DOCUMENT_LIST_FIELDS    = 'nextPageToken,incompleteSearch,files(id,name,mimeType,modifiedTime,version,webViewLink)';
	private const DRIVE_ITEM_LIST_FIELDS  = 'nextPageToken,incompleteSearch,files(id,name,mimeType,modifiedTime,version,webViewLink,iconLink)';
	private const HTML_ZIP_MIME_TYPE      = 'application/zip';
	private const MARKDOWN_MIME_TYPE      = 'text/markdown';
	private const REQUEST_TIMEOUT_SECONDS = 20;
	private const MAX_EXPORT_BYTES        = 10485760;
	private const DEFAULT_LIST_PAGE_SIZE  = 20;
	private const MAX_LIST_PAGE_SIZE      = 50;

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
	 * @return array<string,string>|WP_Error
	 */
	public function getMetadata( int $user_id, string $file_id ): array|WP_Error {
		$response = $this->request(
			$user_id,
			add_query_arg(
				array(
					'fields' => self::METADATA_FIELDS,
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
				__( 'DocSync WP can only inspect Google Docs documents.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'fileId'       => $response['id'],
			'name'         => $response['name'],
			'mimeType'     => $response['mimeType'],
			'modifiedTime' => $response['modifiedTime'],
			'version'      => $response['version'],
			'webViewLink'  => $response['webViewLink'],
		);
	}

	/**
	 * List folders and Google Docs in a Drive folder.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $folder_id  Current Drive folder ID.
	 * @param string $search     Optional name search scoped to the folder.
	 * @param string $page_token Optional Drive pagination token.
	 * @param int    $page_size  Requested page size.
	 * @return array{items:array<int,array<string,mixed>>,nextPageToken:string,incompleteSearch:bool,folderId:string}|WP_Error
	 */
	public function listDriveItems( int $user_id, string $folder_id = 'root', string $search = '', string $page_token = '', int $page_size = self::DEFAULT_LIST_PAGE_SIZE ): array|WP_Error {
		$page_size = min( self::MAX_LIST_PAGE_SIZE, max( 1, $page_size ) );
		$folder_id = '' === trim( $folder_id ) ? 'root' : trim( $folder_id );
		$query     = "'" . $this->escapeDriveQueryValue( $folder_id ) . "' in parents and trashed = false and (mimeType = '" . self::FOLDER_MIME_TYPE . "' or mimeType = '" . self::GOOGLE_DOC_MIME_TYPE . "')";
		$search    = trim( $search );

		if ( '' !== $search ) {
			$query .= " and name contains '" . $this->escapeDriveQueryValue( $search ) . "'";
		}

		$args = array(
			'corpora'  => 'user',
			'fields'   => self::DRIVE_ITEM_LIST_FIELDS,
			'orderBy'  => 'name_natural',
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
		);
	}

	/**
	 * List Google Docs visible to the connected Google account.
	 *
	 * @param int    $user_id    User ID.
	 * @param string $search     Optional name search.
	 * @param string $page_token Optional Drive pagination token.
	 * @param int    $page_size  Requested page size.
	 * @return array{documents:array<int,array<string,string>>,nextPageToken:string,incompleteSearch:bool}|WP_Error
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

			$documents[] = array(
				'fileId'       => $metadata['id'],
				'name'         => $metadata['name'],
				'mimeType'     => $metadata['mimeType'],
				'modifiedTime' => $metadata['modifiedTime'],
				'version'      => $metadata['version'],
				'webViewLink'  => $metadata['webViewLink'],
			);
		}

		return array(
			'documents'        => $documents,
			'nextPageToken'    => isset( $response['nextPageToken'] ) && is_scalar( $response['nextPageToken'] ) ? sanitize_text_field( (string) $response['nextPageToken'] ) : '',
			'incompleteSearch' => ! empty( $response['incompleteSearch'] ),
		);
	}

	/**
	 * Export a Google Docs file as Markdown.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id Google Drive file ID.
	 * @return string|WP_Error
	 */
	public function exportMarkdown( int $user_id, string $file_id ): string|WP_Error {
		return $this->exportFile( $user_id, $file_id, self::MARKDOWN_MIME_TYPE );
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
	 * @return array{id:string,name:string,mimeType:string,modifiedTime:string,version:string,webViewLink:string}|WP_Error
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
	 * @return array{id:string,name:string,mimeType:string,modifiedTime:string,version:string,webViewLink:string}
	 */
	private function formatMetadataResponse( array $data ): array {
		return array(
			'id'           => sanitize_text_field( (string) $data['id'] ),
			'name'         => sanitize_text_field( (string) $data['name'] ),
			'mimeType'     => sanitize_text_field( (string) $data['mimeType'] ),
			'modifiedTime' => sanitize_text_field( (string) $data['modifiedTime'] ),
			'version'      => sanitize_text_field( (string) $data['version'] ),
			'webViewLink'  => esc_url_raw( (string) $data['webViewLink'] ),
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
			'selectable'   => ! $is_folder,
		);

		if ( isset( $data['iconLink'] ) && is_scalar( $data['iconLink'] ) ) {
			$item['iconLink'] = esc_url_raw( (string) $data['iconLink'] );
		}

		if ( isset( $data['version'] ) && is_scalar( $data['version'] ) ) {
			$item['version'] = sanitize_text_field( (string) $data['version'] );
		}

		return $item;
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
				__( 'DocSync WP cannot access this Google Doc. Reconnect Google Drive or choose a document your account can open, then try again.', 'docsync-wp' ),
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
			__( 'This Google Doc is too large to export for DocSync WP.', 'docsync-wp' ),
			array( 'status' => 413 )
		);
	}

	/**
	 * Bad Google response error.
	 */
	private function badGoogleResponseError(): WP_Error {
		return new WP_Error(
			'docsync_wp_bad_google_response',
			__( 'Google returned an unexpected Drive response.', 'docsync-wp' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Transient Google failure error.
	 */
	private function transientGoogleFailureError(): WP_Error {
		return new WP_Error(
			'docsync_wp_google_transient_failure',
			__( 'Google Drive is temporarily unavailable. Try again shortly.', 'docsync-wp' ),
			array( 'status' => 503 )
		);
	}
}
