<?php
/**
 * Represents a consecutive collection of standalone imported images.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Carries standalone images that a renderer may present as one collection.
 */
final class HtmlStandaloneImageCollection {
	/**
	 * Collection images in source order.
	 *
	 * @var array<int,HtmlStandaloneImage>
	 */
	private array $images;

	/**
	 * Constructor.
	 *
	 * @param array<int,HtmlStandaloneImage> $images Collection images.
	 */
	public function __construct( array $images ) {
		$this->images = $images;
	}

	/**
	 * Get collection images in source order.
	 *
	 * @return array<int,HtmlStandaloneImage>
	 */
	public function getImages(): array {
		return $this->images;
	}
}
