<?php
/**
 * Converts uploaded PowerPoint files through Google Slides into canonical documents.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Google\DriveWriteClient;
use DocSyncWP\Google\SlidesClient;
use WP_Error;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

/**
 * PPTX → Google Slides (in My Drive / Imported from WordPress) → canonical.
 *
 * Conversion is resumable across cron ticks. The first tick uploads the deck
 * and hands the presentation ID to `$on_created_file` before any Slides read.
 * Each tick then caches at most THUMBNAILS_PER_TICK real slide thumbnails,
 * keyed by `revisionId:pageObjectId`, and returns `complete:false` until every
 * slide has one; a 429 or 5xx stops the tick and reports `retryAfter`. The
 * final tick extracts titles, text, lists, tables, images, and speaker notes,
 * and detects charts, video, WordArt, animations (from the original slide
 * XML), and unsupported groups. Each such slide gets its thumbnail as a
 * fallback image with numbered `unsupportedElement` warnings.
 */
final class DeckConverter {
	public const THUMBNAILS_PER_TICK = 20;

	private const MAX_SLIDES           = 200;
	private const MAX_IMAGES           = 300;
	private const EMU_PER_POINT        = 12700;
	private const MAX_XML_BYTES        = 20971520;
	private const BOUNDS_TOLERANCE     = 0.08;
	private const SKIPPED_PLACEHOLDERS = array( 'SLIDE_NUMBER', 'FOOTER', 'DATE_AND_TIME', 'SLIDE_IMAGE' );
	private const TITLE_PLACEHOLDERS   = array( 'TITLE', 'CENTERED_TITLE' );

	/**
	 * Drive write client.
	 *
	 * @var DriveWriteClient
	 */
	private DriveWriteClient $drive_write;

	/**
	 * Slides API client.
	 *
	 * @var SlidesClient
	 */
	private SlidesClient $slides;

	/**
	 * Private asset store.
	 *
	 * @var PrivateAssetStore
	 */
	private PrivateAssetStore $assets;

	/**
	 * Per-conversion build state.
	 *
	 * @var array<string,mixed>
	 */
	private array $state = array();

	/**
	 * Constructor.
	 *
	 * @param DriveWriteClient  $drive_write Drive write client.
	 * @param SlidesClient      $slides      Slides API client.
	 * @param PrivateAssetStore $assets      Private asset store.
	 */
	public function __construct( DriveWriteClient $drive_write, SlidesClient $slides, PrivateAssetStore $assets ) {
		$this->drive_write = $drive_write;
		$this->slides      = $slides;
		$this->assets      = $assets;
	}

	/**
	 * Run one bounded conversion tick for a PPTX file.
	 *
	 * Returns `document` (null until `complete` is true), `googleFileId`,
	 * `googleTemporaries`, `slideThumbnails`, `thumbnailCache`, `imageCache`,
	 * `complete`, `progress` {done,total}, `retryAfter` seconds, and `slideCount`.
	 *
	 * @param int                 $user_id         Session owner.
	 * @param string              $session_id      Session ID.
	 * @param array<string,mixed> $file            Session file record.
	 * @param array<string,mixed> $options         Normalized file options (applied later by applyOptions()).
	 * @param callable|null       $on_created_file Receives the created presentation ID; returns true or WP_Error.
	 * @return array<string,mixed>|WP_Error
	 */
	public function convert( int $user_id, string $session_id, array $file, array $options, ?callable $on_created_file = null ): array|WP_Error {
		unset( $options );

		$this->state = array( 'thumbMeta' => array() );

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'docsync_wp_import_zip_unavailable',
				__( 'Word and PowerPoint imports need the PHP zip extension (ZipArchive), which is not installed on this server.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 422 )
			);
		}

		$presentation_id = (string) ( $file['googleFileId'] ?? '' );

		if ( '' === $presentation_id ) {
			$created = $this->uploadDeck( $user_id, $session_id, $file );

			if ( is_wp_error( $created ) ) {
				return $created;
			}

			$presentation_id = $created;

			if ( null !== $on_created_file ) {
				$accepted = $on_created_file( $presentation_id );

				if ( is_wp_error( $accepted ) ) {
					return $this->withTemporaries( $accepted, array( $presentation_id ) );
				}
			}
		}

		$temporaries  = array( $presentation_id );
		$cache_key    = 'p_' . substr( (string) ( $file['fileId'] ?? '' ), 2 ) . '.json';
		$presentation = $this->cachedPresentation( $session_id, $cache_key, $presentation_id );

		if ( null === $presentation ) {
			$presentation = $this->slides->getPresentation( $user_id, $presentation_id );

			if ( is_wp_error( $presentation ) ) {
				return $this->withTemporaries( $presentation, $temporaries );
			}

			$this->assets->put(
				$session_id,
				$cache_key,
				(string) wp_json_encode(
					array(
						'presentationId' => $presentation_id,
						'data'           => $presentation,
					)
				)
			);
		}

		$slides = array_values( array_filter( $presentation['slides'], 'is_array' ) );

