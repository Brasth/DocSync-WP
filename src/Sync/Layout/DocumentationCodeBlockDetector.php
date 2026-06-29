<?php
/**
 * Detects documentation-style code paragraphs from Google Docs exports.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Layout;

use DOMElement;
use DOMNode;
use DOMXPath;

defined( 'ABSPATH' ) || exit;

/**
 * Heuristics for code-like paragraphs in the Documentation layout preset.
 */
final class DocumentationCodeBlockDetector {
	/**
	 * Detect a fenced or code-like paragraph run.
	 *
	 * @param array<int,DOMNode> $children          Container children.
	 * @param int                $start             Start index.
	 * @param callable           $is_code_candidate Candidate checker.
	 * @return array{code:string,end:int}|null
	 */
	public function detectCodeRun( array $children, int $start, callable $is_code_candidate ): ?array {
		$fenced_run = $this->collectFencedCodeRun( $children, $start, $is_code_candidate );

		return $fenced_run ?? $this->collectCodeLikeRun( $children, $start, $is_code_candidate );
	}

	/**
	 * Return normalized code text for a paragraph candidate.
	 *
	 * @param DOMElement $element     Element.
	 * @param bool       $allow_blank Whether empty text is valid.
	 */
	public function paragraphCodeText( DOMElement $element, bool $allow_blank = false ): ?string {
		if ( ! $this->isParagraphCandidate( $element ) ) {
			return null;
		}

		$text = str_replace( "\r\n", "\n", $element->textContent );
		$text = str_replace( "\r", "\n", $text );
		$text = str_replace( html_entity_decode( '&nbsp;', ENT_QUOTES, 'UTF-8' ), ' ', $text );

		$lines = array_map(
			static fn ( string $line ): string => rtrim( $line ),
			explode( "\n", $text )
		);
		$text  = implode( "\n", $lines );

		return '' === trim( $text ) && ! $allow_blank ? null : $text;
	}

	/**
	 * Whether the text is a Markdown-style code fence line.
	 *
	 * @param string $text Text.
	 */
	public function isFenceLine( string $text ): bool {
		return (bool) preg_match( '/^\s*(?:`{3,}|~{3,})(?:[A-Za-z0-9_-]+)?\s*$/', $text );
	}

	/**
	 * Whether a paragraph is strong enough to stand alone as a code block.
	 *
	 * @param string $text Normalized paragraph text.
	 */
	public function isStrongCodeLine( string $text ): bool {
		$trimmed = trim( $text );

		return $this->looksLikeShellCommand( $trimmed )
			|| $this->looksLikeMarkup( $trimmed )
			|| $this->looksLikeJson( $trimmed )
			|| $this->looksLikeProgrammingStatement( $trimmed )
			|| $this->looksLikeGherkinHeader( $trimmed )
			|| $this->looksLikePathOrTreeLine( $trimmed );
	}

	/**
	 * Whether a paragraph can participate in a grouped code block.
	 *
	 * @param string $text Normalized paragraph text.
	 */
	public function isCodeLikeLine( string $text ): bool {
		$trimmed = trim( $text );

		if ( '' === $trimmed ) {
			return false;
		}

		if (
			$this->isStrongCodeLine( $text )
			|| $this->looksLikeGherkinStep( $trimmed )
			|| $this->looksLikeKarateStep( $trimmed )
			|| $this->isTripleQuoteLine( $trimmed )
		) {
			return true;
		}

		if ( preg_match( '/^\s+/', $text ) ) {
			return $this->hasCodeSyntaxHint( $trimmed );
		}

		return (bool) preg_match( '/^[}\]\);,{]+$/', $trimmed );
	}

	/**
	 * Collect a Markdown fenced code block from paragraph children.
	 *
	 * @param array<int,DOMNode> $children          Container children.
	 * @param int                $start             Start index.
	 * @param callable           $is_code_candidate Candidate checker.
	 * @return array{code:string,end:int}|null
	 */
	private function collectFencedCodeRun( array $children, int $start, callable $is_code_candidate ): ?array {
		$text = $this->paragraphTextAt( $children, $start, $is_code_candidate );

		if ( null === $text || ! $this->isFenceLine( $text ) ) {
			return null;
		}

		$lines = array();
		$count = count( $children );

		for ( $index = $start + 1; $index < $count; ++$index ) {
			$next_text = $this->paragraphTextAt( $children, $index, $is_code_candidate, true );

			if ( null === $next_text ) {
				return null;
			}

			if ( $this->isFenceLine( $next_text ) ) {
				return array(
					'code' => rtrim( implode( "\n", $lines ) ),
					'end'  => $index,
				);
			}

			$lines[] = $next_text;
		}

		return null;
	}

