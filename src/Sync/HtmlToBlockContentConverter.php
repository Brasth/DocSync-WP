<?php
/**
 * Converts sanitized HTML fragments into Gutenberg block markup.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Maps common Google Docs HTML export nodes to core WordPress blocks.
 */
final class HtmlToBlockContentConverter {
	/**
	 * Block factory.
	 *
	 * @var HtmlBlockFactory
	 */
	private HtmlBlockFactory $blocks;

	/**
	 * Constructor.
	 *
	 * @param HtmlBlockFactory|null $blocks Block factory.
	 */
	public function __construct( ?HtmlBlockFactory $blocks = null ) {
		$this->blocks = $blocks ?? new HtmlBlockFactory();
	}

	/**
	 * Convert sanitized HTML into serialized Gutenberg blocks.
	 *
	 * @param string $html Sanitized HTML fragment.
	 * @return string|WP_Error
	 */
	public function convert( string $html ): string|WP_Error {
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

		$blocks = array();

		foreach ( $body->childNodes as $node ) {
			$blocks = array_merge( $blocks, $this->nodeToBlocks( $node ) );
		}

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
				'docsync_wp_block_parse_failed',
				__( 'DocSync WP could not prepare Google Docs content for the block editor.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return $document;
	}

	/**
	 * Convert a DOM node to zero or more block arrays.
	 *
	 * @param DOMNode $node DOM node.
	 * @return array<int,array<string,mixed>>
	 */
	private function nodeToBlocks( DOMNode $node ): array {
		if ( $node instanceof DOMText ) {
			$text = trim( $node->textContent );
			return '' === $text ? array() : array( $this->blocks->paragraphText( $text ) );
		}

		if ( ! $node instanceof DOMElement ) {
			return array();
		}

		$tag = strtolower( $node->tagName );

		if ( in_array( $tag, array( 'div', 'section', 'article', 'main' ), true ) ) {
			return $this->childrenToBlocks( $node );
		}

		return array( $this->blocks->fromElement( $node ) );
	}

	/**
	 * Convert container children into blocks.
	 *
	 * @param DOMElement $element Container element.
	 * @return array<int,array<string,mixed>>
	 */
	private function childrenToBlocks( DOMElement $element ): array {
		$blocks = array();

		foreach ( $element->childNodes as $child ) {
			$blocks = array_merge( $blocks, $this->nodeToBlocks( $child ) );
		}

		return $blocks;
	}
}
