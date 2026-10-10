<?php
/**
 * Renders canonical documents through the shared layout pipeline.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Sync\Layout\LayoutConversionService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a canonical document into sanitized HTML and Gutenberg blocks.
 *
 * Preview and commit call the same methods with the same effective document,
 * options, and resolved preset; only the image URLs differ (private asset
 * route versus new attachment URLs). Images whose asset is still waiting for
 * a browser render become an empty figure marked with
 * `data-docsync-pending-asset`.
 */
final class CanonicalRenderer {
	public const RENDERER_VERSION = '1';

	private const PENDING_URL_PREFIX = 'https://docsync-wp-pending.invalid/';

	/**
	 * Layout conversion service.
	 *
	 * @var LayoutConversionService
	 */
	private LayoutConversionService $layout;

	/**
	 * Constructor.
	 *
	 * @param LayoutConversionService $layout Layout conversion service.
	 */
	public function __construct( LayoutConversionService $layout ) {
		$this->layout = $layout;
	}

	/**
	 * Resolve a preset option to a concrete Gutenberg preset ID.
	 *
	 * @param string $layout_preset Preset option; '' means the site default.
	 */
	public function resolvePreset( string $layout_preset ): string {
		return $this->layout->resolvePresetForSource( array( 'layout_preset' => $layout_preset ) );
	}

	/**
	 * Render sanitized HTML for a canonical document.
	 *
	 * @param CanonicalDocument    $document   Effective document.
	 * @param array<string,string> $asset_urls Ready asset URLs keyed by asset ID.
	 */
	public function renderHtml( CanonicalDocument $document, array $asset_urls ): string {
		$assets = array();

		foreach ( $document->getAssets() as $asset ) {
			$assets[ $asset['assetId'] ] = $asset;
		}

		$html = '';

		foreach ( $document->getSections() as $section ) {
			if ( 'notes' === $section['kind'] ) {
				$html .= $this->renderNotes( $section['blocks'], $assets, $asset_urls );
				continue;
			}

			foreach ( $section['blocks'] as $block ) {
				$html .= $this->renderBlock( $block, $assets, $asset_urls );
			}
		}

		return wp_kses_post( $html );
	}

	/**
	 * Render serialized Gutenberg blocks with the resolved preset.
	 *
	 * @param CanonicalDocument    $document      Effective document.
	 * @param array<string,string> $asset_urls    Ready asset URLs keyed by asset ID.
	 * @param string               $layout_preset Resolved preset ID.
	 * @return string|WP_Error
	 */
	public function renderBlocks( CanonicalDocument $document, array $asset_urls, string $layout_preset ): string|WP_Error {
		$markup = $this->layout->convert( $this->renderHtml( $document, $asset_urls ), $this->resolvePreset( $layout_preset ) );

		if ( is_wp_error( $markup ) || ! str_contains( $markup, self::PENDING_URL_PREFIX ) ) {
			return $markup;
		}

		return serialize_blocks( $this->replacePendingImages( parse_blocks( $markup ) ) );
	}

