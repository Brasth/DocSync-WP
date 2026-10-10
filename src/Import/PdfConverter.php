<?php
/**
 * Converts uploaded PDFs locally into canonical documents.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Local PDF → canonical conversion with `smalot/pdfparser`.
 *
 * Text and positions come from `Page::getDataTm()` (with font info). Layout
 * is inferred conservatively: two-column bands only when few lines cross the
 * page center, headings only from clearly larger font sizes, lists only from
 * explicit markers, and tables only when three or more consecutive lines
 * align to the same column anchors; other tabular-looking lines stay text
 * with a `pdfTableAsText` warning. Every lossy visual (embedded images,
 * vector drawings, low-density pages, image mode) becomes a `pdfPageRender`
 * asset in `pendingRender` status with page and crop bounds, which the
 * browser renders with PDF.js and uploads; each one carries a numbered
 * warning. Encrypted and scanned-only PDFs are rejected. Nothing leaves the
 * server and nothing reaches the Media Library before commit.
 */
final class PdfConverter {
	public const MAX_PAGES = 300;

	private const PARSER_CLASS        = '\Smalot\PdfParser\Parser';
	private const CONFIG_CLASS        = '\Smalot\PdfParser\Config';
	private const IMAGE_CLASS         = '\Smalot\PdfParser\XObject\Image';
	private const MIN_DOCUMENT_CHARS  = 20;
	private const LOW_DENSITY_CHARS   = 80;
	private const HEADING_RATIO_MAJOR = 1.6;
	private const HEADING_RATIO_MINOR = 1.25;
	private const HEADING_MAX_WORDS   = 20;
	private const BACKGROUND_COVERAGE = 0.85;
	private const MIN_FIGURE_POINTS   = 24.0;
	private const MIN_FIGURE_COVERAGE = 0.01;
	private const VECTOR_OP_THRESHOLD = 60;
	private const TABLE_MIN_ROWS      = 3;
	private const ANCHOR_TOLERANCE    = 6.0;
	private const COLUMN_CROSS_RATIO  = 0.15;
	private const COLUMN_SIDE_RATIO   = 0.25;
	private const COLUMN_MIN_LINES    = 8;
	private const DEFAULT_PAGE_WIDTH  = 612.0;
	private const DEFAULT_PAGE_HEIGHT = 792.0;
	private const LIST_MARKER         = '/^\s*(?:[\x{2022}\x{25E6}\x{25AA}\x{25CF}\x{2023}\x{2043}\x{2013}\x{2014}*-]|(\d{1,3}|[a-zA-Z]|[ivxlcdmIVXLCDM]{1,6})[.)])\s+/u';

	/**
	 * Private asset store.
	 *
	 * @var PrivateAssetStore
	 */
	private PrivateAssetStore $assets;

	/**
	 * Per-conversion state.
	 *
	 * @var array<string,mixed>
	 */
	private array $state = array();

	/**
	 * Constructor.
	 *
	 * @param PrivateAssetStore $assets Private asset store.
	 */
	public function __construct( PrivateAssetStore $assets ) {
		$this->assets = $assets;
	}

