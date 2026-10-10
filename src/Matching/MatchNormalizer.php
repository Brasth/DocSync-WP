<?php
/**
 * Text normalization and similarity for post-to-Doc matching.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Matching;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes post and Google Doc text into comparable tokens.
 *
 * Normalization strips tags and shortcodes, decodes entities, applies NFKC
 * when `intl` is available, lowercases, turns punctuation and symbols into
 * spaces, and collapses whitespace. Every method is deterministic: the same
 * input always produces the same output, so matching decisions are stable.
 */
final class MatchNormalizer {
	public const LEADING_TOKENS        = 100;
	public const MIN_TOKENS            = 20;
	public const APPROXIMATE_MIN_SCORE = 0.5;
	public const INTRO_TOKENS          = 300;

	private const SCORE_PRECISION = 4;

	/**
	 * Constructor.
	 */
	public function __construct() {
	}

	/**
	 * Normalize a post title or Doc name.
	 *
	 * @param string $title Raw title.
	 */
	public function normalizeTitle( string $title ): string {
		return $this->normalizeText( $title );
	}

	/**
	 * Normalize HTML or plain text into lowercase words separated by single spaces.
	 *
	 * Normalizing an already normalized string returns it unchanged.
	 *
	 * @param string $html_or_text HTML or text.
	 */
	public function normalizeText( string $html_or_text ): string {
		$text = wp_check_invalid_utf8( $html_or_text, true );

		if ( '' === $text ) {
			return '';
		}

		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		}

