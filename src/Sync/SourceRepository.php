<?php
/**
 * Post-linked Google Docs source metadata.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use WP_Error;
use WP_Post;
use WP_Post_Type;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one Google Doc source record per post.
 */
final class SourceRepository {
	public const META_FILE_ID          = '_docsync_wp_google_file_id';
	public const META_DOC_URL          = '_docsync_wp_google_doc_url';
	public const META_TITLE            = '_docsync_wp_google_title';
	public const META_MODIFIED_TIME    = '_docsync_wp_google_modified_time';
	public const META_VERSION          = '_docsync_wp_google_version';
	public const META_LAST_HASH        = '_docsync_wp_last_hash';
	public const META_LAST_SYNCED      = '_docsync_wp_last_synced_at';
	public const META_LAST_METHOD      = '_docsync_wp_last_sync_method';
	public const META_OWNER_USER_ID    = '_docsync_wp_sync_owner_user_id';
	public const META_EXPORT_FORMAT    = '_docsync_wp_export_format';
	public const META_SYNC_STATUS      = '_docsync_wp_sync_status';
	public const META_SYNC_ERROR       = '_docsync_wp_sync_error';
	public const META_SYNC_PROGRESS    = '_docsync_wp_sync_progress';
	public const META_SYNC_STEP        = '_docsync_wp_sync_step';
	public const META_SYNC_MESSAGE     = '_docsync_wp_sync_message';
	public const META_SYNC_STARTED     = '_docsync_wp_sync_started_at';
	public const META_SYNC_UPDATED     = '_docsync_wp_sync_updated_at';
	public const META_SYNC_ERR_CODE    = '_docsync_wp_sync_error_code';
	public const META_SYNC_EVENTS      = '_docsync_wp_sync_events';
	public const META_ELEMENTOR_SYNC   = '_docsync_wp_elementor_sync';
	public const META_ELEMENTOR_PRESET = '_docsync_wp_elementor_preset';
	public const META_LAYOUT_PRESET    = '_docsync_wp_layout_preset';
	public const META_LAYOUT_HASH      = '_docsync_wp_last_layout_fingerprint';

	private const EXPORT_FORMAT_HTML_ZIP = 'html_zip';
	private const STATUS_SYNCING         = 'syncing';
	private const SOURCE_SCAN_BATCH_SIZE = 100;
	private const SUMMARY_SOURCE_LIMIT   = 500;
	private const SYNC_EVENT_LIMIT       = 50;
	private const SYNC_EVENT_LEVELS      = array( 'info', 'warning', 'error' );

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Layout preset registry.
	 *
	 * @var LayoutPresetRegistry
	 */
	private LayoutPresetRegistry $layout_presets;

