<?php
/**
 * REST controller for Drive folder watches.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Google\DriveFolderInventory;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\FolderWatchService;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use DocSyncWP\Sync\SourceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Folder inventory and watch routes.
 */
final class FolderWatchController {
	/**
	 * Folder watch service.
	 *
	 * @var FolderWatchService
	 */
	private FolderWatchService $folder_watches;

	/**
	 * Folder inventory.
	 *
	 * @var DriveFolderInventory
	 */
	private DriveFolderInventory $inventory;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Layout presets.
	 *
	 * @var LayoutPresetRegistry
	 */
	private LayoutPresetRegistry $layout_presets;

	/**
	 * Elementor presets.
	 *
	 * @var ElementorPresetRegistry
	 */
	private ElementorPresetRegistry $elementor_presets;

	/**
	 * Constructor.
	 *
	 * @param FolderWatchService      $folder_watches    Folder watch service.
	 * @param DriveFolderInventory    $inventory         Folder inventory.
	 * @param SourceRepository        $sources           Source repository.
	 * @param LayoutPresetRegistry    $layout_presets    Layout presets.
	 * @param ElementorPresetRegistry $elementor_presets Elementor presets.
	 */
	public function __construct(
		FolderWatchService $folder_watches,
		DriveFolderInventory $inventory,
		SourceRepository $sources,
		LayoutPresetRegistry $layout_presets,
		ElementorPresetRegistry $elementor_presets
	) {
		$this->folder_watches    = $folder_watches;
		$this->inventory         = $inventory;
		$this->sources           = $sources;
		$this->layout_presets    = $layout_presets;
		$this->elementor_presets = $elementor_presets;
	}

	/**
	 * Register routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/drive/folders/(?P<folderId>[^/]+)/documents',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listFolderDocuments' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'listWatches' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createWatch' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/(?P<id>[a-z0-9-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getWatch' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'updateWatch' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'deleteWatch' ),
					'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/(?P<id>[a-z0-9-]+)/scan',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scanWatch' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/(?P<id>[a-z0-9-]+)/pause',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pauseWatch' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/(?P<id>[a-z0-9-]+)/retry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'retryWatch' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/folders/(?P<id>[a-z0-9-]+)/resume',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resumeWatch' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * List Google Docs in a folder for the confirm inventory.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listFolderDocuments( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id           = get_current_user_id();
		$inventory_user_id = $user_id;
		$watch_id          = sanitize_text_field( (string) $request->get_param( 'watch_id' ) );

		if ( '' !== $watch_id ) {
			$watch = $this->folder_watches->getForUser( $watch_id, $user_id );

			if ( is_wp_error( $watch ) ) {
				return $watch;
			}

			$owner_user_id = absint( $watch['ownerUserId'] ?? 0 );

			if ( $owner_user_id > 0 ) {
				$inventory_user_id = $owner_user_id;
			}
		}

		$listing = $this->inventory->listDocuments(
			$inventory_user_id,
			sanitize_text_field( (string) $request->get_param( 'folderId' ) ),
			sanitize_text_field( (string) $request->get_param( 'drive_id' ) ),
			rest_sanitize_boolean( $request->get_param( 'include_subfolders' ) )
		);

		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		return rest_ensure_response( $listing );
	}

	/**
	 * List watches for the current user.
	 */
	public function listWatches(): WP_REST_Response {
		return rest_ensure_response(
			array(
				'folders' => $this->folder_watches->listForUser( get_current_user_id() ),
			)
		);
	}

