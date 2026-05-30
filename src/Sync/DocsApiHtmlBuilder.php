<?php
/**
 * Builds HTML from Google Docs API document JSON.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Converts supported Docs API structural elements into sanitized HTML.
 */
final class DocsApiHtmlBuilder {
	/**
	 * Paragraph renderer.
	 *
	 * @var DocsApiParagraphRenderer
	 */
	private DocsApiParagraphRenderer $paragraph_renderer;

	/**
	 * Document part extractor.
	 *
	 * @var DocsApiDocumentParts
	 */
	private DocsApiDocumentParts $document_parts;

	/**
	 * Constructor.
	 *
	 * @param DocsApiParagraphRenderer  $paragraph_renderer Paragraph renderer.
	 * @param DocsApiDocumentParts|null $document_parts     Document part extractor.
	 */
	public function __construct( DocsApiParagraphRenderer $paragraph_renderer, ?DocsApiDocumentParts $document_parts = null ) {
		$this->paragraph_renderer = $paragraph_renderer;
		$this->document_parts     = $document_parts ?? new DocsApiDocumentParts();
	}

	/**
	 * Build HTML for a Google Docs API document.
	 *
	 * @param array<string,mixed> $document       Docs API document.
	 * @param string              $google_file_id Google Drive file ID.
	 * @param int                 $post_id        Target post ID.
	 * @param int                 $user_id        Sync owner user ID.
	 * @param array<string,mixed> $options        Build options.
	 * @return string|WP_Error
	 */
	public function build( array $document, string $google_file_id, int $post_id, int $user_id, array $options = array() ): string|WP_Error {
		$parts             = $this->document_parts->parts( $document );
		$flush_callback    = isset( $options['flush_callback'] ) && is_callable( $options['flush_callback'] ) ? $options['flush_callback'] : null;
		$total_elements    = DocsApiHtmlBuildProgress::countElements( $parts );
		$rendered_elements = 0;
		$html              = '';

		foreach ( $parts as $part ) {
			$part_html = $this->renderPart( $part, $document, $google_file_id, $post_id, $user_id, $html, $flush_callback, $total_elements, $rendered_elements );

			if ( is_wp_error( $part_html ) ) {
				return $part_html;
			}

			$html .= $part_html;
		}

		return wp_kses_post( $html );
	}

	/**
	 * Render one document part.
	 *
	 * @param array<string,mixed> $part           Document or tab document.
	 * @param array<string,mixed> $root           Root document.
	 * @param string              $google_file_id Google Drive file ID.
	 * @param int                 $post_id        Target post ID.
	 * @param int                 $user_id        Sync owner user ID.
	 * @param string              $html_prefix    HTML rendered before this part.
	 * @param callable|null       $flush_callback Partial flush callback.
	 * @param int                 $total_elements Total renderable elements.
	 * @param int                 $rendered_elements Rendered element count.
	 * @return string|WP_Error
	 */
	private function renderPart( array $part, array $root, string $google_file_id, int $post_id, int $user_id, string $html_prefix, ?callable $flush_callback, int $total_elements, int &$rendered_elements ): string|WP_Error {
		$content = $part['body']['content'] ?? array();

		if ( ! is_array( $content ) ) {
			return '';
		}

		return $this->renderElements( $content, $this->document_parts->context( $part, $root ), $google_file_id, $post_id, $user_id, $html_prefix, $flush_callback, $total_elements, $rendered_elements );
	}

	/**
	 * Render structural elements with basic list grouping.
	 *
	 * @param array<int,array<string,mixed>> $elements       Structural elements.
	 * @param array<string,mixed>            $context        Document context.
	 * @param string                         $google_file_id Google Drive file ID.
	 * @param int                            $post_id        Target post ID.
	 * @param int                            $user_id        Sync owner user ID.
	 * @param string                         $html_prefix    HTML rendered before these elements.
	 * @param callable|null                  $flush_callback Partial flush callback.
	 * @param int                            $total_elements Total renderable elements.
	 * @param int                            $rendered_elements Rendered element count.
	 * @return string|WP_Error
	 */
	private function renderElements( array $elements, array $context, string $google_file_id, int $post_id, int $user_id, string $html_prefix, ?callable $flush_callback, int $total_elements, int &$rendered_elements ): string|WP_Error {
		$html       = '';
		$list_state = null;

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['paragraph'] ) && is_array( $element['paragraph'] ) ) {
				$rendered = $this->paragraph_renderer->render( $element['paragraph'], $context, $google_file_id, $post_id, $user_id, $list_state );
			} elseif ( isset( $element['table'] ) && is_array( $element['table'] ) ) {
				$table = $this->renderTable( $element['table'], $context, $google_file_id, $post_id, $user_id );

				if ( is_wp_error( $table ) ) {
					return $table;
				}

				$rendered   = $this->paragraph_renderer->closeList( $list_state ) . $table;
				$list_state = null;
			} else {
				$rendered = '';
			}

			if ( is_wp_error( $rendered ) ) {
				return $rendered;
			}

			$html .= $rendered;
			++$rendered_elements;

			$flushed = DocsApiHtmlBuildProgress::flushPartialHtml( $flush_callback, $html_prefix . $html . $this->paragraph_renderer->closeList( $list_state ), $rendered_elements, $total_elements );

			if ( is_wp_error( $flushed ) ) {
				return $flushed;
			}
		}

		return $html . $this->paragraph_renderer->closeList( $list_state );
	}

	/**
	 * Render a Docs table.
	 *
	 * @param array<string,mixed> $table          Table object.
	 * @param array<string,mixed> $context        Document context.
	 * @param string              $google_file_id Google Drive file ID.
	 * @param int                 $post_id        Target post ID.
	 * @param int                 $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	private function renderTable( array $table, array $context, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		$rows = isset( $table['tableRows'] ) && is_array( $table['tableRows'] ) ? $table['tableRows'] : array();
		$html = '<table><tbody>';

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$cells = isset( $row['tableCells'] ) && is_array( $row['tableCells'] ) ? $row['tableCells'] : array();
			$html .= '<tr>';

			foreach ( $cells as $cell ) {
				if ( ! is_array( $cell ) ) {
					continue;
				}

				$content         = isset( $cell['content'] ) && is_array( $cell['content'] ) ? $cell['content'] : array();
				$nested_rendered = 0;
				$inner           = $this->renderElements( $content, $context, $google_file_id, $post_id, $user_id, '', null, 1, $nested_rendered );

				if ( is_wp_error( $inner ) ) {
					return $inner;
				}

				$html .= '<td>' . $inner . '</td>';
			}

			$html .= '</tr>';
		}

		return $html . '</tbody></table>';
	}
}
