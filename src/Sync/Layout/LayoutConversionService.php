<?php
/**
 * Converts sanitized HTML through a selected layout preset.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\HtmlBlockFactory;
use DocSyncWP\Sync\HtmlBlockMarkupSanitizer;
use DocSyncWP\Sync\HtmlToBlockContentConverter;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies layout presets before rendering Gutenberg block markup.
 */
final class LayoutConversionService {
	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Layout preset registry.
	 *
	 * @var LayoutPresetRegistry
	 */
	private LayoutPresetRegistry $presets;

	/**
	 * Legacy plain block converter.
	 *
	 * @var HtmlToBlockContentConverter
	 */
	private HtmlToBlockContentConverter $plain_converter;

	/**
	 * Block factory.
	 *
	 * @var HtmlBlockFactory
	 */
	private HtmlBlockFactory $blocks;

	/**
	 * Markup sanitizer.
	 *
	 * @var HtmlBlockMarkupSanitizer
	 */
	private HtmlBlockMarkupSanitizer $markup;

	/**
	 * Content role classifier.
	 *
	 * @var ContentRoleClassifier
	 */
	private ContentRoleClassifier $classifier;

	/**
	 * Documentation code block detector.
	 *
	 * @var DocumentationCodeBlockDetector
	 */
	private DocumentationCodeBlockDetector $documentation_code;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository                  $settings           Settings repository.
	 * @param HtmlToBlockContentConverter         $plain_converter    Legacy plain block converter.
	 * @param LayoutPresetRegistry|null           $presets            Preset registry.
	 * @param HtmlBlockFactory|null               $blocks             Block factory.
	 * @param HtmlBlockMarkupSanitizer|null       $markup             Markup sanitizer.
	 * @param ContentRoleClassifier|null          $classifier         Content role classifier.
	 * @param DocumentationCodeBlockDetector|null $documentation_code Documentation code detector.
	 */
	public function __construct(
		SettingsRepository $settings,
		HtmlToBlockContentConverter $plain_converter,
		?LayoutPresetRegistry $presets = null,
		?HtmlBlockFactory $blocks = null,
		?HtmlBlockMarkupSanitizer $markup = null,
		?ContentRoleClassifier $classifier = null,
		?DocumentationCodeBlockDetector $documentation_code = null
	) {
		$this->settings           = $settings;
		$this->plain_converter    = $plain_converter;
		$this->presets            = $presets ?? new LayoutPresetRegistry();
		$this->blocks             = $blocks ?? new HtmlBlockFactory();
		$this->markup             = $markup ?? new HtmlBlockMarkupSanitizer();
		$this->classifier         = $classifier ?? new ContentRoleClassifier();
		$this->documentation_code = $documentation_code ?? new DocumentationCodeBlockDetector();
	}

	/**
	 * Resolve the effective preset for source metadata.
	 *
	 * @param array<string,mixed> $source Source metadata.
	 */
	public function resolvePresetForSource( array $source ): string {
		$override = isset( $source['layout_preset'] ) ? sanitize_key( (string) $source['layout_preset'] ) : '';

		if ( '' !== $override && $this->presets->isValidPresetId( $override ) ) {
			return $override;
		}

		$settings = $this->settings->get();
		$default  = isset( $settings['default_layout_preset'] ) ? sanitize_key( (string) $settings['default_layout_preset'] ) : '';

		return $this->presets->isValidPresetId( $default ) ? $default : LayoutPresetRegistry::DEFAULT_EXISTING_INSTALL;
	}

	/**
	 * Fingerprint the effective preset for source metadata.
	 *
	 * @param array<string,mixed> $source Source metadata.
	 */
	public function fingerprintForSource( array $source ): string {
		return $this->fingerprintForPreset( $this->resolvePresetForSource( $source ) );
	}

	/**
	 * Fingerprint one preset.
	 *
	 * @param string $preset_id Preset ID.
	 */
	public function fingerprintForPreset( string $preset_id ): string {
		$preset = $this->presets->getPreset( $preset_id ) ?? $this->presets->getPreset( LayoutPresetRegistry::DEFAULT_EXISTING_INSTALL );

		return hash( 'sha256', wp_json_encode( null !== $preset ? $preset->getFingerprintSeed() : array( 'id' => $preset_id ) ) );
	}

	/**
	 * Convert sanitized HTML using source metadata to resolve the preset.
	 *
	 * @param string              $html   Sanitized HTML fragment.
	 * @param array<string,mixed> $source Source metadata.
	 * @return string|WP_Error
	 */
	public function convertForSource( string $html, array $source ): string|WP_Error {
		return $this->convert( $html, $this->resolvePresetForSource( $source ) );
	}

