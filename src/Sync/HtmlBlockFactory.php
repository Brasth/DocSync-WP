<?php
/**
 * Builds Gutenberg block arrays from sanitized HTML elements.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMElement;

defined( 'ABSPATH' ) || exit;

/**
 * Creates serializable WordPress core block arrays.
 */
final class HtmlBlockFactory {
	/**
	 * Markup sanitizer.
	 *
	 * @var HtmlBlockMarkupSanitizer
	 */
	private HtmlBlockMarkupSanitizer $markup;

	/**
	 * Constructor.
	 *
	 * @param HtmlBlockMarkupSanitizer|null $markup Markup sanitizer.
	 */
	public function __construct( ?HtmlBlockMarkupSanitizer $markup = null ) {
		$this->markup = $markup ?? new HtmlBlockMarkupSanitizer();
	}

	/**
	 * Convert a DOM element to a block array.
	 *
	 * @param DOMElement $element HTML element.
	 * @return array<string,mixed>
	 */
	public function fromElement( DOMElement $element ): array {
		$tag = strtolower( $element->tagName );

		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			$level      = (int) $matches[1];
			$inner_html = '<h' . $level . '>' . $this->markup->cleanInlineHtml( $element ) . '</h' . $level . '>';

			return $this->block( 'core/heading', array( 'level' => $level ), $inner_html );
		}

		if ( 'p' === $tag ) {
			$image = $this->singleImageElement( $element );

			if ( null !== $image ) {
				return $this->imageBlock( $image );
			}

			return $this->block( 'core/paragraph', array(), '<p>' . $this->markup->cleanInlineHtml( $element ) . '</p>' );
		}

		if ( $this->isInlineElement( $tag ) ) {
			return $this->block( 'core/paragraph', array(), '<p>' . $this->markup->cleanInlineFragment( $element ) . '</p>' );
		}

		return match ( $tag ) {
			'ul' => $this->block( 'core/list', array(), '<ul>' . $this->markup->cleanListInnerHtml( $element ) . '</ul>' ),
			'ol' => $this->block(
				'core/list',
				array( 'ordered' => true ),
				'<ol>' . $this->markup->cleanListInnerHtml( $element ) . '</ol>'
			),
			'img' => $this->imageBlock( $element ),
			'table' => $this->block(
				'core/table',
				array(),
				'<figure class="wp-block-table"><table>' . $this->markup->cleanTableInnerHtml( $element ) . '</table></figure>'
			),
			'blockquote' => $this->block(
				'core/quote',
				array(),
				'<blockquote>' . $this->markup->cleanQuoteInnerHtml( $element ) . '</blockquote>'
			),
			'pre' => $this->block( 'core/preformatted', array(), '<pre>' . esc_html( $element->textContent ) . '</pre>' ),
			'hr' => $this->block( 'core/separator', array(), '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ),
			default => $this->block( 'core/html', array(), $this->markup->nodeHtml( $element ) ),
		};
	}

	/**
	 * Create a paragraph block from plain text.
	 *
	 * @param string $text Plain text.
	 * @return array<string,mixed>
	 */
	public function paragraphText( string $text ): array {
		return $this->block( 'core/paragraph', array(), '<p>' . esc_html( $text ) . '</p>' );
	}

	/**
	 * Return a single image if the element only wraps one image.
	 *
	 * @param DOMElement $element Candidate wrapper.
	 */
	private function singleImageElement( DOMElement $element ): ?DOMElement {
		if ( '' !== trim( $element->textContent ) ) {
			return null;
		}

		$images = $element->getElementsByTagName( 'img' );

		if ( 1 !== $images->length ) {
			return null;
		}

		$image = $images->item( 0 );

		return $image instanceof DOMElement ? $image : null;
	}

	/**
	 * Create a core image block from an img element.
	 *
	 * @param DOMElement $image Image element.
	 * @return array<string,mixed>
	 */
	private function imageBlock( DOMElement $image ): array {
		$attrs = array();
		$url   = $image->getAttribute( 'src' );
		$alt   = $image->getAttribute( 'alt' );

		if ( '' !== $url ) {
			$attrs['url'] = esc_url_raw( $url );
		}

		if ( '' !== $alt ) {
			$attrs['alt'] = sanitize_text_field( $alt );
		}

		$img = '<img src="' . esc_url( $url ) . '"';

		if ( '' !== $alt ) {
			$img .= ' alt="' . esc_attr( $alt ) . '"';
		}

		$img .= ' />';

		return $this->block( 'core/image', $attrs, '<figure class="wp-block-image">' . $img . '</figure>' );
	}

	/**
	 * Create a serialized block array.
	 *
	 * @param string              $name       Block name.
	 * @param array<string,mixed> $attrs      Block attributes.
	 * @param string              $inner_html Inner HTML.
	 * @return array<string,mixed>
	 */
	private function block( string $name, array $attrs, string $inner_html ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => $inner_html,
			'innerContent' => array( $inner_html ),
		);
	}

	/**
	 * Whether the tag is safe to fold into a paragraph block.
	 *
	 * @param string $tag HTML tag.
	 */
	private function isInlineElement( string $tag ): bool {
		return in_array( $tag, array( 'a', 'strong', 'b', 'em', 'i', 'code', 'sub', 'sup', 'span', 'u' ), true );
	}
}
