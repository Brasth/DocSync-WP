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
	 * Standalone image detector.
	 *
	 * @var HtmlStandaloneImageDetector
	 */
	private HtmlStandaloneImageDetector $standalone_images;

	/**
	 * Constructor.
	 *
	 * @param HtmlBlockMarkupSanitizer|null    $markup            Markup sanitizer.
	 * @param HtmlStandaloneImageDetector|null $standalone_images Standalone image detector.
	 */
	public function __construct( ?HtmlBlockMarkupSanitizer $markup = null, ?HtmlStandaloneImageDetector $standalone_images = null ) {
		$this->markup            = $markup ?? new HtmlBlockMarkupSanitizer();
		$this->standalone_images = $standalone_images ?? new HtmlStandaloneImageDetector();
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

		$standalone_image = $this->standalone_images->detect( $element );

		if ( null !== $standalone_image ) {
			return $this->imageBlock( $standalone_image );
		}

		if ( 'p' === $tag ) {
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
	 * Create a core image block from a standalone image.
	 *
	 * @param HtmlStandaloneImage $image Standalone image.
	 * @return array<string,mixed>
	 */
	private function imageBlock( HtmlStandaloneImage $image ): array {
		$attrs   = array();
		$url     = $image->getUrl();
		$alt     = $image->getAlt();
		$caption = $image->getCaption();
		$link    = $image->getLinkUrl();

		if ( '' !== $url ) {
			$attrs['url'] = esc_url_raw( $url );
		}

		if ( '' !== $alt ) {
			$attrs['alt'] = sanitize_text_field( $alt );
		}

		if ( '' !== $caption ) {
			$attrs['caption'] = $caption;
		}

		if ( '' !== $link ) {
			$attrs['href']            = esc_url_raw( $link );
			$attrs['linkDestination'] = 'custom';
		}

		$img = '<img src="' . esc_url( $url ) . '"';

		if ( '' !== $alt ) {
			$img .= ' alt="' . esc_attr( $alt ) . '"';
		}

		$img .= ' />';

		$inner_html = '<figure class="wp-block-image">';

		if ( '' !== $link ) {
			$inner_html .= '<a href="' . esc_url( $link ) . '">' . $img . '</a>';
		} else {
			$inner_html .= $img;
		}

		if ( '' !== $caption ) {
			$inner_html .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}

		$inner_html .= '</figure>';

		return $this->block( 'core/image', $attrs, $inner_html );
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
