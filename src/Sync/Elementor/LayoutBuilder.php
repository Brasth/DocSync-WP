<?php
/**
 * Wraps Elementor widgets in container or section/column layouts.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the outer Elementor layout structure.
 */
final class LayoutBuilder {
	/**
	 * Compatibility checker.
	 *
	 * @var CompatibilityChecker
	 */
	private CompatibilityChecker $compatibility;

	/**
	 * ID generator.
	 *
	 * @var IdGenerator
	 */
	private IdGenerator $ids;

	/**
	 * Constructor.
	 *
	 * @param CompatibilityChecker|null $compatibility Compatibility checker.
	 * @param IdGenerator|null          $ids           ID generator.
	 */
	public function __construct( ?CompatibilityChecker $compatibility = null, ?IdGenerator $ids = null ) {
		$this->compatibility = $compatibility ?? new CompatibilityChecker();
		$this->ids           = $ids ?? new IdGenerator();
	}

	/**
	 * Wrap widgets in the correct layout structure for a post.
	 *
	 * @param array<int,array<string,mixed>> $widgets Widget arrays.
	 * @param int                            $post_id Post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function wrapWidgets( array $widgets, int $post_id ): array {
		if ( $this->compatibility->postUsesContainers( $post_id ) ) {
			return array( $this->container( $widgets ) );
		}

		return array( $this->section( $widgets ) );
	}

	/**
	 * Wrap widget groups as top-level Elementor layout sections.
	 *
	 * @param array<int,array<int,array<string,mixed>>> $widget_groups Widget groups.
	 * @param int                                       $post_id       Post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function wrapWidgetGroups( array $widget_groups, int $post_id ): array {
		if ( array() === $widget_groups ) {
			return $this->wrapWidgets( array(), $post_id );
		}

		return array_map(
			function ( array $widgets ) use ( $post_id ): array {
				if ( $this->compatibility->postUsesContainers( $post_id ) ) {
					return $this->container( $widgets );
				}

				return $this->section( $widgets );
			},
			$widget_groups
		);
	}

	/**
	 * Create a container element holding the widgets.
	 *
	 * @param array<int,array<string,mixed>> $widgets Widget arrays.
	 */
	private function container( array $widgets ): array {
		return array(
			'id'       => $this->ids->generate(),
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => array(),
			'elements' => $widgets,
		);
	}

	/**
	 * Create a legacy section with a single column holding the widgets.
	 *
	 * @param array<int,array<string,mixed>> $widgets Widget arrays.
	 */
	private function section( array $widgets ): array {
		$column = array(
			'id'       => $this->ids->generate(),
			'elType'   => 'column',
			'isInner'  => false,
			'settings' => array(
				'_column_size' => 100,
				'_inline_size' => null,
			),
			'elements' => $widgets,
		);

		return array(
			'id'       => $this->ids->generate(),
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(),
			'elements' => array( $column ),
		);
	}
}
