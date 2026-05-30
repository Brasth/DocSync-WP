<?php
/**
 * Renders Google Docs paragraph elements to HTML.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Docs API inline runs and inline images into safe HTML fragments.
 */
final class DocsApiInlineRenderer {
	/**
	 * Image importer.
	 *
	 * @var DocsApiImageImporter
	 */
	private DocsApiImageImporter $image_importer;

	/**
	 * Constructor.
	 *
	 * @param DocsApiImageImporter $image_importer Image importer.
	 */
	public function __construct( DocsApiImageImporter $image_importer ) {
		$this->image_importer = $image_importer;
	}

	/**
	 * Render paragraph elements.
	 *
	 * @param array<int,array<string,mixed>> $elements       Paragraph elements.
	 * @param array<string,mixed>            $context        Document context.
	 * @param string                         $google_file_id Google Drive file ID.
	 * @param int                            $post_id        Target post ID.
	 * @param int                            $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	public function render( array $elements, array $context, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		$html = '';

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$fragment = $this->renderElement( $element, $context, $google_file_id, $post_id, $user_id );

			if ( is_wp_error( $fragment ) ) {
				return $fragment;
			}

			$html .= $fragment;
		}

		return $html;
	}

	/**
	 * Render one paragraph element.
	 *
	 * @param array<string,mixed> $element        Paragraph element.
	 * @param array<string,mixed> $context        Document context.
	 * @param string              $google_file_id Google Drive file ID.
	 * @param int                 $post_id        Target post ID.
	 * @param int                 $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	private function renderElement( array $element, array $context, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		if ( isset( $element['textRun'] ) && is_array( $element['textRun'] ) ) {
			return $this->renderTextRun( $element['textRun'] );
		}

		if ( isset( $element['horizontalRule'] ) ) {
			return '<hr />';
		}

		if ( isset( $element['inlineObjectElement'] ) && is_array( $element['inlineObjectElement'] ) ) {
			return $this->renderInlineObject( $element['inlineObjectElement'], $context, $google_file_id, $post_id, $user_id );
		}

		if ( isset( $element['richLink'] ) && is_array( $element['richLink'] ) ) {
			return $this->renderRichLink( $element['richLink'] );
		}

		if ( isset( $element['person'] ) && is_array( $element['person'] ) ) {
			return esc_html( (string) ( $element['person']['personProperties']['name'] ?? '' ) );
		}

		return '';
	}

	/**
	 * Render a Docs text run.
	 *
	 * @param array<string,mixed> $text_run Text run.
	 */
	private function renderTextRun( array $text_run ): string {
		$content = isset( $text_run['content'] ) && is_scalar( $text_run['content'] ) ? (string) $text_run['content'] : '';
		$content = preg_replace( "/\n$/", '', $content );

		if ( ! is_string( $content ) || '' === $content ) {
			return '';
		}

		$html  = nl2br( esc_html( $content ), false );
		$style = isset( $text_run['textStyle'] ) && is_array( $text_run['textStyle'] ) ? $text_run['textStyle'] : array();

		if ( ! empty( $style['bold'] ) ) {
			$html = '<strong>' . $html . '</strong>';
		}

		if ( ! empty( $style['italic'] ) ) {
			$html = '<em>' . $html . '</em>';
		}

		if ( ! empty( $style['underline'] ) ) {
			$html = '<u>' . $html . '</u>';
		}

		if ( isset( $style['link']['url'] ) && is_scalar( $style['link']['url'] ) ) {
			$html = '<a href="' . esc_url( (string) $style['link']['url'] ) . '">' . $html . '</a>';
		}

		return $html;
	}

	/**
	 * Render an inline object image or fallback alt text.
	 *
	 * @param array<string,mixed> $inline         Inline object element.
	 * @param array<string,mixed> $context        Document context.
	 * @param string              $google_file_id Google Drive file ID.
	 * @param int                 $post_id        Target post ID.
	 * @param int                 $user_id        Sync owner user ID.
	 * @return string|WP_Error
	 */
	private function renderInlineObject( array $inline, array $context, string $google_file_id, int $post_id, int $user_id ): string|WP_Error {
		$inline_id = isset( $inline['inlineObjectId'] ) && is_scalar( $inline['inlineObjectId'] ) ? (string) $inline['inlineObjectId'] : '';
		$object    = '' !== $inline_id && isset( $context['inlineObjects'][ $inline_id ] ) && is_array( $context['inlineObjects'][ $inline_id ] )
			? $context['inlineObjects'][ $inline_id ]
			: array();
		$embedded  = $object['inlineObjectProperties']['embeddedObject'] ?? array();
		$alt       = $this->embeddedObjectAltText( is_array( $embedded ) ? $embedded : array() );
		$uri       = $embedded['imageProperties']['contentUri'] ?? '';

		if ( ! is_scalar( $uri ) || '' === (string) $uri ) {
			return '' !== $alt ? esc_html( $alt ) : '';
		}

		$url = $this->image_importer->import( $user_id, (string) $uri, $inline_id, $google_file_id, $post_id );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" />';
	}

	/**
	 * Render a rich link fallback.
	 *
	 * @param array<string,mixed> $rich_link Rich link object.
	 */
	private function renderRichLink( array $rich_link ): string {
		$properties = isset( $rich_link['richLinkProperties'] ) && is_array( $rich_link['richLinkProperties'] ) ? $rich_link['richLinkProperties'] : array();
		$title      = isset( $properties['title'] ) && is_scalar( $properties['title'] ) ? (string) $properties['title'] : '';
		$uri        = isset( $properties['uri'] ) && is_scalar( $properties['uri'] ) ? (string) $properties['uri'] : '';

		if ( '' === $title ) {
			return '';
		}

		return '' !== $uri ? '<a href="' . esc_url( $uri ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
	}

	/**
	 * Get available alt text from an embedded object.
	 *
	 * @param array<string,mixed> $embedded Embedded object.
	 */
	private function embeddedObjectAltText( array $embedded ): string {
		foreach ( array( 'description', 'title' ) as $key ) {
			if ( isset( $embedded[ $key ] ) && is_scalar( $embedded[ $key ] ) && '' !== trim( (string) $embedded[ $key ] ) ) {
				return sanitize_text_field( (string) $embedded[ $key ] );
			}
		}

		return '';
	}
}
