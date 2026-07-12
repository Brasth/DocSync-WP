<?php
/**
 * Detects conservative collections of standalone imported images.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DOMElement;
use DOMNode;
use DOMText;

defined( 'ABSPATH' ) || exit;

/**
 * Groups only maximal runs of three or more eligible standalone images.
 */
final class HtmlStandaloneImageCollectionDetector {
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
	 * Replace eligible maximal image runs with collection values.
	 *
	 * Whitespace is formatting-only in every current renderer, so it neither
	 * produces output nor interrupts a possible collection.
	 *
	 * @param array<int,DOMNode> $nodes Significant renderer content nodes.
	 * @return array<int,DOMNode|HtmlStandaloneImageCollection>
	 */
	public function groupNodes( array $nodes ): array {
		$grouped = array();
		$run     = array();

		$flush = static function () use ( &$grouped, &$run ): void {
			if ( count( $run ) >= 3 ) {
				$grouped[] = new HtmlStandaloneImageCollection( array_column( $run, 'image' ) );
			} else {
				foreach ( $run as $item ) {
					$grouped[] = $item['node'];
				}
			}

			$run = array();
		};

		foreach ( $nodes as $node ) {
			if ( $this->isWhitespace( $node ) ) {
				continue;
			}

			$image = $node instanceof DOMElement ? $this->standalone_images->detect( $node ) : null;

			if ( $image instanceof HtmlStandaloneImage ) {
				$run[] = array(
					'node'  => $node,
					'image' => $image,
				);
				continue;
			}

			$flush();
			$grouped[] = $node;
		}

		$flush();

		return $grouped;
	}

	/**
	 * Whether a node is formatting-only whitespace.
	 *
	 * @param DOMNode $node DOM node.
	 */
	private function isWhitespace( DOMNode $node ): bool {
		return $node instanceof DOMText && '' === trim( $node->textContent );
	}
}