	/**
	 * Create a folder watch.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->getRequestParams(
			$request,
			array(
				'folderId',
				'driveId',
				'includeSubfolders',
				'confirmRoot',
				'postType',
				'postStatus',
				'syncInterval',
				'layoutPreset',
				'elementorSync',
				'elementorPreset',
				'excludeFileIds',
			)
		);

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$post_type = sanitize_key( (string) ( $params['postType'] ?? 'post' ) );
		$user_id   = get_current_user_id();

		if ( ! $this->sources->userCanCreateSyncedPost( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_cannot_create_post',
				__( 'You do not have permission to create a synced draft for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		$post_status = sanitize_key( (string) ( $params['postStatus'] ?? 'draft' ) );

		if ( 'publish' === $post_status && ! $this->sources->userCanPublishSyncedPost( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_cannot_publish_post',
				__( 'You do not have permission to publish synced posts for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		$layout = $this->sanitizeLayoutPreset( $params['layoutPreset'] ?? '' );

		if ( is_wp_error( $layout ) ) {
			return $layout;
		}

		$elementor_preset = $this->sanitizeElementorPreset( $params['elementorPreset'] ?? '' );

		if ( is_wp_error( $elementor_preset ) ) {
			return $elementor_preset;
		}

		$params['layoutPreset']    = $layout;
		$params['elementorPreset'] = $elementor_preset;
		$created                   = $this->folder_watches->create( $user_id, $params );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$response = rest_ensure_response( $created );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * Get one watch.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function getWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$watch = $this->folder_watches->getForUser(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return is_wp_error( $watch ) ? $watch : rest_ensure_response( $watch );
	}

	/**
	 * Update editable watch fields.
	 *
	 * Owner-or-admin access is enforced inside FolderWatchService::update().
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function updateWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params = $this->getRequestParams(
			$request,
			array(
				'syncInterval',
				'postStatus',
				'layoutPreset',
				'elementorSync',
				'elementorPreset',
				'includeSubfolders',
				'excludedFileIds',
			)
		);

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		if ( isset( $params['layoutPreset'] ) ) {
			$layout = $this->sanitizeLayoutPreset( $params['layoutPreset'] );

			if ( is_wp_error( $layout ) ) {
				return $layout;
			}

			$params['layoutPreset'] = $layout;
		}

		if ( isset( $params['elementorPreset'] ) ) {
			$elementor_preset = $this->sanitizeElementorPreset( $params['elementorPreset'] );

			if ( is_wp_error( $elementor_preset ) ) {
				return $elementor_preset;
			}

			$params['elementorPreset'] = $elementor_preset;
		}

		$updated = $this->folder_watches->update(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id(),
			$params
		);

		return is_wp_error( $updated ) ? $updated : rest_ensure_response( $updated );
	}

	/**
	 * Delete a watch.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deleteWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$deleted = $this->folder_watches->delete(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return is_wp_error( $deleted ) ? $deleted : rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Scan a watch now.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scanWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$watch = $this->folder_watches->requestScan(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return is_wp_error( $watch ) ? $watch : rest_ensure_response( $watch );
	}

	/**
	 * Pause a watch.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pauseWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$watch = $this->folder_watches->pause(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return is_wp_error( $watch ) ? $watch : rest_ensure_response( $watch );
	}

	/**
	 * Retry failed imports.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retryWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$watch = $this->folder_watches->retryFailed(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return is_wp_error( $watch ) ? $watch : rest_ensure_response( $watch );
	}

	/**
	 * Resume a watch.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function resumeWatch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$watch = $this->folder_watches->resume(
			sanitize_key( (string) $request->get_param( 'id' ) ),
			get_current_user_id()
		);

		return is_wp_error( $watch ) ? $watch : rest_ensure_response( $watch );
	}

	/**
	 * Parse a JSON object and reject unknown keys.
	 *
	 * @param WP_REST_Request   $request      REST request.
	 * @param array<int,string> $allowed_keys Allowed keys.
	 * @return array<string,mixed>|WP_Error
	 */
	private function getRequestParams( WP_REST_Request $request, array $allowed_keys ): array|WP_Error {
		$params = $request->get_json_params();

		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}

		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'docsync_wp_invalid_folder_payload',
				__( 'Brasth Document Sync folder requests require a JSON object.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$unknown = array_diff( array_keys( $params ), $allowed_keys );

		if ( array() !== $unknown ) {
			return new WP_Error(
				'docsync_wp_unknown_folder_fields',
				__( 'Brasth Document Sync received unknown folder request fields.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $params;
	}

	/**
	 * Sanitize an optional Gutenberg preset.
	 *
	 * @param mixed $preset Raw preset.
	 * @return string|WP_Error
	 */
	private function sanitizeLayoutPreset( mixed $preset ): string|WP_Error {
		$preset = sanitize_key( (string) $preset );

		if ( '' === $preset ) {
			return '';
		}

		if ( null === $this->layout_presets->getPreset( $preset ) ) {
			return new WP_Error(
				'docsync_wp_invalid_layout_preset',
				__( 'Brasth Document Sync received an unknown layout preset.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $preset;
	}

	/**
	 * Sanitize an optional Elementor preset.
	 *
	 * @param mixed $preset Raw preset.
	 * @return string|WP_Error
	 */
	private function sanitizeElementorPreset( mixed $preset ): string|WP_Error {
		$preset = sanitize_key( (string) $preset );

		if ( '' === $preset ) {
			return '';
		}

		if ( null === $this->elementor_presets->getPreset( $preset ) ) {
			return new WP_Error(
				'docsync_wp_invalid_elementor_preset',
				__( 'Brasth Document Sync received an unknown Elementor layout preset.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		return $preset;
	}
}
