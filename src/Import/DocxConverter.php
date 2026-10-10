<?php
/**
 * Converts uploaded Word files through Google Docs into canonical documents.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Google\DocsClient;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Google\DriveWriteClient;
use DocSyncWP\Sync\DocsApiDocumentParts;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * DOCX → Google Doc (in My Drive / Imported from WordPress) → canonical.
 *
 * The created Doc's ID is handed to `$on_created_file` immediately after
 * Drive returns it and before any Docs API read, so it is always in the
 * session's cleanup set. Images are downloaded into private storage only;
 * nothing reaches the Media Library before commit. Every error carries
 * `data.googleTemporaries` with the IDs created so far.
 */
final class DocxConverter {
	private const MAX_IMAGES     = 200;
	private const MAX_IMAGE_SIZE = 10485760;
	private const MONOSPACE      = array( 'courier new', 'courier', 'consolas', 'roboto mono', 'source code pro', 'menlo', 'monaco', 'inconsolata', 'fira code', 'jetbrains mono', 'ubuntu mono', 'droid sans mono', 'pt mono' );
	private const ORDERED_GLYPHS = array( 'DECIMAL', 'ZERO_DECIMAL', 'UPPER_ALPHA', 'ALPHA', 'UPPER_ROMAN', 'ROMAN' );
	private const IMAGE_MIMES    = array( 'image/png', 'image/jpeg', 'image/gif', 'image/webp' );

	/**
	 * Drive write client.
	 *
	 * @var DriveWriteClient
	 */
	private DriveWriteClient $drive_write;

	/**
	 * Docs API client.
	 *
	 * @var DocsClient
	 */
	private DocsClient $docs_client;

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
	 * @param DriveWriteClient  $drive_write Drive write client.
	 * @param DocsClient        $docs_client Docs API client.
	 * @param PrivateAssetStore $assets      Private asset store.
	 */
	public function __construct( DriveWriteClient $drive_write, DocsClient $docs_client, PrivateAssetStore $assets ) {
		$this->drive_write = $drive_write;
		$this->docs_client = $docs_client;
		$this->assets      = $assets;
	}

	/**
	 * Convert one uploaded DOCX file.
	 *
	 * @param int                 $user_id         Session owner.
	 * @param string              $session_id      Session ID.
	 * @param array<string,mixed> $file            Session file record (fileId, originalName, originalKey, sha256).
	 * @param array<string,mixed> $options         Normalized file options.
	 * @param callable|null       $on_created_file Receives each created Google file ID; returns true or WP_Error.
	 * @return array{document:CanonicalDocument,googleFileId:string,googleTemporaries:array<int,string>}|WP_Error
	 */
	public function convert( int $user_id, string $session_id, array $file, array $options, ?callable $on_created_file = null ): array|WP_Error {
		unset( $options );

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'docsync_wp_import_zip_unavailable',
				__( 'Word and PowerPoint imports need the PHP zip extension (ZipArchive), which is not installed on this server.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 422 )
			);
		}

		$path = $this->assets->materialize( $session_id, (string) ( $file['originalKey'] ?? '' ) );

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		try {
			$created = $this->drive_write->uploadForConversion(
				$user_id,
				$path,
				DriveWriteClient::DOCX_MIME_TYPE,
				DriveClient::GOOGLE_DOC_MIME_TYPE,
				$this->baseName( (string) ( $file['originalName'] ?? '' ) ),
				$session_id
			);
		} finally {
			$this->assets->release( $path );
		}

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$google_id   = $created['fileId'];
		$temporaries = array( $google_id );

		if ( null !== $on_created_file ) {
			$accepted = $on_created_file( $google_id );

			if ( is_wp_error( $accepted ) ) {
				return $this->withTemporaries( $accepted, $temporaries );
			}
		}

		$remote = $this->docs_client->getDocument( $user_id, $google_id );

		if ( is_wp_error( $remote ) ) {
			return $this->withTemporaries( $remote, $temporaries );
		}

		$document = $this->buildDocument( $user_id, $session_id, $file, $remote );

		if ( is_wp_error( $document ) ) {
			return $this->withTemporaries( $document, $temporaries );
		}