		$text = $this->replacePattern( '@<(script|style)[^>]*?>.*?</\1>@si', ' ', $text );
		$text = $this->replacePattern( '/<!--.*?-->/s', ' ', $text );
		$text = $this->replacePattern( '/<\/?[a-zA-Z][^>]*>/', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( class_exists( '\Normalizer' ) ) {
			$normalized = \Normalizer::normalize( $text, \Normalizer::FORM_KC );

			if ( is_string( $normalized ) ) {
				$text = $normalized;
			}
		}

		$text = $this->lowercase( $text );
		$text = $this->replacePattern( '/\p{Cf}+/u', '', $text );
		$text = $this->replacePattern( '/[\p{P}\p{S}]+/u', ' ', $text );
		$text = $this->replacePattern( '/[\s\p{Z}\p{Cc}]+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Split HTML or text into normalized tokens.
	 *
	 * @param string $html_or_text HTML or text.
	 * @return array<int,string>
	 */
	public function tokens( string $html_or_text ): array {
		$normalized = $this->normalizeText( $html_or_text );

		if ( '' === $normalized ) {
			return array();
		}

		return explode( ' ', $normalized );
	}

	/**
	 * Key of the first 100 tokens, or null when fewer than 20 tokens exist.
	 *
	 * @param array<int,string> $tokens Normalized tokens.
	 */
	public function leadingKey( array $tokens ): ?string {
		$tokens = array_values( $tokens );

		if ( count( $tokens ) < self::MIN_TOKENS ) {
			return null;
		}

		return implode( ' ', array_slice( $tokens, 0, self::LEADING_TOKENS ) );
	}

	/**
	 * Text of every text run in a Docs API document body, as HTML-escaped text.
	 *
	 * Escaping lets `normalizeText()` treat Doc text exactly like rendered post
	 * HTML: a literal `<b>` typed in a Doc stays text instead of being stripped
	 * as a tag. Documents read with `includeTabsContent` are walked tab by tab,
	 * including child tabs, in document order.
	 *
	 * @param array<string,mixed> $docs_api_document Docs API `documents.get` response.
	 */
	public function docsText( array $docs_api_document ): string {
		$parts = array();
		$tabs  = $docs_api_document['tabs'] ?? null;

		if ( is_array( $tabs ) && array() !== $tabs ) {
			$this->collectTabs( $tabs, $parts );
		} elseif ( isset( $docs_api_document['body']['content'] ) && is_array( $docs_api_document['body']['content'] ) ) {
			$this->collectContent( $docs_api_document['body']['content'], $parts );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Larger of the title similarity and the intro token similarity, 0 to 1.
	 *
	 * @param string            $title_a  First title.
	 * @param string            $title_b  Second title.
	 * @param array<int,string> $tokens_a First normalized tokens.
	 * @param array<int,string> $tokens_b Second normalized tokens.
	 */
	public function approximateScore( string $title_a, string $title_b, array $tokens_a, array $tokens_b ): float {
		return max( $this->titleSimilarity( $title_a, $title_b ), $this->introSimilarity( $tokens_a, $tokens_b ) );
	}

	/**
	 * Symmetric `similar_text` percentage of two normalized titles, 0 to 1.
	 *
	 * @param string $title_a First title.
	 * @param string $title_b Second title.
	 */
	public function titleSimilarity( string $title_a, string $title_b ): float {
		$title_a = $this->normalizeTitle( $title_a );
		$title_b = $this->normalizeTitle( $title_b );

		if ( '' === $title_a || '' === $title_b ) {
			return 0.0;
		}

		if ( $title_a === $title_b ) {
			return 1.0;
		}

		$forward  = 0.0;
		$backward = 0.0;

		similar_text( $title_a, $title_b, $forward );
		similar_text( $title_b, $title_a, $backward );

		return round( max( $forward, $backward ) / 100, self::SCORE_PRECISION );
	}

	/**
	 * Token-set Jaccard similarity over the first 300 tokens, 0 to 1.
	 *
	 * @param array<int,string> $tokens_a First normalized tokens.
	 * @param array<int,string> $tokens_b Second normalized tokens.
	 */
	public function introSimilarity( array $tokens_a, array $tokens_b ): float {
		$set_a = array_flip( array_slice( array_values( $tokens_a ), 0, self::INTRO_TOKENS ) );
		$set_b = array_flip( array_slice( array_values( $tokens_b ), 0, self::INTRO_TOKENS ) );

		if ( array() === $set_a || array() === $set_b ) {
			return 0.0;
		}

		$shared = count( array_intersect_key( $set_a, $set_b ) );
		$union  = count( $set_a ) + count( $set_b ) - $shared;

		return $union > 0 ? round( $shared / $union, self::SCORE_PRECISION ) : 0.0;
	}

	/**
	 * Word diff of two token lists, limited to the first `$limit` tokens of each.
	 *
	 * Uses a linear-space longest-common-subsequence diff (Hirschberg). Within each
	 * changed region, deleted words come before inserted words.
	 *
	 * @param array<int,string> $tokens_a Old tokens (post).
	 * @param array<int,string> $tokens_b New tokens (Doc).
	 * @param int               $limit    Maximum tokens per side.
	 * @return array<int,array{op:string,text:string}>
	 */
	public function tokenDiff( array $tokens_a, array $tokens_b, int $limit = 2000 ): array {
		$limit    = max( 0, $limit );
		$tokens_a = array_slice( array_values( $tokens_a ), 0, $limit );
		$tokens_b = array_slice( array_values( $tokens_b ), 0, $limit );
		$ids      = array();
		$seq_a    = $this->internTokens( $tokens_a, $ids );
		$seq_b    = $this->internTokens( $tokens_b, $ids );
		$len_a    = count( $seq_a );
		$len_b    = count( $seq_b );
		$prefix   = 0;

		while ( $prefix < $len_a && $prefix < $len_b && $seq_a[ $prefix ] === $seq_b[ $prefix ] ) {
			++$prefix;
		}

		$suffix = 0;

		while (
			$suffix < $len_a - $prefix
			&& $suffix < $len_b - $prefix
			&& $seq_a[ $len_a - 1 - $suffix ] === $seq_b[ $len_b - 1 - $suffix ]
		) {
			++$suffix;
		}

		$ops = array();

		for ( $index = 0; $index < $prefix; $index++ ) {
			$ops[] = array( 'equal', $index );
		}

		$this->diffRange( $seq_a, $prefix, $len_a - $suffix, $seq_b, $prefix, $len_b - $suffix, $ops );

		for ( $index = $len_a - $suffix; $index < $len_a; $index++ ) {
			$ops[] = array( 'equal', $index );
		}

		return $this->mergeOps( $ops, $tokens_a, $tokens_b );
	}

	/**
	 * Collect text runs from tabs and their child tabs.
	 *
	 * @param array<int|string,mixed> $tabs  Docs API tabs.
	 * @param array<int,string>       $parts Collected paragraphs.
	 */
	private function collectTabs( array $tabs, array &$parts ): void {
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) ) {
				continue;
			}

			if ( isset( $tab['documentTab']['body']['content'] ) && is_array( $tab['documentTab']['body']['content'] ) ) {
				$this->collectContent( $tab['documentTab']['body']['content'], $parts );
			}

			if ( isset( $tab['childTabs'] ) && is_array( $tab['childTabs'] ) ) {
				$this->collectTabs( $tab['childTabs'], $parts );
			}
		}
	}

	/**
	 * Collect text runs from structural elements, recursing into tables and tables of contents.
	 *
	 * @param array<int|string,mixed> $content Structural elements.
	 * @param array<int,string>       $parts   Collected paragraphs.
	 */
	private function collectContent( array $content, array &$parts ): void {
		foreach ( $content as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['paragraph']['elements'] ) && is_array( $element['paragraph']['elements'] ) ) {
				$text = '';

				foreach ( $element['paragraph']['elements'] as $paragraph_element ) {
					if ( is_array( $paragraph_element ) && isset( $paragraph_element['textRun']['content'] ) && is_string( $paragraph_element['textRun']['content'] ) ) {
						$text .= $paragraph_element['textRun']['content'];
					}
				}

				if ( '' !== trim( $text ) ) {
					$parts[] = htmlspecialchars( trim( $text ), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8' );
				}

				continue;
			}

			if ( isset( $element['table']['tableRows'] ) && is_array( $element['table']['tableRows'] ) ) {
				foreach ( $element['table']['tableRows'] as $row ) {
					if ( ! is_array( $row ) || ! isset( $row['tableCells'] ) || ! is_array( $row['tableCells'] ) ) {
						continue;
					}

					foreach ( $row['tableCells'] as $cell ) {
						if ( is_array( $cell ) && isset( $cell['content'] ) && is_array( $cell['content'] ) ) {
							$this->collectContent( $cell['content'], $parts );
						}
					}
				}

				continue;
			}

			if ( isset( $element['tableOfContents']['content'] ) && is_array( $element['tableOfContents']['content'] ) ) {
				$this->collectContent( $element['tableOfContents']['content'], $parts );
			}
		}
	}

