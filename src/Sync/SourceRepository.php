<?php
/**
 * Post-linked Google Docs source metadata.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Settings\SettingsRepository;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one Google Doc source record per post.
 */
final class SourceRepository {
	public const META_FILE_ID       = '_docsync_wp_google_file_id';
	public const META_DOC_URL       = '_docsync_wp_google_doc_url';
	public const META_TITLE         = '_docsync_wp_google_title';
	public const META_MODIFIED_TIME = '_docsync_wp_google_modified_time';
	public const META_VERSION       = '_docsync_wp_google_version';
	public const META_LAST_HASH     = '_docsync_wp_last_hash';
	public const META_LAST_SYNCED   = '_docsync_wp_last_synced_at';
	public const META_LAST_METHOD   = '_docsync_wp_last_sync_method';
	public const META_OWNER_USER_ID = '_docsync_wp_sync_owner_user_id';
	public const META_EXPORT_FORMAT = '_docsync_wp_export_format';
	public const META_SYNC_STATUS   = '_docsync_wp_sync_status';
	public const META_SYNC_ERROR    = '_docsync_wp_sync_error';
	public const META_SYNC_PROGRESS = '_docsync_wp_sync_progress';
	public const META_SYNC_STEP     = '_docsync_wp_sync_step';
	public const META_SYNC_MESSAGE  = '_docsync_wp_sync_message';

	private const EXPORT_FORMAT_HTML_ZIP = 'html_zip';
	private const STATUS_SYNCING         = 'syncing';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get the source metadata for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function getSource( int $post_id ): ?array {
		if ( $post_id <= 0 || null === get_post( $post_id ) ) {
			return null;
		}

		$file_id = get_post_meta( $post_id, self::META_FILE_ID, true );

		if ( ! is_string( $file_id ) || '' === $file_id ) {
			return null;
		}

		$sync_status = $this->getStringMeta( $post_id, self::META_SYNC_STATUS );
		$sync_step   = sanitize_key( $this->getStringMeta( $post_id, self::META_SYNC_STEP ) );

		if ( '' === $sync_step ) {
			$sync_step = '' !== $sync_status ? sanitize_key( $sync_status ) : 'linked';
		}

		return array(
			'google_file_id'       => $file_id,
			'google_doc_url'       => $this->getStringMeta( $post_id, self::META_DOC_URL ),
			'google_title'         => $this->getStringMeta( $post_id, self::META_TITLE ),
			'google_modified_time' => $this->getStringMeta( $post_id, self::META_MODIFIED_TIME ),
			'google_version'       => $this->getStringMeta( $post_id, self::META_VERSION ),
			'last_hash'            => $this->getStringMeta( $post_id, self::META_LAST_HASH ),
			'last_synced_at'       => $this->getStringMeta( $post_id, self::META_LAST_SYNCED ),
			'last_sync_method'     => $this->getStringMeta( $post_id, self::META_LAST_METHOD ),
			'sync_owner_user_id'   => absint( get_post_meta( $post_id, self::META_OWNER_USER_ID, true ) ),
			'export_format'        => $this->getStringMeta( $post_id, self::META_EXPORT_FORMAT ),
			'sync_status'          => $sync_status,
			'sync_error'           => $this->getStringMeta( $post_id, self::META_SYNC_ERROR ),
			'sync_progress'        => $this->getSyncProgress( $post_id, $sync_status ),
			'sync_step'            => $sync_step,
			'sync_message'         => $this->getSyncMessage( $post_id, $sync_step ),
		);
	}

