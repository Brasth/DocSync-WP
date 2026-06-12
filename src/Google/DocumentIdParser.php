<?php
/**
 * Google Docs document identifier parsing.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses Google Docs URLs and raw Drive file IDs.
 */
final class DocumentIdParser {
	private const SOURCE_PICKER  = 'picker';
	private const SOURCE_URL     = 'url';
	private const SOURCE_FILE_ID = 'file_id';

	/**
	 * Parse a document input into a Google Drive file ID.
	 *
	 * @param mixed  $document Document URL or raw file ID.
	 * @param string $source   Input source.
	 * @return string|WP_Error
	 */
	public function parse( mixed $document, string $source ): string|WP_Error {
		$source   = sanitize_key( $source );
		$document = trim( sanitize_text_field( (string) $document ) );

		if ( ! in_array( $source, $this->sources(), true ) || '' === $document ) {
			return $this->invalidDocumentIdError();
		}

		if ( $this->looksLikeUrl( $document ) ) {
			return $this->parseGoogleDocsUrl( $document );
		}

		if ( self::SOURCE_URL === $source ) {
			return $this->invalidDocumentIdError();
		}

		if ( $this->isValidFileId( $document ) ) {
			return $document;
		}

		return $this->invalidDocumentIdError();
	}

	/**
	 * Parse a Google Docs document URL.
	 *
	 * @param string $url URL.
	 * @return string|WP_Error
	 */
	private function parseGoogleDocsUrl( string $url ): string|WP_Error {
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $this->invalidDocumentIdError();
		}

		$host = strtolower( (string) $parts['host'] );

		if ( 'docs.google.com' !== $host ) {
			return $this->invalidDocumentIdError();
		}

		$segments = array_values(
			array_filter(
				explode( '/', (string) $parts['path'] ),
				static function ( string $segment ): bool {
					return '' !== $segment;
				}
			)
		);

		if ( ! in_array( 'document', $segments, true ) ) {
			return $this->invalidDocumentIdError();
		}

		foreach ( $segments as $index => $segment ) {
			if ( 'd' !== $segment || ! isset( $segments[ $index + 1 ] ) ) {
				continue;
			}

			$file_id = $segments[ $index + 1 ];

			if ( $this->isValidFileId( $file_id ) ) {
				return $file_id;
			}
		}

		return $this->invalidDocumentIdError();
	}

	/**
	 * Whether a value appears to be a URL.
	 *
	 * @param string $value Value.
	 */
	private function looksLikeUrl( string $value ): bool {
		return str_starts_with( $value, 'http://' ) || str_starts_with( $value, 'https://' );
	}

	/**
	 * Validate a raw Drive file ID.
	 *
	 * @param string $file_id Candidate file ID.
	 */
	private function isValidFileId( string $file_id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]{10,200}$/', $file_id );
	}

	/**
	 * Supported input sources.
	 *
	 * @return array<int,string>
	 */
	private function sources(): array {
		return array(
			self::SOURCE_PICKER,
			self::SOURCE_URL,
			self::SOURCE_FILE_ID,
		);
	}

	/**
	 * Invalid document ID error.
	 */
	private function invalidDocumentIdError(): WP_Error {
		return new WP_Error(
			'docsync_wp_invalid_document_id',
			__( 'Enter a Google Docs URL or a valid Google Drive file ID.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
