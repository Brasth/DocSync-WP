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
	private const METADATA_FIELDS         = 'id,name,mimeType,modifiedTime,version,webViewLink';
	private const MARKDOWN_MIME_TYPE      = 'text/markdown';
	private const REQUEST_TIMEOUT_SECONDS = 20;
	private const MAX_EXPORT_BYTES        = 10485760;

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
	 * Export a Google Docs file as Markdown.
	 *
	 * @param int    $user_id User ID.
	 * @param string $file_id Google Drive file ID.
	 * @return string|WP_Error
	 */
	public function exportMarkdown( int $user_id, string $file_id ): string|WP_Error {
		$access_token = $this->oauth->getAccessToken( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'mimeType' => self::MARKDOWN_MIME_TYPE,
				),
				self::API_BASE_URL . '/files/' . rawurlencode( $file_id ) . '/export'
			),
			array(
				'timeout' => self::REQUEST_TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => self::MARKDOWN_MIME_TYPE,
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

		if ( ! is_array( $data ) || ! $this->hasMetadataFields( $data ) ) {
			return $this->badGoogleResponseError();
		}

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
				__( 'DocSync WP cannot access this Google Doc. Choose it with Google Picker, then try again.', 'docsync-wp' ),
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
			__( 'This Google Doc is too large to export as Markdown.', 'docsync-wp' ),
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
