<?php
/**
 * Google Slides API client.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use DocSyncWP\Auth\GoogleOAuthService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads converted presentations, slide thumbnails, and slide images.
 *
 * Responses are size-bounded and returned to the caller only; nothing is
 * cached or written here, so thumbnails and images stay private to the
 * import session that stores them.
 */
final class SlidesClient {
	private const API_BASE_URL             = 'https://slides.googleapis.com/v1';
	private const REQUEST_TIMEOUT_SECONDS  = 20;
	private const MAX_PRESENTATION_BYTES   = 20971520;
	private const MAX_IMAGE_BYTES          = 10485760;
	private const MAX_IMAGE_REDIRECTS      = 3;
	private const CONTENT_HOST_SUFFIX      = '.googleusercontent.com';
	private const PRESENTATION_ID_PATTERN  = '/^[A-Za-z0-9_-]{10,200}$/';
	private const PAGE_OBJECT_ID_PATTERN   = '/^[A-Za-z0-9_.:-]{1,200}$/';
	private const ALLOWED_IMAGE_MIME_TYPES = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

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
	 * Read a presentation's full structure.
	 *
	 * @param int    $user_id         User ID.
	 * @param string $presentation_id Presentation ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getPresentation( int $user_id, string $presentation_id ): array|WP_Error {
		if ( 1 !== preg_match( self::PRESENTATION_ID_PATTERN, $presentation_id ) ) {
			return $this->notFoundError();
		}

		$body = $this->requestApi(
			$user_id,
			self::API_BASE_URL . '/presentations/' . rawurlencode( $presentation_id ),
			self::MAX_PRESENTATION_BYTES
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['slides'] ) || ! is_array( $data['slides'] ) ) {
			return $this->badResponseError();
		}