	/**
	 * Collect consecutive code-like paragraphs.
	 *
	 * @param array<int,DOMNode> $children          Container children.
	 * @param int                $start             Start index.
	 * @param callable           $is_code_candidate Candidate checker.
	 * @return array{code:string,end:int}|null
	 */
	private function collectCodeLikeRun( array $children, int $start, callable $is_code_candidate ): ?array {
		$lines           = array();
		$has_strong      = false;
		$count           = count( $children );
		$end             = $start - 1;
		$in_shell_run    = false;
		$in_quoted_block = false;

		for ( $index = $start; $index < $count; ++$index ) {
			$text = $this->paragraphTextAt( $children, $index, $is_code_candidate, $in_quoted_block );

			if ( null === $text ) {
				break;
			}

			$trimmed = trim( $text );

			if ( $in_quoted_block ) {
				$lines[]         = $text;
				$in_quoted_block = ! $this->isTripleQuoteLine( $trimmed );
				$end             = $index;
				continue;
			}

			$is_code_like         = $this->isCodeLikeLine( $text );
			$is_shell_continuing  = $in_shell_run && $this->looksLikeShellContinuationLine( $trimmed );
			$is_triple_quote_line = $this->isTripleQuoteLine( $trimmed );

			if ( ! $is_code_like && ! $is_shell_continuing ) {
				break;
			}

			$lines[]         = $text;
			$has_strong      = $has_strong || $this->isStrongCodeLine( $text );
			$end             = $index;
			$in_quoted_block = $is_triple_quote_line;
			$in_shell_run    = (
				$this->looksLikeShellCommand( $trimmed )
				|| $is_shell_continuing
			) && $this->endsWithShellLineContinuation( $trimmed );
		}

		if ( $in_quoted_block || array() === $lines || ( 1 === count( $lines ) && ! $has_strong ) ) {
			return null;
		}

		return array(
			'code' => rtrim( implode( "\n", $lines ) ),
			'end'  => $end,
		);
	}

	/**
	 * Return normalized text at a child index when the child is eligible.
	 *
	 * @param array<int,DOMNode> $children          Container children.
	 * @param int                $index             Child index.
	 * @param callable           $is_code_candidate Candidate checker.
	 * @param bool               $allow_blank       Whether empty text is valid.
	 */
	private function paragraphTextAt( array $children, int $index, callable $is_code_candidate, bool $allow_blank = false ): ?string {
		$child = $children[ $index ] ?? null;

		if ( ! $child instanceof DOMElement || ! $is_code_candidate( $child ) ) {
			return null;
		}

		return $this->paragraphCodeText( $child, $allow_blank );
	}

	/**
	 * Whether an element is a safe paragraph-level code candidate.
	 *
	 * @param DOMElement $element Element.
	 */
	private function isParagraphCandidate( DOMElement $element ): bool {
		if ( 'p' !== strtolower( $element->tagName ) ) {
			return false;
		}

		if ( $this->hasListItemAncestor( $element ) ) {
			return false;
		}

		if ( $element->getElementsByTagName( 'img' )->length > 0 ) {
			return false;
		}

		return 0 === $element->getElementsByTagName( 'table' )->length;
	}

	/**
	 * Whether the paragraph is nested under a list item.
	 *
	 * @param DOMElement $element Element.
	 */
	private function hasListItemAncestor( DOMElement $element ): bool {
		if ( null === $element->ownerDocument ) {
			return false;
		}

		$xpath     = new DOMXPath( $element->ownerDocument );
		$ancestors = $xpath->query( 'ancestor::li', $element );

		return false !== $ancestors && $ancestors->length > 0;
	}