	/**
	 * Save the source metadata for a post.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $source  Source metadata.
	 * @return bool|WP_Error
	 */
	public function saveSource( int $post_id, array $source ): bool|WP_Error {
		$post = get_post( $post_id );

		if ( null === $post ) {
			return new WP_Error(
				'docsync_wp_invalid_post',
				__( 'DocSync WP cannot save source metadata for an invalid post.', 'docsync-wp' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->isPostTypeEnabled( $post->post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'DocSync WP source metadata cannot be saved for this post type.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		$file_id = isset( $source['google_file_id'] ) ? sanitize_text_field( (string) $source['google_file_id'] ) : '';

		if ( '' === $file_id ) {
			return new WP_Error(
				'docsync_wp_missing_google_file_id',
				__( 'DocSync WP requires a Google file ID before saving source metadata.', 'docsync-wp' ),
				array( 'status' => 400 )
			);
		}

		update_post_meta( $post_id, self::META_FILE_ID, $file_id );
		update_post_meta( $post_id, self::META_DOC_URL, isset( $source['google_doc_url'] ) ? esc_url_raw( (string) $source['google_doc_url'] ) : '' );
		update_post_meta( $post_id, self::META_TITLE, isset( $source['google_title'] ) ? sanitize_text_field( (string) $source['google_title'] ) : '' );
		update_post_meta( $post_id, self::META_MODIFIED_TIME, isset( $source['google_modified_time'] ) ? sanitize_text_field( (string) $source['google_modified_time'] ) : '' );
		update_post_meta( $post_id, self::META_VERSION, isset( $source['google_version'] ) ? sanitize_text_field( (string) $source['google_version'] ) : '' );
		update_post_meta( $post_id, self::META_LAST_HASH, isset( $source['last_hash'] ) ? sanitize_text_field( (string) $source['last_hash'] ) : '' );
		update_post_meta( $post_id, self::META_LAST_SYNCED, isset( $source['last_synced_at'] ) ? sanitize_text_field( (string) $source['last_synced_at'] ) : '' );
		update_post_meta( $post_id, self::META_LAST_METHOD, $this->sanitizeLastSyncMethod( $source['last_sync_method'] ?? '' ) );
		update_post_meta( $post_id, self::META_OWNER_USER_ID, isset( $source['sync_owner_user_id'] ) ? absint( $source['sync_owner_user_id'] ) : 0 );
		update_post_meta( $post_id, self::META_EXPORT_FORMAT, $this->sanitizeExportFormat( $source['export_format'] ?? self::EXPORT_FORMAT_HTML_ZIP ) );
		update_post_meta( $post_id, self::META_SYNC_STATUS, isset( $source['sync_status'] ) ? sanitize_key( (string) $source['sync_status'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_ERROR, isset( $source['sync_error'] ) ? sanitize_textarea_field( (string) $source['sync_error'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_PROGRESS, $this->sanitizeProgress( $source['sync_progress'] ?? 0 ) );
		update_post_meta( $post_id, self::META_SYNC_STEP, isset( $source['sync_step'] ) ? sanitize_key( (string) $source['sync_step'] ) : 'linked' );
		update_post_meta( $post_id, self::META_SYNC_MESSAGE, $this->sanitizeProgressMessage( $source['sync_message'] ?? __( 'Linked and ready to sync.', 'docsync-wp' ) ) );

		return true;
	}

	/**
	 * Delete source metadata for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function deleteSource( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$deleted = false;

		foreach ( $this->metaKeys() as $meta_key ) {
			$deleted = delete_post_meta( $post_id, $meta_key ) || $deleted;
		}

		return $deleted;
	}

	/**
	 * List linked sources for post types the user can edit.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @param int               $limit      Maximum rows to return.
	 * @param int               $page       Page number.
	 * @return array<int,array<string,mixed>>
	 */
	public function listSources( array $post_types, int $user_id, int $limit = 100, int $page = 1 ): array {
		$page = $this->listSourcesPage( $post_types, $user_id, $limit, $page );

		return $page['sources'];
	}

	/**
	 * List a page of linked sources.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @param int               $limit      Maximum rows to return.
	 * @param int               $page       Page number.
	 * @param string            $search Search term.
	 * @param string            $status Sync status.
	 * @return array{sources:array<int,array<string,mixed>>,has_more:bool,page:int,per_page:int}
	 */
	public function listSourcesPage( array $post_types, int $user_id, int $limit = 100, int $page = 1, string $search = '', string $status = '' ): array {
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types ),
				function ( string $post_type ) use ( $user_id ): bool {
					return $this->isPostTypeEnabled( $post_type )
						&& $this->userCanEditPostType( $post_type, $user_id );
				}
			)
		);

		$limit  = max( 1, min( 100, $limit ) );
		$page   = max( 1, $page );
		$search = trim( sanitize_text_field( $search ) );
		$status = sanitize_key( $status );

		if ( array() === $post_types ) {
			return array(
				'sources'  => array(),
				'has_more' => false,
				'page'     => $page,
				'per_page' => $limit,
			);
		}

		$matching_ids = array();

		if ( '' !== $search ) {
			$matching_ids = $this->findSourceIdsBySearch( $post_types, $search );

			if ( array() === $matching_ids ) {
				return array(
					'sources'  => array(),
					'has_more' => false,
					'page'     => $page,
					'per_page' => $limit,
				);
			}
		}

		$meta_query = array(
			'relation'   => 'AND',
			'has_source' => array(
				'key'     => self::META_FILE_ID,
				'compare' => 'EXISTS',
			),
		);

		if ( '' !== $status ) {
			$meta_query['sync_status'] = array(
				'key'     => self::META_SYNC_STATUS,
				'value'   => $status,
				'compare' => '=',
			);
		}

		$query_args = array(
			'fields'                 => 'ids',
			'meta_query'             => $meta_query,
			'no_found_rows'          => true,
			'order'                  => 'DESC',
			'orderby'                => 'modified',
			'post_status'            => 'any',
			'post_type'              => $post_types,
			'posts_per_page'         => $limit + 1,
			'offset'                 => ( $page - 1 ) * $limit,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		if ( array() !== $matching_ids ) {
			$query_args['post__in'] = $matching_ids;
		}

		$query = new WP_Query(
			$query_args
		);

		$sources = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $this->userCanSyncPost( $post_id, $user_id ) ) {
				continue;
			}

			$source = $this->formatSource( $post_id );

			if ( null !== $source ) {
				$sources[] = $source;
			}
		}

		$has_more = count( $query->posts ) > $limit;

		if ( $has_more ) {
			$sources = array_slice( $sources, 0, $limit );
		}

		return array(
			'sources'  => $sources,
			'has_more' => $has_more,
			'page'     => $page,
			'per_page' => $limit,
		);
	}

	/**
	 * Find source post IDs matching post title/content or Google source metadata.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param string            $search     Search term.
	 * @return array<int,int>
	 */
	private function findSourceIdsBySearch( array $post_types, string $search ): array {
		$post_matches = get_posts(
			array(
				'fields'                 => 'ids',
				'meta_key'               => self::META_FILE_ID,
				'no_found_rows'          => true,
				'post_status'            => 'any',
				'post_type'              => $post_types,
				'posts_per_page'         => -1,
				's'                      => $search,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$source_matches = get_posts(
			array(
				'fields'                 => 'ids',
				'meta_query'             => array(
					'relation'   => 'AND',
					'has_source' => array(
						'key'     => self::META_FILE_ID,
						'compare' => 'EXISTS',
					),
					'search'     => array(
						'relation' => 'OR',
						array(
							'key'     => self::META_TITLE,
							'value'   => $search,
							'compare' => 'LIKE',
						),
						array(
							'key'     => self::META_FILE_ID,
							'value'   => $search,
							'compare' => 'LIKE',
						),
						array(
							'key'     => self::META_DOC_URL,
							'value'   => $search,
							'compare' => 'LIKE',
						),
					),
				),
				'no_found_rows'          => true,
				'post_status'            => 'any',
				'post_type'              => $post_types,
				'posts_per_page'         => -1,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_values( array_unique( array_map( 'absint', array_merge( $post_matches, $source_matches ) ) ) );
	}

	/**
	 * List editable sources that were synced before a cutoff.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @param int               $limit      Maximum rows to return.
	 * @param string            $before     UTC mysql timestamp cutoff.
	 * @param array<int,int>    $exclude    Post IDs to exclude.
	 * @return array<int,array<string,mixed>>
	 */
	public function listDueSources( array $post_types, int $user_id, int $limit, string $before, array $exclude = array() ): array {
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types ),
				function ( string $post_type ) use ( $user_id ): bool {
					return $this->isPostTypeEnabled( $post_type )
						&& $this->userCanEditPostType( $post_type, $user_id );
				}
			)
		);

		if ( array() === $post_types ) {
			return array();
		}

		$query   = $this->queryDueSourceIds( $post_types, $limit, $before, $exclude );
		$sources = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = absint( $post_id );

			if ( ! $this->userCanSyncPost( $post_id, $user_id ) ) {
				continue;
			}

			$source = $this->formatSource( $post_id );

			if ( null !== $source ) {
				$sources[] = $source;
			}
		}

		return $sources;
	}

	/**
	 * List due source post IDs without user filtering.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $limit      Maximum rows to return.
	 * @param string            $before     UTC mysql timestamp cutoff.
	 * @param array<int,int>    $exclude    Post IDs to exclude.
	 * @return array<int,int>
	 */
	public function listDueSourcePostIds( array $post_types, int $limit, string $before, array $exclude = array() ): array {
		$post_types = array_values(
			array_filter(
				array_map( 'sanitize_key', $post_types ),
				array( $this, 'isPostTypeEnabled' )
			)
		);

		if ( array() === $post_types ) {
			return array();
		}

		$query = $this->queryDueSourceIds( $post_types, $limit, $before, $exclude );

		return array_map( 'absint', $query->posts );
	}

	/**
	 * List due source post IDs the user is likely allowed to edit.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @param int               $limit      Maximum rows to return.
	 * @param string            $before     UTC mysql timestamp cutoff.
	 * @param array<int,int>    $exclude    Post IDs to exclude.
	 * @return array<int,int>
	 */
	public function listDueSourcePostIdsForUser( array $post_types, int $user_id, int $limit, string $before, array $exclude = array() ): array {
		$all_editable_post_types = array();
		$own_post_types          = array();

		foreach ( array_map( 'sanitize_key', $post_types ) as $post_type ) {
			if ( ! $this->userCanEditPostType( $post_type, $user_id ) ) {
				continue;
			}

			if ( $this->userCanEditOthersPostType( $post_type, $user_id ) ) {
				$all_editable_post_types[] = $post_type;
				continue;
			}

			$own_post_types[] = $post_type;
		}

		$post_ids = array();

		if ( array() !== $all_editable_post_types ) {
			$post_ids = array_merge(
				$post_ids,
				$this->listDueSourcePostIds( $all_editable_post_types, $limit, $before, $exclude )
			);
		}

		if ( array() !== $own_post_types ) {
			$post_ids = array_merge(
				$post_ids,
				array_map( 'absint', $this->queryDueSourceIds( $own_post_types, $limit, $before, $exclude, $user_id )->posts )
			);
		}

		$post_ids = array_values( array_unique( array_map( 'absint', $post_ids ) ) );

		return array_slice( $post_ids, 0, max( 1, min( 100, $limit ) ) );
	}

	/**
	 * Query source IDs due before a cutoff.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $limit      Maximum rows to return.
	 * @param string            $before     UTC mysql timestamp cutoff.
	 * @param array<int,int>    $exclude    Post IDs to exclude.
	 * @param int               $author     Optional post author filter.
	 */
	private function queryDueSourceIds( array $post_types, int $limit, string $before, array $exclude, int $author = 0 ): WP_Query {
		$query_args = array(
			'fields'                 => 'ids',
			'meta_query'             => array(
				'relation'    => 'AND',
				'has_source'  => array(
					'key'     => self::META_FILE_ID,
					'compare' => 'EXISTS',
				),
				'last_synced' => array(
					'key'     => self::META_LAST_SYNCED,
					'value'   => sanitize_text_field( $before ),
					'compare' => '<=',
					'type'    => 'CHAR',
				),
				'not_syncing' => array(
					'relation' => 'OR',
					array(
						'key'     => self::META_SYNC_STATUS,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_SYNC_STATUS,
						'value'   => self::STATUS_SYNCING,
						'compare' => '!=',
					),
				),
			),
			'no_found_rows'          => true,
			'orderby'                => array(
				'last_synced' => 'ASC',
				'modified'    => 'ASC',
			),
			'order'                  => 'ASC',
			'post_status'            => 'any',
			'post_type'              => $post_types,
			'post__not_in'           => array_map( 'absint', $exclude ),
			'posts_per_page'         => max( 1, min( 100, $limit ) ),
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		if ( $author > 0 ) {
			$query_args['author'] = $author;
		}

		return new WP_Query( $query_args );
	}

	/**
	 * Format source metadata for REST responses.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function formatSource( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$source = $this->getSource( $post_id );

		if ( null === $source ) {
			return null;
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );

		return array(
			'postId'             => $post_id,
			'postType'           => $post->post_type,
			'postStatus'         => $post->post_status,
			'postTitle'          => get_the_title( $post_id ),
			'editUrl'            => is_string( $edit_link ) ? esc_url_raw( $edit_link ) : '',
			'googleFileId'       => $source['google_file_id'],
			'googleDocUrl'       => $source['google_doc_url'],
			'googleTitle'        => $source['google_title'],
			'googleModifiedTime' => $source['google_modified_time'],
			'googleVersion'      => $source['google_version'],
			'lastHash'           => $source['last_hash'],
			'lastSyncedAt'       => $source['last_synced_at'],
			'lastSyncMethod'     => '' !== $source['last_sync_method'] ? $source['last_sync_method'] : null,
			'syncOwnerUserId'    => $source['sync_owner_user_id'],
			'exportFormat'       => $source['export_format'],
			'syncStatus'         => $source['sync_status'],
			'syncError'          => $source['sync_error'],
			'syncProgress'       => $source['sync_progress'],
			'syncStep'           => $source['sync_step'],
			'syncMessage'        => $source['sync_message'],
		);
	}

	/**
	 * Get enabled post types.
	 *
	 * @return array<int,string>
	 */
	public function getEnabledPostTypes(): array {
		return $this->settings->getEnabledPostTypes();
	}

	/**
	 * Whether a post type is enabled for DocSync WP.
	 *
	 * @param string $post_type Post type.
	 */
	public function isPostTypeEnabled( string $post_type ): bool {
		return in_array( $post_type, $this->settings->getEnabledPostTypes(), true )
			&& $this->settings->isPostTypeAvailable( $post_type );
	}

	/**
	 * Whether a user can sync an existing post.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 */
	public function userCanSyncPost( int $post_id, int $user_id ): bool {
		$post = get_post( $post_id );

		return null !== $post
			&& $this->isPostTypeEnabled( $post->post_type )
			&& user_can( $user_id, 'edit_post', $post_id );
	}

	/**
	 * Whether a user can create a synced post of the given type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   User ID.
	 */
	public function userCanCreateSyncedPost( string $post_type, int $user_id ): bool {
		if ( ! $this->isPostTypeEnabled( $post_type ) ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		$capability = $post_type_object->cap->create_posts ?? $post_type_object->cap->edit_posts ?? 'edit_posts';

		if ( 'do_not_allow' === $capability ) {
			return false;
		}

		return user_can( $user_id, $capability );
	}

	/**
	 * Whether a user can edit posts of the given type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   User ID.
	 */
	public function userCanEditPostType( string $post_type, int $user_id ): bool {
		if ( ! $this->isPostTypeEnabled( $post_type ) ) {
			return false;
		}

		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		$capability = $post_type_object->cap->edit_posts ?? 'edit_posts';

		return user_can( $user_id, $capability );
	}

	/**
	 * Whether a user can edit other users' posts of the given type.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   User ID.
	 */
	private function userCanEditOthersPostType( string $post_type, int $user_id ): bool {
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		$capability = $post_type_object->cap->edit_others_posts ?? '';

		return '' !== $capability && user_can( $user_id, $capability );
	}

	/**
	 * Get a string post meta value.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 */
	private function getStringMeta( int $post_id, string $meta_key ): string {
		$value = get_post_meta( $post_id, $meta_key, true );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Get the source progress message with linked-source default.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $step    Current progress step.
	 */
	private function getSyncMessage( int $post_id, string $step ): string {
		$message = $this->getStringMeta( $post_id, self::META_SYNC_MESSAGE );

		if ( '' !== $message ) {
			return $message;
		}

		return 'linked' === $step ? __( 'Linked and ready to sync.', 'docsync-wp' ) : '';
	}

	/**
	 * Get source sync progress with legacy terminal defaults.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $status  Current source status.
	 */
	private function getSyncProgress( int $post_id, string $status ): int {
		if ( metadata_exists( 'post', $post_id, self::META_SYNC_PROGRESS ) ) {
			return $this->sanitizeProgress( get_post_meta( $post_id, self::META_SYNC_PROGRESS, true ) );
		}

		return in_array( $status, array( 'synced', 'skipped' ), true ) ? 100 : 0;
	}

	/**
	 * Sanitize a sync progress percent.
	 *
	 * @param mixed $progress Progress value.
	 */
	private function sanitizeProgress( mixed $progress ): int {
		return max( 0, min( 100, (int) $progress ) );
	}

	/**
	 * Sanitize a short sync progress message.
	 *
	 * @param mixed $message Message.
	 */
	private function sanitizeProgressMessage( mixed $message ): string {
		$message = sanitize_text_field( (string) $message );

		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 240 ) : substr( $message, 0, 240 );
	}

	/**
	 * Sanitize an export format.
	 *
	 * @param mixed $export_format Export format.
	 */
	private function sanitizeExportFormat( mixed $export_format ): string {
		$export_format = sanitize_key( (string) $export_format );

		return self::EXPORT_FORMAT_HTML_ZIP === $export_format ? $export_format : self::EXPORT_FORMAT_HTML_ZIP;
	}

	/**
	 * Sanitize the last successful sync method.
	 *
	 * @param mixed $method Sync method.
	 */
	private function sanitizeLastSyncMethod( mixed $method ): string {
		$method = sanitize_key( (string) $method );

		return in_array( $method, array( 'html_zip', 'docs_api_fallback' ), true ) ? $method : '';
	}

	/**
	 * Source meta keys.
	 *
	 * @return array<int,string>
	 */
	private function metaKeys(): array {
		return array(
			self::META_FILE_ID,
			self::META_DOC_URL,
			self::META_TITLE,
			self::META_MODIFIED_TIME,
			self::META_VERSION,
			self::META_LAST_HASH,
			self::META_LAST_SYNCED,
			self::META_LAST_METHOD,
			self::META_OWNER_USER_ID,
			self::META_EXPORT_FORMAT,
			self::META_SYNC_STATUS,
			self::META_SYNC_ERROR,
			self::META_SYNC_PROGRESS,
			self::META_SYNC_STEP,
			self::META_SYNC_MESSAGE,
		);
	}
}
