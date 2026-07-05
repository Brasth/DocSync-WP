<?php
/**
 * Converts sanitized HTML through a selected Elementor preset.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor\Preset;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DocSyncWP\Sync\Elementor\LayoutBuilder;
use DocSyncWP\Sync\Elementor\WidgetFactory;
use DocSyncWP\Sync\HtmlStandaloneImage;
use DocSyncWP\Sync\HtmlStandaloneImageDetector;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Applies Elementor layout presets before JSON encoding Elementor data.
 */
final class ElementorPresetConversionService {
	/**
	 * Widget factory.
	 *
	 * @var WidgetFactory
	 */
	private WidgetFactory $widgets;

	/**
	 * Layout builder.
	 *
	 * @var LayoutBuilder
	 */
	private LayoutBuilder $layout;

	/**
	 * Preset registry.
	 *
	 * @var ElementorPresetRegistry
	 */
	private ElementorPresetRegistry $presets;

	/**
	 * Standalone image detector.
	 *
	 * @var HtmlStandaloneImageDetector
	 */
	private HtmlStandaloneImageDetector $standalone_images;

	/**
	 * Constructor.
	 *
	 * @param WidgetFactory|null               $widgets           Widget factory.
	 * @param LayoutBuilder|null               $layout            Layout builder.
	 * @param ElementorPresetRegistry|null     $presets           Preset registry.
	 * @param HtmlStandaloneImageDetector|null $standalone_images Standalone image detector.
	 */
	public function __construct(
		?WidgetFactory $widgets = null,
		?LayoutBuilder $layout = null,
		?ElementorPresetRegistry $presets = null,
		?HtmlStandaloneImageDetector $standalone_images = null
	) {
		$this->widgets           = $widgets ?? new WidgetFactory();
		$this->layout            = $layout ?? new LayoutBuilder();
		$this->presets           = $presets ?? new ElementorPresetRegistry();
		$this->standalone_images = $standalone_images ?? new HtmlStandaloneImageDetector();
	}

	/**
	 * Resolve the explicit Elementor preset for source metadata.
	 *
	 * Empty means the source should use the legacy Elementor converter.
	 *
	 * @param array<string,mixed> $source Source metadata.
	 */
	public function resolvePresetForSource( array $source ): string {
		$preset_id = isset( $source['elementor_preset'] ) ? sanitize_key( (string) $source['elementor_preset'] ) : '';

		return '' !== $preset_id && $this->presets->isValidPresetId( $preset_id ) ? $preset_id : '';
	}

	/**
	 * Fingerprint the effective Elementor preset for source metadata.
	 *
	 * Empty means legacy Elementor output and preserves old skip behavior.
	 *
	 * @param array<string,mixed> $source Source metadata.
	 */
	public function fingerprintForSource( array $source ): string {
		$preset_id = $this->resolvePresetForSource( $source );

		return '' === $preset_id ? '' : $this->fingerprintForPreset( $preset_id );
	}

	/**
	 * Fingerprint one Elementor preset.
	 *
	 * @param string $preset_id Preset ID.
	 */
	public function fingerprintForPreset( string $preset_id ): string {
		$preset = $this->presets->getPreset( $preset_id ) ?? $this->presets->getPreset( ElementorPresetRegistry::DEFAULT_PRESET );

		return hash(
			'sha256',
			wp_json_encode(
				null !== $preset ? $preset->getFingerprintSeed() : array(
					'editor' => 'elementor',
					'id'     => $preset_id,
				)
			)
		);
	}

	/**
	 * Convert sanitized HTML using source metadata to resolve the preset.
	 *
	 * @param string              $html    Sanitized HTML fragment.
	 * @param int                 $post_id Target post ID.
	 * @param array<string,mixed> $source  Source metadata.
	 * @return string|WP_Error
	 */
	public function convertForSource( string $html, int $post_id, array $source ): string|WP_Error {
		$preset_id = $this->resolvePresetForSource( $source );

		return $this->convert( $html, $post_id, '' !== $preset_id ? $preset_id : ElementorPresetRegistry::DEFAULT_PRESET );
	}