	/**
	 * Convert the selected pages of one uploaded PDF.
	 *
	 * @param int                 $user_id    Session owner (unused; conversion is local).
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $file       Session file record (fileId, originalName, originalKey, sha256, assetIndex).
	 * @param array<string,mixed> $options    Normalized options; uses pdf.pages and pdf.renderMode.
	 * @return array{document:CanonicalDocument,googleFileId:string,googleTemporaries:array<int,string>,pageCount:int}|WP_Error
	 */
	public function convert( int $user_id, string $session_id, array $file, array $options ): array|WP_Error {
		unset( $user_id );

		if ( ! class_exists( self::PARSER_CLASS ) ) {
			return new WP_Error(
				'docsync_wp_import_pdf_unavailable',
				__( 'PDF import is unavailable because the PDF parser library is missing from this installation.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 503 )
			);
		}

		$path = $this->assets->materialize( $session_id, (string) ( $file['originalKey'] ?? '' ) );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		try {
			$pdf = $this->parse( $path );
		} finally {
			$this->assets->release( $path );
		}

		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}

		try {
			$pages = array_values( $pdf->getPages() );
		} catch ( Throwable $exception ) {
			return $this->invalidPdfError();
		}

		$page_count = count( $pages );

		if ( 0 === $page_count ) {
			return $this->invalidPdfError();
		}

		if ( $page_count > self::MAX_PAGES ) {
			return new WP_Error(
				'docsync_wp_import_too_many_pages',
				__( 'This PDF has more than 300 pages. Split it into smaller files, then upload them.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 422 )
			);
		}

		$pdf_options = isset( $options['pdf'] ) && is_array( $options['pdf'] ) ? $options['pdf'] : array();
		$mode        = in_array( $pdf_options['renderMode'] ?? 'auto', array( 'auto', 'text', 'image' ), true ) ? (string) ( $pdf_options['renderMode'] ?? 'auto' ) : 'auto';
		$selected    = $this->selectedPages( $pdf_options['pages'] ?? array(), $page_count );

		$this->state = array(
			'fileId'     => (string) ( $file['fileId'] ?? '' ),
			'sessionId'  => $session_id,
			'assetIndex' => isset( $file['assetIndex'] ) && is_array( $file['assetIndex'] ) ? $file['assetIndex'] : array(),
			'assets'     => array(),
			'warnings'   => array(),
			'chars'      => 0,
		);

		$analyzed = array();

		foreach ( $selected as $number ) {
			$analyzed[ $number ] = $this->analyzePage( $pages[ $number - 1 ] );
		}

		if ( $this->state['chars'] < self::MIN_DOCUMENT_CHARS ) {
			return new WP_Error(
				'docsync_wp_import_pdf_scanned',
				__( 'This PDF has no selectable text (it looks scanned). Run OCR on it first, or upload the original document.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 422 )
			);
		}

		$body_size = $this->bodyFontSize( $analyzed );
		$sections  = array();

		foreach ( $analyzed as $number => $page ) {
			$sections[] = $this->buildPage( $number, $page, $mode, $body_size );
		}

		$document = CanonicalDocument::fromArray(
			array(
				'version'  => CanonicalDocument::VERSION,
				'title'    => $this->documentTitle( $pdf, $sections, (string) ( $file['originalName'] ?? '' ) ),
				'source'   => array(
					'format'       => 'pdf',
					'originalName' => (string) ( $file['originalName'] ?? '' ),
					'sha256'       => (string) ( $file['sha256'] ?? '' ),
					'pages'        => $page_count,
				),
				'sections' => $sections,
				'assets'   => array_values( $this->state['assets'] ),
				'warnings' => $this->state['warnings'],
			)
		);

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return array(
			'document'          => $document,
			'googleFileId'      => '',
			'googleTemporaries' => array(),
			'pageCount'         => $page_count,
		);
	}

	/**
	 * Parse a PDF file, rejecting encrypted documents.
	 *
	 * @param string $path Plaintext path.
	 * @return object smalot Document, or WP_Error.
	 */
	private function parse( string $path ): object {
		try {
			$config_class = self::CONFIG_CLASS;
			$parser_class = self::PARSER_CLASS;
			$config       = new $config_class();

			$config->setDataTmFontInfoHasToBeIncluded( true );
			$config->setRetainImageContent( false );

			$parser = new $parser_class( array(), $config );

			return $parser->parseFile( $path );
		} catch ( Throwable $exception ) {
			if ( false !== stripos( $exception->getMessage(), 'secured' ) || false !== stripos( $exception->getMessage(), 'encrypt' ) ) {
				return new WP_Error(
					'docsync_wp_import_pdf_encrypted',
					__( 'This PDF is password-protected or encrypted. Remove the protection, then upload it again.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 422 )
				);
			}

			return $this->invalidPdfError();
		}
	}

	/**
	 * Valid, sorted, unique 1-based page selection (every page by default).
	 *
	 * @param mixed $pages      Requested pages.
	 * @param int   $page_count Page count.
	 * @return array<int,int>
	 */
	private function selectedPages( mixed $pages, int $page_count ): array {
		$selected = array();

		foreach ( is_array( $pages ) ? $pages : array() as $page ) {
			if ( is_int( $page ) && $page >= 1 && $page <= $page_count ) {
				$selected[ $page ] = $page;
			}
		}

		if ( array() === $selected ) {
			return range( 1, $page_count );
		}

		ksort( $selected );

		return array_values( $selected );
	}

	/**
	 * Extract text lines, figures, and drawing statistics from one page.
	 *
	 * @param object $page smalot Page.
	 * @return array<string,mixed>
	 */
	private function analyzePage( object $page ): array {
		list( $width, $height ) = $this->pageSize( $page );

		$items   = $this->textItems( $page, $height );
		$figures = $this->imageFigures( $page, $width, $height );
		$vector  = $this->vectorDrawing( $page, $width, $height );
		$chars   = 0;

		foreach ( $items as $item ) {
			$chars += strlen( (string) preg_replace( '/\s+/u', '', $item['text'] ) );
		}

		$this->state['chars'] += $chars;

		return array(
			'width'   => $width,
			'height'  => $height,
			'lines'   => $this->buildLines( $items ),
			'chars'   => $chars,
			'figures' => $figures,
			'vector'  => $vector,
		);
	}

	/**
	 * Page size in points (MediaBox, honoring 90/270 rotation).
	 *
	 * @param object $page smalot Page.
	 * @return array{0:float,1:float}
	 */
	private function pageSize( object $page ): array {
		$width  = self::DEFAULT_PAGE_WIDTH;
		$height = self::DEFAULT_PAGE_HEIGHT;

		try {
			$details = $page->getDetails();
			$box     = $details['CropBox'] ?? $details['MediaBox'] ?? null;

			if ( is_array( $box ) && 4 === count( $box ) ) {
				$box    = array_map( 'floatval', array_values( $box ) );
				$width  = abs( $box[2] - $box[0] );
				$height = abs( $box[3] - $box[1] );
			}

			$rotate = isset( $details['Rotate'] ) ? absint( $details['Rotate'] ) % 360 : 0;

			if ( 90 === $rotate || 270 === $rotate ) {
				list( $width, $height ) = array( $height, $width );
			}
		} catch ( Throwable $exception ) {
			unset( $exception );
		}

		if ( $width < 1 || $height < 1 || $width > 20000 || $height > 20000 ) {
			return array( self::DEFAULT_PAGE_WIDTH, self::DEFAULT_PAGE_HEIGHT );
		}

		return array( $width, $height );
	}

	/**
	 * Positioned text items with effective font size and style.
	 *
	 * @param object $page        smalot Page.
	 * @param float  $page_height Page height.
	 * @return array<int,array<string,mixed>>
	 */
	private function textItems( object $page, float $page_height ): array {
		try {
			$data = $page->getDataTm();
		} catch ( Throwable $exception ) {
			return array();
		}

		$items = array();
		$fonts = array();

		foreach ( is_array( $data ) ? $data : array() as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry[0], $entry[1] ) || ! is_array( $entry[0] ) || count( $entry[0] ) < 6 ) {
				continue;
			}

			$text = $this->cleanText( (string) $entry[1] );

			if ( '' === trim( $text ) ) {
				continue;
			}

			$matrix = array_map( 'floatval', array_values( $entry[0] ) );
			$font   = isset( $entry[3] ) && is_numeric( $entry[3] ) ? (float) $entry[3] : 1.0;
			$scale  = sqrt( $matrix[2] * $matrix[2] + $matrix[3] * $matrix[3] );
			$size   = round( max( 1.0, abs( $font * ( $scale > 0 ? $scale : 1.0 ) ) ), 1 );
			$size   = $size > 200 ? 12.0 : $size;
			$style  = $this->fontStyle( $page, isset( $entry[2] ) ? (string) $entry[2] : '', $fonts );
			$x      = $matrix[4];
			$top    = $page_height - $matrix[5] - $size;

			$items[] = array(
				'text'   => $text,
				'x'      => round( $x, 2 ),
				'top'    => round( $top, 2 ),
				'size'   => $size,
				'width'  => round( $this->textWidth( $text, $size ), 2 ),
				'bold'   => $style['bold'],
				'italic' => $style['italic'],
			);
		}

		return $items;
	}

	/**
	 * Bold and italic flags from a font name.
	 *
	 * @param object              $page    smalot Page.
	 * @param string              $font_id Font ID from getDataTm.
	 * @param array<string,array> $cache   Per-page cache (updated).
	 * @return array{bold:bool,italic:bool}
	 */
	private function fontStyle( object $page, string $font_id, array &$cache ): array {
		$font_id = ltrim( trim( $font_id ), '/' );

		if ( isset( $cache[ $font_id ] ) ) {
			return $cache[ $font_id ];
		}

		$name = '';

		try {
			$font = '' !== $font_id && method_exists( $page, 'getFont' ) ? $page->getFont( $font_id ) : null;
			$name = is_object( $font ) && method_exists( $font, 'getName' ) ? (string) $font->getName() : '';
		} catch ( Throwable $exception ) {
			$name = '';
		}

		$cache[ $font_id ] = array(
			'bold'   => 1 === preg_match( '/bold|black|heavy|semibold|demi/i', $name ),
			'italic' => 1 === preg_match( '/italic|oblique/i', $name ),
		);

		return $cache[ $font_id ];
	}

	/**
	 * Group text items into lines with segments split at wide gaps.
	 *
	 * @param array<int,array<string,mixed>> $items Text items.
	 * @return array<int,array<string,mixed>>
	 */
	private function buildLines( array $items ): array {
		usort(
			$items,
			static function ( array $a, array $b ): int {
				return array( $a['top'], $a['x'] ) <=> array( $b['top'], $b['x'] );
			}
		);

		$lines = array();

		foreach ( $items as $item ) {
			$last = count( $lines ) - 1;

			if ( $last >= 0 && abs( $lines[ $last ]['top'] - $item['top'] ) <= 0.5 * max( $lines[ $last ]['size'], $item['size'] ) ) {
				$lines[ $last ]['items'][] = $item;
				$lines[ $last ]['size']    = max( $lines[ $last ]['size'], $item['size'] );
				continue;
			}

			$lines[] = array(
				'top'   => $item['top'],
				'size'  => $item['size'],
				'items' => array( $item ),
			);
		}

		foreach ( $lines as $index => $line ) {
			usort(
				$line['items'],
				static function ( array $a, array $b ): int {
					return $a['x'] <=> $b['x'];
				}
			);

			$segments = array();
			$previous = null;

			foreach ( $line['items'] as $item ) {
				$gap = null !== $previous ? $item['x'] - ( $previous['x'] + $previous['width'] ) : 0.0;

				if ( null === $previous || $gap > 2.0 * $item['size'] ) {
					$segments[] = array(
						'x0'    => $item['x'],
						'x1'    => $item['x'] + $item['width'],
						'items' => array( $item ),
					);
				} else {
					$last                         = count( $segments ) - 1;
					$segments[ $last ]['items'][] = $item;
					$segments[ $last ]['x1']      = max( $segments[ $last ]['x1'], $item['x'] + $item['width'] );
				}

				$previous = $item;
			}

			$lines[ $index ]['items']    = $line['items'];
			$lines[ $index ]['segments'] = $segments;
			$lines[ $index ]['x0']       = $segments[0]['x0'];
			$lines[ $index ]['x1']       = max( array_column( $segments, 'x1' ) );
		}

		return $lines;
	}

	/**
	 * Embedded image placements as figure bounds (top-left points).
	 *
	 * Graphics state is tracked through q, Q, and cm so the unit square of
	 * each image `Do` maps to its page rectangle.
	 *
	 * @param object $page   smalot Page.
	 * @param float  $width  Page width.
	 * @param float  $height Page height.
	 * @return array<int,array<string,mixed>>
	 */
	private function imageFigures( object $page, float $width, float $height ): array {
		try {
			$xobjects = $page->getXObjects();
		} catch ( Throwable $exception ) {
			return array();
		}

		$images = array();

		foreach ( is_array( $xobjects ) ? $xobjects : array() as $name => $xobject ) {
			if ( is_a( $xobject, ltrim( self::IMAGE_CLASS, '\\' ) ) ) {
				$images[ (string) $name ] = true;
			}
		}

		if ( array() === $images ) {
			return array();
		}

		try {
			$commands = $page->extractRawData();
		} catch ( Throwable $exception ) {
			$commands = array();
		}

		$figures = array();
		$ctm     = array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );
		$stack   = array();

		foreach ( is_array( $commands ) ? $commands : array() as $command ) {
			$operator = is_array( $command ) ? (string) ( $command['o'] ?? '' ) : '';
			$content  = is_array( $command ) && is_string( $command['c'] ?? null ) ? $command['c'] : '';

			if ( 'q' === $operator ) {
				$stack[] = $ctm;
			} elseif ( 'Q' === $operator ) {
				$ctm = array() !== $stack ? array_pop( $stack ) : array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );
			} elseif ( 'cm' === $operator ) {
				$parts  = preg_split( '/\s+/', trim( $content ) );
				$values = is_array( $parts ) ? array_map( 'floatval', $parts ) : array();

				if ( 6 === count( $values ) ) {
					$ctm = $this->multiply( $values, $ctm );
				}
			} elseif ( 'Do' === $operator ) {
				$name = ltrim( trim( $content ), '/' );

				if ( isset( $images[ $name ] ) ) {
					$figures[] = $this->unitSquareBounds( $ctm, $width, $height );
				}
			}
		}

