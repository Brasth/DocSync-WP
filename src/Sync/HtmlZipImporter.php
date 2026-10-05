<?php
/**
 * Imports Google Docs HTML ZIP exports into WordPress-safe HTML.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates ZIP extraction, image import, and content sanitization.
 */
final class HtmlZipImporter {
	/**
	 * ZIP package extractor.
	 *
	 * @var HtmlZipPackageExtractor
	 */
	private HtmlZipPackageExtractor $package_extractor;

	/**
	 * HTML image rewriter.
	 *
	 * @var HtmlDocumentImageRewriter
	 */
	private HtmlDocumentImageRewriter $image_rewriter;

	/**
	 * Google redirect link cleaner.
	 *
	 * @var HtmlGoogleRedirectLinkCleaner
	 */
	private HtmlGoogleRedirectLinkCleaner $link_cleaner;

	/**
	 * Constructor.
	 *
	 * @param HtmlZipPackageExtractor            $package_extractor ZIP package extractor.
	 * @param HtmlDocumentImageRewriter          $image_rewriter    HTML image rewriter.
	 * @param HtmlGoogleRedirectLinkCleaner|null $link_cleaner Google redirect link cleaner.
	 */
	public function __construct( HtmlZipPackageExtractor $package_extractor, HtmlDocumentImageRewriter $image_rewriter, ?HtmlGoogleRedirectLinkCleaner $link_cleaner = null ) {
		$this->package_extractor = $package_extractor;
		$this->image_rewriter    = $image_rewriter;
		$this->link_cleaner      = $link_cleaner ?? new HtmlGoogleRedirectLinkCleaner();
	}

	/**
	 * Import a Google Docs HTML ZIP export.
	 *
	 * @param string $zip_bytes      ZIP file bytes.
	 * @param string $google_file_id Google Drive file ID.
	 * @param int    $post_id        Target post ID.
	 * @param int    $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	public function import( string $zip_bytes, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		$package = $this->package_extractor->extract( $zip_bytes );

		if ( is_wp_error( $package ) ) {
			return $package;
		}

		try {
			$html = $this->image_rewriter->rewrite(
				$package['html'],
				$package['html_path'],
				$package['temp_dir'],
				$google_file_id,
				$post_id,
				$user_id
			);

			return is_wp_error( $html ) ? $html : $this->link_cleaner->clean( $html );
		} finally {
			$this->package_extractor->deleteDirectory( $package['temp_dir'] );
		}
	}

	/**
	 * Images that failed to import during the last import call.
	 */
	public function getLastFailedImageCount(): int {
		return $this->image_rewriter->getFailedImageCount();
	}
}
