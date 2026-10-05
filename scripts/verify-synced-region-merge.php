<?php
/**
 * Verify block fingerprints, synced region location, and pattern placeholders without WordPress.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * JSON encode shim.
	 *
	 * @param mixed $data Data.
	 */
	function wp_json_encode( mixed $data ): string|false {
		return json_encode( $data );
	}
}

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

require_once dirname( __DIR__ ) . '/src/Sync/Region/BlockFingerprint.php';
require_once dirname( __DIR__ ) . '/src/Sync/Region/SyncedRegionMerger.php';
require_once dirname( __DIR__ ) . '/src/Sync/Layout/PatternPlaceholderParser.php';

use DocSyncWP\Sync\Layout\PatternPlaceholderParser;
use DocSyncWP\Sync\Region\BlockFingerprint;
use DocSyncWP\Sync\Region\SyncedRegionMerger;

$failures = 0;
$check    = static function ( string $name, bool $ok ) use ( &$failures ): void {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL {$name}\n" );
	}
};

$block = static fn ( string $name, string $html, array $attrs = array() ): array => array(
	'blockName'   => $name,
	'attrs'       => $attrs,
	'innerBlocks' => array(),
	'innerHTML'   => $html,
);

// Fingerprints ignore attribute order and whitespace; detect real changes.
$a = $block( 'core/heading', "<h2>Title</h2>\n", array( 'level' => 2, 'textAlign' => 'left' ) );
$b = $block( 'core/heading', '<h2>Title</h2>', array( 'textAlign' => 'left', 'level' => 2 ) );
$check( 'fingerprint ignores attr order and whitespace', BlockFingerprint::of( $a ) === BlockFingerprint::of( $b ) );
$check( 'fingerprint detects text change', BlockFingerprint::of( $a ) !== BlockFingerprint::of( $block( 'core/heading', '<h2>Other</h2>', array( 'level' => 2, 'textAlign' => 'left' ) ) ) );
$saved = $block( 'core/heading', '<h2 class="wp-block-heading">Title</h2>', array( 'level' => 2, 'textAlign' => 'left', 'placeholder' => 'x' ) );
$check( 'fingerprint ignores wrapper class and non-semantic attrs', BlockFingerprint::of( $a ) === BlockFingerprint::of( $saved ) );
$check( 'fingerprint detects heading level change', BlockFingerprint::of( $a ) !== BlockFingerprint::of( $block( 'core/heading', '<h3>Title</h3>', array( 'level' => 3 ) ) ) );
$check( 'fingerprint detects link target change', BlockFingerprint::of( $block( 'core/paragraph', '<p><a href="https://a.test/">x</a></p>' ) ) !== BlockFingerprint::of( $block( 'core/paragraph', '<p><a href="https://b.test/">x</a></p>' ) ) );
$check( 'fingerprint detects image source change', BlockFingerprint::of( $block( 'core/image', '<figure><img src="https://a.test/1.png" alt=""/></figure>' ) ) !== BlockFingerprint::of( $block( 'core/image', '<figure><img src="https://a.test/2.png" alt=""/></figure>' ) ) );
$check( 'fingerprint decodes entities', BlockFingerprint::of( $block( 'core/paragraph', '<p>A &amp; B</p>' ) ) === BlockFingerprint::of( $block( 'core/paragraph', '<p>A & B</p>' ) ) );
$check( 'fingerprint detects name change', BlockFingerprint::of( $block( 'core/paragraph', '<p>x</p>' ) ) !== BlockFingerprint::of( $block( 'core/quote', '<p>x</p>' ) ) );

$parent_a = $block( 'core/group', '<div></div>' );
$parent_a['innerBlocks'] = array( $block( 'core/paragraph', '<p>one</p>' ) );
$parent_b = $parent_a;
$parent_b['innerBlocks'] = array( $block( 'core/paragraph', '<p>two</p>' ) );
$check( 'fingerprint includes inner blocks', BlockFingerprint::of( $parent_a ) !== BlockFingerprint::of( $parent_b ) );
$check( 'whitespace-only block detected', BlockFingerprint::isWhitespaceOnly( array( 'blockName' => null, 'innerHTML' => "\n\n" ) ) );

