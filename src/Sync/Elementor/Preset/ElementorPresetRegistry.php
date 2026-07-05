<?php
/**
 * Built-in Elementor preset registry.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor\Preset;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the built-in Elementor layout presets.
 */
final class ElementorPresetRegistry {
	public const PRESET_HERO_PAGE     = 'elementor_hero_page';
	public const PRESET_FEATURE_BLOCK = 'elementor_feature_block';
	public const DEFAULT_PRESET       = self::PRESET_FEATURE_BLOCK;

	/**
	 * Cached presets.
	 *
	 * @var array<string,ElementorPresetBlueprint>|null
	 */
	private ?array $presets = null;

	/**
	 * Get one preset.
	 *
	 * @param string $preset_id Preset ID.
	 */
	public function getPreset( string $preset_id ): ?ElementorPresetBlueprint {
		$presets = $this->getPresets();

		return $presets[ $preset_id ] ?? null;
	}

	/**
	 * Get all built-in presets in UI order.
	 *
	 * @return array<string,ElementorPresetBlueprint>
	 */
	public function getPresets(): array {
		if ( null !== $this->presets ) {
			return $this->presets;
		}

		$this->presets = array(
			self::PRESET_HERO_PAGE     => new ElementorPresetBlueprint(
				self::PRESET_HERO_PAGE,
				__( 'Elementor Hero Page', 'brasth-document-sync-for-google-docs' ),
				__( 'Builds a page-style Elementor layout with the first heading, intro paragraph, and image as the hero section.', 'brasth-document-sync-for-google-docs' ),
				'hero_page',
				'3'
			),
			self::PRESET_FEATURE_BLOCK => new ElementorPresetBlueprint(
				self::PRESET_FEATURE_BLOCK,
				__( 'Elementor Feature Block', 'brasth-document-sync-for-google-docs' ),
				__( 'Builds clean Elementor sections from document headings, text, lists, media, tables, and dividers.', 'brasth-document-sync-for-google-docs' ),
				'feature_block',
				'3'
			),
		);

		return $this->presets;
	}

	/**
	 * Get preset summaries safe for REST responses.
	 *
	 * @return array<int,array{id:string,label:string,description:string}>
	 */
	public function getAvailablePresets(): array {
		return array_map(
			static function ( ElementorPresetBlueprint $preset ): array {
				return array(
					'id'          => $preset->getId(),
					'label'       => $preset->getLabel(),
					'description' => $preset->getDescription(),
				);
			},
			array_values( $this->getPresets() )
		);
	}

	/**
	 * Whether a preset ID is known.
	 *
	 * @param string $preset_id Preset ID.
	 */
	public function isValidPresetId( string $preset_id ): bool {
		return null !== $this->getPreset( $preset_id );
	}

	/**
	 * Sanitize a preset ID and fall back to the Elementor default.
	 *
	 * @param mixed $preset_id Raw preset ID.
	 */
	public function sanitizePresetId( mixed $preset_id ): string {
		$preset_id = sanitize_key( (string) $preset_id );

		return $this->isValidPresetId( $preset_id ) ? $preset_id : self::DEFAULT_PRESET;
	}
}
