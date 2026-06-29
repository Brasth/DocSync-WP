<?php
/**
 * Built-in layout preset registry.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

defined( 'ABSPATH' ) || exit;

/**
 * Provides the built-in Gutenberg layout presets.
 */
final class LayoutPresetRegistry {
	public const PRESET_PLAIN_BLOCKS  = 'plain_blocks';
	public const PRESET_CLEAN_ARTICLE = 'clean_article';
	public const PRESET_DOCUMENTATION = 'documentation';

	public const DEFAULT_EXISTING_INSTALL = self::PRESET_PLAIN_BLOCKS;
	public const DEFAULT_NEW_INSTALL      = self::PRESET_CLEAN_ARTICLE;

	/**
	 * Cached presets.
	 *
	 * @var array<string,LayoutBlueprint>|null
	 */
	private ?array $presets = null;

	/**
	 * Get one preset.
	 *
	 * @param string $preset_id Preset ID.
	 */
	public function getPreset( string $preset_id ): ?LayoutBlueprint {
		$presets = $this->getPresets();

		return $presets[ $preset_id ] ?? null;
	}

	/**
	 * Get all built-in presets in UI order.
	 *
	 * @return array<string,LayoutBlueprint>
	 */
	public function getPresets(): array {
		if ( null !== $this->presets ) {
			return $this->presets;
		}

		$this->presets = array(
			self::PRESET_CLEAN_ARTICLE => new LayoutBlueprint(
				self::PRESET_CLEAN_ARTICLE,
				__( 'Clean Article', 'brasth-document-sync-for-google-docs' ),
				__( 'Best for posts and pages. Keeps headings, media, tables, and lists clean for the block editor.', 'brasth-document-sync-for-google-docs' ),
				true,
				false,
				false
			),
			self::PRESET_DOCUMENTATION => new LayoutBlueprint(
				self::PRESET_DOCUMENTATION,
				__( 'Documentation', 'brasth-document-sync-for-google-docs' ),
				__( 'Best for guides and technical docs. Keeps code examples and callout notes easier to read.', 'brasth-document-sync-for-google-docs' ),
				false,
				true,
				true,
				'2'
			),
			self::PRESET_PLAIN_BLOCKS  => new LayoutBlueprint(
				self::PRESET_PLAIN_BLOCKS,
				__( 'Plain Blocks', 'brasth-document-sync-for-google-docs' ),
				__( 'Compatibility mode for existing synced posts. Keeps output closest to earlier versions.', 'brasth-document-sync-for-google-docs' ),
				false,
				false,
				false
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
			static function ( LayoutBlueprint $preset ): array {
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
	 * Sanitize a preset ID and fall back to the upgrade-safe default.
	 *
	 * @param mixed $preset_id Raw preset ID.
	 */
	public function sanitizePresetId( mixed $preset_id ): string {
		$preset_id = sanitize_key( (string) $preset_id );

		return $this->isValidPresetId( $preset_id ) ? $preset_id : self::DEFAULT_EXISTING_INSTALL;
	}
}
