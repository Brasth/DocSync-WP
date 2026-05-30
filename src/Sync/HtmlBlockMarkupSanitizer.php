<?php
/**
 * Sanitizes HTML fragments for Gutenberg core block markup.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMElement;
use DOMNode;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps block inner HTML close to WordPress core block save output.
 */
final class HtmlBlockMarkupSanitizer {
	/**
	 * Clean inline children for paragraph and heading blocks.
	 *
	 * @param DOMElement $element HTML element.
	 */
	public function cleanInlineHtml( DOMElement $element ): string {
		return $this->cleanHtml( $this->innerHtml( $element ), $this->inlineTags() );
	}

	/**
	 * Clean a full inline element for paragraph fallback.
	 *
	 * @param DOMElement $element HTML element.
	 */
	public function cleanInlineFragment( DOMElement $element ): string {
		return $this->cleanHtml( $this->nodeHtml( $element ), $this->inlineTags() );
	}

	/**
	 * Clean list children.
	 *
	 * @param DOMElement $element HTML element.
	 */
	public function cleanListInnerHtml( DOMElement $element ): string {
		return $this->cleanHtml( $this->innerHtml( $element ), $this->listTags() );
	}

	/**
	 * Clean quote children.
	 *
	 * @param DOMElement $element HTML element.
	 */
	public function cleanQuoteInnerHtml( DOMElement $element ): string {
		return $this->cleanHtml( $this->innerHtml( $element ), $this->quoteTags() );
	}

	/**
	 * Clean table children.
	 *
	 * @param DOMElement $element HTML element.
	 */
	public function cleanTableInnerHtml( DOMElement $element ): string {
		return $this->cleanHtml( $this->innerHtml( $element ), $this->tableTags() );
	}

	/**
	 * Serialize a DOM node to HTML.
	 *
	 * @param DOMNode $node DOM node.
	 */
	public function nodeHtml( DOMNode $node ): string {
		return null === $node->ownerDocument ? '' : (string) $node->ownerDocument->saveHTML( $node );
	}

	/**
	 * Sanitize HTML with a focused allowed-tag map.
	 *
	 * @param string              $html         HTML.
	 * @param array<string,array> $allowed_tags Allowed tags.
	 */
	private function cleanHtml( string $html, array $allowed_tags ): string {
		return wp_kses( $html, $allowed_tags );
	}

	/**
	 * Serialize element children.
	 *
	 * @param DOMElement $element HTML element.
	 */
	private function innerHtml( DOMElement $element ): string {
		$html = '';

		foreach ( $element->childNodes as $child ) {
			$html .= $this->nodeHtml( $child );
		}

		return $html;
	}

	/**
	 * Allowed inline rich-text tags.
	 *
	 * @return array<string,array>
	 */
	private function inlineTags(): array {
		return array(
			'a'      => array(
				'href'   => true,
				'rel'    => true,
				'target' => true,
				'title'  => true,
			),
			'br'     => array(),
			'code'   => array(),
			'em'     => array(),
			'i'      => array(),
			'strong' => array(),
			'sub'    => array(),
			'sup'    => array(),
		);
	}

	/**
	 * Allowed list markup.
	 *
	 * @return array<string,array>
	 */
	private function listTags(): array {
		return array_merge(
			$this->inlineTags(),
			array(
				'li' => array(),
				'ol' => array(),
				'ul' => array(),
			)
		);
	}

	/**
	 * Allowed quote markup.
	 *
	 * @return array<string,array>
	 */
	private function quoteTags(): array {
		return array_merge(
			$this->inlineTags(),
			array(
				'cite' => array(),
				'p'    => array(),
			)
		);
	}

	/**
	 * Allowed table markup.
	 *
	 * @return array<string,array>
	 */
	private function tableTags(): array {
		return array_merge(
			$this->inlineTags(),
			array(
				'tbody' => array(),
				'td'    => array(
					'colspan' => true,
					'rowspan' => true,
				),
				'tfoot' => array(),
				'th'    => array(
					'colspan' => true,
					'rowspan' => true,
				),
				'thead' => array(),
				'tr'    => array(),
			)
		);
	}
}
