<?php
/**
 * Imports Google Docs API inline images.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Google\DocsClient;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Downloads Docs API image content and reuses the Media Library importer.
 */
final class DocsApiImageImporter {
	/**
	 * Docs API client.
	 *
	 * @var DocsClient
	 */
	private DocsClient $docs_client;

	/**
	 * Media asset importer.
	 *
	 * @var MediaAssetImporter
	 */
	private MediaAssetImporter $media_assets;

	/**
	 * Constructor.
	 *
	 * @param DocsClient         $docs_client  Docs API client.
	 * @param MediaAssetImporter $media_assets Media asset importer.
	 */
	public function __construct( DocsClient $docs_client, MediaAssetImporter $media_assets ) {
		$this->docs_client  = $docs_client;
		$this->media_assets = $media_assets;
	}

	/**
	 * Import an inline Docs API image into the Media Library.
	 *
	 * @param int    $user_id        Sync owner user ID.
	 * @param string $content_uri    Google Docs image content URI.
	 * @param string $inline_id      Inline object ID.
	 * @param string $google_file_id Google Drive file ID.
	 * @param int    $post_id        Target post ID.
	 * @return string|WP_Error
	 */
	public function import( int $user_id, string $content_uri, string $inline_id, string $google_file_id, int $post_id ): string|WP_Error {
		$download = $this->docs_client->downloadContentUri( $user_id, $content_uri );

		if ( is_wp_error( $download ) ) {
			return $download;
		}

		$asset_path = 'docs-api/' . sanitize_file_name( $inline_id ) . $this->extensionForContentType( $download['content_type'] );

		try {
			return $this->media_assets->importImage(
				$download['file_path'],
				$asset_path,
				$google_file_id,
				$post_id,
				$user_id
			);
		} finally {
			if ( is_file( $download['file_path'] ) ) {
				wp_delete_file( $download['file_path'] );
			}
		}
	}

	/**
	 * Get a safe file extension from an image content type.
	 *
	 * @param string $content_type Content-Type response header.
	 */
	private function extensionForContentType( string $content_type ): string {
		$parsed_type  = strtok( $content_type, ';' );
		$content_type = strtolower( false !== $parsed_type ? $parsed_type : '' );

		return match ( $content_type ) {
			'image/jpeg' => '.jpg',
			'image/png' => '.png',
			'image/gif' => '.gif',
			'image/webp' => '.webp',
			default => '.png',
		};
	}
}
