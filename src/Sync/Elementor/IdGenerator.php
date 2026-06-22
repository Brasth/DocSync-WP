<?php
/**
 * Generates unique Elementor element IDs.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor element IDs are 8-character hex strings.
 */
final class IdGenerator {
	/**
	 * Generate a new element ID.
	 */
	public function generate(): string {
		return bin2hex( random_bytes( 4 ) );
	}
}
