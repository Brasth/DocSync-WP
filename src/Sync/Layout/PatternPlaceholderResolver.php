<?php
/**
 * Resolves {{pattern: name}} placeholders to synced or registered WordPress patterns.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

use WP_Block_Patterns_Registry;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Looks up published synced patterns by exact title, then registered patterns by slug.
 */
final class PatternPlaceholderResolver {
	/**
	 * Replace placeholders in converted block markup.
	 *
	 * @param string $markup Converted block markup.
	 * @return array{markup:string,unresolved:array<int,string>}
	 */
	public function resolve( string $markup ): array {
		return PatternPlaceholderParser::replace( $markup, array( $this, 'blockMarkupFor' ) );
	}

	/**
	 * Replacement block markup for a pattern name, or null when unknown.
	 *
	 * @param string $name Pattern title or registered slug.
	 */
	public function blockMarkupFor( string $name ): ?string {
		$query = new WP_Query(
			array(
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'post_status'    => 'publish',
				'post_type'      => 'wp_block',
				'posts_per_page' => 1,
				'title'          => $name,
			)
		);

		if ( isset( $query->posts[0] ) ) {
			return '<!-- wp:block {"ref":' . absint( $query->posts[0] ) . '} /-->';
		}

		if ( class_exists( WP_Block_Patterns_Registry::class ) && WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
			return '<!-- wp:pattern {"slug":' . wp_json_encode( $name ) . '} /-->';
		}

		return null;
	}
}
