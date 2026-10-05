<?php
/**
 * Version of the shared HTML-to-content pipeline.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Bump CURRENT when pipeline output changes for unchanged Google Docs, so
 * layout fingerprints differ and each source re-converts once on its next sync.
 */
final class ConversionPipelineVersion {
	public const CURRENT = '3';
}
