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
	public const GROUP_STYLE_DEFAULT    = 'default';
	public const GROUP_STYLE_FEATURE    = 'feature';
	public const GROUP_STYLE_HERO       = 'hero';
	public const GROUP_STYLE_IMAGE_GRID = 'image_grid';

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
	 * @param array<int,string>                         $group_styles  Optional group style names.
	 * @return array<int,array<string,mixed>>
	 */
	public function wrapWidgetGroups( array $widget_groups, int $post_id, array $group_styles = array() ): array {
		if ( array() === $widget_groups ) {
			return $this->wrapWidgets( array(), $post_id );
		}

		return array_map(
			function ( array $widgets, int $index ) use ( $post_id, $group_styles ): array {
				$style = $group_styles[ $index ] ?? self::GROUP_STYLE_DEFAULT;

				if ( self::GROUP_STYLE_IMAGE_GRID === $style ) {
					return $this->compatibility->postUsesContainers( $post_id )
						? $this->imageGridContainer( $widgets )
						: $this->imageGridSection( $widgets );
				}

				if ( $this->compatibility->postUsesContainers( $post_id ) ) {
					return $this->container( $widgets, $style );
				}

				return $this->section( $widgets, $style );
			},
			$widget_groups,
			array_keys( $widget_groups )
		);
	}

	/**
	 * Create a responsive flex grid of ordinary Elementor Image widgets.
	 *
	 * @param array<int,array<string,mixed>> $widgets Image widgets.
	 * @return array<string,mixed>
	 */
	private function imageGridContainer( array $widgets ): array {
		$items = array_map(
			function ( array $widget ): array {
				return array(
					'id'       => $this->ids->generate(),
					'elType'   => 'container',
					'isInner'  => false,
					'settings' => array(
						'content_width'  => 'full',
						'flex_direction' => 'column',
						'width'          => $this->size( 32, '%' ),
						'width_tablet'   => $this->size( 48, '%' ),
						'width_mobile'   => $this->size( 100, '%' ),
					),
					'elements' => array( $widget ),
				);
			},
			$widgets
		);

		return array(
			'id'       => $this->ids->generate(),
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => array(
				'content_width'  => 'boxed',
				'boxed_width'    => $this->size( 960, 'px' ),
				'flex_direction' => 'row',
				'flex_wrap'      => 'wrap',
				'gap'            => $this->size( 16, 'px' ),
				'padding'        => $this->edge( 48, 24, 48, 24 ),
				'padding_tablet' => $this->edge( 40, 22, 40, 22 ),
				'padding_mobile' => $this->edge( 32, 18, 32, 18 ),
				'html_tag'       => 'section',
			),
			'elements' => $items,
		);
	}

	/**
	 * Create a legacy section whose image columns preserve editability.
	 *
	 * Elementor's legacy columns stack on mobile; responsive container grids
	 * use explicit tablet widths where the control is available.
	 *
	 * @param array<int,array<string,mixed>> $widgets Image widgets.
	 * @return array<string,mixed>
	 */
	private function imageGridSection( array $widgets ): array {
		$width   = max( 1, (int) floor( 100 / max( 1, count( $widgets ) ) ) );
		$columns = array_map(
			function ( array $widget ) use ( $width ): array {
				return array(
					'id'       => $this->ids->generate(),
					'elType'   => 'column',
					'isInner'  => false,
					'settings' => array(
						'_column_size' => $width,
						'_inline_size' => null,
					),
					'elements' => array( $widget ),
				);
			},
			$widgets
		);

		return array(
			'id'       => $this->ids->generate(),
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => array(
				'layout'         => 'boxed',
				'content_width'  => $this->size( 960, 'px' ),
				'padding'        => $this->edge( 48, 24, 48, 24 ),
				'padding_tablet' => $this->edge( 40, 22, 40, 22 ),
				'padding_mobile' => $this->edge( 32, 18, 32, 18 ),
				'html_tag'       => 'section',
			),
			'elements' => $columns,
		);
	}

	/**
	 * Create a container element holding the widgets.
	 *
	 * @param array<int,array<string,mixed>> $widgets Widget arrays.
	 * @param string                         $style   Group style name.
	 */
	private function container( array $widgets, string $style = self::GROUP_STYLE_DEFAULT ): array {
		return array(
			'id'       => $this->ids->generate(),
			'elType'   => 'container',
			'isInner'  => false,
			'settings' => $this->containerSettings( $style ),
			'elements' => $widgets,
		);
	}

	/**
	 * Create a legacy section with a single column holding the widgets.
	 *
	 * @param array<int,array<string,mixed>> $widgets Widget arrays.
	 * @param string                         $style   Group style name.
	 */
	private function section( array $widgets, string $style = self::GROUP_STYLE_DEFAULT ): array {
		$column = array(
			'id'       => $this->ids->generate(),
			'elType'   => 'column',
			'isInner'  => false,
			'settings' => array_merge(
				array(
					'_column_size' => 100,
					'_inline_size' => null,
				),
				$this->columnSettings( $style )
			),
			'elements' => $widgets,
		);

		return array(
			'id'       => $this->ids->generate(),
			'elType'   => 'section',
			'isInner'  => false,
			'settings' => $this->sectionSettings( $style ),
			'elements' => array( $column ),
		);
	}

	/**
	 * Get top-level container settings for a preset group style.
	 *
	 * @param string $style Group style name.
	 * @return array<string,mixed>
	 */
	private function containerSettings( string $style ): array {
		if ( self::GROUP_STYLE_HERO === $style ) {
			return array(
				'content_width'         => 'boxed',
				'boxed_width'           => $this->size( 1120, 'px' ),
				'height'                => 'min-height',
				'min_height'            => $this->size( 520, 'px' ),
				'min_height_tablet'     => $this->size( 440, 'px' ),
				'content_position'      => 'middle',
				'flex_direction'        => 'column',
				'justify_content'       => 'center',
				'align_items'           => 'center',
				'gap'                   => $this->size( 24, 'px' ),
				'background_background' => 'classic',
				'background_color'      => '#f6f8fb',
				'padding'               => $this->edge( 96, 24, 80, 24 ),
				'padding_tablet'        => $this->edge( 72, 22, 64, 22 ),
				'padding_mobile'        => $this->edge( 56, 18, 48, 18 ),
				'html_tag'              => 'section',
			);
		}

		if ( self::GROUP_STYLE_FEATURE === $style ) {
			return array(
				'content_width'  => 'boxed',
				'boxed_width'    => $this->size( 960, 'px' ),
				'flex_direction' => 'column',
				'align_items'    => 'stretch',
				'gap'            => $this->size( 18, 'px' ),
				'padding'        => $this->edge( 48, 24, 48, 24 ),
				'padding_tablet' => $this->edge( 40, 22, 40, 22 ),
				'padding_mobile' => $this->edge( 32, 18, 32, 18 ),
				'html_tag'       => 'section',
			);
		}

		return array();
	}

	/**
	 * Get legacy section settings for a preset group style.
	 *
	 * @param string $style Group style name.
	 * @return array<string,mixed>
	 */
	private function sectionSettings( string $style ): array {
		if ( self::GROUP_STYLE_HERO === $style ) {
			return array(
				'layout'                => 'boxed',
				'content_width'         => $this->size( 1120, 'px' ),
				'height'                => 'min-height',
				'custom_height'         => $this->size( 520, 'px' ),
				'content_position'      => 'middle',
				'background_background' => 'classic',
				'background_color'      => '#f6f8fb',
				'padding'               => $this->edge( 96, 24, 80, 24 ),
				'padding_tablet'        => $this->edge( 72, 22, 64, 22 ),
				'padding_mobile'        => $this->edge( 56, 18, 48, 18 ),
				'html_tag'              => 'section',
			);
		}

		if ( self::GROUP_STYLE_FEATURE === $style ) {
			return array(
				'layout'         => 'boxed',
				'content_width'  => $this->size( 960, 'px' ),
				'padding'        => $this->edge( 48, 24, 48, 24 ),
				'padding_tablet' => $this->edge( 40, 22, 40, 22 ),
				'padding_mobile' => $this->edge( 32, 18, 32, 18 ),
				'html_tag'       => 'section',
			);
		}

		return array();
	}

	/**
	 * Get legacy column settings for a preset group style.
	 *
	 * @param string $style Group style name.
	 * @return array<string,mixed>
	 */
	private function columnSettings( string $style ): array {
		if ( self::GROUP_STYLE_HERO === $style ) {
			return array(
				'content_position'      => 'center',
				'space_between_widgets' => 24,
			);
		}

		if ( self::GROUP_STYLE_FEATURE === $style ) {
			return array(
				'space_between_widgets' => 18,
			);
		}

		return array();
	}

	/**
	 * Create an Elementor dimension setting.
	 *
	 * @param int    $size Size.
	 * @param string $unit Unit.
	 * @return array{unit:string,size:int,sizes:array<int,mixed>}
	 */
	private function size( int $size, string $unit ): array {
		return array(
			'unit'  => $unit,
			'size'  => $size,
			'sizes' => array(),
		);
	}

	/**
	 * Create an Elementor edge control setting.
	 *
	 * @param int $top    Top.
	 * @param int $right  Right.
	 * @param int $bottom Bottom.
	 * @param int $left   Left.
	 * @return array{unit:string,top:string,right:string,bottom:string,left:string,isLinked:bool}
	 */
	private function edge( int $top, int $right, int $bottom, int $left ): array {
		return array(
			'unit'     => 'px',
			'top'      => (string) $top,
			'right'    => (string) $right,
			'bottom'   => (string) $bottom,
			'left'     => (string) $left,
			'isLinked' => false,
		);
	}
}
