<?php
/**
 * Detects standalone imported image structures.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMElement;
use DOMNode;
use DOMText;

defined( 'ABSPATH' ) || exit;

/**
 * Finds image content that should become native editor media.
 */
final class HtmlStandaloneImageDetector {
	/**
	 * Detect a standalone image represented by an element.
	 *
	 * @param DOMElement $element Candidate element.
	 */
	public function detect( DOMElement $element ): ?HtmlStandaloneImage {
		$tag = strtolower( $element->tagName );

		if ( 'img' === $tag ) {
			return new HtmlStandaloneImage( $element );
		}

		if ( ! $this->isSupportedWrapper( $tag ) ) {
			return null;
		}

		$image = $this->singleDescendantImage( $element );

		if ( ! $image instanceof DOMElement || ! $this->hasOnlyStandaloneImageContent( $element ) ) {
			return null;
		}

		return new HtmlStandaloneImage(
			$image,
			$this->captionText( $element ),
			$this->linkUrl( $element, $image )
		);
	}

	/**
	 * Whether this tag can wrap a standalone image.
	 *
	 * @param string $tag Element tag.
	 */
	private function isSupportedWrapper( string $tag ): bool {
		return in_array( $tag, array( 'a', 'figure', 'p', 'span' ), true );
	}

	/**
	 * Return the only descendant image, if exactly one exists.
	 *
	 * @param DOMElement $element Candidate wrapper.
	 */
	private function singleDescendantImage( DOMElement $element ): ?DOMElement {
		$images = $element->getElementsByTagName( 'img' );

		if ( 1 !== $images->length ) {
			return null;
		}

		$image = $images->item( 0 );

		return $image instanceof DOMElement ? $image : null;
	}

	/**
	 * Whether the wrapper contains only image structure and optional figure caption.
	 *
	 * @param DOMElement $element Candidate wrapper.
	 */
	private function hasOnlyStandaloneImageContent( DOMElement $element ): bool {
		if ( 'figure' !== strtolower( $element->tagName ) ) {
			return '' === $this->normalizedText( $element->textContent );
		}

		return '' === $this->normalizedText( $this->textOutsideImageAndCaption( $element ) );
	}

	/**
	 * Collect visible text outside image and figcaption nodes.
	 *
	 * @param DOMNode $node Node.
	 */
	private function textOutsideImageAndCaption( DOMNode $node ): string {
		$text = '';

		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof DOMText ) {
				$text .= $child->textContent;
				continue;
			}

			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( in_array( strtolower( $child->tagName ), array( 'figcaption', 'img' ), true ) ) {
				continue;
			}

			$text .= $this->textOutsideImageAndCaption( $child );
		}

		return $text;
	}

	/**
	 * Extract explicit figure caption text.
	 *
	 * @param DOMElement $element Candidate wrapper.
	 */
	private function captionText( DOMElement $element ): string {
		if ( 'figure' !== strtolower( $element->tagName ) ) {
			return '';
		}

		$captions = $element->getElementsByTagName( 'figcaption' );
		$caption  = $captions->item( 0 );

		return $caption instanceof DOMElement ? sanitize_text_field( $caption->textContent ) : '';
	}

	/**
	 * Find a link wrapped around the image.
	 *
	 * @param DOMElement $wrapper Image wrapper.
	 * @param DOMElement $image   Image element.
	 */
	private function linkUrl( DOMElement $wrapper, DOMElement $image ): string {
		$current = $image->parentNode;

		while ( $current instanceof DOMElement ) {
			if ( 'a' === strtolower( $current->tagName ) ) {
				return esc_url_raw( $current->getAttribute( 'href' ) );
			}

			if ( $current === $wrapper ) {
				break;
			}

			$current = $current->parentNode;
		}

		return '';
	}

	/**
	 * Normalize text for structural image checks.
	 *
	 * @param string $text Text.
	 */
	private function normalizedText( string $text ): string {
		return preg_replace( '/[\s\xC2\xA0]+/u', '', $text ) ?? '';
	}
}
