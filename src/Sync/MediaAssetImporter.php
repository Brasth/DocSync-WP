<?php
/**
 * Imports Google Docs ZIP assets into the WordPress Media Library.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Uploads exported document images and reuses prior imports.
 */
final class MediaAssetImporter {
	private const META_GOOGLE_FILE_ID = '_docsync_wp_google_asset_file_id';
	private const META_ASSET_PATH     = '_docsync_wp_google_asset_path';
	private const META_ASSET_HASH     = '_docsync_wp_google_asset_hash';

	/**
	 * Import a local image asset or return the existing matching attachment URL.
	 *
	 * @param string $file_path      Local extracted file path.
	 * @param string $asset_path     Normalized asset path inside the ZIP.
	 * @param string $google_file_id Google Drive file ID.
	 * @param int    $post_id        Parent post ID.
	 * @param int    $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	public function importImage( string $file_path, string $asset_path, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error(
				'docsync_wp_missing_export_asset',
				__( 'DocSync WP could not find an image from the Google Docs export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$image_hash = hash_file( 'sha256', $file_path );

		if ( false === $image_hash ) {
			return new WP_Error(
				'docsync_wp_asset_hash_failed',
				__( 'DocSync WP could not inspect an exported Google Docs image.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$existing_url = $this->findExistingAttachmentUrl( $google_file_id, $asset_path, $image_hash );

		if ( null !== $existing_url ) {
			return $existing_url;
		}

		$attachment_id = $this->uploadImage( $file_path, $asset_path, $post_id, $user_id );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		update_post_meta( $attachment_id, self::META_GOOGLE_FILE_ID, sanitize_text_field( $google_file_id ) );
		update_post_meta( $attachment_id, self::META_ASSET_PATH, sanitize_text_field( $asset_path ) );
		update_post_meta( $attachment_id, self::META_ASSET_HASH, sanitize_text_field( $image_hash ) );

		$url = wp_get_attachment_url( $attachment_id );

		if ( ! is_string( $url ) || '' === $url ) {
			return new WP_Error(
				'docsync_wp_attachment_url_failed',
				__( 'DocSync WP uploaded an image but could not read its Media Library URL.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return esc_url_raw( $url );
	}

	/**
	 * Find an existing attachment imported from the same Google asset.
	 *
	 * @param string $google_file_id Google Drive file ID.
	 * @param string $asset_path     Normalized asset path inside the ZIP.
	 * @param string $image_hash     Image hash.
	 */
	private function findExistingAttachmentUrl( string $google_file_id, string $asset_path, string $image_hash ): ?string {
		$matches = get_posts(
			array(
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'   => self::META_GOOGLE_FILE_ID,
						'value' => sanitize_text_field( $google_file_id ),
					),
					array(
						'key'   => self::META_ASSET_PATH,
						'value' => sanitize_text_field( $asset_path ),
					),
					array(
						'key'   => self::META_ASSET_HASH,
						'value' => sanitize_text_field( $image_hash ),
					),
				),
				'no_found_rows'  => true,
				'post_status'    => 'inherit',
				'post_type'      => 'attachment',
				'posts_per_page' => 1,
			)
		);

		$attachment_id = isset( $matches[0] ) ? absint( $matches[0] ) : 0;
		$url           = $attachment_id > 0 ? wp_get_attachment_url( $attachment_id ) : '';

		return is_string( $url ) && '' !== $url ? esc_url_raw( $url ) : null;
	}

	/**
	 * Upload an exported image to the Media Library.
	 *
	 * @param string $file_path  Local extracted file path.
	 * @param string $asset_path Normalized asset path inside the ZIP.
	 * @param int    $post_id    Parent post ID.
	 * @param int    $user_id    Sync owner user ID.
	 * @return int|WP_Error
	 */
	private function uploadImage( string $file_path, string $asset_path, int $post_id, int $user_id ): int|WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$file_name = sanitize_file_name( basename( $asset_path ) );

		if ( '' === $file_name ) {
			$file_name = 'docsync-wp-image';
		}

		$file_type = wp_check_filetype_and_ext( $file_path, $file_name );

		if ( empty( $file_type['type'] ) || ! str_starts_with( (string) $file_type['type'], 'image/' ) ) {
			return new WP_Error(
				'docsync_wp_invalid_export_image',
				__( 'DocSync WP found an exported Google Docs asset that is not a supported image.', 'docsync-wp' ),
				array( 'status' => 415 )
			);
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => $file_name,
				'tmp_name' => $file_path,
				'type'     => (string) $file_type['type'],
				'error'    => UPLOAD_ERR_OK,
				'size'     => (int) filesize( $file_path ),
			),
			$post_id,
			null,
			array(
				'post_author' => $user_id,
			)
		);

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'docsync_wp_image_upload_failed',
				__( 'DocSync WP could not upload an exported Google Docs image to the Media Library.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return (int) $attachment_id;
	}
}
