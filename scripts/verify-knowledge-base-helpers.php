<?php
/**
 * Verify folder hierarchy mapping, Doc link rewriting, and heading anchors without WordPress.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Strip tags shim.
	 *
	 * @param string $text Text.
	 */
	function wp_strip_all_tags( string $text ): string {
		return trim( strip_tags( $text ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode shim.
	 *
	 * @param mixed $data    Data.
	 * @param int   $options Options.
	 */
	function wp_json_encode( mixed $data, int $options = 0 ): string|false {
		return json_encode( $data, $options );
	}
}

foreach ( array( 'Sync/FolderHierarchyMapper', 'Sync/DocLinkRewriter', 'Sync/Layout/HeadingAnchorBuilder' ) as $file ) {
	require_once dirname( __DIR__ ) . '/src/' . $file . '.php';
}

use DocSyncWP\Sync\DocLinkRewriter;
use DocSyncWP\Sync\FolderHierarchyMapper;
use DocSyncWP\Sync\Layout\HeadingAnchorBuilder;

$failures = 0;
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL {$name}\n" );
	}
};

// Hierarchy mapping.
$check( 'root has no segments', array() === FolderHierarchyMapper::segments( '' ) );
$check( 'nested path split', array( 'Handbook', 'Policies' ) === FolderHierarchyMapper::segments( 'Handbook / Policies' ) );
$check( 'depth capped', 3 === count( FolderHierarchyMapper::segments( 'a / b / c / d / e' ) ) );
$check( 'node key stable and case-insensitive', FolderHierarchyMapper::nodeKey( 'w1', array( 'Handbook' ) ) === FolderHierarchyMapper::nodeKey( 'w1', array( 'handbook' ) ) );
$check( 'node key differs per watch', FolderHierarchyMapper::nodeKey( 'w1', array( 'a' ) ) !== FolderHierarchyMapper::nodeKey( 'w2', array( 'a' ) ) );
$check( 'node key differs per depth', FolderHierarchyMapper::nodeKey( 'w1', array( 'a' ) ) !== FolderHierarchyMapper::nodeKey( 'w1', array( 'a', 'b' ) ) );
$check( 'hierarchy needs subfolders', 'flat' === FolderHierarchyMapper::resolveStructure( 'hierarchy', false, true ) );
$check( 'hierarchy needs hierarchical type', 'flat' === FolderHierarchyMapper::resolveStructure( 'hierarchy', true, false ) );
$check( 'hierarchy allowed', 'hierarchy' === FolderHierarchyMapper::resolveStructure( 'hierarchy', true, true ) );
$check( 'unknown structure is flat', 'flat' === FolderHierarchyMapper::resolveStructure( 'x', true, true ) );

// Doc link rewriting.
$lookup = static fn ( string $id ): ?string => 'DOC_B' === $id ? 'https://example.com/b/?x=1&y=2' : null;
$html   = '<p><a href="https://docs.google.com/document/d/DOC_B/edit?usp=sharing#heading=h.1">B</a> <a href="https://docs.google.com/document/d/UNKNOWN/edit">U</a> <a href=\'https://docs.google.com/document/d/DOC_B\'>B2</a> <a href="https://example.com/">E</a></p>';
$out    = DocLinkRewriter::rewrite( $html, $lookup );
$check( 'known doc rewritten', str_contains( $out, 'href="https://example.com/b/?x=1&amp;y=2"' ) );
$check( 'single-quoted rewritten', str_contains( $out, "href='https://example.com/b/?x=1&amp;y=2'" ) );
$check( 'unknown doc untouched', str_contains( $out, 'https://docs.google.com/document/d/UNKNOWN/edit' ) );
$check( 'other links untouched', str_contains( $out, 'href="https://example.com/"' ) );
$check( 'no google links returns input', 'x' === DocLinkRewriter::rewrite( 'x', $lookup ) );

// Heading anchors and TOC.
$markup = '<!-- wp:heading {"level":2} --><h2>Getting Started</h2><!-- /wp:heading --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- wp:heading {"level":2} --><h2>Install &amp; Run</h2><!-- /wp:heading --><!-- wp:heading {"level":3} --><h3>Install &amp; Run</h3><!-- /wp:heading --><!-- wp:heading {"level":2} --><h2>FAQ</h2><!-- /wp:heading --><!-- wp:heading {"level":1} --><h1>Title</h1><!-- /wp:heading -->';
$out    = HeadingAnchorBuilder::apply( $markup );
$check( 'anchor attr added', str_contains( $out, '{"level":2,"anchor":"getting-started"}' ) );
$check( 'id attr added', str_contains( $out, '<h2 id="getting-started">' ) );
$check( 'duplicate slug suffixed', str_contains( $out, '<h3 id="install-run-2">' ) );
$check( 'toc prepended', str_starts_with( $out, '<!-- wp:list --><ul><li><a href="#getting-started">Getting Started</a></li>' ) );
$check( 'toc lists h2 only', ! str_contains( $out, 'href="#install-run-2"' ) && str_contains( $out, 'href="#faq"' ) );
$check( 'h1 untouched', str_contains( $out, '<h1>Title</h1>' ) );
$two = '<!-- wp:heading {"level":2} --><h2>A</h2><!-- /wp:heading --><!-- wp:heading {"level":2} --><h2>B</h2><!-- /wp:heading -->';
$check( 'no toc under three h2', ! str_contains( HeadingAnchorBuilder::apply( $two ), 'wp:list' ) );
$check( 'slug keeps unicode', 'xin-chào' === HeadingAnchorBuilder::slugify( 'Xin chào!' ) );
$check( 'empty slug fallback', 'section' === HeadingAnchorBuilder::slugify( '!!!' ) );

if ( $failures > 0 ) {
	fwrite( STDERR, "Knowledge base helper checks failed ({$failures}).\n" );
	exit( 1 );
}

echo "Knowledge base helper checks passed.\n";
