<?php
/**
 * Validates import uploads and browser-rendered PDF page images.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * Checks size, type, signature, archive structure, macros, and encryption.
 *
 * Validation never trusts the browser MIME type or the file extension alone:
 * PDFs need a PDF header, an end-of-file marker, and no `/Encrypt`
 * dictionary; DOCX and PPTX need a safe OPC ZIP package whose main part
 * matches the extension, with bounded entry counts and expansion ratios and
 * no macro or ActiveX parts.
 */
final class UploadValidator {
	public const MAX_FILES      = 20;
	public const MAX_FILE_BYTES = 26214400;
	public const MAX_PNG_BYTES  = 8388608;

	public const FORMAT_DOCX = 'docx';
	public const FORMAT_PPTX = 'pptx';
	public const FORMAT_PDF  = 'pdf';

	public const MIME_TYPES = array(
		self::FORMAT_DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		self::FORMAT_PPTX => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
		self::FORMAT_PDF  => 'application/pdf',
	);

	private const MAX_ZIP_ENTRIES          = 5000;
	private const MAX_ZIP_EXPANDED_BYTES   = 262144000;
	private const MAX_ZIP_ENTRY_BYTES      = 104857600;
	private const MAX_ZIP_RATIO            = 200;
	private const ZIP_RATIO_MIN_BYTES      = 1048576;
	private const MAX_CONTENT_TYPES_BYTES  = 1048576;
	private const MAX_NAME_LENGTH          = 200;
	private const PNG_MIN_WIDTH            = 200;
	private const PNG_MAX_WIDTH            = 2400;
	private const PNG_MAX_HEIGHT           = 3400;
	private const PNG_ASPECT_TOLERANCE     = 0.02;
	private const PDF_HEADER_SEARCH_BYTES  = 1024;
	private const PDF_TRAILER_SEARCH_BYTES = 4096;

	private const MACRO_EXTENSIONS = array( 'docm', 'dotm', 'pptm', 'potm', 'ppsm', 'ppam', 'xlsm', 'xltm', 'xlam' );

	private const MAIN_PART = array(
		self::FORMAT_DOCX => 'word/document.xml',
		self::FORMAT_PPTX => 'ppt/presentation.xml',
	);

