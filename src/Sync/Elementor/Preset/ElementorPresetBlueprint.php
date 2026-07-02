<?php
/**
 * Describes a built-in Elementor layout preset.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor\Preset;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable Elementor preset metadata and behavior version.
 */
final class ElementorPresetBlueprint {
	/**
	 * Preset ID.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * User-facing label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * User-facing description.
	 *
	 * @var string
	 */
	private string $description;

	/**
	 * Conversion behavior key.
	 *
	 * @var string
	 */
	private string $layout;

	/**
	 * Conversion behavior version.
	 *
	 * @var string
	 */
	private string $version;

	/**
	 * Constructor.
	 *
	 * @param string $id          Preset ID.
	 * @param string $label       User-facing label.
	 * @param string $description User-facing description.
	 * @param string $layout      Conversion behavior key.
	 * @param string $version     Conversion behavior version.
	 */
	public function __construct( string $id, string $label, string $description, string $layout, string $version = '1' ) {
		$this->id          = $id;
		$this->label       = $label;
		$this->description = $description;
		$this->layout      = $layout;
		$this->version     = $version;
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
	 * Get user-facing description.
	 */
	public function getDescription(): string {
		return $this->description;
	}

	/**
	 * Get conversion behavior key.
	 */
	public function getLayout(): string {
		return $this->layout;
	}

	/**
	 * Get stable data used to fingerprint converted output policy.
	 *
	 * @return array<string,string>
	 */
	public function getFingerprintSeed(): array {
		return array(
			'editor'  => 'elementor',
			'id'      => $this->id,
			'layout'  => $this->layout,
			'version' => $this->version,
		);
	}
}
