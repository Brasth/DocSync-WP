<?php
/**
 * Decides whether a given post should use Elementor sync.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\SourceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Combines global settings, per-post preference, and Elementor detection.
 */
final class SyncDecider {
	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Compatibility checker.
	 *
	 * @var CompatibilityChecker
	 */
	private CompatibilityChecker $compatibility;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository   $settings      Settings repository.
	 * @param SourceRepository     $sources       Source repository.
	 * @param CompatibilityChecker $compatibility Compatibility checker.
	 */
	public function __construct(
		SettingsRepository $settings,
		SourceRepository $sources,
		CompatibilityChecker $compatibility
	) {
		$this->settings      = $settings;
		$this->sources       = $sources;
		$this->compatibility = $compatibility;
	}

	/**
	 * Whether Elementor sync is available at all.
	 */
	public function isElementorSyncAvailable(): bool {
		if ( ! $this->compatibility->isElementorActive() ) {
			return false;
		}

		return $this->settings->get()['elementor_sync_enabled'] ?? false;
	}

	/**
	 * Whether the post should be synced into an Elementor layout.
	 *
	 * @param int $post_id Post ID.
	 */
	public function shouldUseElementor( int $post_id ): bool {
		if ( ! $this->isElementorSyncAvailable() ) {
			return false;
		}

		$preference = $this->sources->getElementorSync( $post_id );

		if ( null !== $preference ) {
			return $preference;
		}

		return $this->compatibility->isBuiltWithElementor( $post_id );
	}

	/**
	 * Whether the default per-post preference for a new post should be enabled.
	 *
	 * Returns true only when the post is already Elementor-built, so new drafts
	 * stay as block editor unless the user explicitly toggles the option.
	 *
	 * @param int $post_id Post ID.
	 */
	public function getDefaultElementorSync( int $post_id ): bool {
		if ( ! $this->isElementorSyncAvailable() ) {
			return false;
		}

		return $this->compatibility->isBuiltWithElementor( $post_id );
	}
}