	private const MAIN_CONTENT_TYPES = array(
		self::FORMAT_DOCX => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
		self::FORMAT_PPTX => 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Effective per-session limits.
	 *
	 * @return array{maxFiles:int,maxFileBytes:int}
	 */
	public function limits(): array {
		$host = absint( wp_max_upload_size() );

		return array(
			'maxFiles'     => self::MAX_FILES,
			'maxFileBytes' => $host > 0 ? min( self::MAX_FILE_BYTES, $host ) : self::MAX_FILE_BYTES,
		);
	}

	/**
	 * Validate one uploaded file.
	 *
	 * @param array<string,mixed> $uploaded_file  One normalized `$_FILES` entry: name, tmp_name, error, size.
	 * @param int                 $accepted_count Files already accepted in the session.
	 * @return array{format:string,mimeType:string,originalName:string,byteSize:int,tmpPath:string}|WP_Error
	 */
	public function validate( array $uploaded_file, int $accepted_count ): array|WP_Error {
		if ( $accepted_count >= self::MAX_FILES ) {
			return new WP_Error(
				'docsync_wp_import_file_limit',
				__( 'An import can hold at most 20 files.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		$error = isset( $uploaded_file['error'] ) ? (int) $uploaded_file['error'] : UPLOAD_ERR_NO_FILE;

		if ( in_array( $error, array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
			return $this->tooLargeError();
		}

		$tmp_path = isset( $uploaded_file['tmp_name'] ) && is_string( $uploaded_file['tmp_name'] ) ? $uploaded_file['tmp_name'] : '';

		if ( UPLOAD_ERR_OK !== $error || '' === $tmp_path || ! is_uploaded_file( $tmp_path ) ) {
			return $this->invalidFileError();
		}

		return $this->validatePath( $tmp_path, isset( $uploaded_file['name'] ) && is_string( $uploaded_file['name'] ) ? $uploaded_file['name'] : '' );
	}

	/**
	 * Whether PHP ZipArchive is available for DOCX and PPTX.
	 */
	public function zipAvailable(): bool {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Validate and re-encode a PNG rendered by PDF.js in the browser.
	 *
	 * @param string              $bytes    Raw PNG bytes.
	 * @param array<string,mixed> $expected Expected render size in points: widthPt, heightPt.
	 * @return array{bytes:string,width:int,height:int}|WP_Error
	 */
	public function validatePng( string $bytes, array $expected ): array|WP_Error {
		$width_pt  = isset( $expected['widthPt'] ) && is_numeric( $expected['widthPt'] ) ? (float) $expected['widthPt'] : 0.0;
		$height_pt = isset( $expected['heightPt'] ) && is_numeric( $expected['heightPt'] ) ? (float) $expected['heightPt'] : 0.0;

		if ( '' === $bytes || strlen( $bytes ) > self::MAX_PNG_BYTES || ! str_starts_with( $bytes, "\x89PNG\r\n\x1a\n" ) || $width_pt <= 0 || $height_pt <= 0 ) {
			return $this->invalidPngError();
		}

		$size = getimagesizefromstring( $bytes );

		if ( ! is_array( $size ) || IMAGETYPE_PNG !== ( $size[2] ?? null ) ) {
			return $this->invalidPngError();
		}

		$width  = absint( $size[0] );
		$height = absint( $size[1] );

		if ( $width < self::PNG_MIN_WIDTH || $width > self::PNG_MAX_WIDTH || $height < 1 || $height > self::PNG_MAX_HEIGHT ) {
			return $this->invalidPngError();
		}

		$expected_ratio = $width_pt / $height_pt;
		$actual_ratio   = $width / $height;

		if ( abs( $actual_ratio - $expected_ratio ) / $expected_ratio > self::PNG_ASPECT_TOLERANCE ) {
			return $this->invalidPngError();
		}

		$encoded = $this->reencodePng( $bytes );

		if ( is_wp_error( $encoded ) ) {
			return $encoded;
		}

		$final = getimagesizefromstring( $encoded );

		if ( ! is_array( $final ) || IMAGETYPE_PNG !== ( $final[2] ?? null ) || absint( $final[0] ) !== $width || absint( $final[1] ) !== $height ) {
			return $this->invalidPngError();
		}

		return array(
			'bytes'  => $encoded,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Validate a local file that PHP accepted as an upload.
	 *
	 * @param string $tmp_path      Temporary path.
	 * @param string $original_name Browser-supplied name.
	 * @return array{format:string,mimeType:string,originalName:string,byteSize:int,tmpPath:string}|WP_Error
	 */
	private function validatePath( string $tmp_path, string $original_name ): array|WP_Error {
		$name      = $this->sanitizeOriginalName( $original_name );
		$extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( in_array( $extension, self::MACRO_EXTENSIONS, true ) ) {
			return $this->macroError();
		}

		if ( ! isset( self::MIME_TYPES[ $extension ] ) ) {
			return new WP_Error(
				'docsync_wp_import_unsupported_type',
				__( 'Only Word (.docx), PowerPoint (.pptx), and PDF files can be imported.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 415 )
			);
		}

		$filesystem = $this->filesystem();
		$size       = $filesystem->is_file( $tmp_path ) ? absint( $filesystem->size( $tmp_path ) ) : 0;

		if ( $size <= 0 ) {
			return $this->invalidFileError();
		}

		if ( $size > $this->limits()['maxFileBytes'] ) {
			return $this->tooLargeError();
		}

		$checked = self::FORMAT_PDF === $extension
			? $this->validatePdf( $tmp_path )
			: $this->validateOfficePackage( $tmp_path, $extension );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		return array(
			'format'       => $extension,
			'mimeType'     => self::MIME_TYPES[ $extension ],
			'originalName' => $name,
			'byteSize'     => $size,
			'tmpPath'      => $tmp_path,
		);
	}

	/**
	 * Check PDF structure and reject encrypted files.
	 *
	 * @param string $path Local path.
	 * @return true|WP_Error
	 */
	private function validatePdf( string $path ): bool|WP_Error {
		$bytes = $this->filesystem()->get_contents( $path );

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return $this->invalidFileError();
		}

		$header = strpos( substr( $bytes, 0, self::PDF_HEADER_SEARCH_BYTES ), '%PDF-' );

		if ( false === $header || ! str_contains( substr( $bytes, -self::PDF_TRAILER_SEARCH_BYTES ), '%%EOF' ) ) {
			return $this->invalidFileError();
		}

		if ( 1 === preg_match( '/\/Encrypt\s*(?:\d+\s+\d+\s+R|<<)/', $bytes ) ) {
			return new WP_Error(
				'docsync_wp_import_pdf_encrypted',
				__( 'This PDF is password-protected or encrypted. Remove the protection, then upload it again.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 422 )
			);
		}

		return true;
	}

	/**
	 * Check a DOCX or PPTX package.
	 *
	 * @param string $path   Local path.
	 * @param string $format docx or pptx.
	 * @return true|WP_Error
	 */
	private function validateOfficePackage( string $path, string $format ): bool|WP_Error {
		if ( ! $this->zipAvailable() ) {
			return new WP_Error(
				'docsync_wp_import_zip_unavailable',
				__( 'Word and PowerPoint imports need the PHP zip extension (ZipArchive), which is not installed on this server. Ask your host to enable it, or upload a PDF instead.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 422 )
			);
		}

		$signature = $this->filesystem()->get_contents( $path );

		if ( ! is_string( $signature ) || ! str_starts_with( $signature, "PK\x03\x04" ) ) {
			return $this->invalidFileError();
		}

		unset( $signature );

		$zip    = new ZipArchive();
		$opened = $zip->open( $path, ZipArchive::CHECKCONS );

		if ( true !== $opened ) {
			return $this->invalidFileError();
		}

		try {
			return $this->inspectArchive( $zip, $format );
		} finally {
			$zip->close();
		}
	}

	/**
	 * Inspect entries, expansion bounds, macros, and the main part.
	 *
	 * @param ZipArchive $zip    Open archive.
	 * @param string     $format docx or pptx.
	 * @return true|WP_Error
	 */
	private function inspectArchive( ZipArchive $zip, string $format ): bool|WP_Error {
		$count = $zip->count();

		if ( $count < 2 || $count > self::MAX_ZIP_ENTRIES ) {
			return $this->archiveUnsafeError();
		}

		$expanded = 0;
		$names    = array();

		for ( $index = 0; $index < $count; ++$index ) {
			$stat = $zip->statIndex( $index );

			if ( ! is_array( $stat ) || ! isset( $stat['name'], $stat['size'], $stat['comp_size'] ) ) {
				return $this->archiveUnsafeError();
			}

			$name = (string) $stat['name'];
			$size = (int) $stat['size'];
			$comp = (int) $stat['comp_size'];

			if ( ! $this->isSafeEntryName( $name ) || $size < 0 || $size > self::MAX_ZIP_ENTRY_BYTES ) {
				return $this->archiveUnsafeError();
			}

			if ( ! empty( $stat['encryption_method'] ) ) {
				return $this->archiveUnsafeError();
			}

			if ( $size >= self::ZIP_RATIO_MIN_BYTES && ( $comp <= 0 || $size / $comp > self::MAX_ZIP_RATIO ) ) {
				return $this->archiveUnsafeError();
			}

			$expanded += $size;

			if ( $expanded > self::MAX_ZIP_EXPANDED_BYTES ) {
				return $this->archiveUnsafeError();
			}

			$lower = strtolower( $name );

			if ( str_ends_with( $lower, 'vbaproject.bin' ) || str_ends_with( $lower, 'vbadata.xml' ) || str_contains( $lower, '/activex/' ) ) {
				return $this->macroError();
			}

			$names[ $lower ] = $name;
		}

		if ( ! isset( $names['[content_types].xml'], $names[ self::MAIN_PART[ $format ] ] ) ) {
			return isset( $names[ self::MAIN_PART[ self::FORMAT_DOCX === $format ? self::FORMAT_PPTX : self::FORMAT_DOCX ] ] )
				? $this->mismatchError()
				: $this->invalidFileError();
		}

		$content_stat = $zip->statName( $names['[content_types].xml'] );

		if ( ! is_array( $content_stat ) || (int) $content_stat['size'] > self::MAX_CONTENT_TYPES_BYTES ) {
			return $this->archiveUnsafeError();
		}

		$content_types = $zip->getFromName( $names['[content_types].xml'] );

		if ( ! is_string( $content_types ) || '' === $content_types ) {
			return $this->invalidFileError();
		}

		if ( 1 === preg_match( '/<!DOCTYPE|<!ENTITY/i', $content_types ) ) {
			return $this->archiveUnsafeError();
		}

		$lower_types = strtolower( $content_types );

		if ( str_contains( $lower_types, 'macroenabled' ) || str_contains( $lower_types, 'vbaproject' ) || str_contains( $lower_types, 'activex' ) ) {
			return $this->macroError();
		}

		if ( ! str_contains( $lower_types, self::MAIN_CONTENT_TYPES[ $format ] ) ) {
			return $this->mismatchError();
		}

		return true;
	}

	/**
	 * Whether a ZIP entry name is a safe relative path.
	 *
	 * @param string $name Entry name.
	 */
	private function isSafeEntryName( string $name ): bool {
		if ( '' === $name || strlen( $name ) > 512 || str_contains( $name, "\0" ) || str_contains( $name, '\\' ) ) {
			return false;
		}

		if ( str_starts_with( $name, '/' ) || 1 === preg_match( '/^[A-Za-z]:/', $name ) ) {
			return false;
		}

		foreach ( explode( '/', $name ) as $segment ) {
			if ( '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Re-encode a PNG with GD (when available) to strip metadata and chunks.
	 *
	 * @param string $bytes Validated PNG bytes.
	 * @return string|WP_Error
	 */
	private function reencodePng( string $bytes ): string|WP_Error {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$source = wp_tempnam( 'docsync-wp-render' );
		$target = is_string( $source ) && '' !== $source ? $source . '-clean.png' : '';

		if ( '' === $target || ! $this->filesystem()->put_contents( $source, $bytes, 0600 ) ) {
			if ( is_string( $source ) && '' !== $source ) {
				$this->filesystem()->delete( $source );
			}

			return $this->invalidPngError();
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$prefer_gd = static function ( array $editors ): array {
			return in_array( 'WP_Image_Editor_GD', $editors, true ) ? array( 'WP_Image_Editor_GD' ) : $editors;
		};

		add_filter( 'wp_image_editors', $prefer_gd );

		try {
			$editor = wp_get_image_editor( $source, array( 'mime_type' => 'image/png' ) );

			if ( is_wp_error( $editor ) ) {
				return $this->invalidPngError();
			}

			$saved = $editor->save( $target, 'image/png' );

			if ( is_wp_error( $saved ) || ! is_array( $saved ) || empty( $saved['path'] ) ) {
				return $this->invalidPngError();
			}

			$clean = $this->filesystem()->get_contents( (string) $saved['path'] );

			if ( (string) $saved['path'] !== $target ) {
				$this->filesystem()->delete( (string) $saved['path'] );
			}

			if ( ! is_string( $clean ) || '' === $clean || strlen( $clean ) > self::MAX_PNG_BYTES || ! str_starts_with( $clean, "\x89PNG\r\n\x1a\n" ) ) {
				return $this->invalidPngError();
			}

			return $clean;
		} finally {
			remove_filter( 'wp_image_editors', $prefer_gd );
			$this->filesystem()->delete( $source );
			$this->filesystem()->delete( $target );
		}
	}

	/**
	 * Display-safe original name.
	 *
	 * @param string $name Browser-supplied name.
	 */
	private function sanitizeOriginalName( string $name ): string {
		$name = trim( sanitize_text_field( wp_basename( str_replace( '\\', '/', $name ) ) ) );

		if ( '' === $name ) {
			$name = 'upload';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $name, -self::MAX_NAME_LENGTH ) : substr( $name, -self::MAX_NAME_LENGTH );
	}

	/**
	 * Direct filesystem instance.
	 */
	private function filesystem(): \WP_Filesystem_Direct {
		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		return new \WP_Filesystem_Direct( null );
	}

	/**
	 * File too large error.
	 */
	private function tooLargeError(): WP_Error {
		$limit = $this->limits()['maxFileBytes'];

		return new WP_Error(
			'docsync_wp_import_file_too_large',
			sprintf(
				/* translators: %s: maximum file size, for example "25 MB". */
				__( 'This file is larger than the %s upload limit.', 'brasth-document-sync-for-google-docs' ),
				size_format( $limit )
			),
			array(
				'status'       => 413,
				'maxFileBytes' => $limit,
			)
		);
	}

	/**
	 * Invalid or unreadable file error.
	 */
	private function invalidFileError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_invalid_file',
			__( 'This file is damaged or is not a valid Word, PowerPoint, or PDF file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Extension and package content disagree.
	 */
	private function mismatchError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_invalid_file',
			__( 'This file’s contents do not match its extension. Save it again as .docx or .pptx, then upload it.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Macro-enabled file error.
	 */
	private function macroError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_macro_file',
			__( 'Files with macros or ActiveX controls cannot be imported. Save a copy without macros (.docx or .pptx), then upload it.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Unsafe archive error.
	 */
	private function archiveUnsafeError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_archive_unsafe',
			__( 'This file’s internal structure looks unsafe (too many parts, extreme compression, encryption, or unsafe paths), so it was not imported.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 422 )
		);
	}

	/**
	 * Invalid PNG render error.
	 */
	private function invalidPngError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_invalid_png',
			__( 'The rendered page image is not a valid PNG of the expected size.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