	/**
	 * Detect common shell and CLI command lines.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeShellCommand( string $text ): bool {
		if ( preg_match( '/^(?:\$|#|>)\s+\S+/', $text ) ) {
			return true;
		}

		return (bool) preg_match(
			'/^(?:\.\/|bin\/|vendor\/bin\/|wp\s+|composer\s+|npm\s+|pnpm\s+|yarn\s+|npx\s+|php\s+|node\s+|python3?\s+|curl\s+|git\s+|docker\s+|kubectl\s+|make\s+|ssh\s+|scp\s+|mysql\s+|psql\s+|cd\s+|mkdir\s+|cp\s+|mv\s+|rm\s+|export\s+[A-Z_][A-Z0-9_]*=)\S*/',
			$text
		);
	}

	/**
	 * Detect XML/HTML-like snippets.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeMarkup( string $text ): bool {
		return (bool) preg_match( '/^<\/?[A-Za-z][A-Za-z0-9:._-]*(?:\s+[^>]*)?>/', $text );
	}

	/**
	 * Detect JSON object lines and key/value entries.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeJson( string $text ): bool {
		if ( preg_match( '/^[{\[]\s*$/', $text ) || preg_match( '/^[}\]],?\s*$/', $text ) ) {
			return true;
		}

		return (bool) preg_match( '/^"[^"]+"\s*:\s*(?:"[^"]*"|[-0-9[{]|true\b|false\b|null\b)/', $text );
	}

	/**
	 * Detect common PHP, Java, and JavaScript-like statements.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeProgrammingStatement( string $text ): bool {
		$patterns = array(
			'/^<\?php\b/',
			'/^(?:package|import)\s+[A-Za-z_][A-Za-z0-9_.]*(?:\.\*)?;?$/',
			'/^(?:public|private|protected|final|abstract|static)\s+.+(?:[;{]|\))$/',
			'/^[A-Za-z_$][A-Za-z0-9_$.]*(?:<[^>]+>)?(?:\[\])?\s+[A-Za-z_$][A-Za-z0-9_$]*\s*\([^)]*\)\s*(?:throws\s+[A-Za-z_$][A-Za-z0-9_$.,\s]*)?\{$/',
			'/^(?:class|interface|enum|trait)\s+[A-Za-z_][A-Za-z0-9_]*/',
			'/^(?:const|let|var)\s+[$A-Za-z_][A-Za-z0-9_$]*\s*=/',
			'/^function\s+[A-Za-z_][A-Za-z0-9_]*\s*\(/',
			'/^(?:if|for|foreach|while|switch|catch)\s*\(/',
			'/^return\b.+;$/',
			'/^\$[A-Za-z_][A-Za-z0-9_]*\s*=/',
			'/^@[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*(?:\(.*\))?$/',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect Gherkin section headers.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeGherkinHeader( string $text ): bool {
		return (bool) preg_match( '/^(?:Feature|Rule|Background|Scenario Outline|Scenario|Examples):(?:\s+\S.*)?$/i', $text );
	}

	/**
	 * Detect Gherkin step lines.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeGherkinStep( string $text ): bool {
		return (bool) preg_match( '/^(?:Given|When|Then|And|But)\s+\S/i', $text );
	}

	/**
	 * Detect Karate star-step lines.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeKarateStep( string $text ): bool {
		return (bool) preg_match( '/^\*\s+\S/', $text );
	}

	/**
	 * Detect Karate/Gherkin triple-quote delimiter lines.
	 *
	 * @param string $text Trimmed text.
	 */
	private function isTripleQuoteLine( string $text ): bool {
		return (bool) preg_match( '/^(?:"{3}|\'{3})$/', $text );
	}

	/**
	 * Detect file paths and simple tree output.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikePathOrTreeLine( string $text ): bool {
		if ( preg_match( '/^(?:\.{1,2}\/|\/|~\/|[A-Za-z0-9_.-]+\/)[A-Za-z0-9_@.\/-]*:?$/', $text ) ) {
			return true;
		}

		return (bool) preg_match( '/^(?:(?:[|`+\\\\-]|├|└|│|─)+\s*)+[A-Za-z0-9_@.\/-]+\/?$/u', $text );
	}

	/**
	 * Detect shell option, payload, and URL continuation lines.
	 *
	 * @param string $text Trimmed text.
	 */
	private function looksLikeShellContinuationLine( string $text ): bool {
		if ( preg_match( '/^https?:\/\/\S+(?:\s*\\\\)?$/', $text ) ) {
			return true;
		}

		return (bool) preg_match( '/^--?[A-Za-z0-9][A-Za-z0-9_-]*(?:=|\s+)\S.*(?:\\\\)?$/', $text );
	}

	/**
	 * Whether a shell line explicitly continues onto the next line.
	 *
	 * @param string $text Trimmed text.
	 */
	private function endsWithShellLineContinuation( string $text ): bool {
		return str_ends_with( rtrim( $text ), '\\' );
	}

	/**
	 * Detect syntax hints on indented continuation lines.
	 *
	 * @param string $text Trimmed text.
	 */
	private function hasCodeSyntaxHint( string $text ): bool {
		return $this->looksLikeMarkup( $text )
			|| $this->looksLikeJson( $text )
			|| $this->looksLikeProgrammingStatement( $text )
			|| $this->looksLikeGherkinStep( $text )
			|| $this->looksLikeKarateStep( $text )
			|| $this->looksLikePathOrTreeLine( $text )
			|| $this->isTripleQuoteLine( $text )
			|| $this->looksLikeShellContinuationLine( $text )
			|| (bool) preg_match( '/(?:[{}();=<>$]|\[[^\]]*\]|\/\/|::|=>|->)/', $text );
	}
}
