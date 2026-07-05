<?php
/**
 * Classifies sanitized HTML elements for layout conversion.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

use DOMElement;
use DocSyncWP\Sync\HtmlStandaloneImage;
use DocSyncWP\Sync\HtmlStandaloneImageDetector;

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight role detection for Google Docs export HTML.
 */
final class ContentRoleClassifier {
	public const ROLE_CALLOUT   = 'callout';
	public const ROLE_CODE      = 'code';
	public const ROLE_CONTAINER = 'container';
	public const ROLE_HEADING   = 'heading';
	public const ROLE_IMAGE     = 'image';
	public const ROLE_LIST      = 'list';
	public const ROLE_TABLE     = 'table';
	public const ROLE_DEFAULT   = 'default';

	/**
	 * Standalone image detector.
	 *
	 * @var HtmlStandaloneImageDetector
	 */
	private HtmlStandaloneImageDetector $standalone_images;

	/**
	 * Constructor.
	 *
	 * @param HtmlStandaloneImageDetector|null $standalone_images Standalone image detector.
	 */
	public function __construct( ?HtmlStandaloneImageDetector $standalone_images = null ) {
		$this->standalone_images = $standalone_images ?? new HtmlStandaloneImageDetector();
	}

	/**
	 * Classify one element.
	 *
	 * @param DOMElement $element Element.
	 */
	public function classifyElement( DOMElement $element ): string {
		$tag = strtolower( $element->tagName );

		if ( preg_match( '/^h[1-6]$/', $tag ) ) {
			return self::ROLE_HEADING;
		}

		if ( in_array( $tag, array( 'ul', 'ol' ), true ) ) {
			return self::ROLE_LIST;
		}

		if ( 'table' === $tag ) {
			return self::ROLE_TABLE;
		}

		if ( in_array( $tag, array( 'pre', 'code' ), true ) ) {
			return self::ROLE_CODE;
		}

		if ( $this->standalone_images->detect( $element ) instanceof HtmlStandaloneImage ) {
			return self::ROLE_IMAGE;
		}

		if ( $this->isCalloutElement( $element ) ) {
			return self::ROLE_CALLOUT;
		}

		if ( in_array( $tag, array( 'body', 'div', 'section', 'article', 'main' ), true ) ) {
			return self::ROLE_CONTAINER;
		}

		return self::ROLE_DEFAULT;
	}

	/**
	 * Whether an element looks like an editorial callout.
	 *
	 * @param DOMElement $element Element.
	 */
	private function isCalloutElement( DOMElement $element ): bool {
		$tag = strtolower( $element->tagName );

		if ( 'blockquote' === $tag || 'aside' === $tag ) {
			return true;
		}

		$signature = strtolower(
			implode(
				' ',
				array(
					$element->getAttribute( 'class' ),
					$element->getAttribute( 'id' ),
					$element->getAttribute( 'data-type' ),
				)
			)
		);

		if ( preg_match( '/\b(callout|note|tip|warning|important|caution)\b/', $signature ) ) {
			return true;
		}

		if ( 'p' !== $tag ) {
			return false;
		}

		$text = strtolower( trim( preg_replace( '/\s+/', ' ', $element->textContent ) ?? '' ) );

		return (bool) preg_match( '/^(note|tip|warning|important|caution):\s+/', $text );
	}
}
