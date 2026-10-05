<?php
/**
 * Resolves links between synced Google Docs at render time.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Filters the_content so links to other synced Docs point to their WordPress pages.
 *
 * Nothing is stored: deactivating the plugin leaves working Google links in post content.
 */
final class DocLinkResolver {
	private const CACHE_GROUP = 'docsync_wp_doc_links';
	private const CACHE_TTL   = 300;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository $sources Source repository.
	 */
	public function __construct( SourceRepository $sources ) {
		$this->sources = $sources;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'the_content', array( $this, 'filterContent' ), 20 );
		add_action( 'transition_post_status', array( $this, 'forgetPost' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'forgetDeletedPost' ) );
	}

	/**
	 * Rewrite Doc links in synced content.
	 *
	 * @param string $content Post content.
	 */
	public function filterContent( string $content ): string {
		$post_id = get_the_ID();

		if ( false === $post_id || ! (bool) apply_filters( 'docsync_wp_resolve_doc_links', true ) ) {
			return $content;
		}

		if ( '' === (string) get_post_meta( $post_id, SourceRepository::META_FILE_ID, true ) ) {
			return $content;
		}

		return DocLinkRewriter::rewrite( $content, array( $this, 'permalinkFor' ) );
	}

	/**
	 * Permalink of the published, public post synced from a Google file, or null.
	 *
	 * @param string $file_id Google file ID.
	 */
	public function permalinkFor( string $file_id ): ?string {
		$cached = wp_cache_get( $file_id, self::CACHE_GROUP );

		if ( is_string( $cached ) ) {
			return '' === $cached ? null : $cached;
		}

		$permalink = '';
		$post_id   = $this->sources->findPostIdByGoogleFileId( $file_id );

		if ( null !== $post_id ) {
			$post = get_post( $post_id );

			if ( null !== $post && 'publish' === $post->post_status && '' === $post->post_password ) {
				$permalink = (string) get_permalink( $post_id );
			}
		}

		wp_cache_set( $file_id, $permalink, self::CACHE_GROUP, self::CACHE_TTL );

		return '' === $permalink ? null : $permalink;
	}

	/**
	 * Drop the cached lookup when a post changes status.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function forgetPost( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status !== $old_status ) {
			$this->forgetDeletedPost( $post->ID );
		}
	}

	/**
	 * Drop the cached lookup for a post's Google file.
	 *
	 * @param int $post_id Post ID.
	 */
	public function forgetDeletedPost( int $post_id ): void {
		$file_id = (string) get_post_meta( $post_id, SourceRepository::META_FILE_ID, true );

		if ( '' !== $file_id ) {
			wp_cache_delete( $file_id, self::CACHE_GROUP );
		}
	}
}
