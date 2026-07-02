<?php
/**
 * Builds Elementor widget arrays from sanitized HTML elements.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

use DocSyncWP\Sync\HtmlBlockMarkupSanitizer;
use DOMElement;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Elementor core widget arrays from DOM nodes.
 */
final class WidgetFactory {
	/**
	 * Markup sanitizer.
	 *
	 * @var HtmlBlockMarkupSanitizer
	 */
	private HtmlBlockMarkupSanitizer $markup;

	/**
	 * ID generator.
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Constructor.
	 *
	 * @param HtmlBlockMarkupSanitizer|null $markup Markup sanitizer.
	 * @param IdGenerator|null              $ids    ID generator.
	 */
	public function __construct( ?HtmlBlockMarkupSanitizer $markup = null, ?IdGenerator $ids = null ) {
		$this->markup = $markup ?? new HtmlBlockMarkupSanitizer();
		$this->ids    = $ids ?? new IdGenerator();
	}

	/**
	 * Convert a DOM element to an Elementor widget array.
	 *
	 * @param DOMElement $element HTML element.
	 * @return array<string,mixed>
	 */
	public function fromElement( DOMElement $element ): array {
		$tag = strtolower( $element->tagName );

		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			return $this->heading( $element, (int) $matches[1] );
		}

		if ( 'p' === $tag ) {
			$image = $this->singleImageElement( $element );

			if ( null !== $image ) {
				return $this->image( $image );
			}

			if ( $this->isEmptyBlock( $element ) ) {
				return $this->spacer();
			}

			return $this->textEditor( $this->markup->cleanInlineHtml( $element ) );
		}

		if ( 'ul' === $tag ) {
			return $this->iconList( $element );
		}

		if ( 'ol' === $tag ) {
			return $this->textEditor( '<ol>' . $this->markup->cleanListInnerHtml( $element ) . '</ol>' );
		}

		if ( 'img' === $tag ) {
			return $this->image( $element );
		}

		if ( 'table' === $tag ) {
			return $this->htmlWidget( '<table>' . $this->markup->cleanTableInnerHtml( $element ) . '</table>' );
		}

		if ( 'blockquote' === $tag ) {
			return $this->textEditor( $this->markup->cleanQuoteInnerHtml( $element ) );
		}

		if ( 'hr' === $tag ) {
			return $this->divider();
		}

		if ( $this->isInlineElement( $tag ) ) {
			return $this->textEditor( $this->markup->cleanInlineFragment( $element ) );
		}

