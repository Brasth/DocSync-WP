<?php
/**
 * Converts sanitized HTML fragments into Elementor JSON data.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use WP_Error;

use function is_wp_error;

defined( 'ABSPATH' ) || exit;

/**
 * Maps common Google Docs HTML export nodes to Elementor widgets.
 */
final class DataConverter {
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
	 * Constructor.
	 *
	 * @param WidgetFactory|null $widgets Widget factory.
	 * @param LayoutBuilder|null $layout  Layout builder.
	 */
	public function __construct( ?WidgetFactory $widgets = null, ?LayoutBuilder $layout = null ) {
		$this->widgets = $widgets ?? new WidgetFactory();
		$this->layout  = $layout ?? new LayoutBuilder();
	}

	/**
	 * Convert sanitized HTML into Elementor data JSON string.
	 *
	 * @param string $html    Sanitized HTML fragment.
	 * @param int    $post_id Target post ID.
	 * @return string|WP_Error
	 */
	public function convert( string $html, int $post_id ): string|WP_Error {
		if ( '' === trim( $html ) ) {
			return wp_json_encode( $this->layout->wrapWidgets( array(), $post_id ) );
		}

		$document = $this->parseDocument( $html );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$body = $document->getElementsByTagName( 'body' )->item( 0 );

		if ( null === $body ) {
			return wp_json_encode( $this->layout->wrapWidgets( array(), $post_id ) );
		}

		$widgets = array();

		foreach ( $body->childNodes as $node ) {
			$widgets = array_merge( $widgets, $this->nodeToWidgets( $node ) );
		}

		$json = wp_json_encode( $this->layout->wrapWidgets( $widgets, $post_id ) );

		if ( false === $json ) {
			return new WP_Error(
				'docsync_wp_elementor_encode_failed',
				__( 'Brasth Document Sync could not encode Elementor data.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $json;
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
				'docsync_wp_elementor_parse_failed',
				__( 'Brasth Document Sync could not prepare Google Docs content for Elementor.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return $document;
	}

	/**
	 * Convert a DOM node to zero or more Elementor widget arrays.
	 *
	 * @param DOMNode $node DOM node.
	 * @return array<int,array<string,mixed>>
	 */
	private function nodeToWidgets( DOMNode $node ): array {
		if ( $node instanceof DOMText ) {
			$text = trim( $node->textContent );

			if ( '' === $text ) {
				return array();
			}

			return array( $this->widgets->textEditor( esc_html( $text ) ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		$tag = strtolower( $node->tagName );

		if ( in_array( $tag, array( 'div', 'section', 'article', 'main' ), true ) ) {
			return $this->childrenToWidgets( $node );
		}

		if ( 'br' === $tag ) {
			return array();
		}

		return array( $this->widgets->fromElement( $node ) );
	}

	/**
	 * Convert container children into widgets.
	 *
	 * @param DOMElement $element Container element.
	 * @return array<int,array<string,mixed>>
	 */
	private function childrenToWidgets( DOMElement $element ): array {
		$widgets = array();

		foreach ( $element->childNodes as $child ) {
			$widgets = array_merge( $widgets, $this->nodeToWidgets( $child ) );
		}

		return $widgets;
	}
}