		if ( count( $slides ) > self::MAX_SLIDES ) {
			return $this->withTemporaries(
				new WP_Error(
					'docsync_wp_import_too_many_pages',
					__( 'This presentation has more than 200 slides. Split it into smaller files, then upload them.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 422 )
				),
				$temporaries
			);
		}

		if ( array() === $slides ) {
			return $this->withTemporaries(
				new WP_Error(
					'docsync_wp_import_invalid_file',
					__( 'This presentation has no slides to import.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 422 )
				),
				$temporaries
			);
		}

		$revision = sanitize_key( (string) ( $presentation['revisionId'] ?? 'r0' ) );
		$revision = '' !== $revision ? substr( $revision, 0, 80 ) : 'r0';
		$cache    = isset( $file['thumbnailCache'] ) && is_array( $file['thumbnailCache'] ) ? $file['thumbnailCache'] : array();
		$fetched  = $this->fetchThumbnails( $user_id, $session_id, (string) ( $file['fileId'] ?? '' ), $presentation_id, $revision, $slides, $cache );

		if ( is_wp_error( $fetched ) ) {
			return $this->withTemporaries( $fetched, $temporaries );
		}

		$result = array(
			'document'          => null,
			'googleFileId'      => $presentation_id,
			'googleTemporaries' => $temporaries,
			'slideThumbnails'   => array(),
			'thumbnailCache'    => $fetched['cache'],
			'imageCache'        => isset( $file['imageCache'] ) && is_array( $file['imageCache'] ) ? $file['imageCache'] : array(),
			'complete'          => $fetched['complete'],
			'progress'          => array(
				'done'  => $fetched['done'],
				'total' => count( $slides ),
			),
			'retryAfter'        => $fetched['retryAfter'],
			'slideCount'        => count( $slides ),
		);

		if ( ! $fetched['complete'] ) {
			return $result;
		}

		// Image content URLs are short-lived, so the final tick reads a fresh copy.
		$fresh = $this->slides->getPresentation( $user_id, $presentation_id );

		if ( is_wp_error( $fresh ) ) {
			return $this->withTemporaries( $fresh, $temporaries );
		}

		$fresh_slides = array_values( array_filter( $fresh['slides'] ?? array(), 'is_array' ) );

		if ( count( $fresh_slides ) !== count( $slides ) ) {
			$fresh_slides = $slides;
		}

		$xml = $this->inspectOriginal( $session_id, (string) ( $file['originalKey'] ?? '' ), count( $fresh_slides ) );

		if ( is_wp_error( $xml ) ) {
			return $this->withTemporaries( $xml, $temporaries );
		}

		$built = $this->buildDocument( $user_id, $session_id, $file, $fresh, $fresh_slides, $revision, $fetched['cache'], $result['imageCache'], $xml );

		if ( is_wp_error( $built ) ) {
			return $this->withTemporaries( $built, $temporaries );
		}

		$result['document']        = $built['document'];
		$result['slideThumbnails'] = $built['slideThumbnails'];
		$result['imageCache']      = $built['imageCache'];

		return $result;
	}

	/**
	 * Upload the private original to Drive for conversion.
	 *
	 * @param int                 $user_id    User ID.
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $file       Session file.
	 * @return string|WP_Error Presentation ID.
	 */
	private function uploadDeck( int $user_id, string $session_id, array $file ): string|WP_Error {
		$path = $this->assets->materialize( $session_id, (string) ( $file['originalKey'] ?? '' ) );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		try {
			$created = $this->drive_write->uploadForConversion(
				$user_id,
				$path,
				DriveWriteClient::PPTX_MIME_TYPE,
				DriveWriteClient::GOOGLE_SLIDES_MIME_TYPE,
				$this->baseName( (string) ( $file['originalName'] ?? '' ) ),
				$session_id
			);
		} finally {
			$this->assets->release( $path );
		}

		return is_wp_error( $created ) ? $created : $created['fileId'];
	}

	/**
	 * Read the cached presentation structure for this presentation ID.
	 *
	 * @param string $session_id      Session ID.
	 * @param string $cache_key       Private cache key.
	 * @param string $presentation_id Presentation ID.
	 * @return array<string,mixed>|null
	 */
	private function cachedPresentation( string $session_id, string $cache_key, string $presentation_id ): ?array {
		$raw = $this->assets->read( $session_id, $cache_key );

		if ( is_wp_error( $raw ) ) {
			return null;
		}

		$cached = json_decode( $raw, true );

		if ( ! is_array( $cached ) || ( $cached['presentationId'] ?? '' ) !== $presentation_id || ! isset( $cached['data']['slides'] ) || ! is_array( $cached['data']['slides'] ) ) {
			return null;
		}

		return $cached['data'];
	}

	/**
	 * Cache up to THUMBNAILS_PER_TICK missing slide thumbnails.
	 *
	 * @param int                            $user_id         User ID.
	 * @param string                         $session_id      Session ID.
	 * @param string                         $file_id         File ID.
	 * @param string                         $presentation_id Presentation ID.
	 * @param string                         $revision        Revision ID.
	 * @param array<int,array<string,mixed>> $slides          Slides.
	 * @param array<string,string>           $cache           Existing cache.
	 * @return array{cache:array<string,string>,complete:bool,done:int,retryAfter:int}|WP_Error
	 */
	private function fetchThumbnails( int $user_id, string $session_id, string $file_id, string $presentation_id, string $revision, array $slides, array $cache ): array|WP_Error {
		$fetched     = 0;
		$retry_after = 0;
		$done        = 0;

		foreach ( $slides as $slide ) {
			$object_id = (string) ( $slide['objectId'] ?? '' );
			$key       = $revision . ':' . $object_id;

			if ( isset( $cache[ $key ] ) && is_string( $cache[ $key ] ) ) {
				++$done;
				continue;
			}

			if ( $fetched >= self::THUMBNAILS_PER_TICK || $retry_after > 0 ) {
				continue;
			}

			$thumbnail = $this->slides->getThumbnail( $user_id, $presentation_id, $object_id );

			if ( is_wp_error( $thumbnail ) ) {
				$data = $thumbnail->get_error_data();

				if ( is_array( $data ) && ! empty( $data['retryable'] ) ) {
					$retry_after = max( 30, absint( $data['retryAfter'] ?? 0 ) );
					continue;
				}

				return $thumbnail;
			}

			$asset_id = 'a_' . substr( hash( 'sha256', $file_id . '|thumb|' . $key ), 0, 16 );
			$stored   = $this->assets->put( $session_id, $asset_id, $thumbnail['bytes'] );

			if ( is_wp_error( $stored ) ) {
				return $stored;
			}

			$cache[ $key ] = $asset_id;
			++$fetched;
			++$done;

			$this->state['thumbMeta'][ $asset_id ] = array(
				'width'    => absint( $thumbnail['width'] ),
				'height'   => absint( $thumbnail['height'] ),
				'byteSize' => $stored['byteSize'],
				'sha256'   => $stored['sha256'],
			);
		}

		return array(
			'cache'      => $cache,
			'complete'   => count( $slides ) === $done,
			'done'       => $done,
			'retryAfter' => $retry_after,
		);
	}

	/**
	 * Inspect the original slide XML for animations, charts, video, and WordArt.
	 *
	 * Slides are read in presentation order through presentation.xml and its
	 * relationships, so slide N always maps to the Nth `p:sldId`.
	 *
	 * @param string $session_id   Session ID.
	 * @param string $original_key Private key of the original.
	 * @param int    $slide_count  Slide count from the Slides API.
	 * @return array<int,array<string,mixed>>|WP_Error Findings keyed by 1-based slide number.
	 */
	private function inspectOriginal( string $session_id, string $original_key, int $slide_count ): array|WP_Error {
		$path = $this->assets->materialize( $session_id, $original_key );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$zip    = new ZipArchive();
		$opened = false;

		try {
			$opened = true === $zip->open( $path );

			if ( ! $opened ) {
				return new WP_Error(
					'docsync_wp_import_invalid_file',
					__( 'Brasth Document Sync could not read the original PowerPoint file.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 422 )
				);
			}

			$targets  = $this->slideTargets( $zip );
			$findings = array();

			foreach ( $targets as $position => $target ) {
				if ( $position >= $slide_count ) {
					break;
				}

				$stat = $zip->statName( $target );
				$xml  = is_array( $stat ) && (int) $stat['size'] <= self::MAX_XML_BYTES ? $zip->getFromName( $target ) : false;

				$findings[ $position + 1 ] = is_string( $xml ) ? $this->slideXmlFindings( $xml ) : $this->emptyFindings();
			}

			return $findings;
		} finally {
			if ( $opened ) {
				$zip->close();
			}

			$this->assets->release( $path );
		}
	}

	/**
	 * Slide part paths in presentation order.
	 *
	 * @param ZipArchive $zip Open archive.
	 * @return array<int,string>
	 */
	private function slideTargets( ZipArchive $zip ): array {
		$presentation = $zip->getFromName( 'ppt/presentation.xml' );
		$relations    = $zip->getFromName( 'ppt/_rels/presentation.xml.rels' );

		if ( ! is_string( $presentation ) || ! is_string( $relations ) ) {
			return array();
		}

		$targets = array();

		if ( preg_match_all( '/<Relationship\b[^>]*>/i', $relations, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				if ( preg_match( '/\bId="([^"]+)"/', $tag, $id ) && preg_match( '/\bTarget="([^"]+)"/', $tag, $target ) && str_contains( $tag, '/slide"' ) ) {
					$path = ltrim( $target[1], '/' );

					if ( ! str_starts_with( $path, 'ppt/' ) ) {
						$path = 'ppt/' . $path;
					}

					$targets[ $id[1] ] = $path;
				}
			}
		}

		$ordered = array();

		if ( preg_match_all( '/<p:sldId\b[^>]*\br:id="([^"]+)"/', $presentation, $ids ) ) {
			foreach ( $ids[1] as $relationship_id ) {
				if ( isset( $targets[ $relationship_id ] ) && ! str_contains( $targets[ $relationship_id ], '..' ) ) {
					$ordered[] = $targets[ $relationship_id ];
				}
			}
		}

		return $ordered;
	}

	/**
	 * Findings for one slide XML part.
	 *
	 * @param string $xml Slide XML.
	 * @return array<string,mixed>
	 */
	private function slideXmlFindings( string $xml ): array {
		$findings = $this->emptyFindings();

		$findings['animation'] = 1 === preg_match( '/<p:timing\b/', $xml ) && 1 === preg_match( '/<p:(?:par|seq|anim\w*|set|video|audio|cmd)\b/', $xml );
		$findings['video']     = (int) preg_match_all( '/<(?:a|p):videoFile\b|<p14:media\b/', $xml );
		$findings['wordArt']   = (int) preg_match_all( '/<a:prstTxWarp\b(?![^>]*prst="textNoShape")/', $xml );

		if ( preg_match_all( '/<p:graphicFrame\b.*?<\/p:graphicFrame>/s', $xml, $frames ) ) {
			foreach ( $frames[0] as $frame ) {
				if ( ! str_contains( $frame, '<c:chart' ) && ! str_contains( $frame, 'drawingml/2006/chart' ) && ! str_contains( $frame, 'drawingml/2014/chartex' ) ) {
					continue;
				}

				$bounds = null;

				if ( preg_match( '/<a:off\b[^>]*\bx="(-?\d+)"[^>]*\by="(-?\d+)"/', $frame, $offset ) && preg_match( '/<a:ext\b[^>]*\bcx="(\d+)"[^>]*\bcy="(\d+)"/', $frame, $extent ) ) {
					$bounds = array(
						'x'      => round( (int) $offset[1] / self::EMU_PER_POINT, 2 ),
						'y'      => round( (int) $offset[2] / self::EMU_PER_POINT, 2 ),
						'width'  => round( (int) $extent[1] / self::EMU_PER_POINT, 2 ),
						'height' => round( (int) $extent[2] / self::EMU_PER_POINT, 2 ),
					);
				}

				$findings['charts'][] = $bounds;
			}
		}

		return $findings;
	}

	/**
	 * Findings with nothing detected.
	 *
	 * @return array<string,mixed>
	 */
	private function emptyFindings(): array {
		return array(
			'animation' => false,
			'video'     => 0,
			'wordArt'   => 0,
			'charts'    => array(),
		);
	}

	/**
	 * Build the canonical document from the presentation.
	 *
	 * @param int                            $user_id      User ID.
	 * @param string                         $session_id   Session ID.
	 * @param array<string,mixed>            $file         Session file.
	 * @param array<string,mixed>            $presentation Presentation.
	 * @param array<int,array<string,mixed>> $slides       Slides.
	 * @param string                         $revision     Revision ID.
	 * @param array<string,string>           $cache        Thumbnail cache.
	 * @param array<string,array>            $image_cache  Image cache.
	 * @param array<int,array<string,mixed>> $xml          XML findings by slide.
	 * @return array{document:CanonicalDocument,slideThumbnails:array<int,array<string,mixed>>,imageCache:array<string,array>}|WP_Error
	 */
	private function buildDocument( int $user_id, string $session_id, array $file, array $presentation, array $slides, string $revision, array $cache, array $image_cache, array $xml ): array|WP_Error {
		$thumb_meta  = $this->state['thumbMeta'] ?? array();
		$this->state = array(
			'userId'     => $user_id,
			'sessionId'  => $session_id,
			'fileId'     => (string) ( $file['fileId'] ?? '' ),
			'revision'   => $revision,
			'assets'     => array(),
			'warnings'   => array(),
			'images'     => 0,
			'imageCache' => $image_cache,
			'thumbMeta'  => $thumb_meta,
		);

		$sections   = array();
		$thumbnails = array();

		foreach ( $slides as $position => $slide ) {
			$number    = $position + 1;
			$object_id = (string) ( $slide['objectId'] ?? '' );
			$thumb_id  = $cache[ $revision . ':' . $object_id ] ?? '';

			$this->registerThumbnail( $session_id, $thumb_id, $number );

			$built = $this->buildSlide( $slide, $number, $thumb_id, $xml[ $number ] ?? $this->emptyFindings() );

			$sections[]   = $built['section'];
			$thumbnails[] = array(
				'slide'          => $number,
				'assetId'        => $thumb_id,
				'title'          => $built['section']['title'],
				'warningNumbers' => $built['warningNumbers'],
			);

			if ( null !== $built['notes'] ) {
				$sections[] = $built['notes'];
			}
		}

		$title = '';

		foreach ( $sections as $section ) {
			if ( 'slide' === $section['kind'] && null !== $section['title'] && '' !== trim( $section['title'] ) ) {
				$title = trim( $section['title'] );
				break;
			}
		}

		if ( '' === $title ) {
			$title = trim( sanitize_text_field( (string) ( $presentation['title'] ?? '' ) ) );
		}

		if ( '' === $title ) {
			$title = $this->baseName( (string) ( $file['originalName'] ?? '' ) );
		}

		$document = CanonicalDocument::fromArray(
			array(
				'version'  => CanonicalDocument::VERSION,
				'title'    => function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 200 ) : substr( $title, 0, 200 ),
				'source'   => array(
					'format'       => 'pptx',
					'originalName' => (string) ( $file['originalName'] ?? '' ),
					'sha256'       => (string) ( $file['sha256'] ?? '' ),
					'slides'       => count( $slides ),
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
			'document'        => $document,
			'slideThumbnails' => $thumbnails,
			'imageCache'      => $this->state['imageCache'],
		);
	}

	/**
	 * Register a cached slide thumbnail as a ready asset.
	 *
	 * @param string $session_id Session ID.
	 * @param string $asset_id   Thumbnail asset ID.
	 * @param int    $slide      Slide number.
	 */
	private function registerThumbnail( string $session_id, string $asset_id, int $slide ): void {
		if ( '' === $asset_id ) {
			return;
		}

		$meta = $this->state['thumbMeta'][ $asset_id ] ?? null;

		if ( null === $meta ) {
			$bytes = $this->assets->read( $session_id, $asset_id );
			$size  = is_string( $bytes ) ? getimagesizefromstring( $bytes ) : false;
			$meta  = array(
				'width'    => is_array( $size ) ? absint( $size[0] ) : null,
				'height'   => is_array( $size ) ? absint( $size[1] ) : null,
				'byteSize' => is_string( $bytes ) ? strlen( $bytes ) : null,
				'sha256'   => is_string( $bytes ) ? hash( 'sha256', $bytes ) : null,
			);
		}

		$this->state['assets'][ $asset_id ] = array(
			'assetId'  => $asset_id,
			'kind'     => 'slideThumbnail',
			'mimeType' => 'image/png',
			'width'    => $meta['width'],
			'height'   => $meta['height'],
			'byteSize' => $meta['byteSize'],
			'sha256'   => $meta['sha256'],
			'status'   => null !== $meta['sha256'] ? 'ready' : 'rejected',
			'origin'   => array( 'slide' => $slide ),
		);
	}

	/**
	 * Build one slide section, its notes, and its unsupported-visual fallback.
	 *
	 * @param array<string,mixed> $slide    Slide.
	 * @param int                 $number   Slide number.
	 * @param string              $thumb_id Thumbnail asset ID.
	 * @param array<string,mixed> $xml      XML findings.
	 * @return array{section:array<string,mixed>,notes:array<string,mixed>|null,warningNumbers:array<int,int>}
	 */
	private function buildSlide( array $slide, int $number, string $thumb_id, array $xml ): array {
		$elements = $this->orderedElements( isset( $slide['pageElements'] ) && is_array( $slide['pageElements'] ) ? $slide['pageElements'] : array() );
		$blocks   = array();
		$title    = null;
		$counter  = 0;
		$numbers  = array();
		$first    = null;

		$chart_frames  = $xml['charts'];
		$xml_charts    = count( $chart_frames );
		$api_charts    = 0;
		$api_videos    = 0;
		$api_word_arts = 0;
		$next_id       = function () use ( $number, &$counter ): string {
			++$counter;

			return 's' . $number . 'b' . $counter;
		};

		foreach ( $elements as $element ) {
			$bounds = $this->bounds( $element );
			$origin = array( 'slide' => $number );

			if ( null !== $bounds ) {
				$origin['bounds'] = $bounds;
			}

			$unsupported = $this->unsupportedKind( $element );

			if ( null === $unsupported && isset( $element['image'] ) && null !== $bounds ) {
				$matched = $this->matchChartFrame( $bounds, $chart_frames );

				if ( null !== $matched ) {
					unset( $chart_frames[ $matched ] );
					$unsupported = 'chart';
				}
			}

			if ( null !== $unsupported ) {
				if ( 'chart' === $unsupported ) {
					++$api_charts;
				} elseif ( 'video' === $unsupported ) {
					++$api_videos;
				} elseif ( 'wordArt' === $unsupported ) {
					++$api_word_arts;
				}

				$numbers[] = $this->warnUnsupported( $unsupported, $number, $origin );
				$first     = $first ?? count( $blocks );

				if ( 'group' === $unsupported ) {
					foreach ( $this->groupChildren( $element ) as $child ) {
						$blocks = array_merge( $blocks, $this->elementBlocks( $child, $number, $next_id, $title ) );
					}
				}

				continue;
			}

			if ( isset( $element['elementGroup'] ) ) {
				foreach ( $this->groupChildren( $element ) as $child ) {
					$blocks = array_merge( $blocks, $this->elementBlocks( $child, $number, $next_id, $title ) );
				}

				continue;
			}

			$blocks = array_merge( $blocks, $this->elementBlocks( $element, $number, $next_id, $title ) );
		}

		$position_after_title = ( isset( $blocks[0] ) && 'heading' === $blocks[0]['type'] ) ? 1 : 0;

		foreach ( array(
			'chart'   => max( 0, $xml_charts - $api_charts ),
			'video'   => max( 0, (int) $xml['video'] - $api_videos ),
			'wordArt' => max( 0, (int) $xml['wordArt'] - $api_word_arts ),
		) as $kind => $missing ) {
			for ( $index = 0; $index < $missing; ++$index ) {
				$numbers[] = $this->warnUnsupported( $kind, $number, array( 'slide' => $number ) );
				$first     = $first ?? $position_after_title;
			}
		}

		if ( ! empty( $xml['animation'] ) ) {
			$numbers[] = $this->warnUnsupported( 'animation', $number, array( 'slide' => $number ) );
			$first     = $first ?? $position_after_title;
		}

		if ( array() !== $numbers && '' !== $thumb_id && 'ready' === ( $this->state['assets'][ $thumb_id ]['status'] ?? '' ) ) {
			$fallback = array(
				'type'     => 'image',
				'id'       => $next_id(),
				'assetId'  => $thumb_id,
				/* translators: %d: slide number. */
				'alt'      => sprintf( __( 'Slide %d', 'brasth-document-sync-for-google-docs' ), $number ),
				'caption'  => null,
				'origin'   => array( 'slide' => $number ),
				'fallback' => array( 'warningNumbers' => $numbers ),
			);

			array_splice( $blocks, min( (int) $first, count( $blocks ) ), 0, array( $fallback ) );
		}

		if ( array() === $blocks ) {
			$this->warn(
				'emptySection',
				/* translators: %d: slide number. */
				sprintf( __( 'Slide %d has no text, tables, or images to import.', 'brasth-document-sync-for-google-docs' ), $number ),
				'info',
				array( 'slide' => $number )
			);
		}

		return array(
			'section'        => array(
				'id'     => 's' . $number,
				'kind'   => 'slide',
				'title'  => $title,
				'origin' => array( 'slide' => $number ),
				'blocks' => $blocks,
			),
			'notes'          => $this->buildNotes( $slide, $number ),
			'warningNumbers' => $numbers,
		);
	}

	/**
	 * Blocks for one supported page element.
	 *
	 * @param array<string,mixed> $element Page element.
	 * @param int                 $number  Slide number.
	 * @param callable            $next_id Block ID generator.
	 * @param string|null         $title   Slide title (set by the title placeholder).
	 * @return array<int,array<string,mixed>>
	 */
	private function elementBlocks( array $element, int $number, callable $next_id, ?string &$title ): array {
		$bounds = $this->bounds( $element );
		$origin = array( 'slide' => $number );

		if ( null !== $bounds ) {
			$origin['bounds'] = $bounds;
		}

		if ( isset( $element['shape'] ) && is_array( $element['shape'] ) ) {
			$placeholder = (string) ( $element['shape']['placeholder']['type'] ?? '' );

			if ( in_array( $placeholder, self::SKIPPED_PLACEHOLDERS, true ) ) {
				return array();
			}

			$paragraphs = $this->textParagraphs( $element['shape']['text']['textElements'] ?? array(), $origin );

			if ( array() === $paragraphs ) {
				return array();
			}

			if ( in_array( $placeholder, self::TITLE_PLACEHOLDERS, true ) || 'SUBTITLE' === $placeholder ) {
				$text = trim( implode( ' ', array_map( fn ( array $paragraph ): string => $this->runsText( $paragraph['runs'] ), $paragraphs ) ) );

				if ( '' === $text ) {
					return array();
				}

				$is_title = in_array( $placeholder, self::TITLE_PLACEHOLDERS, true ) && null === $title;

				if ( $is_title ) {
					$title = $text;
				}

				return array(
					array(
						'type'   => 'heading',
						'id'     => $next_id(),
						'level'  => $is_title ? 2 : 3,
						'runs'   => array( array( 'text' => $text ) ),
						'origin' => $origin,
					),
				);
			}

			return $this->paragraphBlocks( $paragraphs, $origin, $next_id );
		}

		if ( isset( $element['table'] ) && is_array( $element['table'] ) ) {
			$table = $this->tableBlock( $element['table'], $origin, $next_id );

			return null !== $table ? array( $table ) : array();
		}

		if ( isset( $element['image'] ) && is_array( $element['image'] ) ) {
			$image = $this->imageBlock( $element, $origin, $next_id );

			return null !== $image ? array( $image ) : array();
		}

		return array();
	}

	/**
	 * Unsupported element kind for a page element, or null.
	 *
	 * @param array<string,mixed> $element Page element.
	 */
	private function unsupportedKind( array $element ): ?string {
		if ( isset( $element['sheetsChart'] ) ) {
			return 'chart';
		}

		if ( isset( $element['video'] ) ) {
			return 'video';
		}

		if ( isset( $element['wordArt'] ) ) {
			return 'wordArt';
		}

		if ( isset( $element['elementGroup'] ) && ! $this->isSupportedGroup( $element ) ) {
			return 'group';
		}

		return null;
	}

	/**
	 * Whether a group holds only text shapes, tables, images, and supported groups.
	 *
	 * @param array<string,mixed> $element Group element.
	 */
	private function isSupportedGroup( array $element ): bool {
		$children = $element['elementGroup']['children'] ?? array();

		if ( ! is_array( $children ) ) {
			return false;
		}

		foreach ( $children as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( isset( $child['elementGroup'] ) ) {
				if ( ! $this->isSupportedGroup( $child ) ) {
					return false;
				}

				continue;
			}

			if ( ! isset( $child['shape'] ) && ! isset( $child['table'] ) && ! isset( $child['image'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Flattened supported children of a group, in reading order.
	 *
	 * @param array<string,mixed> $element Group element.
	 * @return array<int,array<string,mixed>>
	 */
	private function groupChildren( array $element ): array {
		$flat     = array();
		$children = $element['elementGroup']['children'] ?? array();

		foreach ( is_array( $children ) ? $children : array() as $child ) {
			if ( ! is_array( $child ) ) {
				continue;
			}

			if ( isset( $child['elementGroup'] ) ) {
				$flat = array_merge( $flat, $this->groupChildren( $child ) );
			} elseif ( isset( $child['shape'] ) || isset( $child['table'] ) || isset( $child['image'] ) ) {
				$flat[] = $child;
			}
		}

		return $this->orderedElements( $flat );
	}

	/**
	 * Index of a chart frame whose bounds match an image, or null.
	 *
	 * @param array<string,float>   $bounds Image bounds.
	 * @param array<int,array|null> $frames Remaining chart frame bounds.
	 */
	private function matchChartFrame( array $bounds, array $frames ): ?int {
		foreach ( $frames as $index => $frame ) {
			if ( ! is_array( $frame ) ) {
				continue;
			}

			$scale = max( 1.0, $frame['width'], $frame['height'] );

			if (
				abs( $frame['x'] - $bounds['x'] ) / $scale <= self::BOUNDS_TOLERANCE
				&& abs( $frame['y'] - $bounds['y'] ) / $scale <= self::BOUNDS_TOLERANCE
				&& abs( $frame['width'] - $bounds['width'] ) / $scale <= self::BOUNDS_TOLERANCE
				&& abs( $frame['height'] - $bounds['height'] ) / $scale <= self::BOUNDS_TOLERANCE
			) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Record one unsupported-element warning and return its number.
	 *
	 * @param string              $kind   chart, video, animation, wordArt, or group.
	 * @param int                 $slide  Slide number.
	 * @param array<string,mixed> $origin Origin.
	 */
	private function warnUnsupported( string $kind, int $slide, array $origin ): int {
		$messages = array(
			/* translators: %d: slide number. */
			'chart'     => __( 'Slide %d has a chart that WordPress cannot rebuild; the slide is shown as an image.', 'brasth-document-sync-for-google-docs' ),
			/* translators: %d: slide number. */
			'video'     => __( 'Slide %d has a video that cannot be imported; the slide is shown as an image.', 'brasth-document-sync-for-google-docs' ),
			/* translators: %d: slide number. */
			'animation' => __( 'Slide %d has animations that cannot be imported; the slide is shown as an image.', 'brasth-document-sync-for-google-docs' ),
			/* translators: %d: slide number. */
			'wordArt'   => __( 'Slide %d has WordArt that cannot be imported as text; the slide is shown as an image.', 'brasth-document-sync-for-google-docs' ),
			/* translators: %d: slide number. */
			'group'     => __( 'Slide %d has a grouped graphic that cannot be rebuilt; the slide is shown as an image.', 'brasth-document-sync-for-google-docs' ),
		);

		return $this->warn( 'unsupportedElement', sprintf( $messages[ $kind ], $slide ), 'warning', $origin, $kind );
	}

	/**
	 * Speaker notes section for a slide, or null when it has none.
	 *
	 * @param array<string,mixed> $slide  Slide.
	 * @param int                 $number Slide number.
	 * @return array<string,mixed>|null
	 */
	private function buildNotes( array $slide, int $number ): ?array {
		$notes_page = $slide['slideProperties']['notesPage'] ?? null;

		if ( ! is_array( $notes_page ) ) {
			return null;
		}

		$speaker_id = (string) ( $notes_page['notesProperties']['speakerNotesObjectId'] ?? '' );
		$origin     = array( 'slide' => $number );
		$runs_list  = array();

		foreach ( isset( $notes_page['pageElements'] ) && is_array( $notes_page['pageElements'] ) ? $notes_page['pageElements'] : array() as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['shape'] ) ) {
				continue;
			}

			$is_speaker = '' !== $speaker_id ? ( $element['objectId'] ?? '' ) === $speaker_id : 'BODY' === ( $element['shape']['placeholder']['type'] ?? '' );

			if ( ! $is_speaker ) {
				continue;
			}

			foreach ( $this->textParagraphs( $element['shape']['text']['textElements'] ?? array(), $origin ) as $paragraph ) {
				$runs = $paragraph['runs'];

				if ( '' === trim( $this->runsText( $runs ) ) ) {
					continue;
				}

				if ( null !== $paragraph['bullet'] ) {
					array_unshift( $runs, array( 'text' => str_repeat( '  ', $paragraph['bullet']['level'] ) . '• ' ) );
				}

				$runs_list[] = $runs;
			}
		}

		if ( array() === $runs_list ) {
			return null;
		}

		$blocks = array(
			array(
				'type'   => 'paragraph',
				'id'     => 'n' . $number . 'b0',
				'runs'   => array(
					array(
						'text' => __( 'Speaker notes', 'brasth-document-sync-for-google-docs' ),
						'bold' => true,
					),
				),
				'origin' => $origin,
			),
		);

		foreach ( $runs_list as $index => $runs ) {
			$blocks[] = array(
				'type'   => 'paragraph',
				'id'     => 'n' . $number . 'b' . ( $index + 1 ),
				'runs'   => $runs,
				'origin' => $origin,
			);
		}

		return array(
			'id'     => 'n' . $number,
			'kind'   => 'notes',
			'title'  => null,
			'origin' => $origin,
			'blocks' => $blocks,
		);
	}

	/**
	 * Split Slides text elements into paragraphs with runs and bullets.
	 *
	 * @param mixed               $text_elements Slides text elements.
	 * @param array<string,mixed> $origin        Origin for warnings.
	 * @return array<int,array{runs:array<int,array<string,mixed>>,bullet:array{level:int,ordered:bool}|null}>
	 */
	private function textParagraphs( mixed $text_elements, array $origin ): array {
		$paragraphs = array();
		$current    = null;

		foreach ( is_array( $text_elements ) ? $text_elements : array() as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['paragraphMarker'] ) ) {
				if ( null !== $current ) {
					$paragraphs[] = $current;
				}

				$bullet  = $element['paragraphMarker']['bullet'] ?? null;
				$current = array(
					'runs'   => array(),
					'bullet' => is_array( $bullet ) ? array(
						'level'   => min( 5, absint( $bullet['nestingLevel'] ?? 0 ) ),
						'ordered' => 1 === preg_match( '/^\s*(?:\d+|[a-z]|[ivxlcdm]+)[.)]/i', (string) ( $bullet['glyph'] ?? '' ) ),
					) : null,
				);
				continue;
			}

			if ( isset( $element['textRun'] ) && is_array( $element['textRun'] ) ) {
				$run = $this->textRun( $element['textRun'], $origin );

				if ( null === $run ) {
					continue;
				}

				if ( null === $current ) {
					$current = array(
						'runs'   => array(),
						'bullet' => null,
					);
				}

				$current['runs'][] = $run;
			} elseif ( isset( $element['autoText']['content'] ) && null !== $current ) {
				$current['runs'][] = array( 'text' => sanitize_text_field( (string) $element['autoText']['content'] ) );
			}
		}

		if ( null !== $current ) {
			$paragraphs[] = $current;
		}

		foreach ( $paragraphs as $index => $paragraph ) {
			$paragraphs[ $index ]['runs'] = $this->mergeRuns( $paragraph['runs'] );
		}

		return array_values(
			array_filter(
				$paragraphs,
				fn ( array $paragraph ): bool => '' !== trim( $this->runsText( $paragraph['runs'] ) )
			)
		);
	}

	/**
	 * Convert one Slides text run.
	 *
	 * @param array<string,mixed> $text_run Text run.
	 * @param array<string,mixed> $origin   Origin.
	 * @return array<string,mixed>|null
	 */
	private function textRun( array $text_run, array $origin ): ?array {
		$text = str_replace( array( "\r\n", "\r", "\v", "\u{000B}" ), "\n", (string) ( $text_run['content'] ?? '' ) );
		$text = wp_check_invalid_utf8( (string) preg_replace( '/\n$/', '', $text ), true );

		if ( '' === $text ) {
			return null;
		}

		$style = isset( $text_run['style'] ) && is_array( $text_run['style'] ) ? $text_run['style'] : array();
		$run   = array( 'text' => $text );

		foreach ( array(
			'bold'          => 'bold',
			'italic'        => 'italic',
			'underline'     => 'underline',
			'strikethrough' => 'strike',
		) as $source => $flag ) {
			if ( ! empty( $style[ $source ] ) ) {
				$run[ $flag ] = true;
			}
		}

		if ( 1 === preg_match( '/mono|courier|consolas|menlo|monaco|inconsolata/i', (string) ( $style['fontFamily'] ?? '' ) ) ) {
			$run['code'] = true;
		}

		if ( isset( $style['link'] ) && is_array( $style['link'] ) ) {
			$url    = (string) ( $style['link']['url'] ?? '' );
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			$clean  = in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';

			if ( '' !== $clean && strlen( $clean ) <= 2048 ) {
				$run['link'] = $clean;
				unset( $run['underline'] );
			} else {
				$this->warn( 'linkRemoved', __( 'A link to another slide or a non-web address was removed.', 'brasth-document-sync-for-google-docs' ), 'info', $origin );
			}
		}

		return $run;
	}

	/**
	 * Paragraph and list blocks for text paragraphs.
	 *
	 * @param array<int,array<string,mixed>> $paragraphs Paragraphs.
	 * @param array<string,mixed>            $origin     Origin.
	 * @param callable                       $next_id    Block ID generator.
	 * @return array<int,array<string,mixed>>
	 */
	private function paragraphBlocks( array $paragraphs, array $origin, callable $next_id ): array {
		$blocks = array();
		$items  = array();
		$order  = false;

		$flush = function () use ( &$blocks, &$items, &$order, $origin, $next_id ): void {
			if ( array() === $items ) {
				return;
			}

			$previous = -1;

			foreach ( $items as $index => $item ) {
				$items[ $index ]['level'] = min( $item['level'], $previous + 1 );
				$previous                 = $items[ $index ]['level'];
			}

			$cursor   = 0;
			$blocks[] = array(
				'type'    => 'list',
				'id'      => $next_id(),
				'ordered' => $order,
				'items'   => $this->nestItems( $items, $cursor, 0 ),
				'origin'  => $origin,
			);
			$items    = array();
		};

		foreach ( $paragraphs as $paragraph ) {
			if ( null !== $paragraph['bullet'] ) {
				if ( array() === $items ) {
					$order = $paragraph['bullet']['ordered'];
				}

				$items[] = array(
					'level' => $paragraph['bullet']['level'],
					'runs'  => $paragraph['runs'],
				);
				continue;
			}

			$flush();

			$blocks[] = array(
				'type'   => 'paragraph',
				'id'     => $next_id(),
				'runs'   => $paragraph['runs'],
				'origin' => $origin,
			);
		}

		$flush();

		return $blocks;
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
	 * Table block for a Slides table.
	 *
	 * @param array<string,mixed> $table   Slides table.
	 * @param array<string,mixed> $origin  Origin.
	 * @param callable            $next_id Block ID generator.
	 * @return array<string,mixed>|null
	 */
	private function tableBlock( array $table, array $origin, callable $next_id ): ?array {
		$rows    = array();
		$covered = array();

		foreach ( isset( $table['tableRows'] ) && is_array( $table['tableRows'] ) ? array_values( $table['tableRows'] ) : array() as $row_index => $row ) {
			$cells = array();

			foreach ( isset( $row['tableCells'] ) && is_array( $row['tableCells'] ) ? array_values( $row['tableCells'] ) : array() as $column => $cell ) {
				if ( ! is_array( $cell ) || isset( $covered[ $row_index ][ $column ] ) ) {
					continue;
				}

				$col_span = max( 1, min( 64, absint( $cell['columnSpan'] ?? 1 ) ) );
				$row_span = max( 1, min( 64, absint( $cell['rowSpan'] ?? 1 ) ) );

				for ( $r = 0; $r < $row_span; ++$r ) {
					for ( $c = 0; $c < $col_span; ++$c ) {
						if ( $r > 0 || $c > 0 ) {
							$covered[ $row_index + $r ][ $column + $c ] = true;
						}
					}
				}

				$runs = array();

				foreach ( $this->textParagraphs( $cell['text']['textElements'] ?? array(), $origin ) as $paragraph ) {
					if ( array() !== $runs ) {
						$runs[] = array( 'text' => "\n" );
					}

					$runs = array_merge( $runs, $paragraph['runs'] );
				}

				$cells[] = array(
					'runs'    => $this->mergeRuns( $runs ),
					'header'  => false,
					'colSpan' => $col_span,
					'rowSpan' => $row_span,
				);
			}

			if ( array() !== $cells ) {
				$rows[] = array( 'cells' => array_slice( $cells, 0, 64 ) );
			}
		}

		if ( array() === $rows ) {
			return null;
		}

		return array(
			'type'   => 'table',
			'id'     => $next_id(),
			'rows'   => array_slice( $rows, 0, 1000 ),
			'origin' => $origin,
		);
	}

	/**
	 * Image block for a Slides image, downloading it into private storage once.
	 *
	 * @param array<string,mixed> $element Page element with an image.
	 * @param array<string,mixed> $origin  Origin.
	 * @param callable            $next_id Block ID generator.
	 * @return array<string,mixed>|null
	 */
	private function imageBlock( array $element, array $origin, callable $next_id ): ?array {
		$object_id = (string) ( $element['objectId'] ?? '' );
		$cache_key = $this->state['revision'] . ':' . $object_id;
		$asset_id  = 'a_' . substr( hash( 'sha256', $this->state['fileId'] . '|image|' . $cache_key ), 0, 16 );
		$cached    = $this->state['imageCache'][ $cache_key ] ?? null;

		if ( ! is_array( $cached ) ) {
			if ( $this->state['images'] >= self::MAX_IMAGES ) {
				$this->warn( 'imageSkipped', __( 'This presentation has more than 300 images; the remaining images were skipped.', 'brasth-document-sync-for-google-docs' ), 'warning', $origin );

				return null;
			}

			++$this->state['images'];

			$download = $this->slides->downloadContentUrl( (int) $this->state['userId'], (string) ( $element['image']['contentUrl'] ?? '' ) );
			$stored   = is_wp_error( $download ) ? $download : $this->assets->put( (string) $this->state['sessionId'], $asset_id, $download['bytes'] );

			if ( is_wp_error( $stored ) ) {
				$this->warn( 'imageSkipped', __( 'An image could not be downloaded from Google Slides and was skipped.', 'brasth-document-sync-for-google-docs' ), 'warning', $origin );

				return null;
			}

			$size   = getimagesizefromstring( $download['bytes'] );
			$cached = array(
				'assetId'  => $asset_id,
				'mimeType' => $download['mimeType'],
				'width'    => is_array( $size ) ? absint( $size[0] ) : null,
				'height'   => is_array( $size ) ? absint( $size[1] ) : null,
				'byteSize' => $stored['byteSize'],
				'sha256'   => $stored['sha256'],
			);

			$this->state['imageCache'][ $cache_key ] = $cached;
		}

		$this->state['assets'][ $asset_id ] = array(
			'assetId'  => $asset_id,
			'kind'     => 'embedded',
			'mimeType' => (string) $cached['mimeType'],
			'width'    => $cached['width'],
			'height'   => $cached['height'],
			'byteSize' => $cached['byteSize'],
			'sha256'   => $cached['sha256'],
			'status'   => 'ready',
			'origin'   => $origin,
		);

		return array(
			'type'    => 'image',
			'id'      => $next_id(),
			'assetId' => $asset_id,
			'alt'     => sanitize_text_field( (string) ( $element['description'] ?? $element['title'] ?? '' ) ),
			'caption' => null,
			'origin'  => $origin,
		);
	}

	/**
	 * Page elements in reading order: title placeholders first, then top-to-bottom, left-to-right.
	 *
	 * @param array<int,mixed> $elements Page elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function orderedElements( array $elements ): array {
		$keyed = array();

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$bounds      = $this->bounds( $element );
			$placeholder = (string) ( $element['shape']['placeholder']['type'] ?? '' );
			$keyed[]     = array(
				'rank'    => in_array( $placeholder, self::TITLE_PLACEHOLDERS, true ) ? 0 : ( 'SUBTITLE' === $placeholder ? 1 : 2 ),
				'row'     => null !== $bounds ? (int) floor( $bounds['y'] / 24 ) : PHP_INT_MAX,
				'x'       => null !== $bounds ? $bounds['x'] : 0.0,
				'index'   => (int) $index,
				'element' => $element,
			);
		}

		usort(
			$keyed,
			static function ( array $a, array $b ): int {
				return array( $a['rank'], $a['row'], $a['x'], $a['index'] ) <=> array( $b['rank'], $b['row'], $b['x'], $b['index'] );
			}
		);

		return array_column( $keyed, 'element' );
	}

	/**
	 * Element bounds in points (top-left origin) from the Slides transform.
	 *
	 * @param array<string,mixed> $element Page element.
	 * @return array{x:float,y:float,width:float,height:float}|null
	 */
	private function bounds( array $element ): ?array {
		$transform = isset( $element['transform'] ) && is_array( $element['transform'] ) ? $element['transform'] : null;
		$size      = isset( $element['size'] ) && is_array( $element['size'] ) ? $element['size'] : null;

		if ( null === $transform || null === $size ) {
			return null;
		}

		$unit    = 'PT' === ( $transform['unit'] ?? 'EMU' ) ? 1.0 : (float) self::EMU_PER_POINT;
		$width   = (float) ( $size['width']['magnitude'] ?? 0 ) / ( 'PT' === ( $size['width']['unit'] ?? 'EMU' ) ? 1.0 : (float) self::EMU_PER_POINT );
		$height  = (float) ( $size['height']['magnitude'] ?? 0 ) / ( 'PT' === ( $size['height']['unit'] ?? 'EMU' ) ? 1.0 : (float) self::EMU_PER_POINT );
		$scale_x = (float) ( $transform['scaleX'] ?? 1 );
		$scale_y = (float) ( $transform['scaleY'] ?? 1 );

		return array(
			'x'      => round( (float) ( $transform['translateX'] ?? 0 ) / $unit, 2 ),
			'y'      => round( (float) ( $transform['translateY'] ?? 0 ) / $unit, 2 ),
			'width'  => round( abs( $width * $scale_x ), 2 ),
			'height' => round( abs( $height * $scale_y ), 2 ),
		);
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
			if ( '' === $run['text'] ) {
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
	 * Plain text of runs.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 */
	private function runsText( array $runs ): string {
		return implode( '', array_column( $runs, 'text' ) );
	}

	/**
	 * Record a numbered warning and return its number.
	 *
	 * @param string              $code     Warning code.
	 * @param string              $message  Message.
	 * @param string              $severity info or warning.
	 * @param array<string,mixed> $origin   Origin.
	 * @param string              $element  Unsupported element kind, if any.
	 */
	private function warn( string $code, string $message, string $severity, array $origin, string $element = '' ): int {
		$number  = count( $this->state['warnings'] ) + 1;
		$warning = array(
			'number'   => $number,
			'code'     => $code,
			'message'  => $message,
			'severity' => $severity,
			'origin'   => $origin,
		);

		if ( '' !== $element ) {
			$warning['element'] = $element;
		}

		$this->state['warnings'][] = $warning;

		return $number;
	}

	/**
	 * File name without its extension.
	 *
	 * @param string $name Original name.
	 */
	private function baseName( string $name ): string {
		$base = trim( (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', $name ) );

		return '' !== $base ? $base : __( 'Untitled', 'brasth-document-sync-for-google-docs' );
	}

	/**
	 * Attach created Google IDs to an error so they survive any failure.
	 *
	 * @param WP_Error          $error       Error.
	 * @param array<int,string> $temporaries Created IDs.
	 */
	private function withTemporaries( WP_Error $error, array $temporaries ): WP_Error {
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : array( 'status' => 500 );

		$existing                  = isset( $data['googleTemporaries'] ) && is_array( $data['googleTemporaries'] ) ? $data['googleTemporaries'] : array();
		$data['googleTemporaries'] = array_values( array_unique( array_merge( $existing, $temporaries ) ) );

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
