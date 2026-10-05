<?php
/**
 * One-time import of a Google Docs HTML ZIP uploaded by a user.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Sync\Layout\LayoutConversionService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a draft from an uploaded export. The draft has no Google source link.
 */
final class ZipImportService {
	/**
	 * HTML ZIP importer.
	 *
	 * @var HtmlZipImporter
	 */
	private HtmlZipImporter $importer;

	/**
	 * Layout converter.
	 *
	 * @var LayoutConversionService
	 */
	private LayoutConversionService $layout_converter;

	/**
	 * Constructor.
	 *
	 * @param HtmlZipImporter         $importer         HTML ZIP importer.
	 * @param LayoutConversionService $layout_converter Layout converter.
	 */
	public function __construct( HtmlZipImporter $importer, LayoutConversionService $layout_converter ) {
		$this->importer         = $importer;
		$this->layout_converter = $layout_converter;
	}

	/**
	 * Import ZIP bytes into a new draft.
	 *
	 * @param int    $user_id   Importing user ID.
	 * @param string $zip_bytes ZIP file bytes.
	 * @param string $file_name Uploaded file name, used for the draft title.
	 * @param string $post_type Target post type.
	 * @param string $preset_id Gutenberg layout preset ID.
	 * @return array{postId:int,title:string}|WP_Error
	 */
	public function import( int $user_id, string $zip_bytes, string $file_name, string $post_type, string $preset_id ): array|WP_Error {
		$title = self::titleFromFileName( $file_name );

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_author'  => $user_id,
					'post_content' => '',
					'post_status'  => 'draft',
					'post_title'   => $title,
					'post_type'    => $post_type,
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'docsync_wp_create_post_failed',
				__( 'Brasth Document Sync could not create a draft for this import.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$html = $this->importer->import( $zip_bytes, 'upload-' . substr( hash( 'sha256', $zip_bytes ), 0, 12 ), (int) $post_id, $user_id );

		if ( is_wp_error( $html ) ) {
			$this->discard( (int) $post_id );
			return $html;
		}

		$content = $this->layout_converter->convert( $html, $preset_id );

		if ( is_wp_error( $content ) ) {
			$this->discard( (int) $post_id );
			return $content;
		}

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => (int) $post_id,
					'post_content' => $content,
				)
			),
			true
		);

		if ( is_wp_error( $updated ) ) {
			$this->discard( (int) $post_id );
			return new WP_Error(
				'docsync_wp_update_post_failed',
				__( 'Brasth Document Sync could not save the imported content.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'postId' => (int) $post_id,
			'title'  => $title,
		);
	}

	/**
	 * Delete a failed draft together with the images imported for it.
	 *
	 * @param int $post_id Draft post ID.
	 */
	private function discard( int $post_id ): void {
		foreach ( get_children(
			array(
				'post_parent' => $post_id,
				'post_type'   => 'attachment',
				'fields'      => 'ids',
			)
		) as $attachment_id ) {
			wp_delete_attachment( (int) $attachment_id, true );
		}

		wp_delete_post( $post_id, true );
	}

	/**
	 * Derive a draft title from the ZIP file name.
	 *
	 * @param string $file_name Uploaded file name.
	 */
	public static function titleFromFileName( string $file_name ): string {
		$stem  = (string) preg_replace( '/\.zip$/i', '', basename( $file_name ) );
		$title = trim( (string) preg_replace( '/[\s_]+/', ' ', $stem ) );

		return '' !== $title ? sanitize_text_field( $title ) : __( 'Imported Google Doc', 'brasth-document-sync-for-google-docs' );
	}
}
