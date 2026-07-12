<?php
/**
 * Describes a built-in layout preset.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable layout preset metadata and behavior switches.
 */
final class LayoutBlueprint {
	/**
	 * Preset ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * User-facing preset label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Short user-facing preset description.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Conversion behavior version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Whether eligible standalone images should render as collections.
	 *
	 * @var bool
	 */
	private bool $render_image_collections;

	/**
	 * Whether H1 content headings should become H2 blocks.
	 *
	 * @var bool
	 */
	private bool $demote_top_level_headings;

	/**
	 * Whether pre/code elements should render as core/code blocks.
	 *
	 * @var bool
	 */
	private bool $render_code_blocks;

	/**
	 * Whether callout-like elements should render as quote callout blocks.
	 *
	 * @var bool
	 */
	private bool $render_callouts;

	/**
	 * Constructor.
	 *
	 * @param string $id                        Preset ID.
	 * @param string $label                     User-facing label.
	 * @param string $description               Short user-facing description.
	 * @param bool   $demote_top_level_headings Whether H1 content headings should become H2 blocks.
	 * @param bool   $render_code_blocks        Whether code blocks should use core/code.
	 * @param bool   $render_callouts           Whether callouts should use quote blocks.
	 * @param string $version                   Conversion behavior version.
	 * @param bool   $render_image_collections  Whether standalone image collections should render as galleries.
	 */
	public function __construct(
		string $id,
		string $label,
		string $description,
		bool $demote_top_level_headings,
		bool $render_code_blocks,
		bool $render_callouts,
		string $version = '1',
		bool $render_image_collections = false
	) {
		$this->id                        = $id;
		$this->label                     = $label;
		$this->description               = $description;
		$this->demote_top_level_headings = $demote_top_level_headings;
		$this->render_code_blocks        = $render_code_blocks;
		$this->render_callouts           = $render_callouts;
		$this->version                   = $version;
		$this->render_image_collections  = $render_image_collections;
	}

	/**
	 * Get preset ID.
	 */
	public function getId(): string {
		return $this->id;
	}

	/**
	 * Get user-facing label.
	 */
	public function getLabel(): string {
		return $this->label;
	}

	/**
	 * Get short user-facing description.
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * Whether H1 content headings should become H2 blocks.
	 */
	public function shouldDemoteTopLevelHeadings(): bool {
		return $this->demote_top_level_headings;
	}

	/**
	 * Whether pre/code elements should render as core/code blocks.
	 */
	public function shouldRenderCodeBlocks(): bool {
		return $this->render_code_blocks;
	}

	/**
	 * Whether callout-like elements should render as quote callout blocks.
	 */
	public function shouldRenderCallouts(): bool {
		return $this->render_callouts;
	}

	/**
	 * Whether eligible standalone images should render as collections.
	 */
	public function shouldRenderImageCollections(): bool {
		return $this->render_image_collections;
	}

	/**
	 * Get stable data used to fingerprint converted output policy.
	 *
	 * @return array<string,mixed>
	 */
	public function getFingerprintSeed(): array {
		return array(
			'id'                        => $this->id,
			'version'                   => $this->version,
			'demote_top_level_headings' => $this->demote_top_level_headings,
			'render_code_blocks'        => $this->render_code_blocks,
			'render_callouts'           => $this->render_callouts,
			'render_image_collections'  => $this->render_image_collections,
		);
	}
}
