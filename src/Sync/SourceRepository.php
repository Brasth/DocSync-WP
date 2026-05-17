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
use WP_Post_Type;

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
	public const META_OWNER_USER_ID = '_docsync_wp_sync_owner_user_id';
	public const META_EXPORT_FORMAT = '_docsync_wp_export_format';
	public const META_SYNC_STATUS   = '_docsync_wp_sync_status';
	public const META_SYNC_ERROR    = '_docsync_wp_sync_error';

	private const EXPORT_FORMAT_MARKDOWN = 'markdown';

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

		return array(
			'google_file_id'       => $file_id,
			'google_doc_url'       => $this->getStringMeta( $post_id, self::META_DOC_URL ),
			'google_title'         => $this->getStringMeta( $post_id, self::META_TITLE ),
			'google_modified_time' => $this->getStringMeta( $post_id, self::META_MODIFIED_TIME ),
			'google_version'       => $this->getStringMeta( $post_id, self::META_VERSION ),
			'last_hash'            => $this->getStringMeta( $post_id, self::META_LAST_HASH ),
			'last_synced_at'       => $this->getStringMeta( $post_id, self::META_LAST_SYNCED ),
			'sync_owner_user_id'   => absint( get_post_meta( $post_id, self::META_OWNER_USER_ID, true ) ),
			'export_format'        => $this->getStringMeta( $post_id, self::META_EXPORT_FORMAT ),
			'sync_status'          => $this->getStringMeta( $post_id, self::META_SYNC_STATUS ),
			'sync_error'           => $this->getStringMeta( $post_id, self::META_SYNC_ERROR ),
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
		update_post_meta( $post_id, self::META_OWNER_USER_ID, isset( $source['sync_owner_user_id'] ) ? absint( $source['sync_owner_user_id'] ) : 0 );
		update_post_meta( $post_id, self::META_EXPORT_FORMAT, $this->sanitizeExportFormat( $source['export_format'] ?? self::EXPORT_FORMAT_MARKDOWN ) );
		update_post_meta( $post_id, self::META_SYNC_STATUS, isset( $source['sync_status'] ) ? sanitize_key( (string) $source['sync_status'] ) : '' );
		update_post_meta( $post_id, self::META_SYNC_ERROR, isset( $source['sync_error'] ) ? sanitize_textarea_field( (string) $source['sync_error'] ) : '' );

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
	 * Sanitize an export format.
	 *
	 * @param mixed $export_format Export format.
	 */
	private function sanitizeExportFormat( mixed $export_format ): string {
		$export_format = sanitize_key( (string) $export_format );

		return self::EXPORT_FORMAT_MARKDOWN === $export_format ? $export_format : self::EXPORT_FORMAT_MARKDOWN;
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
			self::META_OWNER_USER_ID,
			self::META_EXPORT_FORMAT,
			self::META_SYNC_STATUS,
			self::META_SYNC_ERROR,
		);
	}
}
