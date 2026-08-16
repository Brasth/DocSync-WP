<?php
/**
 * REST controller for the role-aware operational workspace.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\Elementor\CompatibilityChecker;
use DocSyncWP\Sync\FolderWatchService;
use DocSyncWP\Sync\SourceRepository;
use WP_Post_Type;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes a least-privilege operational bootstrap response.
 */
final class WorkspaceController {
	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Elementor compatibility checker.
	 *
	 * @var CompatibilityChecker
	 */
	private CompatibilityChecker $elementor;

	/**
	 * Folder watch service.
	 *
	 * @var FolderWatchService
	 */
	private FolderWatchService $folder_watches;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository   $settings  Settings repository.
	 * @param SourceRepository     $sources   Source repository.
	 * @param CompatibilityChecker $elementor      Elementor compatibility checker.
	 * @param FolderWatchService   $folder_watches Folder watch service.
	 */
	public function __construct(
		SettingsRepository $settings,
		SourceRepository $sources,
		CompatibilityChecker $elementor,
		FolderWatchService $folder_watches
	) {
		$this->settings       = $settings;
		$this->sources        = $sources;
		$this->elementor      = $elementor;
		$this->folder_watches = $folder_watches;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/workspace',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getWorkspace' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * Get safe site facts and a permission-filtered source summary.
	 */
	public function getWorkspace(): WP_REST_Response {
		$settings             = $this->settings->get();
		$user_id              = get_current_user_id();
		$available_post_types = array_values(
			array_filter(
				$this->settings->getAvailablePostTypes(),
				function ( array $post_type ) use ( $user_id ): bool {
					$name = isset( $post_type['name'] ) ? (string) $post_type['name'] : '';

					return '' !== $name && $this->userCanUseAvailablePostType( $name, $user_id );
				}
			)
		);
		$stored_enabled_types = $this->settings->getEnabledPostTypes();
		$enabled_post_types   = array_values(
			array_filter(
				array_map(
					static function ( array $post_type ): string {
						return (string) $post_type['name'];
					},
					$available_post_types
				),
				static function ( string $post_type ) use ( $stored_enabled_types ): bool {
					return in_array( $post_type, $stored_enabled_types, true );
				}
			)
		);
		$creatable_post_types = array_values(
			array_filter(
				$enabled_post_types,
				function ( string $post_type ) use ( $user_id ): bool {
					return $this->sources->userCanCreateSyncedPost( $post_type, $user_id );
				}
			)
		);

		return rest_ensure_response(
			array(
				'canManageSettings'               => current_user_can( 'manage_options' ),
				'siteConnectionReady'             => $this->settings->hasRequiredOAuthConfiguration(),
				'availablePostTypes'              => $available_post_types,
				'enabledPostTypes'                => $enabled_post_types,
				'creatablePostTypes'              => $creatable_post_types,
				'defaultPostStatus'               => (string) $settings['default_post_status'],
				'defaultExportFormat'             => (string) $settings['default_export_format'],
				'defaultLayoutPreset'             => (string) $settings['default_layout_preset'],
				'availableLayoutPresets'          => $this->settings->getAvailableLayoutPresets(),
				'elementorSyncEnabled'            => (bool) $settings['elementor_sync_enabled'],
				'elementorAvailable'              => $this->elementor->isElementorActive(),
				'availableElementorLayoutPresets' => $this->settings->getAvailableElementorLayoutPresets(),
				'sourceSummary'                   => $this->formatSourceSummary( $user_id ),
				'folderWatches'                   => $this->folder_watches->summarizeForUser( $user_id ),
			)
		);
	}

	/**
	 * Convert the internal summary to the stable wire vocabulary.
	 *
	 * @param int $user_id User ID.
	 * @return array{total:int,attention:int,syncing:int,healthy:int,activated:bool,truncated:bool}
	 */
	private function formatSourceSummary( int $user_id ): array {
		$summary = $this->sources->getAccessibleSourceSummary( $user_id );

		return array(
			'total'     => $summary['total'],
			'attention' => $summary['attention'],
			'syncing'   => $summary['syncing'],
			'healthy'   => $summary['healthy'],
			'activated' => $summary['activated'],
			'truncated' => $summary['truncated'],
		);
	}

	/**
	 * Whether a user can create or edit posts for a safe available post type.
	 *
	 * @param string $post_type Post type name.
	 * @param int    $user_id   User ID.
	 */
	private function userCanUseAvailablePostType( string $post_type, int $user_id ): bool {
		$post_type_object = get_post_type_object( $post_type );

		if ( ! $post_type_object instanceof WP_Post_Type ) {
			return false;
		}

		$edit_capability   = $post_type_object->cap->edit_posts ?? 'edit_posts';
		$create_capability = $post_type_object->cap->create_posts ?? $edit_capability;

		return ( 'do_not_allow' !== $edit_capability && user_can( $user_id, $edit_capability ) )
			|| ( 'do_not_allow' !== $create_capability && user_can( $user_id, $create_capability ) );
	}
}
