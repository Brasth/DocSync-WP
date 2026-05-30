<?php
/**
 * Renders Google Docs API paragraphs.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Docs API paragraphs into paragraph, heading, and list HTML.
 */
final class DocsApiParagraphRenderer {
	/**
	 * Inline renderer.
	 *
	 * @var DocsApiInlineRenderer
	 */
	private DocsApiInlineRenderer $inline_renderer;

	/**
	 * Constructor.
	 *
	 * @param DocsApiInlineRenderer $inline_renderer Inline renderer.
	 */
	public function __construct( DocsApiInlineRenderer $inline_renderer ) {
		$this->inline_renderer = $inline_renderer;
	}

	/**
	 * Render a paragraph or list item.
	 *
	 * @param array<string,mixed>      $paragraph      Paragraph object.
	 * @param array<string,mixed>      $context        Document context.
	 * @param string                   $google_file_id Google Drive file ID.
	 * @param int                      $post_id        Target post ID.
	 * @param int                      $user_id        Sync owner user ID.
	 * @param array<string,mixed>|null $list_state     Current list state.
	 * @return string|WP_Error
	 */
	public function render( array $paragraph, array $context, string $google_file_id, int $post_id, int $user_id, ?array &$list_state ): string|WP_Error {
		$elements = isset( $paragraph['elements'] ) && is_array( $paragraph['elements'] ) ? $paragraph['elements'] : array();
		$inline   = $this->inline_renderer->render( $elements, $context, $google_file_id, $post_id, $user_id );

		if ( is_wp_error( $inline ) ) {
			return $inline;
		}

		if ( ! $this->hasMeaningfulHtml( $inline ) ) {
			return '';
		}

		$next_state = $this->paragraphListState( $paragraph, $context );

		if ( null !== $next_state ) {
			$prefix     = $this->ensureList( $list_state, $next_state );
			$list_state = $next_state;
			return $prefix . '<li>' . $inline . '</li>';
		}

		$html       = $this->closeList( $list_state );
		$list_state = null;

		if ( '<hr />' === trim( $inline ) ) {
			return $html . '<hr />';
		}

		$tag = $this->paragraphTag( $paragraph );

		return $html . '<' . $tag . '>' . $inline . '</' . $tag . '>';
	}

	/**
	 * Close an open list.
	 *
	 * @param array<string,mixed>|null $state List state.
	 */
	public function closeList( ?array $state ): string {
		return null === $state ? '' : '</' . $state['tag'] . '>';
	}

	/**
	 * Get the paragraph HTML tag.
	 *
	 * @param array<string,mixed> $paragraph Paragraph object.
	 */
	private function paragraphTag( array $paragraph ): string {
		$style = isset( $paragraph['paragraphStyle'] ) && is_array( $paragraph['paragraphStyle'] ) ? $paragraph['paragraphStyle'] : array();
		$type  = isset( $style['namedStyleType'] ) && is_scalar( $style['namedStyleType'] ) ? (string) $style['namedStyleType'] : '';

		return preg_match( '/^HEADING_([1-6])$/', $type, $matches ) ? 'h' . $matches[1] : 'p';
	}

	/**
	 * Get list state for a paragraph.
	 *
	 * @param array<string,mixed> $paragraph Paragraph object.
	 * @param array<string,mixed> $context   Document context.
	 * @return array{tag:string,key:string}|null
	 */
	private function paragraphListState( array $paragraph, array $context ): ?array {
		$bullet  = isset( $paragraph['bullet'] ) && is_array( $paragraph['bullet'] ) ? $paragraph['bullet'] : array();
		$list_id = isset( $bullet['listId'] ) && is_scalar( $bullet['listId'] ) ? (string) $bullet['listId'] : '';

		if ( '' === $list_id ) {
			return null;
		}

		$level      = isset( $bullet['nestingLevel'] ) ? absint( $bullet['nestingLevel'] ) : 0;
		$list       = isset( $context['lists'][ $list_id ] ) && is_array( $context['lists'][ $list_id ] ) ? $context['lists'][ $list_id ] : array();
		$levels     = $list['listProperties']['nestingLevels'] ?? array();
		$level_data = is_array( $levels ) && isset( $levels[ $level ] ) && is_array( $levels[ $level ] ) ? $levels[ $level ] : array();
		$glyph_type = isset( $level_data['glyphType'] ) && is_scalar( $level_data['glyphType'] ) ? (string) $level_data['glyphType'] : '';
		$ordered    = in_array( $glyph_type, array( 'DECIMAL', 'ZERO_DECIMAL', 'UPPER_ALPHA', 'ALPHA', 'LOWER_ALPHA', 'UPPER_ROMAN', 'ROMAN', 'LOWER_ROMAN' ), true );

		return array(
			'tag' => $ordered ? 'ol' : 'ul',
			'key' => $list_id . ':' . $level . ':' . $glyph_type,
		);
	}

	/**
	 * Ensure the current list matches the next paragraph.
	 *
	 * @param array<string,mixed>|null $current Current list state.
	 * @param array<string,mixed>      $next    Next list state.
	 */
	private function ensureList( ?array $current, array $next ): string {
		if ( null !== $current && $current['key'] === $next['key'] ) {
			return '';
		}

		return $this->closeList( $current ) . '<' . $next['tag'] . '>';
	}

	/**
	 * Whether HTML contains visible text or media.
	 *
	 * @param string $html Inline HTML.
	 */
	private function hasMeaningfulHtml( string $html ): bool {
		return '' !== trim( wp_strip_all_tags( $html ) ) || preg_match( '/<(?:img|hr)\b/i', $html ) > 0;
	}
}