		return array(
			'document'          => $document,
			'googleFileId'      => $google_id,
			'googleTemporaries' => $temporaries,
		);
	}

	/**
	 * Build the canonical document from a Docs API document.
	 *
	 * @param int                 $user_id    User ID.
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $file       Session file.
	 * @param array<string,mixed> $remote     Docs API document.
	 * @return CanonicalDocument|WP_Error
	 */
	private function buildDocument( int $user_id, string $session_id, array $file, array $remote ): CanonicalDocument|WP_Error {
		$this->state = array(
			'userId'        => $user_id,
			'sessionId'     => $session_id,
			'fileId'        => (string) ( $file['fileId'] ?? '' ),
			'nextId'        => 0,
			'assets'        => array(),
			'assetIds'      => array(),
			'warnings'      => array(),
			'images'        => 0,
			'styling'       => false,
			'footnotes'     => array(),
			'footnoteOrder' => array(),
		);

		$parts  = new DocsApiDocumentParts();
		$blocks = array();

		foreach ( $parts->parts( $remote ) as $part ) {
			$content = isset( $part['body']['content'] ) && is_array( $part['body']['content'] ) ? $part['body']['content'] : array();
			$context = $parts->context( $part, $remote );

			$context['footnotes']         = isset( $part['footnotes'] ) && is_array( $part['footnotes'] ) ? $part['footnotes'] : ( isset( $remote['footnotes'] ) && is_array( $remote['footnotes'] ) ? $remote['footnotes'] : array() );
			$context['positionedObjects'] = isset( $part['positionedObjects'] ) && is_array( $part['positionedObjects'] ) ? $part['positionedObjects'] : ( isset( $remote['positionedObjects'] ) && is_array( $remote['positionedObjects'] ) ? $remote['positionedObjects'] : array() );

			$blocks = array_merge( $blocks, $this->convertElements( $content, $context, false ) );
		}

		$blocks = array_merge( $blocks, $this->footnoteBlocks() );

		if ( $this->state['styling'] ) {
			$this->warn( 'stylingDropped', __( 'Text colors, highlights, and font sizes were not imported; the site theme styles the text.', 'brasth-document-sync-for-google-docs' ), 'info', array() );
		}

		$title = trim( sanitize_text_field( (string) ( $remote['title'] ?? '' ) ) );

		if ( '' === $title ) {
			$title = $this->baseName( (string) ( $file['originalName'] ?? '' ) );
		}

		if ( array() === $blocks ) {
			$this->warn( 'emptySection', __( 'This document has no text, tables, or images to import.', 'brasth-document-sync-for-google-docs' ), 'warning', array() );
		}

		return CanonicalDocument::fromArray(
			array(
				'version'  => CanonicalDocument::VERSION,
				'title'    => $title,
				'source'   => array(
					'format'       => 'docx',
					'originalName' => (string) ( $file['originalName'] ?? '' ),
					'sha256'       => (string) ( $file['sha256'] ?? '' ),
				),
				'sections' => array(
					array(
						'id'     => 'body',
						'kind'   => 'body',
						'title'  => $title,
						'origin' => array(),
						'blocks' => $blocks,
					),
				),
				'assets'   => array_values( $this->state['assets'] ),
				'warnings' => $this->state['warnings'],
			)
		);
	}

	/**
	 * Convert structural elements into blocks.
	 *
	 * @param array<int,mixed>    $elements Structural elements.
	 * @param array<string,mixed> $context  Lists, inline objects, footnotes, positioned objects.
	 * @param bool                $in_cell  Whether the elements are inside a table cell.
	 * @return array<int,array<string,mixed>>
	 */
	private function convertElements( array $elements, array $context, bool $in_cell ): array {
		$blocks = array();
		$list   = null;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$doc_index = absint( $element['startIndex'] ?? 0 );

			if ( isset( $element['paragraph'] ) && is_array( $element['paragraph'] ) ) {
				$paragraph = $element['paragraph'];
				$bullet    = isset( $paragraph['bullet'] ) && is_array( $paragraph['bullet'] ) ? $paragraph['bullet'] : null;
				$converted = $this->convertParagraph( $paragraph, $context, $doc_index );

				if ( null !== $bullet ) {
					$list_id = (string) ( $bullet['listId'] ?? '' );
					$level   = min( 5, absint( $bullet['nestingLevel'] ?? 0 ) );

					if ( null === $list || $list['listId'] !== $list_id ) {
						if ( null !== $list ) {
							$blocks = array_merge( $blocks, $this->finishList( $list ) );
						}

						$list = array(
							'listId'  => $list_id,
							'ordered' => $this->isOrderedList( $context, $list_id ),
							'items'   => array(),
							'origin'  => array( 'docIndex' => $doc_index ),
						);
					}

					$list['items'][] = array(
						'level' => $level,
						'runs'  => $converted['runs'],
					);

					foreach ( $converted['images'] as $image ) {
						$list['trailing'][] = $image;
					}

					continue;
				}

				if ( null !== $list ) {
					$blocks = array_merge( $blocks, $this->finishList( $list ) );
					$list   = null;
				}

				if ( null !== $converted['block'] ) {
					$blocks[] = $converted['block'];
				}

				foreach ( $converted['images'] as $image ) {
					$blocks[] = $image;
				}

				continue;
			}

			if ( null !== $list ) {
				$blocks = array_merge( $blocks, $this->finishList( $list ) );
				$list   = null;
			}

			if ( isset( $element['table'] ) && is_array( $element['table'] ) ) {
				if ( $in_cell ) {
					$this->warn( 'tableSimplified', __( 'A table inside a table cell was flattened to text.', 'brasth-document-sync-for-google-docs' ), 'warning', array( 'docIndex' => $doc_index ) );
					continue;
				}

				$table = $this->convertTable( $element['table'], $context, $doc_index );

				if ( null !== $table ) {
					$blocks[] = $table;
				}

				continue;
			}

			if ( isset( $element['tableOfContents'] ) ) {
				$this->warn( 'stylingDropped', __( 'The table of contents was not imported; WordPress themes and plugins can generate one from the headings.', 'brasth-document-sync-for-google-docs' ), 'info', array( 'docIndex' => $doc_index ) );
			}
		}

		if ( null !== $list ) {
			$blocks = array_merge( $blocks, $this->finishList( $list ) );
		}

		return $blocks;
	}

	/**
	 * Convert a paragraph to a heading or paragraph block plus any images it holds.
	 *
	 * @param array<string,mixed> $paragraph Docs paragraph.
	 * @param array<string,mixed> $context   Conversion context.
	 * @param int                 $doc_index Docs startIndex.
	 * @return array{block:array<string,mixed>|null,runs:array<int,array<string,mixed>>,images:array<int,array<string,mixed>>}
	 */
	private function convertParagraph( array $paragraph, array $context, int $doc_index ): array {
		$runs   = array();
		$images = array();
		$origin = array( 'docIndex' => $doc_index );

		foreach ( isset( $paragraph['elements'] ) && is_array( $paragraph['elements'] ) ? $paragraph['elements'] : array() as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['textRun'] ) && is_array( $element['textRun'] ) ) {
				$run = $this->convertTextRun( $element['textRun'], $origin );

				if ( null !== $run ) {
					$runs[] = $run;
				}
			} elseif ( isset( $element['inlineObjectElement']['inlineObjectId'] ) ) {
				$image = $this->convertImage( (string) $element['inlineObjectElement']['inlineObjectId'], $context['inlineObjects'] ?? array(), $origin );

				if ( null !== $image ) {
					$images[] = $image;
				}
			} elseif ( isset( $element['footnoteReference'] ) && is_array( $element['footnoteReference'] ) ) {
				$runs[] = array( 'text' => '[' . $this->footnoteNumber( $element['footnoteReference'], $context ) . ']' );
			} elseif ( isset( $element['richLink']['richLinkProperties'] ) && is_array( $element['richLink']['richLinkProperties'] ) ) {
				$properties = $element['richLink']['richLinkProperties'];
				$run        = array( 'text' => sanitize_text_field( (string) ( $properties['title'] ?? $properties['uri'] ?? '' ) ) );
				$link       = $this->safeLink( (string) ( $properties['uri'] ?? '' ), $origin );

				if ( '' !== $link ) {
					$run['link'] = $link;
				}

				$runs[] = $run;
			} elseif ( isset( $element['person']['personProperties'] ) && is_array( $element['person']['personProperties'] ) ) {
				$runs[] = array( 'text' => sanitize_text_field( (string) ( $element['person']['personProperties']['name'] ?? $element['person']['personProperties']['email'] ?? '' ) ) );
			} elseif ( isset( $element['equation'] ) ) {
				$this->warn( 'stylingDropped', __( 'An equation could not be imported.', 'brasth-document-sync-for-google-docs' ), 'warning', $origin );
			}
		}

		foreach ( isset( $paragraph['positionedObjectIds'] ) && is_array( $paragraph['positionedObjectIds'] ) ? $paragraph['positionedObjectIds'] : array() as $object_id ) {
			$image = $this->convertImage( (string) $object_id, $context['positionedObjects'] ?? array(), $origin, 'positionedObjectProperties' );

			if ( null !== $image ) {
				$images[] = $image;
			}
		}

		$runs  = $this->mergeRuns( $runs );
		$text  = trim( $this->runsText( $runs ) );
		$style = (string) ( $paragraph['paragraphStyle']['namedStyleType'] ?? 'NORMAL_TEXT' );
		$block = null;

		if ( '' !== $text ) {
			$level = $this->headingLevel( $style );

			if ( null !== $level ) {
				$block = array(
					'type'   => 'heading',
					'id'     => $this->nextId(),
					'level'  => $level,
					'runs'   => $this->stripFormatting( $runs ),
					'origin' => $origin,
				);
			} else {
				$block = array(
					'type' => 'paragraph',
					'id'   => $this->nextId(),
					'runs' => $runs,
				);

				$align = $this->alignment( (string) ( $paragraph['paragraphStyle']['alignment'] ?? '' ) );

				if ( '' !== $align ) {
					$block['align'] = $align;
				}

				$block['origin'] = $origin;
			}
		}

		return array(
			'block'  => $block,
			'runs'   => $runs,
			'images' => $images,
		);
	}

	/**
	 * Convert a text run.
	 *
	 * @param array<string,mixed> $text_run Docs text run.
	 * @param array<string,mixed> $origin   Origin.
	 * @return array<string,mixed>|null
	 */
	private function convertTextRun( array $text_run, array $origin ): ?array {
		$text = str_replace( array( "\r\n", "\r", "\v", "\u{000B}" ), "\n", (string) ( $text_run['content'] ?? '' ) );
		$text = (string) preg_replace( '/\n$/', '', $text );
		$text = wp_check_invalid_utf8( $text, true );

		if ( '' === $text ) {
			return null;
		}

		$style = isset( $text_run['textStyle'] ) && is_array( $text_run['textStyle'] ) ? $text_run['textStyle'] : array();
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

		$family = strtolower( (string) ( $style['weightedFontFamily']['fontFamily'] ?? '' ) );

		if ( '' !== $family && in_array( $family, self::MONOSPACE, true ) ) {
			$run['code'] = true;
		}

		if ( isset( $style['foregroundColor'] ) || isset( $style['backgroundColor'] ) || isset( $style['fontSize'] ) ) {
			$this->state['styling'] = true;
		}

		if ( isset( $style['link'] ) && is_array( $style['link'] ) ) {
			$link = $this->safeLink( (string) ( $style['link']['url'] ?? '' ), $origin );

			if ( '' !== $link ) {
				$run['link'] = $link;
				unset( $run['underline'] );
			}
		}

		return $run;
	}

	/**
	 * Convert an inline or positioned image into a private asset and image block.
	 *
	 * @param string              $object_id    Object ID.
	 * @param array<string,mixed> $objects      Object map.
	 * @param array<string,mixed> $origin       Origin.
	 * @param string              $property_key inlineObjectProperties or positionedObjectProperties.
	 * @return array<string,mixed>|null
	 */
	private function convertImage( string $object_id, array $objects, array $origin, string $property_key = 'inlineObjectProperties' ): ?array {
		$object   = isset( $objects[ $object_id ] ) && is_array( $objects[ $object_id ] ) ? $objects[ $object_id ] : array();
		$embedded = isset( $object[ $property_key ]['embeddedObject'] ) && is_array( $object[ $property_key ]['embeddedObject'] ) ? $object[ $property_key ]['embeddedObject'] : array();
		$uri      = (string) ( $embedded['imageProperties']['contentUri'] ?? '' );

		if ( '' === $uri ) {
			$this->warn( 'imageSkipped', __( 'A drawing or chart could not be imported as an image.', 'brasth-document-sync-for-google-docs' ), 'warning', $origin );

			return null;
		}

		if ( $this->state['images'] >= self::MAX_IMAGES ) {
			$this->warn( 'imageSkipped', __( 'This document has more than 200 images; the remaining images were skipped.', 'brasth-document-sync-for-google-docs' ), 'warning', $origin );

			return null;
		}

		$asset_id = 'a_' . substr( hash( 'sha256', $this->state['fileId'] . '|docx|' . $object_id ), 0, 16 );

		if ( ! isset( $this->state['assets'][ $asset_id ] ) ) {
			++$this->state['images'];

			$stored = $this->downloadImage( $uri, $asset_id );

			if ( is_wp_error( $stored ) ) {
				$this->warn( 'imageSkipped', __( 'An image could not be downloaded from Google Docs and was skipped.', 'brasth-document-sync-for-google-docs' ), 'warning', $origin );

				return null;
			}

			$this->state['assets'][ $asset_id ] = array(
				'assetId'  => $asset_id,
				'kind'     => 'embedded',
				'mimeType' => $stored['mimeType'],
				'width'    => $stored['width'],
				'height'   => $stored['height'],
				'byteSize' => $stored['byteSize'],
				'sha256'   => $stored['sha256'],
				'status'   => 'ready',
				'origin'   => $origin,
			);
		}

		return array(
			'type'    => 'image',
			'id'      => $this->nextId(),
			'assetId' => $asset_id,
			'alt'     => sanitize_text_field( (string) ( $embedded['description'] ?? $embedded['title'] ?? '' ) ),
			'caption' => null,
			'origin'  => $origin,
		);
	}

	/**
	 * Download an image into private storage after validating its bytes.
	 *
	 * @param string $uri      Docs content URI.
	 * @param string $asset_id Asset ID.
	 * @return array{mimeType:string,width:int,height:int,byteSize:int,sha256:string}|WP_Error
	 */
	private function downloadImage( string $uri, string $asset_id ): array|WP_Error {
		$download = $this->docs_client->downloadContentUri( (int) $this->state['userId'], $uri );

		if ( is_wp_error( $download ) ) {
			return $download;
		}

		$filesystem = $this->filesystem();
		$bytes      = $filesystem->get_contents( $download['file_path'] );

		$filesystem->delete( $download['file_path'] );

		if ( ! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > self::MAX_IMAGE_SIZE ) {
			return $this->invalidImageError();
		}

		$size = getimagesizefromstring( $bytes );
		$mime = is_array( $size ) && isset( $size['mime'] ) ? (string) $size['mime'] : '';

		if ( ! in_array( $mime, self::IMAGE_MIMES, true ) ) {
			return $this->invalidImageError();
		}

		$stored = $this->assets->put( (string) $this->state['sessionId'], $asset_id, $bytes );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		return array(
			'mimeType' => $mime,
			'width'    => absint( $size[0] ),
			'height'   => absint( $size[1] ),
			'byteSize' => $stored['byteSize'],
			'sha256'   => $stored['sha256'],
		);
	}

	/**
	 * Convert a Docs table.
	 *
	 * @param array<string,mixed> $table     Docs table.
	 * @param array<string,mixed> $context   Conversion context.
	 * @param int                 $doc_index Docs startIndex.
	 * @return array<string,mixed>|null
	 */
	private function convertTable( array $table, array $context, int $doc_index ): ?array {
		$rows      = array();
		$columns   = absint( $table['columns'] ?? 0 );
		$covered   = array();
		$simplify  = false;
		$row_index = 0;

		foreach ( isset( $table['tableRows'] ) && is_array( $table['tableRows'] ) ? $table['tableRows'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$source_cells = isset( $row['tableCells'] ) && is_array( $row['tableCells'] ) ? $row['tableCells'] : array();
			$full_grid    = $columns > 0 && count( $source_cells ) === $columns;
			$header       = ! empty( $row['tableRowStyle']['tableHeader'] );
			$cells        = array();

			foreach ( $source_cells as $column => $cell ) {
				if ( ! is_array( $cell ) ) {
					continue;
				}

				if ( $full_grid && isset( $covered[ $row_index ][ $column ] ) ) {
					continue;
				}

				$col_span = max( 1, absint( $cell['tableCellStyle']['columnSpan'] ?? 1 ) );
				$row_span = max( 1, absint( $cell['tableCellStyle']['rowSpan'] ?? 1 ) );

				for ( $r = 0; $r < $row_span; ++$r ) {
					for ( $c = 0; $c < $col_span; ++$c ) {
						if ( $r > 0 || $c > 0 ) {
							$covered[ $row_index + $r ][ (int) $column + $c ] = true;
						}
					}
				}

				$content = isset( $cell['content'] ) && is_array( $cell['content'] ) ? $cell['content'] : array();
				$runs    = array();

				foreach ( $this->convertElements( $content, $context, true ) as $block ) {
					if ( 'list' === $block['type'] || 'image' === $block['type'] ) {
						$simplify = true;
					}

					$block_runs = $this->blockRuns( $block );

					if ( array() !== $block_runs ) {
						if ( array() !== $runs ) {
							$runs[] = array( 'text' => "\n" );
						}

						$runs = array_merge( $runs, $block_runs );
					}
				}

				$cells[] = array(
					'runs'    => $this->mergeRuns( $runs ),
					'header'  => $header,
					'colSpan' => min( 64, $col_span ),
					'rowSpan' => min( 64, $row_span ),
				);
			}

			if ( array() !== $cells ) {
				$rows[] = array( 'cells' => array_slice( $cells, 0, 64 ) );
			}

			++$row_index;
		}

		if ( $simplify ) {
			$this->warn( 'tableSimplified', __( 'Lists or images inside table cells were simplified to text.', 'brasth-document-sync-for-google-docs' ), 'warning', array( 'docIndex' => $doc_index ) );
		}

		if ( array() === $rows ) {
			return null;
		}

		return array(
			'type'   => 'table',
			'id'     => $this->nextId(),
			'rows'   => array_slice( $rows, 0, 1000 ),
			'origin' => array( 'docIndex' => $doc_index ),
		);
	}

	/**
	 * Build a nested list block (plus images held by its items) from flat items with nesting levels.
	 *
	 * @param array<string,mixed> $pending Pending list.
	 * @return array<int,array<string,mixed>>
	 */
	private function finishList( array $pending ): array {
		$items    = $pending['items'];
		$previous = -1;

		foreach ( $items as $position => $item ) {
			$items[ $position ]['level'] = min( (int) $item['level'], $previous + 1 );
			$previous                    = $items[ $position ]['level'];
		}

		$cursor = 0;
		$blocks = array(
			array(
				'type'    => 'list',
				'id'      => $this->nextId(),
				'ordered' => (bool) $pending['ordered'],
				'items'   => $this->nestItems( $items, $cursor, 0 ),
				'origin'  => $pending['origin'],
			),
		);

		return array_merge( $blocks, $pending['trailing'] ?? array() );
	}

	/**
	 * Nest flat items whose levels never jump by more than one.
	 *
	 * @param array<int,array<string,mixed>> $items  Flat items with level and runs.
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
	 * Whether a Docs list renders ordered glyphs at its first level.
	 *
	 * @param array<string,mixed> $context Conversion context.
	 * @param string              $list_id List ID.
	 */
	private function isOrderedList( array $context, string $list_id ): bool {
		$levels = $context['lists'][ $list_id ]['listProperties']['nestingLevels'] ?? array();
		$glyph  = is_array( $levels ) && isset( $levels[0] ) && is_array( $levels[0] ) ? (string) ( $levels[0]['glyphType'] ?? '' ) : '';

		return in_array( $glyph, self::ORDERED_GLYPHS, true );
	}

	/**
	 * Footnote number for a reference, registering its content.
	 *
	 * @param array<string,mixed> $reference Footnote reference.
	 * @param array<string,mixed> $context   Conversion context.
	 */
	private function footnoteNumber( array $reference, array $context ): int {
		$footnote_id = (string) ( $reference['footnoteId'] ?? '' );

		if ( ! isset( $this->state['footnoteOrder'][ $footnote_id ] ) ) {
			$this->state['footnoteOrder'][ $footnote_id ] = count( $this->state['footnoteOrder'] ) + 1;

			$content = $context['footnotes'][ $footnote_id ]['content'] ?? array();
			$runs    = array();

			foreach ( is_array( $content ) ? $this->convertElements( $content, $context, true ) : array() as $block ) {
				$block_runs = $this->blockRuns( $block );

				if ( array() !== $block_runs ) {
					if ( array() !== $runs ) {
						$runs[] = array( 'text' => ' ' );
					}

					$runs = array_merge( $runs, $block_runs );
				}
			}

			$this->state['footnotes'][] = $this->mergeRuns( $runs );
		}

		return (int) $this->state['footnoteOrder'][ $footnote_id ];
	}

	/**
	 * Footnotes appended as an ordered list at the end.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function footnoteBlocks(): array {
		if ( array() === $this->state['footnotes'] ) {
			return array();
		}

		$items = array();

		foreach ( $this->state['footnotes'] as $runs ) {
			$items[] = array(
				'runs'     => array() !== $runs ? $runs : array( array( 'text' => '' ) ),
				'children' => array(),
			);
		}

		return array(
			array(
				'type'   => 'heading',
				'id'     => $this->nextId(),
				'level'  => 2,
				'runs'   => array( array( 'text' => __( 'Footnotes', 'brasth-document-sync-for-google-docs' ) ) ),
				'origin' => array(),
			),
			array(
				'type'    => 'list',
				'id'      => $this->nextId(),
				'ordered' => true,
				'items'   => $items,
				'origin'  => array(),
			),
		);
	}

	/**
	 * Runs of a paragraph or heading block (other blocks yield none).
	 *
	 * @param array<string,mixed> $block Block.
	 * @return array<int,array<string,mixed>>
	 */
	private function blockRuns( array $block ): array {
		if ( in_array( $block['type'], array( 'paragraph', 'heading' ), true ) ) {
			return $block['runs'];
		}

		if ( 'list' === $block['type'] ) {
			$runs = array();

			foreach ( $block['items'] as $item ) {
				if ( array() !== $runs ) {
					$runs[] = array( 'text' => "\n" );
				}

				$runs = array_merge( $runs, $item['runs'] );
			}

			return $runs;
		}

		return array();
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
	 * Remove inline formatting (headings carry their own weight), keeping links.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 * @return array<int,array<string,mixed>>
	 */
	private function stripFormatting( array $runs ): array {
		$clean = array();

		foreach ( $runs as $run ) {
			$next = array( 'text' => $run['text'] );

			if ( isset( $run['link'] ) ) {
				$next['link'] = $run['link'];
			}

			if ( isset( $run['italic'] ) ) {
				$next['italic'] = true;
			}

			$clean[] = $next;
		}

		return $this->mergeRuns( $clean );
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
	 * Heading level for a named paragraph style.
	 *
	 * @param string $style Named style type.
	 */
	private function headingLevel( string $style ): ?int {
		if ( 'TITLE' === $style ) {
			return 1;
		}

		if ( 'SUBTITLE' === $style ) {
			return 2;
		}

		if ( 1 === preg_match( '/^HEADING_([1-6])$/', $style, $matches ) ) {
			return (int) $matches[1];
		}

		return null;
	}

	/**
	 * Canonical alignment for a Docs alignment.
	 *
	 * @param string $alignment Docs alignment.
	 */
	private function alignment( string $alignment ): string {
		return match ( $alignment ) {
			'CENTER' => 'center',
			'END' => 'right',
			'JUSTIFIED' => 'justify',
			default => '',
		};
	}

	/**
	 * Keep http(s) links only; record removed links.
	 *
	 * @param string              $url    Raw URL.
	 * @param array<string,mixed> $origin Origin.
	 */
	private function safeLink( string $url, array $origin ): string {
		if ( '' === $url ) {
			$this->warn( 'linkRemoved', __( 'A link to a heading or bookmark inside the document was removed.', 'brasth-document-sync-for-google-docs' ), 'info', $origin );

			return '';
		}

		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$clean  = in_array( $scheme, array( 'http', 'https' ), true ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';

		if ( '' === $clean || strlen( $clean ) > 2048 ) {
			$this->warn( 'linkRemoved', __( 'A link that was not a web address was removed.', 'brasth-document-sync-for-google-docs' ), 'info', $origin );

			return '';
		}

		return $clean;
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
		$warning = array(
			'number'   => count( $this->state['warnings'] ) + 1,
			'code'     => $code,
			'message'  => $message,
			'severity' => $severity,
		);

		if ( array() !== $origin ) {
			$warning['origin'] = $origin;
		}

		$this->state['warnings'][] = $warning;
	}

	/**
	 * Next unique block ID.
	 */
	private function nextId(): string {
		++$this->state['nextId'];

		return 'b' . $this->state['nextId'];
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
	 * Invalid image bytes error.
	 */
	private function invalidImageError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_image_invalid',
			__( 'An image in this document is not a supported PNG, JPEG, GIF, or WebP file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 422 )
		);
	}
}