	/**
	 * Convert sanitized HTML into serialized Gutenberg blocks.
	 *
	 * @param string $html      Sanitized HTML fragment.
	 * @param string $preset_id Preset ID.
	 * @return string|WP_Error
	 */
	public function convert( string $html, string $preset_id ): string|WP_Error {
		if ( LayoutPresetRegistry::PRESET_PLAIN_BLOCKS === $preset_id ) {
			return $this->plain_converter->convert( $html );
		}

		$preset = $this->presets->getPreset( $preset_id );

		if ( null === $preset ) {
			return new WP_Error(
				'docsync_wp_invalid_layout_preset',
				__( 'Brasth Document Sync received an unsupported layout preset.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( $html ) ) {
			return '';
		}

		$document = $this->parseDocument( $html );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return '';
		}

		$blocks = $this->childrenToBlocks( $body, $preset );

		return serialize_blocks( $blocks );
	}

	/**
	 * Parse an HTML fragment into a DOM document.
	 *
	 * @param string $html Sanitized HTML fragment.
	 * @return DOMDocument|WP_Error
	 */
	private function parseDocument( string $html ): DOMDocument|WP_Error {
		$document = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $document->loadHTML(
			'<?xml encoding="UTF-8">' . $html,
			LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new WP_Error(
				'docsync_wp_layout_parse_failed',
				__( 'Brasth Document Sync could not prepare Google Docs content for the selected layout.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $document;
	}

	/**
	 * Convert a DOM node to zero or more blocks.
	 *
	 * @param DOMNode         $node   DOM node.
	 * @param LayoutBlueprint $preset Layout preset.
	 * @return array<int,array<string,mixed>>
	 */
	private function nodeToBlocks( DOMNode $node, LayoutBlueprint $preset ): array {
		if ( $node instanceof DOMText ) {
			$text = trim( $node->textContent );

			return '' === $text ? array() : array( $this->blocks->paragraphText( $text ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		if ( $this->isEmptyElement( $node ) ) {
			return array();
		}

		$role = $this->classifier->classifyElement( $node );

		if ( ContentRoleClassifier::ROLE_CONTAINER === $role ) {
			return $this->childrenToBlocks( $node, $preset );
		}

		if ( ContentRoleClassifier::ROLE_HEADING === $role ) {
			return array( $this->headingBlock( $node, $preset ) );
		}

		if ( ContentRoleClassifier::ROLE_IMAGE === $role ) {
			return array( $this->imageBlock( $node ) );
		}

		if ( ContentRoleClassifier::ROLE_CODE === $role && $preset->shouldRenderCodeBlocks() ) {
			return array( $this->codeBlock( $node ) );
		}

		if ( ContentRoleClassifier::ROLE_CALLOUT === $role && $preset->shouldRenderCallouts() ) {
			return array( $this->calloutBlock( $node ) );
		}

		return array( $this->blocks->fromElement( $node ) );
	}

	/**
	 * Convert container children into blocks.
	 *
	 * @param DOMElement      $element Container element.
	 * @param LayoutBlueprint $preset  Layout preset.
	 * @return array<int,array<string,mixed>>
	 */
	private function childrenToBlocks( DOMElement $element, LayoutBlueprint $preset ): array {
		if ( LayoutPresetRegistry::PRESET_DOCUMENTATION === $preset->getId() && $preset->shouldRenderCodeBlocks() ) {
			return $this->documentationChildrenToBlocks( $element, $preset );
		}

		$blocks = array();

		foreach ( $element->childNodes as $child ) {
			$blocks = array_merge( $blocks, $this->nodeToBlocks( $child, $preset ) );
		}

		return $blocks;
	}

	/**
	 * Convert Documentation preset children with paragraph code grouping.
	 *
	 * @param DOMElement      $element Container element.
	 * @param LayoutBlueprint $preset  Layout preset.
	 * @return array<int,array<string,mixed>>
	 */
	private function documentationChildrenToBlocks( DOMElement $element, LayoutBlueprint $preset ): array {
		$blocks   = array();
		$children = $this->significantChildren( $element );
		$count    = count( $children );

		for ( $index = 0; $index < $count; ++$index ) {
			$child = $children[ $index ];

			if ( $child instanceof DOMElement && $this->isDocumentationCodeCandidate( $child ) ) {
				$code_run = $this->documentation_code->detectCodeRun(
					$children,
					$index,
					fn ( DOMElement $candidate ): bool => $this->isDocumentationCodeCandidate( $candidate )
				);

				if ( null !== $code_run ) {
					$blocks[] = $this->codeTextBlock( $code_run['code'] );
					$index    = $code_run['end'];
					continue;
				}
			}

			$blocks = array_merge( $blocks, $this->nodeToBlocks( $child, $preset ) );
		}

		return $blocks;
	}

	/**
	 * Whether an element can be considered for Documentation paragraph code grouping.
	 *
	 * @param DOMElement $element Element.
	 */
	private function isDocumentationCodeCandidate( DOMElement $element ): bool {
		return ContentRoleClassifier::ROLE_DEFAULT === $this->classifier->classifyElement( $element );
	}

	/**
	 * Return children without formatting-only whitespace nodes.
	 *
	 * @param DOMElement $element Container element.
	 * @return array<int,DOMNode>
	 */
	private function significantChildren( DOMElement $element ): array {
		$children = array();

		foreach ( $element->childNodes as $child ) {
			if ( $child instanceof DOMText && '' === trim( $child->textContent ) ) {
				continue;
			}

			$children[] = $child;
		}

		return $children;
	}

	/**
	 * Create a heading block, applying preset heading policy.
	 *
	 * @param DOMElement      $element Heading element.
	 * @param LayoutBlueprint $preset  Layout preset.
	 * @return array<string,mixed>
	 */
	private function headingBlock( DOMElement $element, LayoutBlueprint $preset ): array {
		preg_match( '/^h([1-6])$/', strtolower( $element->tagName ), $matches );

		$level = isset( $matches[1] ) ? (int) $matches[1] : 2;

		if ( 1 === $level && $preset->shouldDemoteTopLevelHeadings() ) {
			$level = 2;
		}

		$inner_html = '<h' . $level . '>' . $this->markup->cleanInlineHtml( $element ) . '</h' . $level . '>';

		return $this->block( 'core/heading', array( 'level' => $level ), $inner_html );
	}

	/**
	 * Create a code block.
	 *
	 * @param DOMElement $element Code or pre element.
	 * @return array<string,mixed>
	 */
	private function codeBlock( DOMElement $element ): array {
		return $this->codeTextBlock( $element->textContent );
	}

	/**
	 * Create a code block from plain code text.
	 *
	 * @param string $code Code text.
	 * @return array<string,mixed>
	 */
	private function codeTextBlock( string $code ): array {
		$code = rtrim( str_replace( "\r\n", "\n", $code ) );
		$code = str_replace( "\r", "\n", $code );

		return $this->block(
			'core/code',
			array(),
			'<pre class="wp-block-code"><code>' . esc_html( $code ) . '</code></pre>'
		);
	}

	/**
	 * Create a quote-styled callout block.
	 *
	 * @param DOMElement $element Callout element.
	 * @return array<string,mixed>
	 */
	private function calloutBlock( DOMElement $element ): array {
		$tag = strtolower( $element->tagName );

		if ( 'p' === $tag ) {
			$inner_html = '<p>' . $this->markup->cleanInlineHtml( $element ) . '</p>';
		} else {
			$inner_html = $this->markup->cleanQuoteInnerHtml( $element );
		}

		return $this->block(
			'core/quote',
			array( 'className' => 'docsync-wp-callout' ),
			'<blockquote class="wp-block-quote docsync-wp-callout">' . $inner_html . '</blockquote>'
		);
	}

	/**
	 * Create an image block from an image or figure-like wrapper.
	 *
	 * @param DOMElement $element Image or wrapper element.
	 * @return array<string,mixed>
	 */
	private function imageBlock( DOMElement $element ): array {
		$image = 'img' === strtolower( $element->tagName ) ? $element : $this->classifier->singleImageElement( $element );

		if ( ! $image instanceof DOMElement ) {
			return $this->blocks->fromElement( $element );
		}

		$attrs   = array();
		$url     = $image->getAttribute( 'src' );
		$alt     = $image->getAttribute( 'alt' );
		$caption = $this->imageCaption( $element );

		if ( '' !== $url ) {
			$attrs['url'] = esc_url_raw( $url );
		}

		if ( '' !== $alt ) {
			$attrs['alt'] = sanitize_text_field( $alt );
		}

		if ( '' !== $caption ) {
			$attrs['caption'] = $caption;
		}

		$img = '<img src="' . esc_url( $url ) . '"';

		if ( '' !== $alt ) {
			$img .= ' alt="' . esc_attr( $alt ) . '"';
		}

		$img .= ' />';

		$inner_html = '<figure class="wp-block-image">' . $img;

		if ( '' !== $caption ) {
			$inner_html .= '<figcaption class="wp-element-caption">' . esc_html( $caption ) . '</figcaption>';
		}

		$inner_html .= '</figure>';

		return $this->block( 'core/image', $attrs, $inner_html );
	}

	/**
	 * Get a figure caption for an image wrapper.
	 *
	 * @param DOMElement $element Image or wrapper element.
	 */
	private function imageCaption( DOMElement $element ): string {
		if ( 'figure' !== strtolower( $element->tagName ) ) {
			return '';
		}

		$captions = $element->getElementsByTagName( 'figcaption' );
		$caption  = $captions->item( 0 );

		return $caption instanceof DOMElement ? sanitize_text_field( $caption->textContent ) : '';
	}

	/**
	 * Whether an element is empty enough to skip for article/documentation presets.
	 *
	 * @param DOMElement $element Element.
	 */
	private function isEmptyElement( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );

		if ( in_array( $tag, array( 'br', 'hr', 'img' ), true ) ) {
			return false;
		}

		if ( $element->getElementsByTagName( 'img' )->length > 0 ) {
			return false;
		}

		return '' === trim( $element->textContent );
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
}