	/**
	 * Map tokens to integer IDs shared by both sides of a diff.
	 *
	 * @param array<int,string> $tokens Tokens.
	 * @param array<string,int> $ids    Token IDs, updated in place.
	 * @return array<int,int>
	 */
	private function internTokens( array $tokens, array &$ids ): array {
		$sequence = array();

		foreach ( $tokens as $token ) {
			if ( ! isset( $ids[ $token ] ) ) {
				$ids[ $token ] = count( $ids );
			}

			$sequence[] = $ids[ $token ];
		}

		return $sequence;
	}

	/**
	 * Hirschberg diff of `$seq_a[$a_lo..$a_hi)` against `$seq_b[$b_lo..$b_hi)`.
	 *
	 * Appends `[op, index]` pairs: `equal` and `delete` index into side A,
	 * `insert` indexes into side B.
	 *
	 * @param array<int,int>                   $seq_a Side A token IDs.
	 * @param int                              $a_lo  Side A start (inclusive).
	 * @param int                              $a_hi  Side A end (exclusive).
	 * @param array<int,int>                   $seq_b Side B token IDs.
	 * @param int                              $b_lo  Side B start (inclusive).
	 * @param int                              $b_hi  Side B end (exclusive).
	 * @param array<int,array{0:string,1:int}> $ops   Operations, appended in place.
	 */
	private function diffRange( array $seq_a, int $a_lo, int $a_hi, array $seq_b, int $b_lo, int $b_hi, array &$ops ): void {
		$len_a = $a_hi - $a_lo;
		$len_b = $b_hi - $b_lo;

		if ( $len_a <= 0 ) {
			for ( $index = $b_lo; $index < $b_hi; $index++ ) {
				$ops[] = array( 'insert', $index );
			}

			return;
		}

		if ( $len_b <= 0 ) {
			for ( $index = $a_lo; $index < $a_hi; $index++ ) {
				$ops[] = array( 'delete', $index );
			}

			return;
		}

		if ( 1 === $len_a ) {
			$found = -1;

			for ( $index = $b_lo; $index < $b_hi; $index++ ) {
				if ( $seq_b[ $index ] === $seq_a[ $a_lo ] ) {
					$found = $index;
					break;
				}
			}

			if ( $found < 0 ) {
				$ops[] = array( 'delete', $a_lo );

				for ( $index = $b_lo; $index < $b_hi; $index++ ) {
					$ops[] = array( 'insert', $index );
				}

				return;
			}

			for ( $index = $b_lo; $index < $found; $index++ ) {
				$ops[] = array( 'insert', $index );
			}

			$ops[] = array( 'equal', $a_lo );

			for ( $index = $found + 1; $index < $b_hi; $index++ ) {
				$ops[] = array( 'insert', $index );
			}

			return;
		}

		$middle   = $a_lo + intdiv( $len_a, 2 );
		$forward  = $this->lcsForward( $seq_a, $a_lo, $middle, $seq_b, $b_lo, $b_hi );
		$backward = $this->lcsBackward( $seq_a, $middle, $a_hi, $seq_b, $b_lo, $b_hi );
		$split    = 0;
		$best     = -1;

		for ( $offset = 0; $offset <= $len_b; $offset++ ) {
			$total = $forward[ $offset ] + $backward[ $offset ];

			if ( $total > $best ) {
				$best  = $total;
				$split = $offset;
			}
		}

		$this->diffRange( $seq_a, $a_lo, $middle, $seq_b, $b_lo, $b_lo + $split, $ops );
		$this->diffRange( $seq_a, $middle, $a_hi, $seq_b, $b_lo + $split, $b_hi, $ops );
	}

