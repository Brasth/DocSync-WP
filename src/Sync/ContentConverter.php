<?php
/**
 * Markdown to WordPress-safe HTML conversion.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use League\CommonMark\CommonMarkConverter;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Converts exported Google Docs Markdown into sanitized post content.
 */
final class ContentConverter {
	/**
	 * Markdown converter.
	 *
	 * @var CommonMarkConverter
	 */
	private CommonMarkConverter $converter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->converter = new CommonMarkConverter(
			array(
				'html_input'              => 'strip',
				'allow_unsafe_links'      => false,
				'max_nesting_level'       => 50,
				'max_delimiters_per_line' => 1000,
			)
		);
	}

	/**
	 * Convert Markdown to sanitized HTML.
	 *
	 * @param string $markdown Markdown content.
	 * @return string|WP_Error
	 */
	public function convertMarkdown( string $markdown ): string|WP_Error {
		try {
			$html = $this->converter->convert( $markdown )->getContent();
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'docsync_wp_markdown_conversion_failed',
				__( 'DocSync WP could not convert this Google Doc export.', 'docsync-wp' ),
				array( 'status' => 500 )
			);
		}

		return wp_kses_post( $html );
	}
}