	/**
	 * Convert sanitized HTML into Elementor data JSON.
	 *
	 * @param string $html      Sanitized HTML fragment.
	 * @param int    $post_id   Target post ID.
	 * @param string $preset_id Preset ID.
	 * @return string|WP_Error
	 */
	public function convert( string $html, int $post_id, string $preset_id ): string|WP_Error {
		$preset = $this->presets->getPreset( $preset_id );

		if ( null === $preset ) {
			return new WP_Error(
				'docsync_wp_invalid_elementor_preset',
				__( 'Brasth Document Sync received an unsupported Elementor layout preset.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( '' === trim( $html ) ) {
			return $this->encodeLayout( array(), $post_id );
		}

		$document = $this->parseDocument( $html );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return $this->encodeLayout( array(), $post_id );
		}

		$nodes      = $this->contentNodes( $body );
		$group_data = 'hero_page' === $preset->getLayout()
			? $this->heroWidgetGroupData( $nodes )
			: $this->featureWidgetGroupData( $nodes );

		return $this->encodeLayout( $group_data['groups'], $post_id, $group_data['styles'] );
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
				'docsync_wp_elementor_preset_parse_failed',
				__( 'Brasth Document Sync could not prepare Google Docs content for the selected Elementor layout.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $document;
	}

	/**
	 * Return significant content nodes, flattening structural wrappers.
	 *
	 * @param DOMNode $node DOM node.
	 * @return array<int,DOMNode>
	 */
	private function contentNodes( DOMNode $node ): array {
		if ( $node instanceof DOMText ) {
			return '' === trim( $node->textContent ) ? array() : array( $node );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		$tag = strtolower( $node->tagName );

		if ( ! in_array( $tag, array( 'body', 'div', 'section', 'article', 'main' ), true ) ) {
			return $this->isEmptyElement( $node ) ? array() : array( $node );
		}

		$nodes = array();

		foreach ( $node->childNodes as $child ) {
			$nodes = array_merge( $nodes, $this->contentNodes( $child ) );
		}

		return $nodes;
	}

	/**
	 * Build feature-style widget groups.
	 *
	 * @param array<int,DOMNode> $nodes Content nodes.
	 * @return array{groups:array<int,array<int,array<string,mixed>>>,styles:array<int,string>}
	 */
	private function featureWidgetGroupData( array $nodes ): array {
		$groups = $this->featureWidgetGroups( $nodes );

		return array(
			'groups' => $groups,
			'styles' => array_fill( 0, count( $groups ), LayoutBuilder::GROUP_STYLE_FEATURE ),
		);
	}

	/**
	 * Build feature-style widget groups.
	 *
	 * @param array<int,DOMNode> $nodes Content nodes.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	private function featureWidgetGroups( array $nodes ): array {
		$groups  = array();
		$current = array();

		foreach ( $nodes as $node ) {
			$widgets = $this->nodeToWidgets( $node, WidgetFactory::STYLE_FEATURE );

			if ( array() === $widgets ) {
				continue;
			}

			if ( $this->isHeading( $node ) && array() !== $current ) {
				$groups[] = $current;
				$current  = array();
			}

			$current = array_merge( $current, $widgets );
		}

		if ( array() !== $current ) {
			$groups[] = $current;
		}

		return $groups;
	}

	/**
	 * Build hero-first widget groups.
	 *
	 * @param array<int,DOMNode> $nodes Content nodes.
	 * @return array{groups:array<int,array<int,array<string,mixed>>>,styles:array<int,string>}
	 */
	private function heroWidgetGroupData( array $nodes ): array {
		$heading_index = $this->findFirstIndex( $nodes, array( $this, 'isHeroHeading' ) );

		if ( null === $heading_index ) {
			return $this->featureWidgetGroupData( $nodes );
		}

		$intro_index = $this->findFirstIndex( $nodes, array( $this, 'isIntroParagraph' ), $heading_index + 1 );

		if ( null === $intro_index ) {
			return $this->featureWidgetGroupData( $nodes );
		}

		$image_index = $this->findFirstImageIndex( $nodes );
		$selected    = array_filter( array( $heading_index, $intro_index, $image_index ), 'is_int' );
		$hero        = array_merge(
			$this->nodeToWidgets( $nodes[ $heading_index ], WidgetFactory::STYLE_HERO ),
			$this->nodeToWidgets( $nodes[ $intro_index ], WidgetFactory::STYLE_HERO )
		);

		if ( null !== $image_index ) {
			$image = $this->nodeStandaloneImage( $nodes[ $image_index ] );

			if ( $image instanceof HtmlStandaloneImage ) {
				$hero[] = $this->widgets->imageFromStandalone( $image, WidgetFactory::STYLE_HERO );
			}
		}

		if ( array() === $hero ) {
			return $this->featureWidgetGroupData( $nodes );
		}

		$remaining = array();

		foreach ( $nodes as $index => $node ) {
			if ( in_array( $index, $selected, true ) ) {
				continue;
			}

			$remaining[] = $node;
		}

		$feature_groups = $this->featureWidgetGroups( $remaining );

		return array(
			'groups' => array_merge( array( $hero ), $feature_groups ),
			'styles' => array_merge(
				array( LayoutBuilder::GROUP_STYLE_HERO ),
				array_fill( 0, count( $feature_groups ), LayoutBuilder::GROUP_STYLE_FEATURE )
			),
		);
	}

	/**
	 * Convert one DOM node to Elementor widgets.
	 *
	 * @param DOMNode $node DOM node.
	 * @param string  $style Widget style profile.
	 * @return array<int,array<string,mixed>>
	 */
	private function nodeToWidgets( DOMNode $node, string $style ): array {
		if ( $node instanceof DOMText ) {
			$text = trim( $node->textContent );

			return '' === $text ? array() : array( $this->widgets->textEditor( esc_html( $text ), $style ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		return array( $this->widgets->fromElement( $node, $style ) );
	}

	/**
	 * Find the first node index matching a callback.
	 *
	 * @param array<int,DOMNode> $nodes Content nodes.
	 * @param callable           $check Node matcher.
	 * @param int                $start Start index.
	 */
	private function findFirstIndex( array $nodes, callable $check, int $start = 0 ): ?int {
		$count = count( $nodes );

		for ( $index = max( 0, $start ); $index < $count; ++$index ) {
			if ( $check( $nodes[ $index ] ) ) {
				return $index;
			}
		}

		return null;
	}

	/**
	 * Find the first image-like node index.
	 *
	 * @param array<int,DOMNode> $nodes Content nodes.
	 * @param int                $start Start index.
	 */
	private function findFirstImageIndex( array $nodes, int $start = 0 ): ?int {
		return $this->findFirstIndex(
			$nodes,
			fn ( DOMNode $node ): bool => $this->nodeStandaloneImage( $node ) instanceof HtmlStandaloneImage,
			$start
		);
	}

	/**
	 * Whether a node is any heading.
	 *
	 * @param DOMNode $node DOM node.
	 */
	private function isHeading( DOMNode $node ): bool {
		return $node instanceof DOMElement && 1 === preg_match( '/^h[1-6]$/', strtolower( $node->tagName ) );
	}

	/**
	 * Whether a node is a hero heading.
	 *
	 * @param DOMNode $node DOM node.
	 */
	private function isHeroHeading( DOMNode $node ): bool {
		return $node instanceof DOMElement && in_array( strtolower( $node->tagName ), array( 'h1', 'h2' ), true );
	}

	/**
	 * Whether a node is a non-empty intro paragraph.
	 *
	 * @param DOMNode $node DOM node.
	 */
	private function isIntroParagraph( DOMNode $node ): bool {
		return $node instanceof DOMElement
			&& 'p' === strtolower( $node->tagName )
			&& '' !== trim( $node->textContent );
	}

	/**
	 * Get the standalone image represented by a node.
	 *
	 * @param DOMNode $node DOM node.
	 */
	private function nodeStandaloneImage( DOMNode $node ): ?HtmlStandaloneImage {
		if ( ! $node instanceof DOMElement ) {
			return null;
		}

		return $this->standalone_images->detect( $node );
	}

	/**
	 * Whether an element can be skipped.
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
	 * Encode widget groups as Elementor data JSON.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $groups  Widget groups.
	 * @param int                                       $post_id Target post ID.
	 * @param array<int,string>                         $styles  Group style names.
	 * @return string|WP_Error
	 */
	private function encodeLayout( array $groups, int $post_id, array $styles = array() ): string|WP_Error {
		$json = wp_json_encode( $this->layout->wrapWidgetGroups( $groups, $post_id, $styles ) );

		if ( false === $json ) {
			return new WP_Error(
				'docsync_wp_elementor_preset_encode_failed',
				__( 'Brasth Document Sync could not encode Elementor preset data.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $json;
	}
}