	/**
	 * LCS lengths of `$seq_a[$a_lo..$a_hi)` against every prefix of the B range.
	 *
	 * @param array<int,int> $seq_a Side A token IDs.
	 * @param int            $a_lo  Side A start.
	 * @param int            $a_hi  Side A end.
	 * @param array<int,int> $seq_b Side B token IDs.
	 * @param int            $b_lo  Side B start.
	 * @param int            $b_hi  Side B end.
	 * @return array<int,int> Index k holds the LCS length with the first k B tokens.
	 */
	private function lcsForward( array $seq_a, int $a_lo, int $a_hi, array $seq_b, int $b_lo, int $b_hi ): array {
		$len_b    = $b_hi - $b_lo;
		$previous = array_fill( 0, $len_b + 1, 0 );

		for ( $row = $a_lo; $row < $a_hi; $row++ ) {
			$current = array( 0 );
			$token   = $seq_a[ $row ];

			for ( $column = 1; $column <= $len_b; $column++ ) {
				if ( $seq_b[ $b_lo + $column - 1 ] === $token ) {
					$current[ $column ] = $previous[ $column - 1 ] + 1;
				} else {
					$current[ $column ] = max( $previous[ $column ], $current[ $column - 1 ] );
				}
			}

			$previous = $current;
		}

		return $previous;
	}