// Region location.
$base = array( 'h', 'p1', 'p2' );
$r    = SyncedRegionMerger::locate( $base, array( 'cta', 'h', 'p1', 'p2', 'footer', 'form' ) );
$check( 'prefix and suffix kept', SyncedRegionMerger::MERGED === $r['status'] && 1 === $r['prefix'] && 2 === $r['suffix'] );
$r = SyncedRegionMerger::locate( $base, array( 'h', 'p1', 'p2' ) );
$check( 'untouched run', SyncedRegionMerger::MERGED === $r['status'] && 0 === $r['prefix'] && 0 === $r['suffix'] );
$r = SyncedRegionMerger::locate( $base, array( 'h', 'edited', 'p2' ) );
$check( 'edit inside run is conflict', SyncedRegionMerger::CONFLICT === $r['status'] );
$r = SyncedRegionMerger::locate( $base, array( 'h', 'p2', 'p1' ) );
$check( 'reorder is conflict', SyncedRegionMerger::CONFLICT === $r['status'] );
$r = SyncedRegionMerger::locate( $base, array( 'h', 'p1' ) );
$check( 'deleted block is conflict', SyncedRegionMerger::CONFLICT === $r['status'] );
$r = SyncedRegionMerger::locate( array(), array( 'x' ) );
$check( 'no baseline', SyncedRegionMerger::NO_BASELINE === $r['status'] );
$r = SyncedRegionMerger::locate( array( 'a', 'a' ), array( 'a', 'a', 'a' ) );
$check( 'duplicate fingerprints use first full match', SyncedRegionMerger::MERGED === $r['status'] && 0 === $r['prefix'] && 1 === $r['suffix'] );

// Placeholders.
$resolver = static fn ( string $name ): ?string => 'Newsletter' === $name ? '<!-- wp:block {"ref":7} /-->' : ( 'theme/hero' === $name ? '<!-- wp:pattern {"slug":"theme/hero"} /-->' : null );
$markup   = '<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{{pattern: Newsletter}}</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{{ pattern : theme/hero }}</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{{pattern: Missing}}</p><!-- /wp:paragraph -->';
$out      = PatternPlaceholderParser::replace( $markup, $resolver );
$check( 'synced pattern replaced', str_contains( $out['markup'], '<!-- wp:block {"ref":7} /-->' ) );
$check( 'registered pattern replaced', str_contains( $out['markup'], '<!-- wp:pattern {"slug":"theme/hero"} /-->' ) );
$check( 'unknown removed and reported', ! str_contains( $out['markup'], 'Missing' ) && array( 'Missing' ) === $out['unresolved'] );
$check( 'other paragraphs untouched', str_contains( $out['markup'], '<p>Intro</p>' ) );
$cross = '<!-- wp:paragraph --><p>{{pattern: Foo}} see below</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Keep me</h2><!-- /wp:heading --><!-- wp:paragraph --><p>{{pattern: Newsletter}}</p><!-- /wp:paragraph -->';
$crossed = PatternPlaceholderParser::replace( $cross, $resolver );
$check( 'placeholder never spans blocks', str_contains( $crossed['markup'], 'Keep me' ) && str_contains( $crossed['markup'], '{{pattern: Foo}} see below' ) && array() === $crossed['unresolved'] );
$inline = '<!-- wp:paragraph --><p>Use {{pattern: Newsletter}} here</p><!-- /wp:paragraph -->';
$check( 'inline mention untouched', PatternPlaceholderParser::replace( $inline, $resolver )['markup'] === $inline );

if ( $failures > 0 ) {
	fwrite( STDERR, "Synced region checks failed ({$failures}).\n" );
	exit( 1 );
}

echo "Synced region checks passed.\n";
