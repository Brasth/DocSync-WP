<?php
/**
 * Maps Google Docs API errors.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Google Docs API HTTP failures into stable plugin errors.
 */
final class DocsApiErrorMapper {
	/**
	 * Map Google Docs API errors to stable plugin errors.
	 *
	 * @param int    $status HTTP status.
	 * @param string $body   Response body.
	 */
	public function map( int $status, string $body ): WP_Error {
		if ( 429 === $status || $status >= 500 ) {
			return $this->transientFailure();
		}

		if ( 403 === $status && $this->isApiDisabledResponse( $body ) ) {
			return new WP_Error(
				'docsync_wp_docs_api_unavailable',
				__( 'Enable Google Docs API in the same Google Cloud project, then retry sync.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 502 )
			);
		}

		if ( in_array( $status, array( 401, 403, 404 ), true ) ) {
			return new WP_Error(
				'docsync_wp_docs_api_access_denied',
				__( 'Brasth Document Sync cannot access this Google Doc through the Docs API fallback. Reconnect Google Drive or choose a document your account can open, then try again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return $this->badResponse();
	}

	/**
	 * Unexpected Docs API response.
	 */
	public function badResponse(): WP_Error {
		return new WP_Error(
			'docsync_wp_bad_docs_response',
			__( 'Google returned an unexpected Docs API response.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 502 )
		);
	}

	/**
	 * Transient Docs API failure.
	 */
	public function transientFailure(): WP_Error {
		return new WP_Error(
			'docsync_wp_docs_api_transient_failure',
			__( 'Google Docs API is temporarily unavailable. Try again shortly.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Whether a Docs API error points to an API setup issue.
	 *
	 * @param string $body Response body.
	 */
	private function isApiDisabledResponse( string $body ): bool {
		$body = strtolower( $body );

		return str_contains( $body, 'accessnotconfigured' )
			|| str_contains( $body, 'has not been used' )
			|| str_contains( $body, 'disabled' );
	}
}