	/**
	 * LCS lengths of `$seq_a[$a_lo..$a_hi)` against every suffix of the B range.
	 *
	 * @param array<int,int> $seq_a Side A token IDs.
	 * @param int            $a_lo  Side A start.
	 * @param int            $a_hi  Side A end.
	 * @param array<int,int> $seq_b Side B token IDs.
	 * @param int            $b_lo  Side B start.
	 * @param int            $b_hi  Side B end.
	 * @return array<int,int> Index k holds the LCS length with B tokens from offset k to the end.
	 */
	private function lcsBackward( array $seq_a, int $a_lo, int $a_hi, array $seq_b, int $b_lo, int $b_hi ): array {
		$len_b    = $b_hi - $b_lo;
		$previous = array_fill( 0, $len_b + 1, 0 );

		for ( $row = $a_hi - 1; $row >= $a_lo; $row-- ) {
			$current = array_fill( 0, $len_b + 1, 0 );
			$token   = $seq_a[ $row ];

			for ( $column = $len_b - 1; $column >= 0; $column-- ) {
				if ( $seq_b[ $b_lo + $column ] === $token ) {
					$current[ $column ] = $previous[ $column + 1 ] + 1;
				} else {
					$current[ $column ] = max( $previous[ $column ], $current[ $column + 1 ] );
				}
			}

			$previous = $current;
		}

		return $previous;
	}

	/**
	 * Merge raw operations into text segments; deletes precede inserts in each change.
	 *
	 * @param array<int,array{0:string,1:int}> $ops      Raw operations.
	 * @param array<int,string>                $tokens_a Side A tokens.
	 * @param array<int,string>                $tokens_b Side B tokens.
	 * @return array<int,array{op:string,text:string}>
	 */
	private function mergeOps( array $ops, array $tokens_a, array $tokens_b ): array {
		$segments = array();
		$equal    = array();
		$deleted  = array();
		$inserted = array();

		foreach ( $ops as $op ) {
			if ( 'equal' === $op[0] ) {
				$this->flushChange( $segments, $deleted, $inserted );
				$equal[] = $tokens_a[ $op[1] ];
				continue;
			}

			$this->flushEqual( $segments, $equal );

			if ( 'delete' === $op[0] ) {
				$deleted[] = $tokens_a[ $op[1] ];
			} else {
				$inserted[] = $tokens_b[ $op[1] ];
			}
		}

		$this->flushEqual( $segments, $equal );
		$this->flushChange( $segments, $deleted, $inserted );

		return $segments;
	}

	/**
	 * Append a pending equal run.
	 *
	 * @param array<int,array{op:string,text:string}> $segments Segments.
	 * @param array<int,string>                       $equal    Pending equal tokens, cleared.
	 */
	private function flushEqual( array &$segments, array &$equal ): void {
		if ( array() !== $equal ) {
			$segments[] = array(
				'op'   => 'equal',
				'text' => implode( ' ', $equal ),
			);
			$equal      = array();
		}
	}

	/**
	 * Append a pending change: deleted words first, then inserted words.
	 *
	 * @param array<int,array{op:string,text:string}> $segments Segments.
	 * @param array<int,string>                       $deleted  Pending deleted tokens, cleared.
	 * @param array<int,string>                       $inserted Pending inserted tokens, cleared.
	 */
	private function flushChange( array &$segments, array &$deleted, array &$inserted ): void {
		if ( array() !== $deleted ) {
			$segments[] = array(
				'op'   => 'delete',
				'text' => implode( ' ', $deleted ),
			);
			$deleted    = array();
		}

		if ( array() !== $inserted ) {
			$segments[] = array(
				'op'   => 'insert',
				'text' => implode( ' ', $inserted ),
			);
			$inserted   = array();
		}
	}

	/**
	 * Lowercase UTF-8 text.
	 *
	 * Without `mbstring` only ASCII letters are folded, byte-safely and
	 * independent of the locale. Other letters keep their case, so text that
	 * differs only in non-ASCII case stops matching exactly: a missing extension
	 * can only remove exact matches, never add them.
	 *
	 * @param string $text UTF-8 text.
	 */
	private function lowercase( string $text ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $text, 'UTF-8' );
		}

		return strtr( $text, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * `preg_replace` that keeps the input when the pattern fails.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject     Subject.
	 */
	private function replacePattern( string $pattern, string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );

		return is_string( $result ) ? $result : $subject;
	}
}
