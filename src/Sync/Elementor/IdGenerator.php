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
	 * Optional deterministic seed for tests.
	 *
	 * @var string
	 */
	private string $seed;

	/**
	 * Deterministic ID counter.
	 *
	 * @var int
	 */
	private int $counter = 0;

	/**
	 * Constructor.
	 *
	 * @param string $seed Optional deterministic seed.
	 */
	public function __construct( string $seed = '' ) {
		$this->seed = $seed;
	}

	/**
	 * Generate a new element ID.
	 */
	public function generate(): string {
		if ( '' !== $this->seed ) {
			++$this->counter;

			return substr( hash( 'sha256', $this->seed . ':' . $this->counter ), 0, 8 );
		}

		return bin2hex( random_bytes( 4 ) );
	}
}
