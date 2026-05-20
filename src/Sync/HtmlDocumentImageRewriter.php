<?php
/**
 * Rewrites Google Docs HTML image references.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMDocument;
use DOMElement;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Imports local HTML image assets and rewrites them to attachment URLs.
 */
final class HtmlDocumentImageRewriter {
	/**
	 * Media asset importer.
	 *
	 * @var MediaAssetImporter
	 */
	private MediaAssetImporter $media_assets;

	/**
	 * Constructor.
	 *
	 * @param MediaAssetImporter $media_assets Media asset importer.
	 */
	public function __construct( MediaAssetImporter $media_assets ) {
		$this->media_assets = $media_assets;
	}

	/**
	 * Rewrite local image references to Media Library URLs.
	 *
	 * @param string $html           HTML content.
	 * @param string $html_path      HTML file path.
	 * @param string $temp_dir       Temp directory.
	 * @param string $google_file_id Google Drive file ID.
	 * @param int    $post_id        Target post ID.
	 * @param int    $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	public function rewrite( string $html, string $html_path, string $temp_dir, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		$document = $this->parseDocument( $html );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$image_urls = array();
		$images     = $document->getElementsByTagName( 'img' );

		foreach ( $images as $image ) {
			if ( ! $image instanceof DOMElement ) {
				continue;
			}

			$src = $image->getAttribute( 'src' );

			if ( $this->isRemoteImage( $src ) ) {
				continue;
			}

			$asset = $this->resolveAssetPath( $src, dirname( $html_path ), $temp_dir );

			if ( is_wp_error( $asset ) ) {
				return $asset;
			}

			if ( null === $asset ) {
				continue;
			}

			if ( ! isset( $image_urls[ $asset['asset_path'] ] ) ) {
				$url = $this->media_assets->importImage( $asset['file_path'], $asset['asset_path'], $google_file_id, $post_id, $user_id );

				if ( is_wp_error( $url ) ) {
					return $url;
				}

				$image_urls[ $asset['asset_path'] ] = $url;
			}

			$image->setAttribute( 'src', $image_urls[ $asset['asset_path'] ] );
		}

		return wp_kses_post( $this->getBodyHtml( $document ) );
	}

	/**
	 * Parse HTML into a DOM document.
	 *
	 * @param string $html HTML content.
	 * @return DOMDocument|WP_Error
	 */
	private function parseDocument( string $html ): DOMDocument|WP_Error {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new WP_Error(
				'docsync_wp_html_parse_failed',
				__( 'DocSync WP could not parse the Google Docs HTML export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return $document;
	}

	/**
	 * Resolve a local asset reference to a file inside the ZIP extraction root.
	 *
	 * @param string $src      Image src.
	 * @param string $html_dir Directory containing the HTML file.
	 * @param string $temp_dir Temp directory.
	 * @return array{file_path:string,asset_path:string}|WP_Error|null
	 */
	private function resolveAssetPath( string $src, string $html_dir, string $temp_dir ): array|WP_Error|null {
		$src = trim( html_entity_decode( $src, ENT_QUOTES, 'UTF-8' ) );

		if ( '' === $src ) {
			return null;
		}

		$path = rawurldecode( (string) preg_replace( '/[?#].*$/', '', $src ) );

		if ( str_starts_with( $path, 'file://' ) ) {
			$parsed_path = wp_parse_url( $path, PHP_URL_PATH );
			$path        = is_string( $parsed_path ) ? $parsed_path : '';
		}

		$candidate = str_starts_with( $path, '/' )
			? trailingslashit( $temp_dir ) . ltrim( $path, '/' )
			: trailingslashit( $html_dir ) . $path;

		$real_root = realpath( $temp_dir );
		$real_file = realpath( $candidate );

		if ( ! is_string( $real_root ) || ! is_string( $real_file ) || ! is_file( $real_file ) ) {
			return new WP_Error(
				'docsync_wp_export_image_missing',
				__( 'DocSync WP could not find an image referenced by the Google Docs export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		if ( ! str_starts_with( $real_file, trailingslashit( $real_root ) ) ) {
			return new WP_Error(
				'docsync_wp_export_image_unsafe',
				__( 'DocSync WP rejected an unsafe image path from the Google Docs export.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		return array(
			'file_path'  => $real_file,
			'asset_path' => str_replace( DIRECTORY_SEPARATOR, '/', ltrim( substr( $real_file, strlen( $real_root ) ), DIRECTORY_SEPARATOR ) ),
		);
	}

	/**
	 * Whether an image source is remote or inline.
	 *
	 * @param string $src Image source.
	 */
	private function isRemoteImage( string $src ): bool {
		return preg_match( '#^(?:https?:)?//#i', $src ) > 0
			|| preg_match( '#^(?:data|blob):#i', $src ) > 0;
	}

	/**
	 * Return document body HTML.
	 *
	 * @param DOMDocument $document HTML document.
	 */
	private function getBodyHtml( DOMDocument $document ): string {
		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return (string) $document->saveHTML();
		}

		$html = '';

		foreach ( $body->childNodes as $child ) {
			$html .= $document->saveHTML( $child );
		}

		return $html;
	}
}