		return $this->htmlWidget( $this->markup->nodeHtml( $element ) );
	}

	/**
	 * Create a heading widget.
	 *
	 * @param DOMElement $element HTML element.
	 * @param int        $level   Heading level 1-6.
	 */
	public function heading( DOMElement $element, int $level ): array {
		$text = $this->markup->cleanInlineHtml( $element );

		return $this->widget(
			'heading',
			array(
				'title'       => $text,
				'header_size' => 'h' . max( 1, min( 6, $level ) ),
			)
		);
	}

	/**
	 * Create a text-editor widget.
	 *
	 * @param string $html HTML content.
	 */
	public function textEditor( string $html ): array {
		return $this->widget(
			'text-editor',
			array(
				'editor' => $this->ensureWrapped( $html ),
			)
		);
	}

	/**
	 * Create an image widget from an img element.
	 *
	 * @param DOMElement $image Image element.
	 */
	public function image( DOMElement $image ): array {
		$url      = $image->getAttribute( 'src' );
		$alt      = $image->getAttribute( 'alt' );
		$link     = $image->getAttribute( 'data-docsync-link' );
		$settings = array(
			'image' => array(
				'url' => $this->sanitizeUrl( $url ),
				'id'  => $this->attachmentIdFromUrl( $url ),
				'alt' => sanitize_text_field( $alt ),
			),
		);

		if ( '' !== $link ) {
			$settings['link'] = array(
				'url'         => $this->sanitizeUrl( $link ),
				'is_external' => false,
				'nofollow'    => false,
			);
		}

		return $this->widget( 'image', $settings );
	}

	/**
	 * Create an icon-list widget from a ul element.
	 *
	 * @param DOMElement $element UL element.
	 */
	public function iconList( DOMElement $element ): array {
		$items    = array();
		$children = $element->getElementsByTagName( 'li' );

		foreach ( $children as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$items[] = array(
				'text'          => $this->markup->cleanInlineHtml( $child ),
				'selected_icon' => array(
					'value'   => 'fas fa-circle',
					'library' => 'fa-solid',
				),
				'link'          => array(
					'url'         => '',
					'is_external' => '',
					'nofollow'    => '',
				),
			);
		}

		if ( array() === $items ) {
			return $this->textEditor( $this->markup->cleanListInnerHtml( $element ) );
		}

		return $this->widget(
			'icon-list',
			array(
				'icon_list' => $items,
			)
		);
	}

	/**
	 * Create a divider widget.
	 */
	public function divider(): array {
		return $this->widget( 'divider', array() );
	}

	/**
	 * Create a spacer widget for empty paragraphs.
	 */
	public function spacer(): array {
		return $this->widget(
			'spacer',
			array(
				'space' => array(
					'size' => 20,
					'unit' => 'px',
				),
			)
		);
	}

	/**
	 * Create an HTML widget.
	 *
	 * @param string $html HTML content.
	 */
	public function htmlWidget( string $html ): array {
		return $this->widget(
			'html',
			array(
				'html' => $this->ensureWrapped( $html ),
			)
		);
	}

	/**
	 * Create a base widget array.
	 *
	 * @param string              $type     Widget type.
	 * @param array<string,mixed> $settings Widget settings.
	 */
	private function widget( string $type, array $settings ): array {
		return array(
			'id'         => $this->ids->generate(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'isInner'    => false,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	/**
	 * Return a single image if the element only wraps one image.
	 *
	 * Accepts an image wrapped in a single anchor as well.
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

		if ( ! $image instanceof DOMElement ) {
			return null;
		}

		foreach ( $element->childNodes as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			if ( 'a' !== strtolower( $child->tagName ) ) {
				continue;
			}

			$child_images = $child->getElementsByTagName( 'img' );

			if ( 1 !== $child_images->length ) {
				continue;
			}

			$link = $child->getAttribute( 'href' );

			if ( '' !== $link ) {
				$image->setAttribute( 'data-docsync-link', esc_url( $link ) );
			}

			break;
		}

		return $image;
	}

	/**
	 * Whether the element has no meaningful content.
	 *
	 * @param DOMElement $element HTML element.
	 */
	private function isEmptyBlock( DOMElement $element ): bool {
		$text = preg_replace( '/[\s\xC2\xA0]+/u', '', (string) $element->textContent );

		return '' === $text;
	}

	/**
	 * Whether the tag is safe to fold into a text editor widget.
	 *
	 * @param string $tag HTML tag.
	 */
	private function isInlineElement( string $tag ): bool {
		return in_array( $tag, array( 'a', 'strong', 'b', 'em', 'i', 'code', 'sub', 'sup', 'span', 'u', 'br' ), true );
	}

	/**
	 * Ensure inline HTML has a block wrapper for the text editor widget.
	 *
	 * @param string $html HTML content.
	 */
	private function ensureWrapped( string $html ): string {
		$html = trim( $html );

		if ( '' === $html ) {
			return $html;
		}

		if ( preg_match( '/^<(p|h[1-6]|ul|ol|blockquote|pre|table|div|figure)\b/i', $html ) ) {
			return $html;
		}

		return '<p>' . $html . '</p>';
	}

	/**
	 * Sanitize an image URL.
	 *
	 * @param string $url Image URL.
	 */
	private function sanitizeUrl( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return '';
		}

		$sanitized = esc_url_raw( $url );

		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Try to find a local attachment ID from an image URL.
	 *
	 * @param string $url Image URL.
	 */
	private function attachmentIdFromUrl( string $url ): int {
		$url = trim( $url );

		if ( '' === $url || ! function_exists( 'attachment_url_to_postid' ) ) {
			return 0;
		}

		$post_id = attachment_url_to_postid( $url );

		return is_int( $post_id ) && $post_id > 0 ? $post_id : 0;
	}
}
