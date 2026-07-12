<?php
/**
 * Builds Elementor widget arrays from sanitized HTML elements.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

use DocSyncWP\Sync\HtmlBlockMarkupSanitizer;
use DocSyncWP\Sync\HtmlStandaloneImage;
use DocSyncWP\Sync\HtmlStandaloneImageDetector;
use DOMElement;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Elementor core widget arrays from DOM nodes.
 */
final class WidgetFactory {
	public const STYLE_LEGACY       = 'legacy';
	public const STYLE_FEATURE      = 'feature';
	public const STYLE_HERO         = 'hero';
	public const STYLE_VISUAL_STORY = 'visual_story';

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
	 * Standalone image detector.
	 *
	 * @var HtmlStandaloneImageDetector
	 */
	private HtmlStandaloneImageDetector $standalone_images;

	/**
	 * Constructor.
	 *
	 * @param HtmlBlockMarkupSanitizer|null    $markup            Markup sanitizer.
	 * @param IdGenerator|null                 $ids               ID generator.
	 * @param HtmlStandaloneImageDetector|null $standalone_images Standalone image detector.
	 */
	public function __construct(
		?HtmlBlockMarkupSanitizer $markup = null,
		?IdGenerator $ids = null,
		?HtmlStandaloneImageDetector $standalone_images = null
	) {
		$this->markup            = $markup ?? new HtmlBlockMarkupSanitizer();
		$this->ids               = $ids ?? new IdGenerator();
		$this->standalone_images = $standalone_images ?? new HtmlStandaloneImageDetector();
	}

	/**
	 * Convert a DOM element to an Elementor widget array.
	 *
	 * @param DOMElement $element HTML element.
	 * @param string     $style   Widget style profile.
	 * @return array<string,mixed>
	 */
	public function fromElement( DOMElement $element, string $style = self::STYLE_LEGACY ): array {
		$tag = strtolower( $element->tagName );

		if ( preg_match( '/^h([1-6])$/', $tag, $matches ) ) {
			return $this->heading( $element, (int) $matches[1], $style );
		}

		$standalone_image = $this->standalone_images->detect( $element );

		if ( $standalone_image instanceof HtmlStandaloneImage ) {
			return $this->imageFromStandalone( $standalone_image, $style );
		}

		if ( 'p' === $tag ) {
			if ( $this->isEmptyBlock( $element ) ) {
				return $this->spacer();
			}

			return $this->textEditor( $this->markup->cleanInlineHtml( $element ), $style );
		}

		if ( 'ul' === $tag ) {
			return $this->iconList( $element, $style );
		}

		if ( 'ol' === $tag ) {
			return $this->textEditor( '<ol>' . $this->markup->cleanListInnerHtml( $element ) . '</ol>', $style );
		}

		if ( 'table' === $tag ) {
			return $this->htmlWidget( '<table>' . $this->markup->cleanTableInnerHtml( $element ) . '</table>', $style );
		}

		if ( 'blockquote' === $tag ) {
			return $this->textEditor( $this->markup->cleanQuoteInnerHtml( $element ), $style );
		}

		if ( 'hr' === $tag ) {
			return $this->divider();
		}

		if ( $this->isInlineElement( $tag ) ) {
			return $this->textEditor( $this->markup->cleanInlineFragment( $element ), $style );
		}

		return $this->htmlWidget( $this->markup->nodeHtml( $element ), $style );
	}