	/**
	 * Elementor preset registry.
	 *
	 * @var ElementorPresetRegistry
	 */
	private ElementorPresetRegistry $elementor_presets;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository           $settings          Settings repository.
	 * @param LayoutPresetRegistry|null    $layout_presets    Layout preset registry.
	 * @param ElementorPresetRegistry|null $elementor_presets Elementor preset registry.
	 */
	public function __construct(
		SettingsRepository $settings,
		?LayoutPresetRegistry $layout_presets = null,
		?ElementorPresetRegistry $elementor_presets = null
	) {
		$this->settings          = $settings;
		$this->layout_presets    = $layout_presets ?? new LayoutPresetRegistry();
		$this->elementor_presets = $elementor_presets ?? new ElementorPresetRegistry();
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
			'layout_preset'        => $this->getLayoutPreset( $post_id ),
			'last_layout_hash'     => $this->getStringMeta( $post_id, self::META_LAYOUT_HASH ),
			'sync_owner_user_id'   => absint( get_post_meta( $post_id, self::META_OWNER_USER_ID, true ) ),
			'export_format'        => $this->getStringMeta( $post_id, self::META_EXPORT_FORMAT ),
			'elementor_sync'       => $this->getElementorSync( $post_id ),
			'elementor_preset'     => $this->getElementorPreset( $post_id ),
			'sync_status'          => $sync_status,
			'sync_error'           => $this->sanitizeErrorMessage( $this->getStringMeta( $post_id, self::META_SYNC_ERROR ) ),
			'sync_progress'        => $this->getSyncProgress( $post_id, $sync_status ),
			'sync_step'            => $sync_step,
			'sync_message'         => $this->getSyncMessage( $post_id, $sync_step ),
			'sync_started_at'      => $this->getStringMeta( $post_id, self::META_SYNC_STARTED ),
			'sync_updated_at'      => $this->getStringMeta( $post_id, self::META_SYNC_UPDATED ),
			'sync_error_code'      => $this->getStringMeta( $post_id, self::META_SYNC_ERR_CODE ),
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
				__( 'Brasth Document Sync cannot save source metadata for an invalid post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->isPostTypeEnabled( $post->post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'Brasth Document Sync source metadata cannot be saved for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$file_id = isset( $source['google_file_id'] ) ? sanitize_text_field( (string) $source['google_file_id'] ) : '';

		if ( '' === $file_id ) {
			return new WP_Error(
				'docsync_wp_missing_google_file_id',
				__( 'Brasth Document Sync requires a Google file ID before saving source metadata.', 'brasth-document-sync-for-google-docs' ),
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
		update_post_meta( $post_id, self::META_LAYOUT_PRESET, $this->sanitizeLayoutPreset( $source['layout_preset'] ?? '' ) );
		update_post_meta( $post_id, self::META_LAYOUT_HASH, isset( $source['last_layout_hash'] ) ? sanitize_text_field( (string) $source['last_layout_hash'] ) : '' );
		update_post_meta( $post_id, self::META_OWNER_USER_ID, isset( $source['sync_owner_user_id'] ) ? absint( $source['sync_owner_user_id'] ) : 0 );
		update_post_meta( $post_id, self::META_EXPORT_FORMAT, $this->sanitizeExportFormat( $source['export_format'] ?? self::EXPORT_FORMAT_HTML_ZIP ) );

		if ( array_key_exists( 'elementor_sync', $source ) ) {
			update_post_meta( $post_id, self::META_ELEMENTOR_SYNC, $this->sanitizeElementorSync( $source['elementor_sync'] ) );
		}

		if ( array_key_exists( 'elementor_preset', $source ) ) {
			$elementor_preset = $this->sanitizeElementorPreset( $source['elementor_preset'] );

			if ( '' === $elementor_preset ) {
				delete_post_meta( $post_id, self::META_ELEMENTOR_PRESET );
			} else {
				update_post_meta( $post_id, self::META_ELEMENTOR_PRESET, $elementor_preset );
			}
		}

		update_post_meta( $post_id, self::META_SYNC_STATUS, isset( $source['sync_status'] ) ? sanitize_key( (string) $source['sync_status'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_ERROR, isset( $source['sync_error'] ) ? $this->sanitizeErrorMessage( $source['sync_error'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_PROGRESS, $this->sanitizeProgress( $source['sync_progress'] ?? 0 ) );
		update_post_meta( $post_id, self::META_SYNC_STEP, isset( $source['sync_step'] ) ? sanitize_key( (string) $source['sync_step'] ) : 'linked' );
		update_post_meta( $post_id, self::META_SYNC_MESSAGE, $this->sanitizeProgressMessage( $source['sync_message'] ?? __( 'Linked and ready to sync.', 'brasth-document-sync-for-google-docs' ) ) );
		update_post_meta( $post_id, self::META_SYNC_STARTED, isset( $source['sync_started_at'] ) ? sanitize_text_field( (string) $source['sync_started_at'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_UPDATED, isset( $source['sync_updated_at'] ) ? sanitize_text_field( (string) $source['sync_updated_at'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_ERR_CODE, isset( $source['sync_error_code'] ) ? sanitize_key( (string) $source['sync_error_code'] ) : '' );

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
	 * Append one sanitized diagnostic sync event to a source history.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $event   Event fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function appendSyncEvent( int $post_id, array $event ): array|WP_Error {
		$source = $this->getSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		$next_event = $this->sanitizeSyncEvent( $post_id, $source, $event );
		$events     = $this->getSyncEvents( $post_id );

		array_unshift( $events, $next_event );

		update_post_meta( $post_id, self::META_SYNC_EVENTS, array_slice( $events, 0, self::SYNC_EVENT_LIMIT ) );

		return $next_event;
	}

	/**
	 * Get stored diagnostic sync events for a source, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function getSyncEvents( int $post_id ): array {
		$raw_events = get_post_meta( $post_id, self::META_SYNC_EVENTS, true );

		if ( ! is_array( $raw_events ) ) {
			return array();
		}

		$source = $this->getSource( $post_id ) ?? array();
		$events = array();

		foreach ( $raw_events as $event ) {
			if ( is_array( $event ) ) {
				$events[] = $this->sanitizeSyncEvent( $post_id, $source, $event );
			}
		}

		return array_slice( $events, 0, self::SYNC_EVENT_LIMIT );
	}

	/**
	 * List diagnostic sync events visible to the current user.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @param int               $post_id    Optional source post ID.
	 * @param string            $level      Optional event level.
	 * @param int               $limit      Maximum rows to return.
	 * @param int               $page       Page number.
	 * @param string            $search     Search term.
	 * @param string            $status     Sync status.
	 * @param string            $step       Sync step.
	 * @return array{entries:array<int,array<string,mixed>>,has_more:bool,page:int,per_page:int}|WP_Error
	 */
	public function listSyncEvents(
		array $post_types,
		int $user_id,
		int $post_id = 0,
		string $level = '',
		int $limit = 50,
		int $page = 1,
		string $search = '',
		string $status = '',
		string $step = ''
	): array|WP_Error {
		$level  = sanitize_key( $level );
		$search = trim( sanitize_text_field( $search ) );
		$status = sanitize_key( $status );
		$step   = sanitize_key( $step );

		if ( '' !== $level && ! in_array( $level, self::SYNC_EVENT_LEVELS, true ) ) {
			return new WP_Error(
				'docsync_wp_invalid_sync_log_level',
				__( 'Brasth Document Sync received an unsupported sync log level filter.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$limit    = max( 1, min( 100, $limit ) );
		$page     = max( 1, $page );
		$post_ids = $post_id > 0 ? $this->validateSyncEventPostId( $post_id, $user_id ) : $this->querySyncEventPostIds( $post_types, $user_id );

		if ( is_wp_error( $post_ids ) ) {
			return $post_ids;
		}

		$entries = array();

		foreach ( $post_ids as $event_post_id ) {
			foreach ( $this->getSyncEvents( $event_post_id ) as $event ) {
				if ( ! $this->syncEventMatchesFilters( $event, $level, $search, $status, $step ) ) {
					continue;
				}

				$entries[] = $event;
			}
		}

		usort(
			$entries,
			static function ( array $left, array $right ): int {
				$timestamp_compare = strcmp( (string) $right['timestamp'], (string) $left['timestamp'] );

				if ( 0 !== $timestamp_compare ) {
					return $timestamp_compare;
				}

				return strcmp( (string) $right['eventId'], (string) $left['eventId'] );
			}
		);

		$offset       = ( $page - 1 ) * $limit;
		$page_entries = array_slice( $entries, $offset, $limit );

		return array(
			'entries'  => $page_entries,
			'has_more' => count( $entries ) > $offset + $limit,
			'page'     => $page,
			'per_page' => $limit,
		);
	}

	/**
	 * Event levels available for filtering.
	 *
	 * @return array<int,string>
	 */
	public function getSyncEventLevels(): array {
		return self::SYNC_EVENT_LEVELS;
	}

	/**
	 * Clear diagnostic sync events visible to the current user.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @param int               $post_id    Optional source post ID.
	 * @return int|WP_Error
	 */
	public function clearSyncEvents( array $post_types, int $user_id, int $post_id = 0 ): int|WP_Error {
		$post_ids = $post_id > 0 ? $this->validateSyncEventPostId( $post_id, $user_id ) : $this->querySyncEventPostIds( $post_types, $user_id );

		if ( is_wp_error( $post_ids ) ) {
			return $post_ids;
		}

		$cleared = 0;

		foreach ( $post_ids as $event_post_id ) {
			$cleared += count( $this->getSyncEvents( $event_post_id ) );
			delete_post_meta( $event_post_id, self::META_SYNC_EVENTS );
		}

		return $cleared;
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
			'docsync_wp_source_health_order' => true,
			'fields'                         => 'ids',
			'meta_query'                     => $meta_query,
			'no_found_rows'                  => true,
			'post_status'                    => 'any',
			'post_type'                      => $post_types,
			'posts_per_page'                 => self::SOURCE_SCAN_BATCH_SIZE,
			'update_post_meta_cache'         => true,
			'update_post_term_cache'         => false,
		);

		if ( array() !== $matching_ids ) {
			$query_args['post__in'] = $matching_ids;
		}

		$sources         = array();
		$has_more        = false;
		$accessible_seen = 0;
		$page_start      = ( $page - 1 ) * $limit;
		$page_end        = $page_start + $limit;
		$scan_page       = 1;

		add_filter( 'posts_clauses', array( $this, 'applySourceHealthOrder' ), 10, 2 );

		try {
			while ( true ) {
				$query_args['paged'] = $scan_page;
				$query               = new WP_Query( $query_args );

				foreach ( $query->posts as $post_id ) {
					$post_id = absint( $post_id );

					if ( ! $this->userCanSyncPost( $post_id, $user_id ) ) {
						continue;
					}

					$source = $this->formatSource( $post_id );

					if ( null === $source ) {
						continue;
					}

					if ( $accessible_seen >= $page_end ) {
						$has_more = true;
						break 2;
					}

					if ( $accessible_seen >= $page_start ) {
						$sources[] = $source;
					}

					++$accessible_seen;
				}

				if ( count( $query->posts ) < self::SOURCE_SCAN_BATCH_SIZE ) {
					break;
				}

				++$scan_page;
			}
		} finally {
			remove_filter( 'posts_clauses', array( $this, 'applySourceHealthOrder' ), 10 );
		}

		return array(
			'sources'  => $sources,
			'has_more' => $has_more,
			'page'     => $page,
			'per_page' => $limit,
		);
	}

	/**
	 * Order source candidates by operational health before pagination.
	 *
	 * @param array<string,string> $clauses SQL query clauses.
	 * @param WP_Query             $query   WordPress query.
	 * @return array<string,string>
	 */
	public function applySourceHealthOrder( array $clauses, WP_Query $query ): array {
		if ( true !== $query->get( 'docsync_wp_source_health_order' ) ) {
			return $clauses;
		}

		global $wpdb;

		$clauses['orderby'] = (string) $wpdb->prepare(
			"CASE
				WHEN EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS docsync_syncing
					WHERE docsync_syncing.post_id = {$wpdb->posts}.ID
						AND docsync_syncing.meta_key = %s
						AND docsync_syncing.meta_value = %s
				) THEN 1
				WHEN EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS docsync_healthy_status
					WHERE docsync_healthy_status.post_id = {$wpdb->posts}.ID
						AND docsync_healthy_status.meta_key = %s
						AND docsync_healthy_status.meta_value IN ( %s, %s )
				) AND EXISTS (
					SELECT 1 FROM {$wpdb->postmeta} AS docsync_last_synced
					WHERE docsync_last_synced.post_id = {$wpdb->posts}.ID
						AND docsync_last_synced.meta_key = %s
						AND docsync_last_synced.meta_value <> ''
				) THEN 2
				ELSE 0
			END ASC,
			{$wpdb->posts}.post_modified DESC,
			{$wpdb->posts}.ID DESC",
			self::META_SYNC_STATUS,
			self::STATUS_SYNCING,
			self::META_SYNC_STATUS,
			'synced',
			'skipped',
			self::META_LAST_SYNCED
		);

		return $clauses;
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
			'postId'                => $post_id,
			'postType'              => $post->post_type,
			'postStatus'            => $post->post_status,
			'postTitle'             => get_the_title( $post_id ),
			'editUrl'               => is_string( $edit_link ) ? esc_url_raw( $edit_link ) : '',
			'googleFileId'          => $source['google_file_id'],
			'googleDocUrl'          => $source['google_doc_url'],
			'googleTitle'           => $source['google_title'],
			'googleModifiedTime'    => $source['google_modified_time'],
			'googleVersion'         => $source['google_version'],
			'lastHash'              => $source['last_hash'],
			'lastSyncedAt'          => $source['last_synced_at'],
			'lastSyncMethod'        => '' !== $source['last_sync_method'] ? $source['last_sync_method'] : null,
			'layoutPreset'          => '' !== $source['layout_preset'] ? $source['layout_preset'] : null,
			'lastLayoutFingerprint' => $source['last_layout_hash'],
			'syncOwnerUserId'       => $source['sync_owner_user_id'],
			'exportFormat'          => $source['export_format'],
			'elementorSync'         => $source['elementor_sync'],
			'elementorPreset'       => '' !== $source['elementor_preset'] ? $source['elementor_preset'] : null,
			'syncStatus'            => $source['sync_status'],
			'syncError'             => $source['sync_error'],
			'syncProgress'          => $source['sync_progress'],
			'syncStep'              => $source['sync_step'],
			'syncMessage'           => $source['sync_message'],
			'syncStartedAt'         => $source['sync_started_at'],
			'syncUpdatedAt'         => $source['sync_updated_at'],
			'syncErrorCode'         => $source['sync_error_code'],
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
	 * Whether the user can access at least one valid linked source.
	 *
	 * This menu-time predicate avoids source formatting, health joins, and full
	 * summary counts. Candidates are scanned in bounded batches and stop at the
	 * first post accepted by the normal source-operation authority.
	 *
	 * @param int $user_id User ID.
	 */
	public function hasAccessibleSource( int $user_id ): bool {
		$post_types = array_values(
			array_filter(
				$this->getEnabledPostTypes(),
				function ( string $post_type ) use ( $user_id ): bool {
					return $this->userCanEditPostType( $post_type, $user_id );
				}
			)
		);

		if ( array() === $post_types ) {
			return false;
		}

		$query_args = array(
			'fields'                 => 'ids',
			'meta_query'             => array(
				array(
					'key'     => self::META_FILE_ID,
					'value'   => '',
					'compare' => '!=',
				),
			),
			'no_found_rows'          => true,
			'order'                  => 'ASC',
			'orderby'                => 'ID',
			'post_status'            => 'any',
			'post_type'              => $post_types,
			'posts_per_page'         => self::SOURCE_SCAN_BATCH_SIZE,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$scan_page  = 1;

		while ( true ) {
			$query_args['paged'] = $scan_page;
			$query               = new WP_Query( $query_args );

			foreach ( $query->posts as $post_id ) {
				if ( $this->userCanSyncPost( absint( $post_id ), $user_id ) ) {
					return true;
				}
			}

			if ( count( $query->posts ) < self::SOURCE_SCAN_BATCH_SIZE ) {
				return false;
			}

			++$scan_page;
		}
	}

	/**
	 * Summarize accessible sources without exposing source records or identities.
	 *
	 * Candidates are scanned in bounded batches and every record is checked with
	 * the same per-post authority used by source operations. This preserves
	 * custom per-post capability grants without counting inaccessible records.
	 *
	 * @param int $user_id User ID.
	 * @return array{total:int,attention:int,syncing:int,healthy:int,activated:bool,truncated:bool}
	 */
	public function getAccessibleSourceSummary( int $user_id ): array {
		$summary = array(
			'total'     => 0,
			'attention' => 0,
			'syncing'   => 0,
			'healthy'   => 0,
			'activated' => false,
			'truncated' => false,
		);

		foreach ( $this->getEnabledPostTypes() as $post_type ) {
			if ( ! $this->userCanEditPostType( $post_type, $user_id ) ) {
				continue;
			}

			$query_args = array(
				'fields'                 => 'ids',
				'meta_query'             => array(
					array(
						'key'     => self::META_FILE_ID,
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'          => true,
				'post_status'            => 'any',
				'post_type'              => $post_type,
				'posts_per_page'         => self::SOURCE_SCAN_BATCH_SIZE,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			);

			$scan_page = 1;

			while ( true ) {
				$query_args['paged'] = $scan_page;
				$query               = new WP_Query( $query_args );

				foreach ( $query->posts as $post_id ) {
					$post_id = absint( $post_id );

					if ( ! $this->userCanSyncPost( $post_id, $user_id ) || null === $this->getSource( $post_id ) ) {
						continue;
					}

					if ( $summary['total'] >= self::SUMMARY_SOURCE_LIMIT ) {
						$summary['truncated'] = true;
						break 3;
					}

					$status      = sanitize_key( $this->getStringMeta( $post_id, self::META_SYNC_STATUS ) );
					$last_synced = $this->getStringMeta( $post_id, self::META_LAST_SYNCED );
					$is_healthy  = in_array( $status, array( 'synced', 'skipped' ), true ) && '' !== $last_synced;

					++$summary['total'];

					if ( self::STATUS_SYNCING === $status ) {
						++$summary['syncing'];
					} elseif ( $is_healthy ) {
						++$summary['healthy'];
						$summary['activated'] = true;
					} else {
						++$summary['attention'];
					}
				}

				if ( count( $query->posts ) < self::SOURCE_SCAN_BATCH_SIZE ) {
					break;
				}

				++$scan_page;
			}
		}

		return $summary;
	}

	/**
	 * Whether a post type is enabled for Brasth Document Sync.
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
	 * Whether one sync event matches the active filters.
	 *
	 * @param array<string,mixed> $event  Sync event.
	 * @param string              $level  Event level.
	 * @param string              $search Search term.
	 * @param string              $status Sync status.
	 * @param string              $step   Sync step.
	 */
	private function syncEventMatchesFilters( array $event, string $level, string $search, string $status, string $step ): bool {
		if ( '' !== $level && $level !== (string) $event['level'] ) {
			return false;
		}

		if ( '' !== $status && $status !== (string) $event['status'] ) {
			return false;
		}

		if ( '' !== $step && $step !== (string) $event['step'] ) {
			return false;
		}

		return '' === $search || $this->syncEventMatchesSearch( $event, $search );
	}

	/**
	 * Whether one sync event contains the search term in visible diagnostic fields.
	 *
	 * @param array<string,mixed> $event  Sync event.
	 * @param string              $search Search term.
	 */
	private function syncEventMatchesSearch( array $event, string $search ): bool {
		$post_id  = absint( $event['postId'] ?? 0 );
		$haystack = array(
			(string) $post_id,
			(string) ( $event['postTitle'] ?? '' ),
			$post_id > 0 ? (string) get_the_title( $post_id ) : '',
			(string) ( $event['googleTitle'] ?? '' ),
			(string) ( $event['message'] ?? '' ),
			(string) ( $event['errorCode'] ?? '' ),
			(string) ( $event['step'] ?? '' ),
			(string) ( $event['status'] ?? '' ),
		);
		$context  = isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : array();

		foreach ( array( 'outputType', 'layoutPreset', 'elementorMode', 'elementorPreset' ) as $context_key ) {
			$haystack[] = (string) ( $context[ $context_key ] ?? '' );
		}

		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $search ) : strtolower( $search );

		foreach ( $haystack as $value ) {
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

			if ( str_contains( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate one source post for sync event listing.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return array<int,int>|WP_Error
	 */
	private function validateSyncEventPostId( int $post_id, int $user_id ): array|WP_Error {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'docsync_wp_invalid_post',
				__( 'Brasth Document Sync could not find that post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->isPostTypeEnabled( $post->post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'Brasth Document Sync is not enabled for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to edit this post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		if ( null === $this->getSource( $post_id ) ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		return array( $post_id );
	}

	/**
	 * Query source post IDs that have diagnostic sync events.
	 *
	 * @param array<int,string> $post_types Post types.
	 * @param int               $user_id    User ID.
	 * @return array<int,int>
	 */
	private function querySyncEventPostIds( array $post_types, int $user_id ): array {
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

		$query = new WP_Query(
			array(
				'fields'                 => 'ids',
				'meta_query'             => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_FILE_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_SYNC_EVENTS,
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'          => true,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'post_status'            => 'any',
				'post_type'              => $post_types,
				'posts_per_page'         => -1,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$post_ids = array();

		foreach ( $query->posts as $post_id ) {
			$post_id = absint( $post_id );

			if ( $this->userCanSyncPost( $post_id, $user_id ) ) {
				$post_ids[] = $post_id;
			}
		}

		return $post_ids;
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
	 * Get an optional per-post layout preset override.
	 *
	 * Empty or invalid values mean callers should use the site default.
	 *
	 * @param int $post_id Post ID.
	 */
	private function getLayoutPreset( int $post_id ): string {
		$preset_id = sanitize_key( $this->getStringMeta( $post_id, self::META_LAYOUT_PRESET ) );

		return $this->layout_presets->isValidPresetId( $preset_id ) ? $preset_id : '';
	}

	/**
	 * Get the per-post Elementor sync preference.
	 *
	 * Returns null when the user has never set a preference, so callers can
	 * fall back to auto-detection based on the existing post state.
	 *
	 * @param int $post_id Post ID.
	 */
	public function getElementorSync( int $post_id ): ?bool {
		if ( ! metadata_exists( 'post', $post_id, self::META_ELEMENTOR_SYNC ) ) {
			return null;
		}

		$value = get_post_meta( $post_id, self::META_ELEMENTOR_SYNC, true );

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );

			return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Get the optional per-post Elementor preset.
	 *
	 * Empty means use the legacy Elementor converter.
	 *
	 * @param int $post_id Post ID.
	 */
	private function getElementorPreset( int $post_id ): string {
		if ( ! metadata_exists( 'post', $post_id, self::META_ELEMENTOR_PRESET ) ) {
			return '';
		}

		$preset_id = sanitize_key( $this->getStringMeta( $post_id, self::META_ELEMENTOR_PRESET ) );

		return $this->elementor_presets->isValidPresetId( $preset_id ) ? $preset_id : '';
	}

	/**
	 * Whether Elementor sync is explicitly enabled for a post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function isElementorSyncEnabled( int $post_id ): bool {
		$preference = $this->getElementorSync( $post_id );

		return true === $preference;
	}

	/**
	 * Sanitize a diagnostic sync event for storage and output.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $source  Current source.
	 * @param array<string,mixed> $event   Raw event.
	 * @return array<string,mixed>
	 */
	private function sanitizeSyncEvent( int $post_id, array $source, array $event ): array {
		$level = isset( $event['level'] ) ? sanitize_key( (string) $event['level'] ) : 'info';

		if ( ! in_array( $level, self::SYNC_EVENT_LEVELS, true ) ) {
			$level = 'info';
		}

		$event_id = isset( $event['eventId'] ) ? sanitize_key( (string) $event['eventId'] ) : '';

		if ( '' === $event_id ) {
			$event_id = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : str_replace( '.', '-', uniqid( 'sync-event-', true ) );
			$event_id = sanitize_key( $event_id );
		}

		$timestamp = isset( $event['timestamp'] ) ? sanitize_text_field( (string) $event['timestamp'] ) : '';

		if ( '' === $timestamp ) {
			$timestamp = current_time( 'mysql', true );
		}

		return array(
			'eventId'       => $event_id,
			'timestamp'     => $timestamp,
			'level'         => $level,
			'postId'        => $post_id,
			'postTitle'     => $this->truncateDiagnosticText( $event['postTitle'] ?? get_the_title( $post_id ), 160 ),
			'googleTitle'   => $this->truncateDiagnosticText( $event['googleTitle'] ?? ( $source['google_title'] ?? '' ), 160 ),
			'status'        => sanitize_key( (string) ( $event['status'] ?? ( $source['sync_status'] ?? '' ) ) ),
			'step'          => sanitize_key( (string) ( $event['step'] ?? ( $source['sync_step'] ?? '' ) ) ),
			'progress'      => $this->sanitizeProgress( $event['progress'] ?? ( $source['sync_progress'] ?? 0 ) ),
			'message'       => $this->truncateDiagnosticText( $event['message'] ?? ( $source['sync_message'] ?? '' ), 300 ),
			'errorCode'     => sanitize_key( (string) ( $event['errorCode'] ?? ( $source['sync_error_code'] ?? '' ) ) ),
			'syncStartedAt' => sanitize_text_field( (string) ( $event['syncStartedAt'] ?? ( $source['sync_started_at'] ?? '' ) ) ),
			'syncUpdatedAt' => sanitize_text_field( (string) ( $event['syncUpdatedAt'] ?? ( $source['sync_updated_at'] ?? '' ) ) ),
			'context'       => $this->sanitizeSyncEventContext( $event['context'] ?? array() ),
		);
	}

	/**
	 * Sanitize optional diagnostic context flags.
	 *
	 * @param mixed $context Raw context.
	 * @return array<string,bool|string>
	 */
	private function sanitizeSyncEventContext( mixed $context ): array {
		if ( ! is_array( $context ) ) {
			return array();
		}

		$sanitized = array();

		foreach ( array( 'hasLock', 'hasCronEvent' ) as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$sanitized[ $key ] = (bool) $context[ $key ];
			}
		}

		if ( isset( $context['lastHeartbeat'] ) ) {
			$sanitized['lastHeartbeat'] = sanitize_text_field( (string) $context['lastHeartbeat'] );
		}

		if ( isset( $context['lastStep'] ) ) {
			$sanitized['lastStep'] = sanitize_key( (string) $context['lastStep'] );
		}

		$output_type = isset( $context['outputType'] ) ? sanitize_key( (string) $context['outputType'] ) : '';

		if ( in_array( $output_type, array( 'gutenberg', 'elementor' ), true ) ) {
			$sanitized['outputType'] = $output_type;
		}

		$layout_preset = isset( $context['layoutPreset'] ) ? sanitize_key( (string) $context['layoutPreset'] ) : '';

		if ( '' !== $layout_preset && $this->layout_presets->isValidPresetId( $layout_preset ) ) {
			$sanitized['layoutPreset'] = $layout_preset;
		}

		$elementor_mode = isset( $context['elementorMode'] ) ? sanitize_key( (string) $context['elementorMode'] ) : '';

		if ( in_array( $elementor_mode, array( 'preset', 'legacy' ), true ) ) {
			$sanitized['elementorMode'] = $elementor_mode;
		}

		$elementor_preset = isset( $context['elementorPreset'] ) ? sanitize_key( (string) $context['elementorPreset'] ) : '';

		if ( '' !== $elementor_preset && $this->elementor_presets->isValidPresetId( $elementor_preset ) ) {
			$sanitized['elementorPreset'] = $elementor_preset;
		}

		return $sanitized;
	}

	/**
	 * Sanitize and trim short diagnostic text.
	 *
	 * @param mixed $text   Text value.
	 * @param int   $length Maximum length.
	 */
	private function truncateDiagnosticText( mixed $text, int $length ): string {
		$text = $this->redactSensitiveText( sanitize_text_field( (string) $text ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
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
			return $this->sanitizeProgressMessage( $message );
		}

		return 'linked' === $step ? __( 'Linked and ready to sync.', 'brasth-document-sync-for-google-docs' ) : '';
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
		$message = $this->redactSensitiveText( sanitize_text_field( (string) $message ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 240 ) : substr( $message, 0, 240 );
	}

	/**
	 * Sanitize a short source error message.
	 *
	 * @param mixed $message Message.
	 */
	private function sanitizeErrorMessage( mixed $message ): string {
		$message = $this->redactSensitiveText( sanitize_textarea_field( (string) $message ) );

		return function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 500 ) : substr( $message, 0, 500 );
	}

	/**
	 * Redact token-like values before diagnostic text is stored or returned.
	 *
	 * @param string $text Diagnostic text.
	 */
	private function redactSensitiveText( string $text ): string {
		$patterns = array(
			'/Bearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer [redacted]',
			'/(access_token|refresh_token|client_secret|authorization)\s*[:=]\s*[^\s,\]}]+/i' => '$1=[redacted]',
		);

		foreach ( $patterns as $pattern => $replacement ) {
			$redacted = preg_replace( $pattern, $replacement, $text );

			if ( is_string( $redacted ) ) {
				$text = $redacted;
			}
		}

		return $text;
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
	 * Sanitize the Elementor sync preference.
	 *
	 * @param mixed $elementor_sync Elementor sync preference.
	 */
	private function sanitizeElementorSync( mixed $elementor_sync ): bool {
		if ( is_bool( $elementor_sync ) ) {
			return $elementor_sync;
		}

		if ( is_string( $elementor_sync ) ) {
			$value = strtolower( trim( $elementor_sync ) );

			return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
		}

		return (bool) $elementor_sync;
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
	 * Sanitize an optional layout preset override.
	 *
	 * @param mixed $preset_id Layout preset ID.
	 */
	private function sanitizeLayoutPreset( mixed $preset_id ): string {
		$preset_id = sanitize_key( (string) $preset_id );

		return '' !== $preset_id && $this->layout_presets->isValidPresetId( $preset_id ) ? $preset_id : '';
	}

	/**
	 * Sanitize an optional Elementor preset.
	 *
	 * @param mixed $preset_id Elementor preset ID.
	 */
	private function sanitizeElementorPreset( mixed $preset_id ): string {
		$preset_id = sanitize_key( (string) $preset_id );

		return '' !== $preset_id && $this->elementor_presets->isValidPresetId( $preset_id ) ? $preset_id : '';
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
			self::META_LAYOUT_PRESET,
			self::META_LAYOUT_HASH,
			self::META_OWNER_USER_ID,
			self::META_EXPORT_FORMAT,
			self::META_ELEMENTOR_SYNC,
			self::META_ELEMENTOR_PRESET,
			self::META_SYNC_STATUS,
			self::META_SYNC_ERROR,
			self::META_SYNC_PROGRESS,
			self::META_SYNC_STEP,
			self::META_SYNC_MESSAGE,
			self::META_SYNC_STARTED,
			self::META_SYNC_UPDATED,
			self::META_SYNC_ERR_CODE,
			self::META_SYNC_EVENTS,
		);
	}
}