	/**
	 * Fingerprint of everything that decides the rendered blocks.
	 *
	 * @param CanonicalDocument   $effective_document Effective document.
	 * @param array<string,mixed> $options            Normalized options.
	 * @param string              $layout_preset      Resolved preset ID.
	 */
	public function previewFingerprint( CanonicalDocument $effective_document, array $options, string $layout_preset ): string {
		$preset = $this->resolvePreset( $layout_preset );

		return hash(
			'sha256',
			implode(
				"\n",
				array(
					hash( 'sha256', (string) wp_json_encode( $effective_document->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) ),
					hash( 'sha256', (string) wp_json_encode( $this->sortRecursive( $options ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ),
					$preset,
					$this->layout->fingerprintForPreset( $preset ),
					self::RENDERER_VERSION,
				)
			)
		);
	}

	/**
	 * Render speaker notes as one quote.
	 *
	 * @param array<int,array<string,mixed>> $blocks     Notes blocks.
	 * @param array<string,array>            $assets     Assets by ID.
	 * @param array<string,string>           $asset_urls Ready asset URLs.
	 */
	private function renderNotes( array $blocks, array $assets, array $asset_urls ): string {
		$quote = '';
		$after = '';

		foreach ( $blocks as $block ) {
			if ( 'paragraph' === $block['type'] ) {
				$quote .= '<p>' . $this->renderRuns( $block['runs'] ) . '</p>';
			} else {
				$after .= $this->renderBlock( $block, $assets, $asset_urls );
			}
		}

		return ( '' !== $quote ? '<blockquote>' . $quote . '</blockquote>' : '' ) . $after;
	}

	/**
	 * Render one block.
	 *
	 * @param array<string,mixed>  $block      Block.
	 * @param array<string,array>  $assets     Assets by ID.
	 * @param array<string,string> $asset_urls Ready asset URLs.
	 */
	private function renderBlock( array $block, array $assets, array $asset_urls ): string {
		switch ( $block['type'] ) {
			case 'heading':
				$level = max( 1, min( 6, (int) $block['level'] ) );

				return '<h' . $level . '>' . $this->renderRuns( $block['runs'] ) . '</h' . $level . '>';

			case 'paragraph':
				$inner = $this->renderRuns( $block['runs'] );

				return '' === trim( wp_strip_all_tags( $inner ) ) ? '' : '<p>' . $inner . '</p>';

			case 'list':
				return $this->renderList( $block['items'], (bool) $block['ordered'] );

			case 'table':
				return $this->renderTable( $block['rows'] );

			case 'image':
				return $this->renderImage( $block, $assets, $asset_urls );
		}

		return '';
	}

	/**
	 * Render runs as inline HTML.
	 *
	 * @param array<int,array<string,mixed>> $runs Runs.
	 */
	private function renderRuns( array $runs ): string {
		$html = '';

		foreach ( $runs as $run ) {
			$text = str_replace( "\n", '<br />', esc_html( (string) $run['text'] ) );

			if ( '' === $text ) {
				continue;
			}

			if ( ! empty( $run['code'] ) ) {
				$text = '<code>' . $text . '</code>';
			}

			if ( ! empty( $run['strike'] ) ) {
				$text = '<s>' . $text . '</s>';
			}

			if ( ! empty( $run['underline'] ) ) {
				$text = '<u>' . $text . '</u>';
			}

			if ( ! empty( $run['italic'] ) ) {
				$text = '<em>' . $text . '</em>';
			}

			if ( ! empty( $run['bold'] ) ) {
				$text = '<strong>' . $text . '</strong>';
			}

			if ( isset( $run['link'] ) && '' !== $run['link'] ) {
				$text = '<a href="' . esc_url( (string) $run['link'], array( 'http', 'https' ) ) . '">' . $text . '</a>';
			}

			$html .= $text;
		}

		return $html;
	}

	/**
	 * Render a (nested) list.
	 *
	 * @param array<int,array<string,mixed>> $items   Items.
	 * @param bool                           $ordered Ordered list.
	 */
	private function renderList( array $items, bool $ordered ): string {
		$tag  = $ordered ? 'ol' : 'ul';
		$html = '<' . $tag . '>';

		foreach ( $items as $item ) {
			$html .= '<li>' . $this->renderRuns( $item['runs'] );

			if ( array() !== $item['children'] ) {
				$html .= $this->renderList( $item['children'], $ordered );
			}

			$html .= '</li>';
		}

		return $html . '</' . $tag . '>';
	}

	/**
	 * Render a table with header cells and spans.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows.
	 */
	private function renderTable( array $rows ): string {
		$head = '';
		$body = '';

		foreach ( $rows as $index => $row ) {
			$cells     = '';
			$all_heads = array() !== $row['cells'];

			foreach ( $row['cells'] as $cell ) {
				$tag        = $cell['header'] ? 'th' : 'td';
				$all_heads  = $all_heads && $cell['header'];
				$attributes = '';

				if ( $cell['colSpan'] > 1 ) {
					$attributes .= ' colspan="' . absint( $cell['colSpan'] ) . '"';
				}

				if ( $cell['rowSpan'] > 1 ) {
					$attributes .= ' rowspan="' . absint( $cell['rowSpan'] ) . '"';
				}

				$cells .= '<' . $tag . $attributes . '>' . $this->renderRuns( $cell['runs'] ) . '</' . $tag . '>';
			}

			if ( 0 === $index && $all_heads ) {
				$head .= '<tr>' . $cells . '</tr>';
			} else {
				$body .= '<tr>' . $cells . '</tr>';
			}
		}

		return '<table>' . ( '' !== $head ? '<thead>' . $head . '</thead>' : '' ) . '<tbody>' . $body . '</tbody></table>';
	}

	/**
	 * Render an image figure, or a pending-render placeholder.
	 *
	 * @param array<string,mixed>  $block      Image block.
	 * @param array<string,array>  $assets     Assets by ID.
	 * @param array<string,string> $asset_urls Ready asset URLs.
	 */
	private function renderImage( array $block, array $assets, array $asset_urls ): string {
		$asset_id = (string) $block['assetId'];
		$asset    = $assets[ $asset_id ] ?? null;

		if ( null === $asset || 'rejected' === $asset['status'] ) {
			return '';
		}

		$url = 'ready' === $asset['status'] && isset( $asset_urls[ $asset_id ] ) && '' !== $asset_urls[ $asset_id ]
			? $asset_urls[ $asset_id ]
			: self::PENDING_URL_PREFIX . $asset_id . '.png';

		$html = '<figure><img src="' . esc_url( $url ) . '" alt="' . esc_attr( (string) $block['alt'] ) . '" />';

		if ( null !== $block['caption'] && '' !== $block['caption'] ) {
			$html .= '<figcaption>' . esc_html( (string) $block['caption'] ) . '</figcaption>';
		}

		return $html . '</figure>';
	}

	/**
	 * Replace pending-render image blocks with marked empty figures.
	 *
	 * @param array<int,array<string,mixed>> $blocks Parsed blocks.
	 * @return array<int,array<string,mixed>>
	 */
	private function replacePendingImages( array $blocks ): array {
		foreach ( $blocks as $index => $block ) {
			$url = isset( $block['attrs']['url'] ) ? (string) $block['attrs']['url'] : '';

			if ( 'core/image' === ( $block['blockName'] ?? '' ) && str_starts_with( $url, self::PENDING_URL_PREFIX ) ) {
				$asset_id = basename( substr( $url, strlen( self::PENDING_URL_PREFIX ) ), '.png' );
				$html     = '<figure class="docsync-wp-pending-asset" data-docsync-pending-asset="' . esc_attr( $asset_id ) . '"></figure>';

				$blocks[ $index ] = array(
					'blockName'    => 'core/html',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $html,
					'innerContent' => array( $html ),
				);
				continue;
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$blocks[ $index ]['innerBlocks'] = $this->replacePendingImages( $block['innerBlocks'] );
			}
		}

		return $blocks;
	}

	/**
	 * Sort associative arrays recursively so equal options hash equally.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private function sortRecursive( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		foreach ( $value as $key => $child ) {
			$value[ $key ] = $this->sortRecursive( $child );
		}

		return $value;
	}
}
