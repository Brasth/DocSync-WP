<?php
/**
 * Imports Google Docs API JSON as sanitized HTML.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Google\DocsClient;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates Docs API document reads and HTML conversion.
 */
final class DocsApiHtmlImporter {
	/**
	 * Docs API client.
	 *
	 * @var DocsClient
	 */
	private DocsClient $docs_client;

	/**
	 * HTML builder.
	 *
	 * @var DocsApiHtmlBuilder
	 */
	private DocsApiHtmlBuilder $html_builder;

	/**
	 * Constructor.
	 *
	 * @param DocsClient         $docs_client  Docs API client.
	 * @param DocsApiHtmlBuilder $html_builder HTML builder.
	 */
	public function __construct( DocsClient $docs_client, DocsApiHtmlBuilder $html_builder ) {
		$this->docs_client  = $docs_client;
		$this->html_builder = $html_builder;
	}

	/**
	 * Import a Google Doc through the Docs API fallback.
	 *
	 * @param int    $user_id        Sync owner user ID.
	 * @param string $google_file_id Google Drive file ID.
	 * @param int    $post_id        Target post ID.
	 * @return string|WP_Error
	 */
	public function import( int $user_id, string $google_file_id, int $post_id ): string|WP_Error {
		$document = $this->docs_client->getDocument( $user_id, $google_file_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		return $this->html_builder->build( $document, $google_file_id, $post_id, $user_id );
	}
}
