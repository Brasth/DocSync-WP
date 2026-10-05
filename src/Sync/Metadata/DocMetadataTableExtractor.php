<?php
/**
 * Extracts a leading key/value metadata table from synced Doc HTML.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Metadata;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Detects a two-column table of recognized keys at the top of the Doc and removes it from content.
 */
final class DocMetadataTableExtractor {
	private const MAX_LIST_ITEMS = 20;

	private const KEYS = array(
		'title'           => 'title',
		'slug'            => 'slug',
		'excerpt'         => 'excerpt',
		'featured image'  => 'featured_image',
		'category'        => 'categories',
		'categories'      => 'categories',
		'tag'             => 'tags',
		'tags'            => 'tags',
		'author'          => 'author',
		'seo title'       => 'seo_title',
		'seo description' => 'seo_description',
	);

	/**
	 * Extract metadata fields.
	 *
	 * Returns the original HTML untouched when no metadata table is present.
	 *
	 * @param string $html Sanitized HTML fragment.
	 * @return array{html:string,fields:array<string,mixed>}
	 */
	public function extract( string $html ): array {
		$none = array(
			'html'   => $html,
			'fields' => array(),
		);

		if ( false === stripos( $html, '<table' ) ) {
			return $none;
		}

		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML( '<?xml encoding="UTF-8"><body>' . $html . '</body>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $loaded || null === $body ) {
			return $none;
		}

		$table = $this->firstElement( $body );

		if ( null === $table || 'table' !== strtolower( $table->tagName ) ) {
			return $none;
		}

		$fields = $this->parseTable( $table );

		if ( array() === $fields ) {
			return $none;
		}

		$body->removeChild( $table );

		$remaining = '';

		foreach ( iterator_to_array( $body->childNodes ) as $child ) {
			$remaining .= $document->saveHTML( $child );
		}

		$featured = $fields['featured_image'] ?? null;

		if ( is_array( $featured ) ) {
			$fields['featured_image'] = 'first' === $featured['mode'] ? $this->firstImageUrl( $document ) : $featured['url'];
		}

		if ( isset( $fields['featured_image'] ) && '' === $fields['featured_image'] ) {
			unset( $fields['featured_image'] );
		}

		return array(
			'html'   => ltrim( $remaining ),
			'fields' => $fields,
		);
	}

	/**
	 * First element child, skipping whitespace text.
	 *
	 * @param DOMNode $container Container node.
	 */
	private function firstElement( DOMNode $container ): ?DOMElement {
		foreach ( $container->childNodes as $child ) {
			if ( $child instanceof DOMElement ) {
				return $child;
			}

			if ( $child instanceof DOMText && '' !== trim( $child->textContent ) ) {
				return null;
			}
		}

		return null;
	}

	/**
	 * Parse table rows. Returns an empty array unless every non-empty key is recognized.
	 *
	 * @param DOMElement $table Table element.
	 * @return array<string,mixed>
	 */
	private function parseTable( DOMElement $table ): array {
		$xpath  = new DOMXPath( $table->ownerDocument );
		$rows   = $xpath->query( './/tr', $table );
		$fields = array();

		if ( false === $rows || 0 === $rows->length ) {
			return array();
		}

		foreach ( $rows as $row ) {
			$cells = $xpath->query( './td|./th', $row );

			if ( false === $cells || 2 !== $cells->length ) {
				return array();
			}

			$key = $this->normalizeKey( (string) $cells->item( 0 )?->textContent );

			if ( '' === $key ) {
				// A blank key with a value means this is real content, not a metadata table.
				if ( '' !== $this->cleanText( (string) $cells->item( 1 )?->textContent ) || $cells->item( 1 )?->getElementsByTagName( 'img' )->length > 0 ) {
					return array();
				}

				continue;
			}

			if ( ! isset( self::KEYS[ $key ] ) ) {
				return array();
			}

			$cell  = $cells->item( 1 );
			$field = self::KEYS[ $key ];
			$text  = $this->cleanText( (string) $cell?->textContent );

			if ( 'featured_image' === $field && $cell instanceof DOMElement ) {
				$image = $cell->getElementsByTagName( 'img' )->item( 0 );
				$url   = $image instanceof DOMElement ? trim( $image->getAttribute( 'src' ) ) : '';

				if ( '' !== $url ) {
					$fields[ $field ] = array(
						'mode' => 'url',
						'url'  => $url,
					);
				} elseif ( 'first' === strtolower( $text ) ) {
					$fields[ $field ] = array(
						'mode' => 'first',
						'url'  => '',
					);
				}

				continue;
			}

			if ( '' === $text ) {
				continue;
			}

			$fields[ $field ] = in_array( $field, array( 'categories', 'tags' ), true ) ? $this->splitList( $text ) : $text;
		}

		return $fields;
	}

	/**
	 * Normalize a key cell.
	 *
	 * @param string $key Raw key text.
	 */
	private function normalizeKey( string $key ): string {
		$key = strtolower( $this->cleanText( $key ) );

		return rtrim( $key, ':' );
	}

	/**
	 * Collapse whitespace, including non-breaking spaces.
	 *
	 * @param string $text Text.
	 */
	private function cleanText( string $text ): string {
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Split a comma-separated list.
	 *
	 * @param string $text Text.
	 * @return array<int,string>
	 */
	private function splitList( string $text ): array {
		$items = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $text ) ), static fn ( string $item ): bool => '' !== $item ) ) );

		return array_slice( $items, 0, self::MAX_LIST_ITEMS );
	}

	/**
	 * First body image URL after the table was removed.
	 *
	 * @param DOMDocument $document Document.
	 */
	private function firstImageUrl( DOMDocument $document ): string {
		$image = $document->getElementsByTagName( 'img' )->item( 0 );

		return $image instanceof DOMElement ? trim( $image->getAttribute( 'src' ) ) : '';
	}
}
