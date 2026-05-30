<?php
/**
 * Google Docs API client.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use DocSyncWP\Auth\GoogleOAuthService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Google Docs structure and image content for large-doc fallback sync.
 */
final class DocsClient {
	private const API_BASE_URL            = 'https://docs.googleapis.com/v1';
	private const REQUEST_TIMEOUT_SECONDS = 20;
	private const MAX_IMAGE_BYTES         = 10485760;

	/**
	 * OAuth service.
	 *
	 * @var GoogleOAuthService
	 */
	private GoogleOAuthService $oauth;

	/**
	 * Error mapper.
	 *
	 * @var DocsApiErrorMapper
	 */
	private DocsApiErrorMapper $errors;

	/**
	 * Constructor.
	 *
	 * @param GoogleOAuthService      $oauth  OAuth service.
	 * @param DocsApiErrorMapper|null $errors Error mapper.
	 */
	public function __construct( GoogleOAuthService $oauth, ?DocsApiErrorMapper $errors = null ) {
		$this->oauth  = $oauth;
		$this->errors = $errors ?? new DocsApiErrorMapper();
	}

	/**
	 * Get a Google Docs document JSON representation.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $document_id Google Docs document ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getDocument( int $user_id, string $document_id ): array|WP_Error {
		$response = $this->request(
			$user_id,
			add_query_arg(
				array( 'includeTabsContent' => 'true' ),
				self::API_BASE_URL . '/documents/' . rawurlencode( $document_id )
			),
			'application/json'
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = json_decode( $response['body'], true );

		if ( ! is_array( $data ) ) {
			return $this->errors->badResponse();
		}

		return $data;
	}

	/**
	 * Download an image content URI to a temporary file.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $content_uri Google Docs image content URI.
	 * @return array{file_path:string,content_type:string}|WP_Error
	 */
	public function downloadContentUri( int $user_id, string $content_uri ): array|WP_Error {
		$scheme = wp_parse_url( $content_uri, PHP_URL_SCHEME );

		if ( 'https' !== $scheme ) {
			return new WP_Error(
				'docsync_wp_docs_api_image_uri_invalid',
				__( 'DocSync WP rejected an unsafe Google Docs image URL.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		$response = $this->request( $user_id, $content_uri, 'image/*' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( '' === $response['body'] || strlen( $response['body'] ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error(
				'docsync_wp_docs_api_image_invalid',
				__( 'DocSync WP could not read an image from the Google Docs API fallback.', 'docsync-wp' ),
				array( 'status' => 502 )
			);
		}

		$temp_file = wp_tempnam( 'docsync-wp-docs-api-image' );

		if ( ! is_string( $temp_file ) || '' === $temp_file ) {
			return new WP_Error(
				'docsync_wp_docs_api_temp_file_failed',
				__( 'DocSync WP could not create a temporary file for a Google Docs image.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		if ( false === file_put_contents( $temp_file, $response['body'] ) ) {
			wp_delete_file( $temp_file );
			return new WP_Error(
				'docsync_wp_docs_api_image_write_failed',
				__( 'DocSync WP could not store a Google Docs image before importing it.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'file_path'    => $temp_file,
			'content_type' => $response['content_type'],
		);
	}

	/**
	 * Perform an authenticated GET request.
	 *
	 * @param int    $user_id User ID.
	 * @param string $url     Request URL.
	 * @param string $accept  Accept header.
	 * @return array{body:string,content_type:string}|WP_Error
	 */
	private function request( int $user_id, string $url, string $accept ): array|WP_Error {
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
					'Accept'        => $accept,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->errors->transientFailure();
		}

		$status = absint( wp_remote_retrieve_response_code( $response ) );
		$body   = wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			return $this->errors->map( $status, $body );
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( is_array( $content_type ) ) {
			$content_type = reset( $content_type );
		}

		return array(
			'body'         => $body,
			'content_type' => is_scalar( $content_type ) ? sanitize_text_field( (string) $content_type ) : '',
		);
	}
}
