<?php
/**
 * Represents a standalone image found in imported HTML.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMElement;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the image element plus editor-relevant media context.
 */
final class HtmlStandaloneImage {
	/**
	 * Image element.
	 *
	 * @var DOMElement
	 */
	private DOMElement $image;

	/**
	 * Caption text.
	 *
	 * @var string
	 */
	private string $caption;

	/**
	 * Linked image URL.
	 *
	 * @var string
	 */
	private string $link_url;

	/**
	 * Constructor.
	 *
	 * @param DOMElement $image    Image element.
	 * @param string     $caption  Caption text.
	 * @param string     $link_url Linked image URL.
	 */
	public function __construct( DOMElement $image, string $caption = '', string $link_url = '' ) {
		$this->image    = $image;
		$this->caption  = $caption;
		$this->link_url = $link_url;
	}

	/**
	 * Get the image element.
	 */
	public function getImageElement(): DOMElement {
		return $this->image;
	}

	/**
	 * Get image URL.
	 */
	public function getUrl(): string {
		return $this->image->getAttribute( 'src' );
	}

	/**
	 * Get image alt text.
	 */
	public function getAlt(): string {
		return $this->image->getAttribute( 'alt' );
	}

	/**
	 * Get caption text.
	 */
	public function getCaption(): string {
		return $this->caption;
	}

	/**
	 * Get linked image URL.
	 */
	public function getLinkUrl(): string {
		return $this->link_url;
	}
}