	/**
	 * Create a heading widget.
	 *
	 * @param DOMElement $element HTML element.
	 * @param int        $level   Heading level 1-6.
	 * @param string     $style   Widget style profile.
	 */
	public function heading( DOMElement $element, int $level, string $style = self::STYLE_LEGACY ): array {
		$text     = $this->markup->cleanInlineHtml( $element );
		$settings = array(
			'title'       => $text,
			'header_size' => 'h' . max( 1, min( 6, $level ) ),
		);

		if ( self::STYLE_HERO === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'size'    => 'xxl',
					'align'   => 'center',
					'_margin' => $this->edge( 0, 0, 8, 0 ),
				)
			);
		} elseif ( self::STYLE_FEATURE === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'size'    => $level <= 2 ? 'xl' : 'large',
					'align'   => 'left',
					'_margin' => $this->edge( 0, 0, 8, 0 ),
				)
			);
		}

		return $this->widget(
			'heading',
			$settings
		);
	}

	/**
	 * Create a text-editor widget.
	 *
	 * @param string $html HTML content.
	 * @param string $style Widget style profile.
	 */
	public function textEditor( string $html, string $style = self::STYLE_LEGACY ): array {
		$settings = array(
			'editor' => $this->ensureWrapped( $html ),
		);

		if ( self::STYLE_HERO === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'align'   => 'center',
					'_margin' => $this->edge( 0, 0, 16, 0 ),
				)
			);
		} elseif ( self::STYLE_FEATURE === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'align'   => 'left',
					'_margin' => $this->edge( 0, 0, 4, 0 ),
				)
			);
		}

		return $this->widget(
			'text-editor',
			$settings
		);
	}

	/**
	 * Create an image widget from an img element.
	 *
	 * @param DOMElement $image Image element.
	 * @param string     $style Widget style profile.
	 * @param string     $link_url Linked image URL.
	 * @param string     $caption Caption text.
	 */
	public function image( DOMElement $image, string $style = self::STYLE_LEGACY, string $link_url = '', string $caption = '' ): array {
		$url      = $image->getAttribute( 'src' );
		$alt      = $image->getAttribute( 'alt' );
		$link     = '' !== $link_url ? $link_url : $image->getAttribute( 'data-docsync-link' );
		$settings = array(
			'image' => array(
				'url' => $this->sanitizeUrl( $url ),
				'id'  => $this->attachmentIdFromUrl( $url ),
				'alt' => sanitize_text_field( $alt ),
			),
		);

		if ( self::STYLE_HERO === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'image_size'   => 'large',
					'align'        => 'center',
					'width'        => $this->size( 86, '%' ),
					'width_tablet' => $this->size( 92, '%' ),
					'width_mobile' => $this->size( 100, '%' ),
					'_margin'      => $this->edge( 8, 0, 0, 0 ),
				)
			);
		} elseif ( self::STYLE_FEATURE === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'image_size'   => 'large',
					'align'        => 'center',
					'width'        => $this->size( 72, '%' ),
					'width_tablet' => $this->size( 86, '%' ),
					'width_mobile' => $this->size( 100, '%' ),
					'_margin'      => $this->edge( 8, 0, 0, 0 ),
				)
			);
		} elseif ( self::STYLE_VISUAL_STORY === $style ) {
			$settings = array_merge(
				$settings,
				array(
					'image_size'   => 'large',
					'align'        => 'center',
					'width'        => $this->size( 100, '%' ),
					'width_tablet' => $this->size( 100, '%' ),
					'width_mobile' => $this->size( 100, '%' ),
					'_margin'      => $this->edge( 0, 0, 0, 0 ),
				)
			);
		}

		if ( '' !== $caption ) {
			$settings['caption_source'] = 'custom';
			$settings['caption']        = sanitize_text_field( $caption );
		}

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
	 * Create an image widget from a standalone image.
	 *
	 * @param HtmlStandaloneImage $image Standalone image.
	 * @param string              $style Widget style profile.
	 */
	public function imageFromStandalone( HtmlStandaloneImage $image, string $style = self::STYLE_LEGACY ): array {
		return $this->image( $image->getImageElement(), $style, $image->getLinkUrl(), $image->getCaption() );
	}

	/**
	 * Create an icon-list widget from a ul element.
	 *
	 * @param DOMElement $element UL element.
	 * @param string     $style   Widget style profile.
	 */
	public function iconList( DOMElement $element, string $style = self::STYLE_LEGACY ): array {
		$items    = array();
		$children = $element->getElementsByTagName( 'li' );
		$icon     = self::STYLE_LEGACY === $style ? 'fas fa-circle' : 'fas fa-check';

		foreach ( $children as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$items[] = array(
				'text'          => $this->markup->cleanInlineHtml( $child ),
				'selected_icon' => array(
					'value'   => $icon,
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
			return $this->textEditor( $this->markup->cleanListInnerHtml( $element ), $style );
		}

		$settings = array(
			'icon_list' => $items,
		);

		if ( self::STYLE_LEGACY !== $style ) {
			$settings = array_merge(
				$settings,
				array(
					'space_between' => $this->size( 10, 'px' ),
					'icon_size'     => $this->size( 14, 'px' ),
					'text_indent'   => $this->size( 8, 'px' ),
					'_margin'       => $this->edge( 4, 0, 4, 0 ),
				)
			);
		}

		return $this->widget(
			'icon-list',
			$settings
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
	 * @param string $style Widget style profile.
	 */
	public function htmlWidget( string $html, string $style = self::STYLE_LEGACY ): array {
		$settings = array(
			'html' => $this->ensureWrapped( $html ),
		);

		if ( self::STYLE_LEGACY !== $style ) {
			$settings['_margin'] = $this->edge( 8, 0, 0, 0 );
		}

		return $this->widget(
			'html',
			$settings
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

	/**
	 * Create an Elementor dimension setting.
	 *
	 * @param int    $size Size.
	 * @param string $unit Unit.
	 * @return array{unit:string,size:int,sizes:array<int,mixed>}
	 */
	private function size( int $size, string $unit ): array {
		return array(
			'unit'  => $unit,
			'size'  => $size,
			'sizes' => array(),
		);
	}

	/**
	 * Create an Elementor edge control setting.
	 *
	 * @param int $top    Top.
	 * @param int $right  Right.
	 * @param int $bottom Bottom.
	 * @param int $left   Left.
	 * @return array{unit:string,top:string,right:string,bottom:string,left:string,isLinked:bool}
	 */
	private function edge( int $top, int $right, int $bottom, int $left ): array {
		return array(
			'unit'     => 'px',
			'top'      => (string) $top,
			'right'    => (string) $right,
			'bottom'   => (string) $bottom,
			'left'     => (string) $left,
			'isLinked' => false,
		);
	}
}
