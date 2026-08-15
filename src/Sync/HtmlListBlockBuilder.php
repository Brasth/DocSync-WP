<?php
/**
 * Builds Gutenberg list blocks from HTML list elements.
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
 * Converts ul/ol DOM nodes into core/list + core/list-item block arrays.
 */
final class HtmlListBlockBuilder {
	/**
	 * Markup sanitizer.
	 *
	 * @var HtmlBlockMarkupSanitizer
	 */
	private HtmlBlockMarkupSanitizer $markup;

	/**
	 * Constructor.
	 *
	 * @param HtmlBlockMarkupSanitizer $markup Markup sanitizer.
	 */
	public function __construct( HtmlBlockMarkupSanitizer $markup ) {
		$this->markup = $markup;
	}

	/**
	 * Convert a ul or ol element to a core/list block.
	 *
	 * @param DOMElement $element List element.
	 * @return array<string,mixed>
	 */
	public function fromElement( DOMElement $element ): array {
		$tag     = strtolower( $element->tagName );
		$ordered = 'ol' === $tag;
		$tag     = $ordered ? 'ol' : 'ul';
		$items   = array();

		foreach ( $element->childNodes as $child ) {
			if ( ! $child instanceof DOMElement || 'li' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$item = $this->listItem( $child );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		$attrs = $ordered ? array( 'ordered' => true ) : array();
		$start = $this->orderedStart( $element );

		if ( null !== $start ) {
			$attrs['start'] = $start;
		}

		$open  = '<' . $tag . ' class="wp-block-list">';
		$close = '</' . $tag . '>';

		return $this->block( 'core/list', $attrs, $items, $open, $close );
	}

	/**
	 * Convert one li to a core/list-item block.
	 *
	 * @param DOMElement $element List item.
	 * @return array<string,mixed>|null
	 */
	private function listItem( DOMElement $element ): ?array {
		$nested = array();
		$inline = '';

		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMElement && in_array( strtolower( $child->tagName ), array( 'ul', 'ol' ), true ) ) {
				$nested[] = $this->fromElement( $child );
				continue;
			}

			$inline .= $this->collectInline( $child );
		}

		$inline = trim( $this->markup->cleanInlineHtmlString( $inline ) );

		if ( '' === trim( wp_strip_all_tags( $inline ) ) && array() === $nested ) {
			return null;
		}

		if ( array() === $nested ) {
			return $this->block( 'core/list-item', array(), array(), '<li>' . $inline . '</li>', '' );
		}

		return $this->block( 'core/list-item', array(), $nested, '<li>' . $inline, '</li>' );
	}

	/**
	 * Collect inline HTML, unwrapping presentation wrappers.
	 *
	 * @param DOMNode $node Node.
	 */
	private function collectInline( DOMNode $node ): string {
		if ( $node instanceof DOMText ) {
			return $node->textContent;
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$tag = strtolower( $node->tagName );

		if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
			return '';
		}

		if ( in_array( $tag, array( 'p', 'span', 'div' ), true ) ) {
			$html = '';

			foreach ( $node->childNodes as $child ) {
				$html .= $this->collectInline( $child );
			}

			return $html;
		}

		return $this->markup->nodeHtml( $node );
	}

	/**
	 * Optional ol start greater than 1.
	 *
	 * @param DOMElement $element List element.
	 */
	private function orderedStart( DOMElement $element ): ?int {
		if ( 'ol' !== strtolower( $element->tagName ) || ! $element->hasAttribute( 'start' ) ) {
			return null;
		}

		$start = (int) $element->getAttribute( 'start' );

		return $start > 1 ? $start : null;
	}

	/**
	 * Create a list or list-item block array.
	 *
	 * @param string                         $name         Block name.
	 * @param array<string,mixed>            $attrs        Attributes.
	 * @param array<int,array<string,mixed>> $inner_blocks Inner blocks.
	 * @param string                         $before       Opening HTML.
	 * @param string                         $after        Closing HTML, empty when the opening string is the full inner HTML.
	 * @return array<string,mixed>
	 */
	private function block( string $name, array $attrs, array $inner_blocks, string $before, string $after ): array {
		$inner_content = array( $before );

		foreach ( $inner_blocks as $_unused ) {
			$inner_content[] = null;
		}

		if ( '' !== $after ) {
			$inner_content[] = $after;
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => $before . $after,
			'innerContent' => $inner_content,
		);
	}
}
