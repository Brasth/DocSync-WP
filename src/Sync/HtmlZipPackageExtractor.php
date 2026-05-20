<?php
/**
 * Extracts Google Docs HTML ZIP exports.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Creates a temporary extraction directory and locates exported HTML.
 */
final class HtmlZipPackageExtractor {
	/**
	 * Extract a ZIP package and return its HTML content.
	 *
	 * @param string $zip_bytes ZIP file bytes.
	 * @return array{temp_dir:string,html_path:string,html:string}|WP_Error
	 */
	public function extract( string $zip_bytes ): array|WP_Error {
		if ( ! class_exists( ZipArchive::class ) ) {
			return new WP_Error(
				'docsync_wp_zip_missing',
				__( 'DocSync WP requires the PHP Zip extension to import Google Docs images.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$temp_dir = $this->createTempDir();

		if ( is_wp_error( $temp_dir ) ) {
			return $temp_dir;
		}

		$zip_path = trailingslashit( $temp_dir ) . 'export.zip';

		if ( false === file_put_contents( $zip_path, $zip_bytes ) ) {
			$this->deleteDirectory( $temp_dir );
			return new WP_Error(
				'docsync_wp_zip_write_failed',
				__( 'DocSync WP could not prepare the Google Docs export for import.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$extracted = $this->extractZip( $zip_path, $temp_dir );

		if ( is_wp_error( $extracted ) ) {
			$this->deleteDirectory( $temp_dir );
			return $extracted;
		}

		$html_path = $this->findHtmlFile( $temp_dir );

		if ( is_wp_error( $html_path ) ) {
			$this->deleteDirectory( $temp_dir );
			return $html_path;
		}

		$html = file_get_contents( $html_path );

		if ( ! is_string( $html ) ) {
			$this->deleteDirectory( $temp_dir );
			return new WP_Error(
				'docsync_wp_html_read_failed',
				__( 'DocSync WP could not read the Google Docs HTML export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return array(
			'temp_dir'  => $temp_dir,
			'html_path' => $html_path,
			'html'      => $html,
		);
	}

	/**
	 * Delete a temp directory tree.
	 *
	 * @param string $directory Directory path.
	 */
	public function deleteDirectory( string $directory ): void {
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isDir() ) {
				rmdir( $file->getPathname() );
			} else {
				unlink( $file->getPathname() );
			}
		}

		rmdir( $directory );
	}

	/**
	 * Extract a ZIP archive into a validated temp directory.
	 *
	 * @param string $zip_path ZIP path.
	 * @param string $temp_dir Temp directory.
	 * @return true|WP_Error
	 */
	private function extractZip( string $zip_path, string $temp_dir ): true|WP_Error {
		$zip = new ZipArchive();

		if ( true !== $zip->open( $zip_path ) ) {
			return new WP_Error(
				'docsync_wp_zip_open_failed',
				__( 'DocSync WP could not open the Google Docs HTML export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		$valid_paths = $this->validateZipPaths( $zip );

		if ( is_wp_error( $valid_paths ) ) {
			$zip->close();
			return $valid_paths;
		}

		$extracted = $zip->extractTo( $temp_dir );
		$zip->close();

		if ( ! $extracted ) {
			return new WP_Error(
				'docsync_wp_zip_extract_failed',
				__( 'DocSync WP could not extract the Google Docs HTML export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Ensure ZIP entries cannot escape the temp directory.
	 *
	 * @param ZipArchive $zip ZIP archive.
	 * @return true|WP_Error
	 */
	private function validateZipPaths( ZipArchive $zip ): true|WP_Error {
		for ( $index = 0; $index < $zip->numFiles; $index++ ) {
			$name = (string) $zip->getNameIndex( $index );

			if ( '' === $name || str_starts_with( $name, '/' ) || str_contains( $name, '\\' ) || preg_match( '#(^|/)\.\.(/|$)#', $name ) || preg_match( '/^[A-Za-z]:/', $name ) ) {
				return new WP_Error(
					'docsync_wp_zip_path_invalid',
					__( 'DocSync WP rejected a Google Docs export with unsafe file paths.', 'docsync-wp' ),
					array( 'status' => 400 )
				);
			}
		}

		return true;
	}

	/**
	 * Find the exported HTML document.
	 *
	 * @param string $temp_dir Temp directory.
	 * @return string|WP_Error
	 */
	private function findHtmlFile( string $temp_dir ): string|WP_Error {
		$fallback = '';

		foreach ( $this->walkFiles( $temp_dir ) as $path ) {
			if ( ! str_ends_with( strtolower( $path ), '.html' ) && ! str_ends_with( strtolower( $path ), '.htm' ) ) {
				continue;
			}

			if ( 'index.html' === strtolower( basename( $path ) ) ) {
				return $path;
			}

			$fallback = '' === $fallback ? $path : $fallback;
		}

		if ( '' !== $fallback ) {
			return $fallback;
		}

		return new WP_Error(
			'docsync_wp_html_missing',
			__( 'DocSync WP could not find HTML content in the Google Docs export.', 'docsync-wp' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Create an empty temp directory.
	 *
	 * @return string|WP_Error
	 */
	private function createTempDir(): string|WP_Error {
		$temp_dir = trailingslashit( get_temp_dir() ) . 'docsync-wp-' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $temp_dir ) ) {
			return new WP_Error(
				'docsync_wp_temp_dir_failed',
				__( 'DocSync WP could not create a temporary import directory.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return $temp_dir;
	}

	/**
	 * Recursively iterate files in a directory.
	 *
	 * @param string $directory Directory path.
	 * @return iterable<int,string>
	 */
	private function walkFiles( string $directory ): iterable {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				yield $file->getPathname();
			}
		}
	}
}
