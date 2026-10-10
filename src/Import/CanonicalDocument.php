<?php
/**
 * Canonical, versioned document model shared by every import converter.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable canonical document v1.
 *
 * Converters build plain arrays and pass them through fromArray(), which
 * validates the full schema, normalizes key order, and recomputes
 * statistics. applyOptions() derives the effective document that both the
 * preview and the commit render, so they always produce the same blocks.
 */
final class CanonicalDocument {
	public const VERSION = 1;

	public const FORMATS       = array( 'docx', 'pptx', 'pdf' );
	public const SECTION_KINDS = array( 'body', 'slide', 'page', 'notes' );
	public const ASSET_KINDS   = array( 'embedded', 'slideThumbnail', 'pdfPageRender' );
	public const ASSET_MIMES   = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );
	public const ASSET_STATUS  = array( 'ready', 'pendingRender', 'rejected' );
	public const WARNING_CODES = array( 'unsupportedElement', 'imageSkipped', 'pageRenderedAsImage', 'stylingDropped', 'tableSimplified', 'linkRemoved', 'pdfTableAsText', 'emptySection' );
	public const ELEMENTS      = array( 'chart', 'video', 'animation', 'wordArt', 'group' );

	private const ID_PATTERN         = '/^[A-Za-z0-9_-]{1,64}$/';
	private const ASSET_ID_PATTERN   = '/^a_[a-f0-9]{16}$/';
	private const SHA_PATTERN        = '/^[a-f0-9]{64}$/';
	private const MAX_TITLE          = 1000;
	private const MAX_SECTIONS       = 2000;
	private const MAX_BLOCKS         = 50000;
	private const MAX_ASSETS         = 2000;
	private const MAX_WARNINGS       = 5000;
	private const MAX_RUNS           = 2000;
	private const MAX_RUN_TEXT       = 100000;
	private const MAX_LIST_DEPTH     = 6;
	private const MAX_TABLE_ROWS     = 1000;
	private const MAX_TABLE_CELLS    = 64;
	private const MAX_SPAN           = 64;
	private const MAX_TEXT           = 1000;
	private const RUN_FLAGS          = array( 'bold', 'italic', 'underline', 'strike', 'code' );
	private const CLOSING_PREFIXES   = array( 'thank you', 'thanks', 'q&a', 'q & a', 'questions', 'any questions' );
	private const CLOSING_MAX_TOKENS = 8;

	/**
	 * Normalized document data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Normalized data with statistics.
	 */
	private function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Build a document with strict schema validation.
	 *
	 * Statistics are always recomputed from the content.
	 *
	 * @param array<string,mixed> $data Raw document data.
	 * @return self|WP_Error
	 */
	public static function fromArray( array $data ): self|WP_Error {
		$normalized = self::normalizeDocument( $data );

		if ( null === $normalized ) {
			return new WP_Error(
				'docsync_wp_import_canonical_invalid',
				__( 'Brasth Document Sync could not read the converted document.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$normalized['statistics'] = self::computeStatistics( $normalized );

		return new self( $normalized );
	}

	/**
	 * Plain array form (canonical JSON shape).
	 *
	 * @return array<string,mixed>
	 */
	public function toArray(): array {
		return $this->data;
	}

	/**
	 * SHA-256 of the canonical JSON.
	 */
	public function fingerprint(): string {
		return hash( 'sha256', self::encode( $this->data ) );
	}

	/**
	 * Document title.
	 */
	public function getTitle(): string {
		return (string) $this->data['title'];
	}

	/**
	 * Sections.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getSections(): array {
		return $this->data['sections'];
	}

	/**
	 * Assets.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getAssets(): array {
		return $this->data['assets'];
	}

	/**
	 * Warnings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getWarnings(): array {
		return $this->data['warnings'];
	}

	/**
	 * Statistics.
	 *
	 * @return array<string,int>
	 */
	public function getStatistics(): array {
		return $this->data['statistics'];
	}

	/**
	 * Return a copy with one asset added or replaced by asset ID.
	 *
	 * Used when a PDF.js render or a slide thumbnail arrives. An invalid asset
	 * leaves the document unchanged.
	 *
	 * @param array<string,mixed> $asset Asset.
	 */
	public function withAsset( array $asset ): self {
		$normalized = self::normalizeAsset( $asset );

		if ( null === $normalized ) {
			return $this;
		}

		$data     = $this->data;
		$replaced = false;

		foreach ( $data['assets'] as $index => $existing ) {
			if ( $existing['assetId'] === $normalized['assetId'] ) {
				$data['assets'][ $index ] = $normalized;
				$replaced                 = true;
				break;
			}
		}

		if ( ! $replaced ) {
			$data['assets'][] = $normalized;
		}

		$data['statistics'] = self::computeStatistics( $data );

		return new self( $data );
	}

	/**
	 * Derive the effective document for preview and commit.
	 *
	 * PPTX: filters slides, keeps or drops notes, inserts slide thumbnails when
	 * `addSlideImages` is on, always keeps the unsupported-visual fallback
	 * (moved onto the top thumbnail when both apply), and merges consecutive
	 * equal titles. Every format: numbers warnings in document order over the
	 * effective content, refreshes fallback numbers and captions, and
	 * recomputes statistics.
	 *
	 * @param array<string,mixed> $options Normalized ImportFileOptions.
	 */
	public function applyOptions( array $options ): self {
		$data = $this->data;

		if ( 'pptx' === $data['source']['format'] ) {
			$data = $this->applyPptxOptions( $data, isset( $options['pptx'] ) && is_array( $options['pptx'] ) ? $options['pptx'] : array() );
		}

		$data               = self::numberWarnings( $data );
		$data['statistics'] = self::computeStatistics( $data );

		return new self( $data );
	}

	/**
	 * Detect PPTX notes and suggested skips.
	 *
	 * @param self $document Stored (full) PPTX document.
	 * @return array{hasNotes:bool,suggestedSkips:array<int,array{slide:int,reason:string}>}
	 */
	public static function detectPptx( self $document ): array {
		$has_notes = false;
		$slides    = array();

		foreach ( $document->getSections() as $section ) {
			$slide = isset( $section['origin']['slide'] ) ? (int) $section['origin']['slide'] : 0;

			if ( 'notes' === $section['kind'] && '' !== trim( self::blocksText( $section['blocks'] ) ) ) {
				$has_notes = true;
			}

			if ( 'slide' === $section['kind'] && $slide > 0 ) {
				$slides[ $slide ] = $section;
			}
		}

		ksort( $slides );

		$skips = array();
		$total = count( $slides );

		if ( isset( $slides[1] ) && self::isTitleOnlySlide( $slides[1] ) ) {
			$skips[1] = 'titleOnly';
		}

		$last = array() !== $slides ? (int) array_key_last( $slides ) : 0;

		if ( $last > 1 && ! isset( $skips[ $last ] ) && self::isClosingSlide( $slides[ $last ] ) ) {
			$skips[ $last ] = 'closing';
		}

		if ( count( $skips ) >= $total ) {
			$skips = array();
		}

		$suggested = array();

		foreach ( $skips as $slide => $reason ) {
			$suggested[] = array(
				'slide'  => $slide,
				'reason' => $reason,
			);
		}

		return array(
			'hasNotes'       => $has_notes,
			'suggestedSkips' => $suggested,
		);
	}

	/**
	 * Apply PPTX options to the stored document data.
	 *
	 * @param array<string,mixed> $data    Document data.
	 * @param array<string,mixed> $options PPTX options.
	 * @return array<string,mixed>
	 */
	private function applyPptxOptions( array $data, array $options ): array {
		$detection = self::detectPptx( $this );
		$all       = array();

		foreach ( $data['sections'] as $section ) {
			if ( 'slide' === $section['kind'] && isset( $section['origin']['slide'] ) ) {
				$all[] = (int) $section['origin']['slide'];
			}
		}

		$skipped = array_column( $detection['suggestedSkips'], 'slide' );
		$slides  = isset( $options['slides'] ) && is_array( $options['slides'] ) && array() !== $options['slides']
			? array_map( 'intval', $options['slides'] )
			: array_values( array_diff( $all, $skipped ) );

		$selected      = array_fill_keys( $slides, true );
		$include_notes = array_key_exists( 'includeNotes', $options ) ? true === $options['includeNotes'] : $detection['hasNotes'];
		$add_images    = true === ( $options['addSlideImages'] ?? false );
		$merge         = true === ( $options['mergeConsecutiveTitles'] ?? false );
		$thumbnails    = array();

		foreach ( $data['assets'] as $asset ) {
			if ( 'slideThumbnail' === $asset['kind'] && 'ready' === $asset['status'] && isset( $asset['origin']['slide'] ) ) {
				$thumbnails[ (int) $asset['origin']['slide'] ] = $asset['assetId'];
			}
		}

		$sections   = array();
		$last_slide = null;
		$last_title = null;

		foreach ( $data['sections'] as $section ) {
			$slide = isset( $section['origin']['slide'] ) ? (int) $section['origin']['slide'] : 0;

			if ( ! isset( $selected[ $slide ] ) ) {
				continue;
			}

			if ( 'notes' === $section['kind'] ) {
				if ( $include_notes ) {
					$sections[] = $section;
				}

				continue;
			}

			if ( 'slide' !== $section['kind'] ) {
				$sections[] = $section;
				continue;
			}

			$blocks = $section['blocks'];

			if ( $add_images && isset( $thumbnails[ $slide ] ) ) {
				$top = array(
					'type'    => 'image',
					'id'      => substr( $section['id'], 0, 58 ) . '-thumb',
					'assetId' => $thumbnails[ $slide ],
					/* translators: %d: slide number. */
					'alt'     => sprintf( __( 'Slide %d', 'brasth-document-sync-for-google-docs' ), $slide ),
					'caption' => null,
					'origin'  => array( 'slide' => $slide ),
				);

				foreach ( $blocks as $index => $block ) {
					if ( 'image' === $block['type'] && isset( $block['fallback'] ) ) {
						$top['fallback'] = $block['fallback'];
						unset( $blocks[ $index ] );
						break;
					}
				}

				array_unshift( $blocks, $top );
				$blocks = array_values( $blocks );
			}

			$title = self::normalizeForCompare( (string) $section['title'] );

			if ( $merge && null !== $last_slide && '' !== $title && $title === $last_title ) {
				foreach ( $blocks as $index => $block ) {
					if ( 'heading' === $block['type'] && self::normalizeForCompare( self::runsText( $block['runs'] ) ) === $title ) {
						unset( $blocks[ $index ] );
						break;
					}
				}

				$sections[ $last_slide ]['blocks'] = array_merge( $sections[ $last_slide ]['blocks'], array_values( $blocks ) );
			} else {
				$section['blocks'] = $blocks;
				$sections[]        = $section;
				$last_slide        = count( $sections ) - 1;
			}

			$last_title = $title;
		}

		$data['sections'] = $sections;
		$data['warnings'] = array_values(
			array_filter(
				$data['warnings'],
				static function ( array $warning ) use ( $selected ): bool {
					return ! isset( $warning['origin']['slide'] ) || isset( $selected[ (int) $warning['origin']['slide'] ] );
				}
			)
		);

		return $data;
	}

	/**
	 * Number warnings in document order and refresh fallback numbers and captions.
	 *
	 * @param array<string,mixed> $data Document data.
	 * @return array<string,mixed>
	 */
	private static function numberWarnings( array $data ): array {
		$positions = array();

		foreach ( $data['sections'] as $position => $section ) {
			foreach ( array( 'slide', 'page' ) as $origin_key ) {
				if ( isset( $section['origin'][ $origin_key ] ) && ! isset( $positions[ $origin_key ][ (int) $section['origin'][ $origin_key ] ] ) ) {
					$positions[ $origin_key ][ (int) $section['origin'][ $origin_key ] ] = $position;
				}
			}
		}

		$keyed = array();

		foreach ( $data['warnings'] as $warning ) {
			$section_position = PHP_INT_MAX;

			foreach ( array( 'slide', 'page' ) as $origin_key ) {
				if ( isset( $warning['origin'][ $origin_key ] ) ) {
					$value = (int) $warning['origin'][ $origin_key ];

					if ( ! isset( $positions[ $origin_key ][ $value ] ) ) {
						continue 2;
					}

					$section_position = $positions[ $origin_key ][ $value ];
				}
			}

			$keyed[] = array(
				'section' => $section_position,
				'doc'     => isset( $warning['origin']['docIndex'] ) ? (int) $warning['origin']['docIndex'] : 0,
				'number'  => (int) $warning['number'],
				'warning' => $warning,
			);
		}

		usort(
			$keyed,
			static function ( array $a, array $b ): int {
				return array( $a['section'], $a['doc'], $a['number'] ) <=> array( $b['section'], $b['doc'], $b['number'] );
			}
		);

		$map      = array();
		$warnings = array();

		foreach ( $keyed as $position => $entry ) {
			$number                     = $position + 1;
			$map[ $entry['number'] ]    = $number;
			$entry['warning']['number'] = $number;
			$warnings[]                 = $entry['warning'];
		}

		$by_number = array();

		foreach ( $warnings as $warning ) {
			$by_number[ $warning['number'] ] = $warning;
		}

		foreach ( $data['sections'] as $section_index => $section ) {
			foreach ( $section['blocks'] as $block_index => $block ) {
				if ( 'image' !== $block['type'] || ! isset( $block['fallback'] ) ) {
					continue;
				}

				$numbers = array();

				foreach ( $block['fallback']['warningNumbers'] as $old ) {
					if ( isset( $map[ $old ] ) ) {
						$numbers[] = $map[ $old ];
					}
				}

				sort( $numbers );

				$slide = isset( $block['origin']['slide'] ) ? (int) $block['origin']['slide'] : ( isset( $section['origin']['slide'] ) ? (int) $section['origin']['slide'] : 0 );

				$data['sections'][ $section_index ]['blocks'][ $block_index ]['fallback'] = array( 'warningNumbers' => $numbers );
				$data['sections'][ $section_index ]['blocks'][ $block_index ]['caption']  = self::fallbackCaption( $slide, $numbers, $by_number );
			}
		}

		$data['warnings'] = $warnings;

		return $data;
	}

	/**
	 * Caption for an unsupported-visual slide fallback.
	 *
	 * @param int                            $slide     Slide number.
	 * @param array<int,int>                 $numbers   Effective warning numbers.
	 * @param array<int,array<string,mixed>> $by_number Effective warnings by number.
	 */
	private static function fallbackCaption( int $slide, array $numbers, array $by_number ): string {
		$labels   = array(
			'chart'     => __( 'chart', 'brasth-document-sync-for-google-docs' ),
			'video'     => __( 'video', 'brasth-document-sync-for-google-docs' ),
			'animation' => __( 'animation', 'brasth-document-sync-for-google-docs' ),
			'wordArt'   => __( 'WordArt', 'brasth-document-sync-for-google-docs' ),
			'group'     => __( 'group', 'brasth-document-sync-for-google-docs' ),
		);
		$elements = array();

		foreach ( $numbers as $number ) {
			$element = $by_number[ $number ]['element'] ?? '';

			if ( isset( $labels[ $element ] ) && ! in_array( $labels[ $element ], $elements, true ) ) {
				$elements[] = $labels[ $element ];
			}
		}

		return sprintf(
			/* translators: 1: slide number, 2: comma-separated element types such as "chart, animation", 3: comma-separated warning numbers. */
			__( 'Slide %1$d shown as an image: %2$s (warnings %3$s)', 'brasth-document-sync-for-google-docs' ),
			$slide,
			implode( ', ', $elements ),
			implode( ', ', $numbers )
		);
	}

	/**
	 * Whether a slide holds only title and subtitle headings.
	 *
	 * @param array<string,mixed> $section Slide section.
	 */
	private static function isTitleOnlySlide( array $section ): bool {
		if ( array() === $section['blocks'] ) {
			return false;
		}

		foreach ( $section['blocks'] as $block ) {
			if ( 'heading' !== $block['type'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a slide is a short thank-you or Q&A closing slide.
	 *
	 * @param array<string,mixed> $section Slide section.
	 */
	private static function isClosingSlide( array $section ): bool {
		foreach ( $section['blocks'] as $block ) {
			if ( in_array( $block['type'], array( 'table', 'image' ), true ) ) {
				return false;
			}
		}

		$text = self::normalizeForCompare( self::blocksText( $section['blocks'] ) );

		if ( '' === $text ) {
			$text = self::normalizeForCompare( (string) $section['title'] );
		}

		$tokens = '' === $text ? array() : explode( ' ', $text );

		if ( array() === $tokens || count( $tokens ) > self::CLOSING_MAX_TOKENS ) {
			return false;
		}

		foreach ( self::CLOSING_PREFIXES as $prefix ) {
			if ( $text === $prefix || str_starts_with( $text, $prefix . ' ' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercase, punctuation-free (except `&`), single-spaced text.
	 *
	 * @param string $text Text.
	 */
	private static function normalizeForCompare( string $text ): string {
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		$text = (string) preg_replace( '/[^\p{L}\p{N}&]+/u', ' ', $text );

		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Plain text of runs.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 */
	private static function runsText( array $runs ): string {
		$text = '';

		foreach ( $runs as $run ) {
			$text .= (string) $run['text'];
		}

		return $text;
	}

	/**
	 * Plain text of blocks, separated by spaces.
	 *
	 * @param array<int,array<string,mixed>> $blocks Blocks.
	 */
	private static function blocksText( array $blocks ): string {
		$parts = array();

		foreach ( $blocks as $block ) {
			switch ( $block['type'] ) {
				case 'paragraph':
				case 'heading':
					$parts[] = self::runsText( $block['runs'] );
					break;
				case 'list':
					$parts[] = self::listText( $block['items'] );
					break;
				case 'table':
					foreach ( $block['rows'] as $row ) {
						foreach ( $row['cells'] as $cell ) {
							$parts[] = self::runsText( $cell['runs'] );
						}
					}
					break;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Plain text of list items.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 */
	private static function listText( array $items ): string {
		$parts = array();

		foreach ( $items as $item ) {
			$parts[] = self::runsText( $item['runs'] );

			if ( array() !== $item['children'] ) {
				$parts[] = self::listText( $item['children'] );
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Compute statistics for document data.
	 *
	 * @param array<string,mixed> $data Document data.
	 * @return array<string,int>
	 */
	private static function computeStatistics( array $data ): array {
		$stats = array(
			'sections'       => count( $data['sections'] ),
			'blocks'         => 0,
			'paragraphs'     => 0,
			'headings'       => 0,
			'lists'          => 0,
			'tables'         => 0,
			'images'         => 0,
			'words'          => 0,
			'characters'     => 0,
			'assets'         => 0,
			'pendingRenders' => 0,
			'skippedPages'   => 0,
			'skippedSlides'  => 0,
		);

		$assets     = array();
		$referenced = array();
		$pages      = array();
		$slides     = array();

		foreach ( $data['assets'] as $asset ) {
			$assets[ $asset['assetId'] ] = $asset;
		}

		foreach ( $data['sections'] as $section ) {
			if ( 'page' === $section['kind'] && isset( $section['origin']['page'] ) ) {
				$pages[ (int) $section['origin']['page'] ] = true;
			}

			if ( 'slide' === $section['kind'] && isset( $section['origin']['slide'] ) ) {
				$slides[ (int) $section['origin']['slide'] ] = true;
			}

			foreach ( $section['blocks'] as $block ) {
				++$stats['blocks'];

				// Merged slides keep their own block origins, so they never count as skipped.
				if ( 'slide' === $section['kind'] && isset( $block['origin']['slide'] ) ) {
					$slides[ (int) $block['origin']['slide'] ] = true;
				}

				switch ( $block['type'] ) {
					case 'paragraph':
						++$stats['paragraphs'];
						break;
					case 'heading':
						++$stats['headings'];
						break;
					case 'list':
						++$stats['lists'];
						break;
					case 'table':
						++$stats['tables'];
						break;
					case 'image':
						++$stats['images'];
						$referenced[ $block['assetId'] ] = true;
						break;
				}
			}

			$text                 = self::blocksText( $section['blocks'] );
			$words                = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
			$stats['words']      += is_array( $words ) ? count( $words ) : 0;
			$stats['characters'] += function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		}

		foreach ( array_keys( $referenced ) as $asset_id ) {
			if ( isset( $assets[ $asset_id ] ) ) {
				++$stats['assets'];

				if ( 'pendingRender' === $assets[ $asset_id ]['status'] ) {
					++$stats['pendingRenders'];
				}
			}
		}

		if ( 'pdf' === $data['source']['format'] && isset( $data['source']['pages'] ) ) {
			$stats['skippedPages'] = max( 0, (int) $data['source']['pages'] - count( $pages ) );
		}

		if ( 'pptx' === $data['source']['format'] && isset( $data['source']['slides'] ) ) {
			$stats['skippedSlides'] = max( 0, (int) $data['source']['slides'] - count( $slides ) );
		}

		return $stats;
	}

	/**
	 * Validate and normalize a whole document.
	 *
	 * @param array<string,mixed> $data Raw data.
	 * @return array<string,mixed>|null
	 */
	private static function normalizeDocument( array $data ): ?array {
		if ( array() !== array_diff( array_keys( $data ), array( 'version', 'title', 'source', 'sections', 'assets', 'warnings', 'statistics' ) ) ) {
			return null;
		}

		if ( self::VERSION !== ( $data['version'] ?? null ) || ! self::isText( $data['title'] ?? null, self::MAX_TITLE ) ) {
			return null;
		}

		$source = self::normalizeSource( $data['source'] ?? null );

		if ( null === $source || ! self::isList( $data['sections'] ?? null, self::MAX_SECTIONS ) || ! self::isList( $data['assets'] ?? array(), self::MAX_ASSETS ) || ! self::isList( $data['warnings'] ?? array(), self::MAX_WARNINGS ) ) {
			return null;
		}

		if ( isset( $data['statistics'] ) && ! is_array( $data['statistics'] ) ) {
			return null;
		}

		$assets    = array();
		$asset_ids = array();

		foreach ( $data['assets'] ?? array() as $asset ) {
			$normalized = is_array( $asset ) ? self::normalizeAsset( $asset ) : null;

			if ( null === $normalized || isset( $asset_ids[ $normalized['assetId'] ] ) ) {
				return null;
			}

			$asset_ids[ $normalized['assetId'] ] = true;
			$assets[]                            = $normalized;
		}

		$ids         = array();
		$block_count = 0;
		$sections    = array();

		foreach ( $data['sections'] as $section ) {
			$normalized = is_array( $section ) ? self::normalizeSection( $section, $asset_ids, $ids, $block_count ) : null;

			if ( null === $normalized ) {
				return null;
			}

			$sections[] = $normalized;
		}

		$warnings = array();
		$numbers  = array();

		foreach ( $data['warnings'] ?? array() as $warning ) {
			$normalized = is_array( $warning ) ? self::normalizeWarning( $warning ) : null;

			if ( null === $normalized || isset( $numbers[ $normalized['number'] ] ) ) {
				return null;
			}

			$numbers[ $normalized['number'] ] = true;
			$warnings[]                       = $normalized;
		}

		return array(
			'version'    => self::VERSION,
			'title'      => (string) $data['title'],
			'source'     => $source,
			'sections'   => $sections,
			'assets'     => $assets,
			'warnings'   => $warnings,
			'statistics' => array(),
		);
	}

	/**
	 * Normalize the source descriptor.
	 *
	 * @param mixed $source Raw source.
	 * @return array<string,mixed>|null
	 */
	private static function normalizeSource( mixed $source ): ?array {
		if ( ! is_array( $source ) || array() !== array_diff( array_keys( $source ), array( 'format', 'originalName', 'sha256', 'pages', 'slides' ) ) ) {
			return null;
		}

		if ( ! in_array( $source['format'] ?? null, self::FORMATS, true ) || ! self::isText( $source['originalName'] ?? null, 255 ) ) {
			return null;
		}

		$sha = $source['sha256'] ?? null;

		if ( ! is_string( $sha ) || ( '' !== $sha && 1 !== preg_match( self::SHA_PATTERN, $sha ) ) ) {
			return null;
		}

		$normalized = array(
			'format'       => $source['format'],
			'originalName' => (string) $source['originalName'],
			'sha256'       => $sha,
		);

		foreach ( array( 'pages', 'slides' ) as $key ) {
			if ( array_key_exists( $key, $source ) ) {
				if ( ! is_int( $source[ $key ] ) || $source[ $key ] < 0 || $source[ $key ] > 100000 ) {
					return null;
				}

				$normalized[ $key ] = $source[ $key ];
			}
		}

		return $normalized;
	}

	/**
	 * Normalize a section.
	 *
	 * @param array<string,mixed> $section     Raw section.
	 * @param array<string,bool>  $asset_ids   Known asset IDs.
	 * @param array<string,bool>  $ids         Used IDs (updated).
	 * @param int                 $block_count Running block count (updated).
	 * @return array<string,mixed>|null
	 */
	private static function normalizeSection( array $section, array $asset_ids, array &$ids, int &$block_count ): ?array {
		if ( array() !== array_diff( array_keys( $section ), array( 'id', 'kind', 'title', 'origin', 'blocks' ) ) ) {
			return null;
		}

		$id = $section['id'] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || isset( $ids[ $id ] ) || ! in_array( $section['kind'] ?? null, self::SECTION_KINDS, true ) ) {
			return null;
		}

		$title = $section['title'] ?? null;

		if ( null !== $title && ! self::isText( $title, self::MAX_TEXT ) ) {
			return null;
		}

		$origin = self::normalizeOrigin( $section['origin'] ?? array() );

		if ( null === $origin || ! self::isList( $section['blocks'] ?? null, self::MAX_BLOCKS ) ) {
			return null;
		}

		$ids[ $id ] = true;
		$blocks     = array();

		foreach ( $section['blocks'] as $block ) {
			++$block_count;

			$normalized = is_array( $block ) && $block_count <= self::MAX_BLOCKS ? self::normalizeBlock( $block, $asset_ids, $ids ) : null;

			if ( null === $normalized ) {
				return null;
			}

			$blocks[] = $normalized;
		}

		return array(
			'id'     => $id,
			'kind'   => $section['kind'],
			'title'  => null === $title ? null : (string) $title,
			'origin' => $origin,
			'blocks' => $blocks,
		);
	}

	/**
	 * Normalize one block.
	 *
	 * @param array<string,mixed> $block     Raw block.
	 * @param array<string,bool>  $asset_ids Known asset IDs.
	 * @param array<string,bool>  $ids       Used IDs (updated).
	 * @return array<string,mixed>|null
	 */
	private static function normalizeBlock( array $block, array $asset_ids, array &$ids ): ?array {
		$type = $block['type'] ?? null;
		$id   = $block['id'] ?? null;

		if ( ! is_string( $id ) || 1 !== preg_match( self::ID_PATTERN, $id ) || isset( $ids[ $id ] ) ) {
			return null;
		}

		$origin = self::normalizeOrigin( $block['origin'] ?? array() );

		if ( null === $origin ) {
			return null;
		}

		$allowed = array(
			'paragraph' => array( 'type', 'id', 'runs', 'align', 'origin' ),
			'heading'   => array( 'type', 'id', 'level', 'runs', 'origin' ),
			'list'      => array( 'type', 'id', 'ordered', 'items', 'origin' ),
			'table'     => array( 'type', 'id', 'rows', 'origin' ),
			'image'     => array( 'type', 'id', 'assetId', 'alt', 'caption', 'origin', 'fallback' ),
		);

		if ( ! is_string( $type ) || ! isset( $allowed[ $type ] ) || array() !== array_diff( array_keys( $block ), $allowed[ $type ] ) ) {
			return null;
		}

		$normalized = null;

		switch ( $type ) {
			case 'paragraph':
				$runs = self::normalizeRuns( $block['runs'] ?? null );
				$ok   = null !== $runs && ( ! isset( $block['align'] ) || in_array( $block['align'], array( 'left', 'center', 'right', 'justify' ), true ) );

				if ( $ok ) {
					$normalized = array(
						'type' => 'paragraph',
						'id'   => $id,
						'runs' => $runs,
					);

					if ( isset( $block['align'] ) ) {
						$normalized['align'] = $block['align'];
					}

					$normalized['origin'] = $origin;
				}
				break;

			case 'heading':
				$runs  = self::normalizeRuns( $block['runs'] ?? null );
				$level = $block['level'] ?? null;

				if ( null !== $runs && is_int( $level ) && $level >= 1 && $level <= 6 ) {
					$normalized = array(
						'type'   => 'heading',
						'id'     => $id,
						'level'  => $level,
						'runs'   => $runs,
						'origin' => $origin,
					);
				}
				break;

			case 'list':
				$items = self::normalizeListItems( $block['items'] ?? null, 1 );

				if ( null !== $items && array() !== $items && is_bool( $block['ordered'] ?? null ) ) {
					$normalized = array(
						'type'    => 'list',
						'id'      => $id,
						'ordered' => $block['ordered'],
						'items'   => $items,
						'origin'  => $origin,
					);
				}
				break;

			case 'table':
				$rows = self::normalizeRows( $block['rows'] ?? null );

				if ( null !== $rows ) {
					$normalized = array(
						'type'   => 'table',
						'id'     => $id,
						'rows'   => $rows,
						'origin' => $origin,
					);
				}
				break;

			case 'image':
				$asset_id = $block['assetId'] ?? null;
				$caption  = $block['caption'] ?? null;
				$ok       = is_string( $asset_id ) && isset( $asset_ids[ $asset_id ] )
					&& self::isText( $block['alt'] ?? null, self::MAX_TEXT )
					&& ( null === $caption || self::isText( $caption, self::MAX_TEXT ) );

				$fallback = null;

				if ( $ok && array_key_exists( 'fallback', $block ) ) {
					$fallback = self::normalizeFallback( $block['fallback'] );
					$ok       = null !== $fallback;
				}

				if ( $ok ) {
					$normalized = array(
						'type'    => 'image',
						'id'      => $id,
						'assetId' => $asset_id,
						'alt'     => (string) $block['alt'],
						'caption' => null === $caption ? null : (string) $caption,
						'origin'  => $origin,
					);

					if ( null !== $fallback ) {
						$normalized['fallback'] = $fallback;
					}
				}
				break;
		}

		if ( null !== $normalized ) {
			$ids[ $id ] = true;
		}

		return $normalized;
	}

	/**
	 * Normalize image fallback data.
	 *
	 * @param mixed $fallback Raw fallback.
	 * @return array{warningNumbers:array<int,int>}|null
	 */
	private static function normalizeFallback( mixed $fallback ): ?array {
		if ( ! is_array( $fallback ) || array( 'warningNumbers' ) !== array_keys( $fallback ) || ! self::isList( $fallback['warningNumbers'], self::MAX_WARNINGS ) ) {
			return null;
		}

		$numbers = array();

		foreach ( $fallback['warningNumbers'] as $number ) {
			if ( ! is_int( $number ) || $number < 1 ) {
				return null;
			}

			$numbers[] = $number;
		}

		return array( 'warningNumbers' => $numbers );
	}

	/**
	 * Normalize runs.
	 *
	 * @param mixed $runs Raw runs.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function normalizeRuns( mixed $runs ): ?array {
		if ( ! self::isList( $runs, self::MAX_RUNS ) ) {
			return null;
		}

		$normalized = array();

		foreach ( $runs as $run ) {
			if ( ! is_array( $run ) || array() !== array_diff( array_keys( $run ), array_merge( array( 'text', 'link' ), self::RUN_FLAGS ) ) || ! self::isText( $run['text'] ?? null, self::MAX_RUN_TEXT ) ) {
				return null;
			}

			$clean = array( 'text' => (string) $run['text'] );

			foreach ( self::RUN_FLAGS as $flag ) {
				if ( array_key_exists( $flag, $run ) ) {
					if ( true !== $run[ $flag ] ) {
						return null;
					}

					$clean[ $flag ] = true;
				}
			}

			if ( array_key_exists( 'link', $run ) ) {
				$link = $run['link'];

				if ( ! is_string( $link ) || strlen( $link ) > 2048 || ! in_array( strtolower( (string) wp_parse_url( $link, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true ) || esc_url_raw( $link, array( 'http', 'https' ) ) !== $link ) {
					return null;
				}

				$clean['link'] = $link;
			}

			$normalized[] = $clean;
		}

		return $normalized;
	}

	/**
	 * Normalize list items up to depth 6.
	 *
	 * @param mixed $items Raw items.
	 * @param int   $depth Current depth.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function normalizeListItems( mixed $items, int $depth ): ?array {
		if ( $depth > self::MAX_LIST_DEPTH || ! self::isList( $items, 5000 ) ) {
			return null;
		}

		$normalized = array();

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || array() !== array_diff( array_keys( $item ), array( 'runs', 'children' ) ) ) {
				return null;
			}

			$runs     = self::normalizeRuns( $item['runs'] ?? null );
			$children = self::normalizeListItems( $item['children'] ?? array(), $depth + 1 );

			if ( null === $runs || null === $children ) {
				return null;
			}

			$normalized[] = array(
				'runs'     => $runs,
				'children' => $children,
			);
		}

		return $normalized;
	}

	/**
	 * Normalize table rows.
	 *
	 * @param mixed $rows Raw rows.
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function normalizeRows( mixed $rows ): ?array {
		if ( ! self::isList( $rows, self::MAX_TABLE_ROWS ) || array() === $rows ) {
			return null;
		}

		$normalized = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || array( 'cells' ) !== array_keys( $row ) || ! self::isList( $row['cells'], self::MAX_TABLE_CELLS ) ) {
				return null;
			}

			$cells = array();

			foreach ( $row['cells'] as $cell ) {
				if ( ! is_array( $cell ) || array() !== array_diff( array_keys( $cell ), array( 'runs', 'header', 'colSpan', 'rowSpan' ) ) ) {
					return null;
				}

				$runs = self::normalizeRuns( $cell['runs'] ?? null );
				$col  = $cell['colSpan'] ?? 1;
				$span = $cell['rowSpan'] ?? 1;

				if ( null === $runs || ! is_bool( $cell['header'] ?? null ) || ! is_int( $col ) || ! is_int( $span ) || $col < 1 || $span < 1 || $col > self::MAX_SPAN || $span > self::MAX_SPAN ) {
					return null;
				}

				$cells[] = array(
					'runs'    => $runs,
					'header'  => $cell['header'],
					'colSpan' => $col,
					'rowSpan' => $span,
				);
			}

			$normalized[] = array( 'cells' => $cells );
		}

		return $normalized;
	}

	/**
	 * Normalize an asset.
	 *
	 * @param array<string,mixed> $asset Raw asset.
	 * @return array<string,mixed>|null
	 */
	private static function normalizeAsset( array $asset ): ?array {
		if ( array() !== array_diff( array_keys( $asset ), array( 'assetId', 'kind', 'mimeType', 'width', 'height', 'byteSize', 'sha256', 'status', 'origin' ) ) ) {
			return null;
		}

		$asset_id = $asset['assetId'] ?? null;
		$origin   = self::normalizeOrigin( $asset['origin'] ?? array() );

		if (
			! is_string( $asset_id )
			|| 1 !== preg_match( self::ASSET_ID_PATTERN, $asset_id )
			|| ! in_array( $asset['kind'] ?? null, self::ASSET_KINDS, true )
			|| ! in_array( $asset['mimeType'] ?? null, self::ASSET_MIMES, true )
			|| ! in_array( $asset['status'] ?? null, self::ASSET_STATUS, true )
			|| null === $origin
		) {
			return null;
		}

		$normalized = array(
			'assetId'  => $asset_id,
			'kind'     => $asset['kind'],
			'mimeType' => $asset['mimeType'],
		);

		foreach ( array( 'width', 'height', 'byteSize' ) as $key ) {
			$value = $asset[ $key ] ?? null;

			if ( null !== $value && ( ! is_int( $value ) || $value < 0 ) ) {
				return null;
			}

			$normalized[ $key ] = $value;
		}

		$sha = $asset['sha256'] ?? null;

		if ( null !== $sha && ( ! is_string( $sha ) || 1 !== preg_match( self::SHA_PATTERN, $sha ) ) ) {
			return null;
		}

		$normalized['sha256'] = $sha;
		$normalized['status'] = $asset['status'];
		$normalized['origin'] = $origin;

		return $normalized;
	}

	/**
	 * Normalize a warning.
	 *
	 * @param array<string,mixed> $warning Raw warning.
	 * @return array<string,mixed>|null
	 */
	private static function normalizeWarning( array $warning ): ?array {
		if ( array() !== array_diff( array_keys( $warning ), array( 'number', 'code', 'message', 'severity', 'origin', 'element' ) ) ) {
			return null;
		}

		$number = $warning['number'] ?? null;
		$code   = $warning['code'] ?? null;

		if ( ! is_int( $number ) || $number < 1 || ! in_array( $code, self::WARNING_CODES, true ) || ! self::isText( $warning['message'] ?? null, self::MAX_TEXT ) || ! in_array( $warning['severity'] ?? null, array( 'info', 'warning' ), true ) ) {
			return null;
		}

		$normalized = array(
			'number'   => $number,
			'code'     => $code,
			'message'  => (string) $warning['message'],
			'severity' => $warning['severity'],
		);

		if ( array_key_exists( 'origin', $warning ) ) {
			$origin = self::normalizeOrigin( $warning['origin'] );

			if ( null === $origin ) {
				return null;
			}

			$normalized['origin'] = $origin;
		}

		if ( array_key_exists( 'element', $warning ) ) {
			if ( 'unsupportedElement' !== $code || ! in_array( $warning['element'], self::ELEMENTS, true ) ) {
				return null;
			}

			$normalized['element'] = $warning['element'];
		}

		return $normalized;
	}

	/**
	 * Normalize an origin.
	 *
	 * @param mixed $origin Raw origin.
	 * @return array<string,mixed>|null
	 */
	private static function normalizeOrigin( mixed $origin ): ?array {
		if ( ! is_array( $origin ) || array() !== array_diff( array_keys( $origin ), array( 'page', 'slide', 'docIndex', 'bounds' ) ) ) {
			return null;
		}

		$normalized = array();

		foreach ( array(
			'page'     => 1,
			'slide'    => 1,
			'docIndex' => 0,
		) as $key => $minimum ) {
			if ( array_key_exists( $key, $origin ) ) {
				if ( ! is_int( $origin[ $key ] ) || $origin[ $key ] < $minimum ) {
					return null;
				}

				$normalized[ $key ] = $origin[ $key ];
			}
		}

		if ( array_key_exists( 'bounds', $origin ) ) {
			$bounds = $origin['bounds'];

			if ( ! is_array( $bounds ) || array( 'height', 'width', 'x', 'y' ) !== self::sortedKeys( $bounds ) ) {
				return null;
			}

			$clean = array();

			foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
				if ( ! is_int( $bounds[ $key ] ) && ! is_float( $bounds[ $key ] ) ) {
					return null;
				}

				$value = round( (float) $bounds[ $key ], 2 );

				if ( ! is_finite( $value ) || abs( $value ) > 1000000 || ( in_array( $key, array( 'width', 'height' ), true ) && $value < 0 ) ) {
					return null;
				}

				$clean[ $key ] = $value;
			}

			$normalized['bounds'] = $clean;
		}

		return $normalized;
	}

	/**
	 * Sorted keys of an array.
	 *
	 * @param array<string,mixed> $values Values.
	 * @return array<int,string>
	 */
	private static function sortedKeys( array $values ): array {
		$keys = array_map( 'strval', array_keys( $values ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * Whether a value is a list with at most $max entries.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum length.
	 */
	private static function isList( mixed $value, int $max ): bool {
		return is_array( $value ) && array_is_list( $value ) && count( $value ) <= $max;
	}

	/**
	 * Whether a value is a valid UTF-8 string of at most $max characters.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Maximum length.
	 */
	private static function isText( mixed $value, int $max ): bool {
		if ( ! is_string( $value ) ) {
			return false;
		}

		if ( '' !== $value && wp_check_invalid_utf8( $value ) !== $value ) {
			return false;
		}

		return ( function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value ) ) <= $max;
	}

	/**
	 * Stable JSON encoding used for fingerprints.
	 *
	 * @param array<string,mixed> $data Data.
	 */
	private static function encode( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );
	}
}
