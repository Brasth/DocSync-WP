<?php
/**
 * REST controller for one-time HTML ZIP imports.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\ZipImportService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts an uploaded Google Docs "Web Page (.html, zipped)" download.
 */
final class ZipImportController {
	private const MAX_UPLOAD_BYTES = 26214400;
	private const HOURLY_LIMIT     = 10;
	private const RATE_PREFIX      = 'docsync_wp_zip_import_';

	/**
	 * Import service.
	 *
	 * @var ZipImportService
	 */
	private ZipImportService $imports;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Layout presets.
	 *
	 * @var LayoutPresetRegistry
	 */
	private LayoutPresetRegistry $presets;

	/**
	 * Constructor.
	 *
	 * @param ZipImportService     $imports  Import service.
	 * @param SourceRepository     $sources  Source repository.
	 * @param SettingsRepository   $settings Settings repository.
	 * @param LayoutPresetRegistry $presets  Layout presets.
	 */
	public function __construct( ZipImportService $imports, SourceRepository $sources, SettingsRepository $settings, LayoutPresetRegistry $presets ) {
		$this->imports  = $imports;
		$this->sources  = $sources;
		$this->settings = $settings;
		$this->presets  = $presets;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/imports/zip',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'importZip' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * Import an uploaded ZIP into a new draft.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function importZip( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id   = get_current_user_id();
		$post_type = sanitize_key( (string) $request->get_param( 'postType' ) );
		$preset_id = sanitize_key( (string) $request->get_param( 'layoutPreset' ) );

		if (
			'' === $post_type
			|| ! $this->sources->isPostTypeEnabled( $post_type )
			|| ! $this->sources->userCanCreateSyncedPost( $post_type, $user_id )
			|| ! user_can( $user_id, 'upload_files' )
		) {
			return $this->error( 'docsync_wp_forbidden', __( 'You cannot create content of this type.', 'brasth-document-sync-for-google-docs' ), 403 );
		}

		if ( '' !== $preset_id && ! $this->presets->isValidPresetId( $preset_id ) ) {
			return $this->error( 'docsync_wp_invalid_layout_preset', __( 'Choose a supported layout.', 'brasth-document-sync-for-google-docs' ), 400 );
		}

		$bytes = $this->readUpload( $request );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		if ( ! $this->consumeRateLimit( $user_id ) ) {
			return $this->error( 'docsync_wp_zip_import_rate_limited', __( 'Too many imports. Try again in an hour.', 'brasth-document-sync-for-google-docs' ), 429 );
		}

		if ( '' === $preset_id ) {
			$settings  = $this->settings->get();
			$preset_id = (string) ( $settings['default_layout_preset'] ?? '' );
			$preset_id = $this->presets->isValidPresetId( $preset_id ) ? $preset_id : LayoutPresetRegistry::DEFAULT_EXISTING_INSTALL;
		}

		$files  = $request->get_file_params();
		$result = $this->imports->import( $user_id, $bytes, (string) ( $files['file']['name'] ?? '' ), $post_type, $preset_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response(
			array(
				'postId'  => $result['postId'],
				'title'   => $result['title'],
				'editUrl' => (string) get_edit_post_link( $result['postId'], 'raw' ),
			),
			201
		);
	}

	/**
	 * Validate the uploaded file and return its bytes.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return string|WP_Error
	 */
	private function readUpload( WP_REST_Request $request ): string|WP_Error {
		$files = $request->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : array();

		if ( array() === $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $this->error( 'docsync_wp_zip_missing_upload', __( 'Choose a .zip file to import.', 'brasth-document-sync-for-google-docs' ), 400 );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		$size     = (int) ( $file['size'] ?? 0 );
		$name     = (string) ( $file['name'] ?? '' );

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return $this->error( 'docsync_wp_zip_missing_upload', __( 'Choose a .zip file to import.', 'brasth-document-sync-for-google-docs' ), 400 );
		}

		if ( $size <= 0 || $size > min( self::MAX_UPLOAD_BYTES, wp_max_upload_size() ) ) {
			return $this->error( 'docsync_wp_zip_upload_too_large', __( 'That file is too large. Connect Google instead to sync large documents.', 'brasth-document-sync-for-google-docs' ), 413 );
		}

		$type = wp_check_filetype( $name, array( 'zip' => 'application/zip' ) );
		$head = (string) file_get_contents( $tmp_name, false, null, 0, 4 );

		if ( 'zip' !== $type['ext'] || ! str_starts_with( $head, 'PK' ) ) {
			return $this->error( 'docsync_wp_zip_invalid_upload', __( 'That is not a .zip file. In Google Docs choose File > Download > Web Page (.html, zipped).', 'brasth-document-sync-for-google-docs' ), 415 );
		}

		$bytes = file_get_contents( $tmp_name );

		return is_string( $bytes ) ? $bytes : $this->error( 'docsync_wp_zip_missing_upload', __( 'Choose a .zip file to import.', 'brasth-document-sync-for-google-docs' ), 400 );
	}

	/**
	 * Claim one of the user's hourly import slots.
	 *
	 * Each slot is a unique option row created with add_option(), which fails when the row already
	 * exists, so concurrent requests cannot both claim the same slot (unlike a transient read-then-write).
	 *
	 * @param int $user_id User ID.
	 */
	private function consumeRateLimit( int $user_id ): bool {
		$bucket = (int) floor( time() / HOUR_IN_SECONDS );

		for ( $slot = 1; $slot <= self::HOURLY_LIMIT; $slot++ ) {
			if ( add_option( self::RATE_PREFIX . $user_id . '_' . $bucket . '_' . $slot, 1, '', false ) ) {
				if ( 1 === $slot ) {
					$this->clearRateLimitBucket( $user_id, $bucket - 1 );
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Remove an expired bucket's slot rows.
	 *
	 * @param int $user_id User ID.
	 * @param int $bucket  Hour bucket to clear.
	 */
	private function clearRateLimitBucket( int $user_id, int $bucket ): void {
		for ( $slot = 1; $slot <= self::HOURLY_LIMIT; $slot++ ) {
			delete_option( self::RATE_PREFIX . $user_id . '_' . $bucket . '_' . $slot );
		}
	}

	/**
	 * Build a REST error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