		if ( array() === $figures ) {
			// Image resources without a traceable placement: fall back to the whole page.
			$figures[] = array(
				'x'      => 0.0,
				'y'      => 0.0,
				'width'  => round( $width, 2 ),
				'height' => round( $height, 2 ),
			);
		}

		return $figures;
	}

	/**
	 * Vector drawing summary: whether the page draws enough paths to be a figure, and its bounds.
	 *
	 * @param object $page   smalot Page.
	 * @param float  $width  Page width.
	 * @param float  $height Page height.
	 * @return array{present:bool,bounds:array<string,float>}
	 */
	private function vectorDrawing( object $page, float $width, float $height ): array {
		$full    = array(
			'x'      => 0.0,
			'y'      => 0.0,
			'width'  => round( $width, 2 ),
			'height' => round( $height, 2 ),
		);
		$content = $this->pageContentStream( $page );

		if ( '' === $content ) {
			return array(
				'present' => false,
				'bounds'  => $full,
			);
		}

		$operations = (int) preg_match_all( '/(?<=\s)(?:re|l|c|v|y)(?=\s)/', ' ' . $content . ' ' );

		if ( $operations < self::VECTOR_OP_THRESHOLD ) {
			return array(
				'present' => false,
				'bounds'  => $full,
			);
		}

		$points = array();

		if ( preg_match_all( '/(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+re(?=\s)/', $content, $rects, PREG_SET_ORDER ) ) {
			foreach ( $rects as $rect ) {
				$area = abs( (float) $rect[3] * (float) $rect[4] );

				if ( $area >= 0.9 * $width * $height ) {
					continue;
				}

				$points[] = array( (float) $rect[1], (float) $rect[2] );
				$points[] = array( (float) $rect[1] + (float) $rect[3], (float) $rect[2] + (float) $rect[4] );
			}
		}

		if ( preg_match_all( '/(-?\d*\.?\d+)\s+(-?\d*\.?\d+)\s+[ml](?=\s)/', $content, $moves, PREG_SET_ORDER ) ) {
			foreach ( $moves as $move ) {
				$points[] = array( (float) $move[1], (float) $move[2] );
			}
		}

		$bounds = $full;

		if ( count( $points ) >= 2 ) {
			$xs = array_column( $points, 0 );
			$ys = array_column( $points, 1 );
			$x0 = max( 0.0, min( $xs ) );
			$x1 = min( $width, max( $xs ) );
			$y0 = max( 0.0, min( $ys ) );
			$y1 = min( $height, max( $ys ) );

			if ( $x1 - $x0 >= self::MIN_FIGURE_POINTS && $y1 - $y0 >= self::MIN_FIGURE_POINTS ) {
				$bounds = array(
					'x'      => round( $x0, 2 ),
					'y'      => round( $height - $y1, 2 ),
					'width'  => round( $x1 - $x0, 2 ),
					'height' => round( $y1 - $y0, 2 ),
				);
			}
		}

		return array(
			'present' => true,
			'bounds'  => $bounds,
		);
	}

	/**
	 * Decoded page content stream (all parts concatenated).
	 *
	 * @param object $page smalot Page.
	 */
	private function pageContentStream( object $page ): string {
		try {
			$contents = $page->get( 'Contents' );
			$value    = is_object( $contents ) && method_exists( $contents, 'getContent' ) ? $contents->getContent() : null;

			if ( is_string( $value ) ) {
				return $value;
			}

			$stream = '';

			foreach ( is_array( $value ) ? $value : array() as $part ) {
				if ( is_object( $part ) && method_exists( $part, 'getObject' ) ) {
					$part = $part->getObject();
				}

				if ( is_object( $part ) && method_exists( $part, 'getContent' ) ) {
					$chunk   = $part->getContent();
					$stream .= is_string( $chunk ) ? $chunk . "\n" : '';
				}
			}

			return $stream;
		} catch ( Throwable $exception ) {
			return '';
		}
	}

	/**
	 * Build one page section.
	 *
	 * @param int                 $number    Page number.
	 * @param array<string,mixed> $page      Analyzed page.
	 * @param string              $mode      auto, text, or image.
	 * @param float               $body_size Document body font size.
	 * @return array<string,mixed>
	 */
	private function buildPage( int $number, array $page, string $mode, float $body_size ): array {
		$origin  = array( 'page' => $number );
		$counter = 0;
		$next_id = function () use ( $number, &$counter ): string {
			++$counter;

			return 'p' . $number . 'b' . $counter;
		};
		$full    = array(
			'x'      => 0.0,
			'y'      => 0.0,
			'width'  => round( $page['width'], 2 ),
			'height' => round( $page['height'], 2 ),
		);

		$has_visual = array() !== $page['figures'] || $page['vector']['present'];

		if ( 'image' === $mode || ( 'auto' === $mode && $page['chars'] < self::LOW_DENSITY_CHARS && ( $has_visual || $page['chars'] > 0 ) ) ) {
			$this->warn(
				'pageRenderedAsImage',
				'image' === $mode
					/* translators: %d: page number. */
					? sprintf( __( 'Page %d was imported as an image, as requested.', 'brasth-document-sync-for-google-docs' ), $number )
					/* translators: %d: page number. */
					: sprintf( __( 'Page %d has little text, so it was imported as an image.', 'brasth-document-sync-for-google-docs' ), $number ),
				'info',
				array_merge( $origin, array( 'bounds' => $full ) )
			);

			return array(
				'id'     => 'p' . $number,
				'kind'   => 'page',
				'title'  => null,
				'origin' => $origin,
				'blocks' => array( $this->renderBlock( $number, $full, $next_id, true ) ),
			);
		}

		$entries = $this->textEntries( $number, $page, $body_size, $next_id );
		$visuals = $this->visualEntries( $number, $page, $mode, $next_id );
		$merged  = array_merge( $entries, $visuals );

		usort(
			$merged,
			static function ( array $a, array $b ): int {
				return array( $a['order'], $a['seq'] ) <=> array( $b['order'], $b['seq'] );
			}
		);

		$blocks = array_column( $merged, 'block' );

		if ( array() === $blocks ) {
			$this->warn(
				'emptySection',
				/* translators: %d: page number. */
				sprintf( __( 'Page %d has no text or images to import.', 'brasth-document-sync-for-google-docs' ), $number ),
				'info',
				$origin
			);
		}

		return array(
			'id'     => 'p' . $number,
			'kind'   => 'page',
			'title'  => null,
			'origin' => $origin,
			'blocks' => $blocks,
		);
	}

	/**
	 * Text blocks for a page, each with its reading-order key.
	 *
	 * @param int                 $number    Page number.
	 * @param array<string,mixed> $page      Analyzed page.
	 * @param float               $body_size Body font size.
	 * @param callable            $next_id   Block ID generator.
	 * @return array<int,array{order:float,seq:int,block:array<string,mixed>}>
	 */
	private function textEntries( int $number, array $page, float $body_size, callable $next_id ): array {
		$lines   = $this->readingOrder( $page['lines'], (float) $page['width'] );
		$entries = array();
		$seq     = 0;
		$count   = count( $lines );
		$warned  = false;

		for ( $index = 0; $index < $count; ) {
			$table_end = $this->tableRunEnd( $lines, $index );

			if ( null !== $table_end ) {
				$block     = $this->tableBlock( array_slice( $lines, $index, $table_end - $index + 1 ), $number, $next_id );
				$entries[] = array(
					'order' => $lines[ $index ]['order'],
					'seq'   => $seq++,
					'block' => $block,
				);
				$index     = $table_end + 1;
				continue;
			}

			if ( ! $warned && count( $lines[ $index ]['segments'] ) >= 3 ) {
				$warned = true;
				$this->warn(
					'pdfTableAsText',
					/* translators: %d: page number. */
					sprintf( __( 'Page %d has text laid out like a table that could not be rebuilt reliably; it was kept as paragraphs.', 'brasth-document-sync-for-google-docs' ), $number ),
					'warning',
					array(
						'page'   => $number,
						'bounds' => array(
							'x'      => round( $lines[ $index ]['x0'], 2 ),
							'y'      => round( max( 0.0, $lines[ $index ]['top'] ), 2 ),
							'width'  => round( max( 0.0, $lines[ $index ]['x1'] - $lines[ $index ]['x0'] ), 2 ),
							'height' => round( $lines[ $index ]['size'], 2 ),
						),
					)
				);
			}

			$paragraph = array( $lines[ $index ] );
			$cursor    = $index + 1;

			while ( $cursor < $count && null === $this->tableRunEnd( $lines, $cursor ) && $this->continuesParagraph( $lines[ $cursor - 1 ], $lines[ $cursor ] ) ) {
				$paragraph[] = $lines[ $cursor ];
				++$cursor;
			}

			$block = $this->paragraphBlock( $paragraph, $number, $body_size, $next_id );

			if ( null !== $block ) {
				$entries[] = array(
					'order' => $lines[ $index ]['order'],
					'seq'   => $seq++,
					'block' => $block,
				);
			}

			$index = $cursor;
		}

		return $this->groupLists( $entries, $number, $next_id );
	}

	/**
	 * Order lines for reading, splitting conservative two-column bands.
	 *
	 * Lines crossing the page center separate bands; inside a band, left
	 * column lines come before right column lines. Each line gets an `order`
	 * key used to place figures between text.
	 *
	 * @param array<int,array<string,mixed>> $lines Lines sorted top to bottom.
	 * @param float                          $width Page width.
	 * @return array<int,array<string,mixed>>
	 */
	private function readingOrder( array $lines, float $width ): array {
		$lines    = $this->splitColumnLines( $lines, $width );
		$mid      = $width / 2;
		$crossing = 0;
		$left     = 0;
		$right    = 0;

		foreach ( $lines as $line ) {
			if ( $line['x0'] < $mid - 6 && $line['x1'] > $mid + 6 ) {
				++$crossing;
			} elseif ( $line['x1'] <= $mid + 6 ) {
				++$left;
			} else {
				++$right;
			}
		}

		$total   = count( $lines );
		$columns = $total >= self::COLUMN_MIN_LINES
			&& $crossing <= self::COLUMN_CROSS_RATIO * $total
			&& $left >= self::COLUMN_SIDE_RATIO * $total
			&& $right >= self::COLUMN_SIDE_RATIO * $total;

		if ( ! $columns ) {
			foreach ( $lines as $index => $line ) {
				$lines[ $index ]['order'] = $line['top'];
			}

			return $lines;
		}

		$ordered = array();
		$band    = array(
			'left'  => array(),
			'right' => array(),
		);
		$flush   = static function () use ( &$ordered, &$band ): void {
			foreach ( array_merge( $band['left'], $band['right'] ) as $line ) {
				$ordered[] = $line;
			}

			$band = array(
				'left'  => array(),
				'right' => array(),
			);
		};

		foreach ( $lines as $line ) {
			if ( $line['x0'] < $mid - 6 && $line['x1'] > $mid + 6 ) {
				$flush();
				$ordered[] = $line;
				continue;
			}

			$band[ $line['x1'] <= $mid + 6 ? 'left' : 'right' ][] = $line;
		}

		$flush();

		// Reading order keys keep the band order while staying comparable with figure tops.
		foreach ( $ordered as $index => $line ) {
			$ordered[ $index ]['order'] = $index > 0 ? max( $ordered[ $index - 1 ]['order'] + 0.001, $line['top'] ) : $line['top'];
		}

		return $ordered;
	}

	/**
	 * Split side-by-side prose columns that share baselines into separate lines.
	 *
	 * A line is split only when it has exactly two segments separated by a
	 * gutter that contains the page center, most lines on the page are either
	 * split that way or sit on one side, and the split segments read like
	 * prose (four or more words on average). Short tabular cells never split.
	 *
	 * @param array<int,array<string,mixed>> $lines Lines.
	 * @param float                          $width Page width.
	 * @return array<int,array<string,mixed>>
	 */
	private function splitColumnLines( array $lines, float $width ): array {
		$mid        = $width / 2;
		$splittable = 0;
		$one_side   = 0;
		$words      = 0;
		$segments   = 0;

		foreach ( $lines as $line ) {
			if ( 2 === count( $line['segments'] ) && $line['segments'][0]['x1'] < $mid && $line['segments'][1]['x0'] > $mid ) {
				++$splittable;

				foreach ( $line['segments'] as $segment ) {
					$words += count( explode( ' ', trim( implode( ' ', array_column( $segment['items'], 'text' ) ) ) ) );
					++$segments;
				}
			} elseif ( $line['x1'] <= $mid + 6 || $line['x0'] >= $mid - 6 ) {
				++$one_side;
			}
		}

		$total = count( $lines );

		if ( $splittable < self::COLUMN_MIN_LINES / 2 || ( $splittable + $one_side ) < 0.6 * $total || $segments < 1 || $words / $segments < 4 ) {
			return $lines;
		}

		$split = array();

		foreach ( $lines as $line ) {
			if ( 2 !== count( $line['segments'] ) || $line['segments'][0]['x1'] >= $mid || $line['segments'][1]['x0'] <= $mid ) {
				$split[] = $line;
				continue;
			}

			foreach ( $line['segments'] as $segment ) {
				$split[] = array(
					'top'      => $line['top'],
					'size'     => max( array_column( $segment['items'], 'size' ) ),
					'items'    => $segment['items'],
					'segments' => array( $segment ),
					'x0'       => $segment['x0'],
					'x1'       => $segment['x1'],
				);
			}
		}

		return $split;
	}

	/**
	 * End index of a table run starting at $start, or null when no table starts there.
	 *
	 * @param array<int,array<string,mixed>> $lines Ordered lines.
	 * @param int                            $start Start index.
	 */
	private function tableRunEnd( array $lines, int $start ): ?int {
		if ( count( $lines[ $start ]['segments'] ) < 2 ) {
			return null;
		}

		$anchors = array_column( $lines[ $start ]['segments'], 'x0' );
		$end     = $start;
		$count   = count( $lines );

		for ( $index = $start + 1; $index < $count; ++$index ) {
			$segments = $lines[ $index ]['segments'];

			if ( count( $segments ) < 2 || abs( count( $segments ) - count( $anchors ) ) > 1 ) {
				break;
			}

			$gap = $lines[ $index ]['top'] - $lines[ $index - 1 ]['top'];

			if ( $gap > 3.0 * max( $lines[ $index ]['size'], $lines[ $index - 1 ]['size'] ) ) {
				break;
			}

			$matched = 0;

			foreach ( $segments as $segment ) {
				foreach ( $anchors as $anchor ) {
					if ( abs( $segment['x0'] - $anchor ) <= self::ANCHOR_TOLERANCE ) {
						++$matched;
						break;
					}
				}
			}

			if ( $matched < 2 || $matched < count( $segments ) - 1 ) {
				break;
			}

			$end = $index;
		}

		return $end - $start + 1 >= self::TABLE_MIN_ROWS ? $end : null;
	}

	/**
	 * Table block from aligned lines.
	 *
	 * @param array<int,array<string,mixed>> $lines   Table lines.
	 * @param int                            $number  Page number.
	 * @param callable                       $next_id Block ID generator.
	 * @return array<string,mixed>
	 */
	private function tableBlock( array $lines, int $number, callable $next_id ): array {
		$anchors = array();

		foreach ( $lines as $line ) {
			foreach ( $line['segments'] as $segment ) {
				$found = false;

				foreach ( $anchors as $position => $anchor ) {
					if ( abs( $segment['x0'] - $anchor ) <= self::ANCHOR_TOLERANCE ) {
						$found = true;
						break;
					}
				}

				if ( ! $found ) {
					$anchors[] = $segment['x0'];
				}
			}
		}

		sort( $anchors );
		$anchors = array_slice( $anchors, 0, 64 );
		$rows    = array();

		foreach ( $lines as $row_index => $line ) {
			$cells = array_fill( 0, count( $anchors ), array() );

			foreach ( $line['segments'] as $segment ) {
				$best = 0;

				foreach ( $anchors as $position => $anchor ) {
					if ( abs( $segment['x0'] - $anchor ) < abs( $segment['x0'] - $anchors[ $best ] ) ) {
						$best = $position;
					}
				}

				$cells[ $best ] = array_merge( $cells[ $best ], $this->segmentRuns( $segment['items'] ) );
			}

			$header = 0 === $row_index && $this->allBold( $line['items'] );
			$row    = array();

			foreach ( $cells as $runs ) {
				$row[] = array(
					'runs'    => $this->mergeRuns( $header ? $this->plainRuns( $runs ) : $runs ),
					'header'  => $header,
					'colSpan' => 1,
					'rowSpan' => 1,
				);
			}

			$rows[] = array( 'cells' => $row );
		}

		return array(
			'type'   => 'table',
			'id'     => $next_id(),
			'rows'   => $rows,
			'origin' => array(
				'page'   => $number,
				'bounds' => $this->linesBounds( $lines ),
			),
		);
	}

	/**
	 * Whether the next line continues the current paragraph.
	 *
	 * @param array<string,mixed> $previous Previous line.
	 * @param array<string,mixed> $line     Candidate line.
	 */
	private function continuesParagraph( array $previous, array $line ): bool {
		$size = max( $previous['size'], $line['size'] );
		$gap  = $line['top'] - $previous['top'];

		if ( $gap <= 0 || $gap > 1.6 * $size ) {
			return false;
		}

		if ( abs( $previous['size'] - $line['size'] ) > 0.15 * $size ) {
			return false;
		}

		if ( 1 === preg_match( self::LIST_MARKER, $this->lineText( $line ) ) ) {
			return false;
		}

		return abs( $previous['x0'] - $line['x0'] ) <= 3.0 * $size;
	}

	/**
	 * Paragraph or heading block for consecutive lines.
	 *
	 * @param array<int,array<string,mixed>> $lines     Lines.
	 * @param int                            $number    Page number.
	 * @param float                          $body_size Body font size.
	 * @param callable                       $next_id   Block ID generator.
	 * @return array<string,mixed>|null
	 */
	private function paragraphBlock( array $lines, int $number, float $body_size, callable $next_id ): ?array {
		$runs = array();

		foreach ( $lines as $index => $line ) {
			$line_runs = $this->segmentRuns( $line['items'] );

			if ( $index > 0 && array() !== $runs ) {
				$last_text = (string) $runs[ count( $runs ) - 1 ]['text'];

				if ( str_ends_with( $last_text, '-' ) && isset( $line_runs[0] ) && 1 === preg_match( '/^\p{Ll}/u', (string) $line_runs[0]['text'] ) ) {
					$runs[ count( $runs ) - 1 ]['text'] = substr( $last_text, 0, -1 );
				} else {
					$runs[] = array( 'text' => ' ' );
				}
			}

			$runs = array_merge( $runs, $line_runs );
		}

		$runs = $this->mergeRuns( $runs );
		$text = trim( implode( '', array_column( $runs, 'text' ) ) );

		if ( '' === $text ) {
			return null;
		}

		$origin = array(
			'page'   => $number,
			'bounds' => $this->linesBounds( $lines ),
		);
		$size   = max( array_column( $lines, 'size' ) );
		$split  = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words  = is_array( $split ) ? count( $split ) : 0;
		$level  = null;

		if ( $body_size > 0 && count( $lines ) <= 2 && $words <= self::HEADING_MAX_WORDS && 1 !== preg_match( '/[.,;]$/u', $text ) ) {
			if ( $size >= self::HEADING_RATIO_MAJOR * $body_size ) {
				$level = 2;
			} elseif ( $size >= self::HEADING_RATIO_MINOR * $body_size ) {
				$level = 3;
			}
		}

		if ( null !== $level ) {
			return array(
				'type'   => 'heading',
				'id'     => $next_id(),
				'level'  => $level,
				'runs'   => $this->mergeRuns( $this->plainRuns( $runs ) ),
				'origin' => $origin,
			);
		}

		return array(
			'type'   => 'paragraph',
			'id'     => $next_id(),
			'runs'   => $runs,
			'origin' => $origin,
		);
	}

	/**
	 * Group consecutive marker paragraphs into list blocks.
	 *
	 * @param array<int,array<string,mixed>> $entries Ordered text entries.
	 * @param int                            $number  Page number.
	 * @param callable                       $next_id Block ID generator.
	 * @return array<int,array<string,mixed>>
	 */
	private function groupLists( array $entries, int $number, callable $next_id ): array {
		$grouped = array();
		$items   = array();
		$first   = null;
		$ordered = false;
		$min_x   = null;

		$flush = function () use ( &$grouped, &$items, &$first, &$ordered, &$min_x, $number, $next_id ): void {
			if ( array() === $items ) {
				return;
			}

			$previous = -1;
			$flat     = array();

			foreach ( $items as $item ) {
				$level    = min( 5, (int) round( max( 0.0, $item['x'] - (float) $min_x ) / 18.0 ), $previous + 1 );
				$previous = $level;
				$flat[]   = array(
					'level' => $level,
					'runs'  => $item['runs'],
				);
			}

			$cursor    = 0;
			$grouped[] = array(
				'order' => $first['order'],
				'seq'   => $first['seq'],
				'block' => array(
					'type'    => 'list',
					'id'      => $next_id(),
					'ordered' => $ordered,
					'items'   => $this->nestItems( $flat, $cursor, 0 ),
					'origin'  => array( 'page' => $number ),
				),
			);
			$items     = array();
			$first     = null;
			$min_x     = null;
		};

		foreach ( $entries as $entry ) {
			$block = $entry['block'];
			$text  = 'paragraph' === $block['type'] ? implode( '', array_column( $block['runs'], 'text' ) ) : '';

			if ( 'paragraph' !== $block['type'] || 1 !== preg_match( self::LIST_MARKER, $text, $marker ) ) {
				$flush();
				$grouped[] = $entry;
				continue;
			}

			$is_ordered = isset( $marker[1] ) && '' !== $marker[1];

			if ( array() !== $items && $is_ordered !== $ordered ) {
				$flush();
			}

			if ( array() === $items ) {
				$first   = $entry;
				$ordered = $is_ordered;
			}

			$x       = (float) ( $block['origin']['bounds']['x'] ?? 0 );
			$min_x   = null === $min_x ? $x : min( $min_x, $x );
			$items[] = array(
				'x'    => $x,
				'runs' => $this->stripMarker( $block['runs'], strlen( $marker[0] ) ),
			);
		}

		$flush();

		return $grouped;
	}

	/**
	 * Figure and drawing render entries for a page.
	 *
	 * @param int                 $number  Page number.
	 * @param array<string,mixed> $page    Analyzed page.
	 * @param string              $mode    auto or text.
	 * @param callable            $next_id Block ID generator.
	 * @return array<int,array{order:float,seq:int,block:array<string,mixed>}>
	 */
	private function visualEntries( int $number, array $page, string $mode, callable $next_id ): array {
		$entries    = array();
		$page_area  = max( 1.0, (float) $page['width'] * (float) $page['height'] );
		$seq        = 100000;
		$candidates = array();

		foreach ( $page['figures'] as $bounds ) {
			$candidates[] = array(
				'bounds' => $bounds,
				'kind'   => 'image',
			);
		}

		if ( $page['vector']['present'] ) {
			$candidates[] = array(
				'bounds' => $page['vector']['bounds'],
				'kind'   => 'drawing',
			);
		}

		foreach ( $candidates as $candidate ) {
			$bounds   = $candidate['bounds'];
			$coverage = ( $bounds['width'] * $bounds['height'] ) / $page_area;
			$origin   = array(
				'page'   => $number,
				'bounds' => $bounds,
			);

			if ( 'image' === $candidate['kind'] && $coverage >= self::BACKGROUND_COVERAGE ) {
				$this->warn(
					'imageSkipped',
					/* translators: %d: page number. */
					sprintf( __( 'A full-page background image on page %d was not imported. Choose “Image” mode for this file to keep the page’s look.', 'brasth-document-sync-for-google-docs' ), $number ),
					'info',
					$origin
				);
				continue;
			}

			if ( $bounds['width'] < self::MIN_FIGURE_POINTS || $bounds['height'] < self::MIN_FIGURE_POINTS || $coverage < self::MIN_FIGURE_COVERAGE ) {
				$this->warn(
					'imageSkipped',
					/* translators: %d: page number. */
					sprintf( __( 'A very small image or decoration on page %d was skipped.', 'brasth-document-sync-for-google-docs' ), $number ),
					'info',
					$origin
				);
				continue;
			}

			if ( 'text' === $mode ) {
				$this->warn(
					'imageSkipped',
					'image' === $candidate['kind']
						/* translators: %d: page number. */
						? sprintf( __( 'An image on page %d was skipped because this file uses text-only mode.', 'brasth-document-sync-for-google-docs' ), $number )
						/* translators: %d: page number. */
						: sprintf( __( 'A drawing or chart on page %d was skipped because this file uses text-only mode.', 'brasth-document-sync-for-google-docs' ), $number ),
					'warning',
					$origin
				);
				continue;
			}

			$this->warn(
				'pageRenderedAsImage',
				'image' === $candidate['kind']
					/* translators: %d: page number. */
					? sprintf( __( 'An image on page %d was imported as a rendered picture of that area of the page.', 'brasth-document-sync-for-google-docs' ), $number )
					/* translators: %d: page number. */
					: sprintf( __( 'A drawing or chart on page %d was imported as a rendered picture of that area of the page.', 'brasth-document-sync-for-google-docs' ), $number ),
				'warning',
				$origin
			);

			$entries[] = array(
				'order' => (float) $bounds['y'],
				'seq'   => $seq++,
				'block' => $this->renderBlock( $number, $bounds, $next_id, false ),
			);
		}

		return $entries;
	}

	/**
	 * Image block backed by a page or crop render asset.
	 *
	 * The asset ID is derived from the page and crop bounds, so a reconversion
	 * with other page options reuses a render that already arrived.
	 *
	 * @param int                 $number    Page number.
	 * @param array<string,float> $bounds    Crop bounds (top-left points).
	 * @param callable            $next_id   Block ID generator.
	 * @param bool                $full_page Whether the render is the whole page.
	 * @return array<string,mixed>
	 */
	private function renderBlock( int $number, array $bounds, callable $next_id, bool $full_page ): array {
		$key      = sprintf( '%d|%.2f|%.2f|%.2f|%.2f', $number, $bounds['x'], $bounds['y'], $bounds['width'], $bounds['height'] );
		$asset_id = 'a_' . substr( hash( 'sha256', $this->state['fileId'] . '|pdf|' . $key ), 0, 16 );
		$known    = $this->state['assetIndex'][ $asset_id ] ?? null;
		$ready    = is_array( $known ) && 'ready' === ( $known['status'] ?? '' ) && is_string( $known['sha256'] ?? null );

		$this->state['assets'][ $asset_id ] = array(
			'assetId'  => $asset_id,
			'kind'     => 'pdfPageRender',
			'mimeType' => 'image/png',
			'width'    => $ready ? absint( $known['width'] ?? 0 ) : null,
			'height'   => $ready ? absint( $known['height'] ?? 0 ) : null,
			'byteSize' => $ready ? absint( $known['byteSize'] ?? 0 ) : null,
			'sha256'   => $ready ? (string) $known['sha256'] : null,
			'status'   => $ready ? 'ready' : 'pendingRender',
			'origin'   => array(
				'page'   => $number,
				'bounds' => $bounds,
			),
		);

		return array(
			'type'    => 'image',
			'id'      => $next_id(),
			'assetId' => $asset_id,
			/* translators: %d: page number. */
			'alt'     => $full_page ? sprintf( __( 'Page %d', 'brasth-document-sync-for-google-docs' ), $number ) : sprintf( __( 'Figure from page %d', 'brasth-document-sync-for-google-docs' ), $number ),
			'caption' => null,
			'origin'  => array(
				'page'   => $number,
				'bounds' => $bounds,
			),
		);
	}

	/**
	 * Character-weighted median font size across the selected pages.
	 *
	 * @param array<int,array<string,mixed>> $pages Analyzed pages.
	 */
	private function bodyFontSize( array $pages ): float {
		$weights = array();

		foreach ( $pages as $page ) {
			foreach ( $page['lines'] as $line ) {
				foreach ( $line['items'] as $item ) {
					$key             = (string) $item['size'];
					$weights[ $key ] = ( $weights[ $key ] ?? 0 ) + strlen( $item['text'] );
				}
			}
		}

		if ( array() === $weights ) {
			return 0.0;
		}

		arsort( $weights );

		return (float) array_key_first( $weights );
	}

	/**
	 * Document title from metadata, the first heading, or the file name.
	 *
	 * @param object                         $pdf           smalot Document.
	 * @param array<int,array<string,mixed>> $sections      Page sections.
	 * @param string                         $original_name Original name.
	 */
	private function documentTitle( object $pdf, array $sections, string $original_name ): string {
		try {
			$details = $pdf->getDetails();
			$title   = is_array( $details ) && is_scalar( $details['Title'] ?? null ) ? trim( sanitize_text_field( (string) $details['Title'] ) ) : '';
		} catch ( Throwable $exception ) {
			$title = '';
		}

		if ( '' !== $title && 1 !== preg_match( '/^(microsoft (word|powerpoint)|untitled)\b|\.(docx?|pptx?|pdf)$/i', $title ) ) {
			return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 );
		}

		foreach ( $sections as $section ) {
			foreach ( $section['blocks'] as $block ) {
				if ( 'heading' === $block['type'] ) {
					$text = trim( implode( '', array_column( $block['runs'], 'text' ) ) );

					if ( '' !== $text ) {
						return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 200 ) : substr( $text, 0, 200 );
					}
				}
			}

			break;
		}

		$base = trim( (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $original_name ) );

		return '' !== $base ? $base : __( 'Untitled', 'brasth-document-sync-for-google-docs' );
	}

	/**
	 * Runs for positioned items, inserting spaces at visual gaps.
	 *
	 * @param array<int,array<string,mixed>> $items Items sorted by x.
	 * @return array<int,array<string,mixed>>
	 */
	private function segmentRuns( array $items ): array {
		$runs     = array();
		$previous = null;

		foreach ( $items as $item ) {
			$text = $item['text'];

			if ( null !== $previous ) {
				$gap = $item['x'] - ( $previous['x'] + $previous['width'] );

				if ( $gap > 0.15 * $item['size'] && ! str_ends_with( $previous['text'], ' ' ) && ! str_starts_with( $text, ' ' ) ) {
					$text = ' ' . $text;
				}
			}

			$run = array( 'text' => $text );

			if ( $item['bold'] ) {
				$run['bold'] = true;
			}

			if ( $item['italic'] ) {
				$run['italic'] = true;
			}

			$runs[]   = $run;
			$previous = $item;
		}

		return $this->mergeRuns( $runs );
	}

	/**
	 * Remove a list marker of $length bytes from the start of runs.
	 *
	 * @param array<int,array<string,mixed>> $runs   Runs.
	 * @param int                            $length Marker byte length.
	 * @return array<int,array<string,mixed>>
	 */
	private function stripMarker( array $runs, int $length ): array {
		foreach ( $runs as $index => $run ) {
			$text = ltrim( (string) $run['text'] );

			if ( '' === $text ) {
				unset( $runs[ $index ] );
				continue;
			}

			$runs[ $index ]['text'] = ltrim( substr( (string) $run['text'], min( strlen( (string) $run['text'] ), $length + ( strlen( (string) $run['text'] ) - strlen( $text ) ) ) ) );
			break;
		}

		return $this->mergeRuns( array_values( $runs ) );
	}

	/**
	 * Runs without bold or italic.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @return array<int,array<string,mixed>>
	 */
	private function plainRuns( array $runs ): array {
		return array_map(
			static function ( array $run ): array {
				return array( 'text' => (string) $run['text'] );
			},
			$runs
		);
	}

	/**
	 * Whether every item is bold.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	private function allBold( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! $item['bold'] ) {
				return false;
			}
		}

		return array() !== $items;
	}

	/**
	 * Merge adjacent runs with identical formatting.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @return array<int,array<string,mixed>>
	 */
	private function mergeRuns( array $runs ): array {
		$merged = array();

		foreach ( $runs as $run ) {
			if ( '' === (string) $run['text'] ) {
				continue;
			}

			$last = count( $merged ) - 1;

			if ( $last >= 0 && array_diff_key( $merged[ $last ], array( 'text' => true ) ) === array_diff_key( $run, array( 'text' => true ) ) ) {
				$merged[ $last ]['text'] .= $run['text'];
				continue;
			}

			$merged[] = $run;
		}

		return $merged;
	}

	/**
	 * Nest flat list items whose levels never jump by more than one.
	 *
	 * @param array<int,array<string,mixed>> $items  Items with level and runs.
	 * @param int                            $cursor Current position (advanced).
	 * @param int                            $level  Level being built.
	 * @return array<int,array<string,mixed>>
	 */
	private function nestItems( array $items, int &$cursor, int $level ): array {
		$result = array();
		$count  = count( $items );

		while ( $cursor < $count && $items[ $cursor ]['level'] >= $level ) {
			if ( $items[ $cursor ]['level'] === $level || array() === $result ) {
				$result[] = array(
					'runs'     => $items[ $cursor ]['runs'],
					'children' => array(),
				);
				++$cursor;
				continue;
			}

			$last                        = count( $result ) - 1;
			$result[ $last ]['children'] = $this->nestItems( $items, $cursor, $level + 1 );
		}

		return $result;
	}

	/**
	 * Plain text of a line.
	 *
	 * @param array<string,mixed> $line Line.
	 */
	private function lineText( array $line ): string {
		return implode( ' ', array_column( $line['items'], 'text' ) );
	}

	/**
	 * Bounding box of lines (top-left points).
	 *
	 * @param array<int,array<string,mixed>> $lines Lines.
	 * @return array{x:float,y:float,width:float,height:float}
	 */
	private function linesBounds( array $lines ): array {
		$x0     = min( array_column( $lines, 'x0' ) );
		$x1     = max( array_column( $lines, 'x1' ) );
		$top    = min( array_column( $lines, 'top' ) );
		$last   = $lines[ count( $lines ) - 1 ];
		$bottom = $last['top'] + $last['size'];

		return array(
			'x'      => round( max( 0.0, $x0 ), 2 ),
			'y'      => round( max( 0.0, $top ), 2 ),
			'width'  => round( max( 0.0, $x1 - $x0 ), 2 ),
			'height' => round( max( 0.0, $bottom - $top ), 2 ),
		);
	}

	/**
	 * Concatenate matrices: returns $m × $n (PDF row-vector convention).
	 *
	 * @param array<int,float> $m First matrix.
	 * @param array<int,float> $n Second matrix.
	 * @return array<int,float>
	 */
	private function multiply( array $m, array $n ): array {
		return array(
			$m[0] * $n[0] + $m[1] * $n[2],
			$m[0] * $n[1] + $m[1] * $n[3],
			$m[2] * $n[0] + $m[3] * $n[2],
			$m[2] * $n[1] + $m[3] * $n[3],
			$m[4] * $n[0] + $m[5] * $n[2] + $n[4],
			$m[4] * $n[1] + $m[5] * $n[3] + $n[5],
		);
	}

	/**
	 * Page rectangle (top-left points) of the unit square under a CTM, clipped to the page.
	 *
	 * @param array<int,float> $ctm    Current transformation matrix.
	 * @param float            $width  Page width.
	 * @param float            $height Page height.
	 * @return array{x:float,y:float,width:float,height:float}
	 */
	private function unitSquareBounds( array $ctm, float $width, float $height ): array {
		$xs = array();
		$ys = array();

		foreach ( array( array( 0, 0 ), array( 1, 0 ), array( 0, 1 ), array( 1, 1 ) ) as $corner ) {
			$xs[] = $corner[0] * $ctm[0] + $corner[1] * $ctm[2] + $ctm[4];
			$ys[] = $corner[0] * $ctm[1] + $corner[1] * $ctm[3] + $ctm[5];
		}

		$x0 = max( 0.0, min( $xs ) );
		$x1 = min( $width, max( $xs ) );
		$y0 = max( 0.0, min( $ys ) );
		$y1 = min( $height, max( $ys ) );

		return array(
			'x'      => round( $x0, 2 ),
			'y'      => round( max( 0.0, $height - $y1 ), 2 ),
			'width'  => round( max( 0.0, $x1 - $x0 ), 2 ),
			'height' => round( max( 0.0, $y1 - $y0 ), 2 ),
		);
	}

	/**
	 * Approximate rendered width of text.
	 *
	 * @param string $text Text.
	 * @param float  $size Font size.
	 */
	private function textWidth( string $text, float $size ): float {
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );

		return $length * $size * 0.5;
	}

	/**
	 * Strip control characters and invalid UTF-8 from extracted text.
	 *
	 * @param string $text Raw text.
	 */
	private function cleanText( string $text ): string {
		$text = wp_check_invalid_utf8( $text, true );

		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
	}

	/**
	 * Record a numbered warning.
	 *
	 * @param string              $code     Warning code.
	 * @param string              $message  Message.
	 * @param string              $severity info or warning.
	 * @param array<string,mixed> $origin   Origin.
	 */
	private function warn( string $code, string $message, string $severity, array $origin ): void {
		$this->state['warnings'][] = array(
			'number'   => count( $this->state['warnings'] ) + 1,
			'code'     => $code,
			'message'  => $message,
			'severity' => $severity,
			'origin'   => $origin,
		);
	}

	/**
	 * Invalid or unreadable PDF error.
	 */
	private function invalidPdfError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_invalid_file',
			__( 'This PDF is damaged or uses features Brasth Document Sync cannot read.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 422 )
		);
	}
}