		return $data;
	}

	/**
	 * Fetch one rendered MEDIUM PNG thumbnail for a slide.
	 *
	 * @param int    $user_id         User ID.
	 * @param string $presentation_id Presentation ID.
	 * @param string $page_object_id  Slide page object ID.
	 * @return array{bytes:string,mimeType:string,width:int,height:int}|WP_Error
	 */
	public function getThumbnail( int $user_id, string $presentation_id, string $page_object_id ): array|WP_Error {
		if ( 1 !== preg_match( self::PRESENTATION_ID_PATTERN, $presentation_id ) || 1 !== preg_match( self::PAGE_OBJECT_ID_PATTERN, $page_object_id ) ) {
			return $this->notFoundError();
		}

		$body = $this->requestApi(
			$user_id,
			add_query_arg(
				array(
					'thumbnailProperties.mimeType'      => 'PNG',
					'thumbnailProperties.thumbnailSize' => 'MEDIUM',
				),
				self::API_BASE_URL . '/presentations/' . rawurlencode( $presentation_id ) . '/pages/' . rawurlencode( $page_object_id ) . '/thumbnail'
			),
			self::MAX_IMAGE_BYTES
		);

		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['contentUrl'] ) || ! is_string( $data['contentUrl'] ) ) {
			return $this->badResponseError();
		}

		$image = $this->downloadContentUrl( $user_id, $data['contentUrl'] );

		if ( is_wp_error( $image ) ) {
			return $image;
		}

		if ( 'image/png' !== $image['mimeType'] ) {
			return $this->invalidImageError();
		}

		$size = getimagesizefromstring( $image['bytes'] );

		return array(
			'bytes'    => $image['bytes'],
			'mimeType' => 'image/png',
			'width'    => is_array( $size ) ? absint( $size[0] ) : absint( $data['width'] ?? 0 ),
			'height'   => is_array( $size ) ? absint( $size[1] ) : absint( $data['height'] ?? 0 ),
		);
	}

	/**
	 * Download a Slides image or thumbnail content URL.
	 *
	 * Content URLs are short-lived and already tagged with the requesting account,
	 * so no bearer token is sent. Only HTTPS Google user-content hosts are fetched,
	 * the body is capped at 10 MiB, and the MIME type is detected from the bytes.
	 *
	 * @param int    $user_id     User ID that requested the content URL.
	 * @param string $content_url Content URL from the Slides API.
	 * @return array{bytes:string,mimeType:string}|WP_Error
	 */
	public function downloadContentUrl( int $user_id, string $content_url ): array|WP_Error {
		$host = strtolower( (string) wp_parse_url( $content_url, PHP_URL_HOST ) );

		if ( $user_id <= 0 || 'https' !== wp_parse_url( $content_url, PHP_URL_SCHEME ) || ! str_ends_with( $host, self::CONTENT_HOST_SUFFIX ) ) {
			return new WP_Error(
				'docsync_wp_slides_image_url_invalid',
				__( 'Brasth Document Sync rejected an unsafe Google Slides image URL.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$response = wp_safe_remote_get(
			$content_url,
			array(
				'timeout'             => self::REQUEST_TIMEOUT_SECONDS,
				'redirection'         => self::MAX_IMAGE_REDIRECTS,
				'limit_response_size' => self::MAX_IMAGE_BYTES + 1,
				'headers'             => array( 'Accept' => 'image/*' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transientFailureError( 0 );
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$bytes  = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return $this->mapError( $status, '', $this->retryAfter( $response ) );
		}

		if ( '' === $bytes || strlen( $bytes ) > self::MAX_IMAGE_BYTES ) {
			return $this->invalidImageError();
		}

		$mime_type = $this->detectImageMimeType( $bytes );
		$size      = '' !== $mime_type ? getimagesizefromstring( $bytes ) : false;

		$size_mime = is_array( $size ) && isset( $size['mime'] ) ? (string) $size['mime'] : '';

		if ( $size_mime !== $mime_type || ! in_array( $mime_type, self::ALLOWED_IMAGE_MIME_TYPES, true ) ) {
			return $this->invalidImageError();
		}

		return array(
			'bytes'    => $bytes,
			'mimeType' => $mime_type,
		);
	}

	/**
	 * Detect an allowed image type from its signature bytes.
	 *
	 * @param string $bytes Image bytes.
	 * @return string MIME type, or '' when the bytes are not a supported image.
	 */
	private function detectImageMimeType( string $bytes ): string {
		if ( str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) ) {
			return 'image/png';
		}

		if ( str_starts_with( $bytes, "\xFF\xD8\xFF" ) ) {
			return 'image/jpeg';
		}

		if ( str_starts_with( $bytes, 'GIF87a' ) || str_starts_with( $bytes, 'GIF89a' ) ) {
			return 'image/gif';
		}

		if ( str_starts_with( $bytes, 'RIFF' ) && 'WEBP' === substr( $bytes, 8, 4 ) ) {
			return 'image/webp';
		}

		return '';
	}

	/**
	 * Perform an authenticated, size-bounded Slides API GET.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $url       Request URL.
	 * @param int    $max_bytes Maximum accepted body size.
	 * @return string|WP_Error Response body.
	 */
	private function requestApi( int $user_id, string $url, int $max_bytes ): string|WP_Error {
		$access_token = $this->oauth->getAccessToken( $user_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::REQUEST_TIMEOUT_SECONDS,
				'limit_response_size' => $max_bytes + 1,
				'headers'             => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->transientFailureError( 0 );
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return $this->mapError( $status, $body, $this->retryAfter( $response ) );
		}

		if ( strlen( $body ) > $max_bytes ) {
			return new WP_Error(
				'docsync_wp_slides_response_too_large',
				__( 'This presentation is too large for Brasth Document Sync to import.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 413 )
			);
		}

		return $body;
	}

	/**
	 * Map Slides API failures to stable plugin errors.
	 *
	 * @param int    $status      HTTP status.
	 * @param string $body        Response body.
	 * @param int    $retry_after Seconds from Retry-After, or 0.
	 */
	private function mapError( int $status, string $body, int $retry_after ): WP_Error {
		if ( 429 === $status || $status >= 500 ) {
			return $this->transientFailureError( $retry_after );
		}

		$lower = strtolower( $body );

		if ( 403 === $status && ( str_contains( $lower, 'accessnotconfigured' ) || str_contains( $lower, 'has not been used' ) || str_contains( $lower, 'service_disabled' ) ) ) {
			return new WP_Error(
				'docsync_wp_slides_api_unavailable',
				__( 'Enable Google Slides API in the same Google Cloud project, then retry the import.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		if ( 404 === $status ) {
			return $this->notFoundError();
		}

		if ( in_array( $status, array( 401, 403 ), true ) ) {
			return new WP_Error(
				'docsync_wp_slides_access_denied',
				__( 'Brasth Document Sync cannot read this converted presentation. Reconnect Google Drive, then try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return $this->badResponseError();
	}

	/**
	 * Seconds requested by a Retry-After header.
	 *
	 * @param array<string,mixed> $response HTTP response.
	 */
	private function retryAfter( array $response ): int {
		$header = wp_remote_retrieve_header( $response, 'retry-after' );
		$header = is_array( $header ) ? (string) reset( $header ) : (string) $header;

		return ctype_digit( $header ) ? min( DAY_IN_SECONDS, (int) $header ) : 0;
	}

	/**
	 * Retryable Slides failure (429, 5xx, or network).
	 *
	 * @param int $retry_after Seconds from Retry-After, or 0.
	 */
	private function transientFailureError( int $retry_after ): WP_Error {
		return new WP_Error(
			'docsync_wp_slides_transient_failure',
			__( 'Google Slides is temporarily unavailable. Brasth Document Sync will retry shortly.', 'brasth-document-sync-for-google-docs' ),
			array(
				'status'     => 503,
				'retryable'  => true,
				'retryAfter' => $retry_after,
			)
		);
	}

	/**
	 * Missing presentation or slide error.
	 */
	private function notFoundError(): WP_Error {
		return new WP_Error(
			'docsync_wp_slides_not_found',
			__( 'Google Slides could not find this presentation or slide.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Invalid image bytes error.
	 */
	private function invalidImageError(): WP_Error {
		return new WP_Error(
			'docsync_wp_slides_image_invalid',
			__( 'Brasth Document Sync could not read an image from Google Slides.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Unexpected Slides response error.
	 */
	private function badResponseError(): WP_Error {
		return new WP_Error(
			'docsync_wp_bad_slides_response',
			__( 'Google returned an unexpected Slides API response.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 502 )
		);
	}
}
