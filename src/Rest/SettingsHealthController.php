<?php
/**
 * REST controller for administrator sync health.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Auth\GoogleConnectionDirectory;
use DocSyncWP\Auth\TokenStore;
use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Settings\SyncHealthActivity;
use DocSyncWP\Settings\SyncHealthSnapshot;
use DocSyncWP\Sync\FolderWatchService;
use DocSyncWP\Sync\SourceRepository;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Local sync health for administrators.
 *
 * The read route never calls Google. The cron route only asks WordPress to
 * spawn due cron in the background and does not report that work as finished.
 */
final class SettingsHealthController {
	/**
	 * Health snapshot builder.
	 *
	 * @var SyncHealthSnapshot
	 */
	private SyncHealthSnapshot $snapshot;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private string $rest_namespace;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository        $settings       Settings repository.
	 * @param TokenStore                $token_store    Token store.
	 * @param GoogleConnectionDirectory $directory     Local connection directory.
	 * @param FolderWatchService        $folder_watches Folder watch service.
	 * @param SourceRepository          $sources        Source repository.
	 * @param string                    $rest_namespace REST namespace.
	 */
	public function __construct(
		SettingsRepository $settings,
		TokenStore $token_store,
		GoogleConnectionDirectory $directory,
		FolderWatchService $folder_watches,
		SourceRepository $sources,
		string $rest_namespace
	) {
		$this->snapshot       = new SyncHealthSnapshot(
			$settings,
			$token_store,
			$directory,
			$folder_watches,
			new SyncHealthActivity( $sources )
		);
		$this->rest_namespace = $rest_namespace;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register sync health routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			$this->rest_namespace,
			'/settings/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getHealth' ),
				'permission_callback' => array( RestPermissions::class, 'canManageSettings' ),
			)
		);

		register_rest_route(
			$this->rest_namespace,
			'/settings/health/run-cron',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'runCron' ),
				'permission_callback' => array( RestPermissions::class, 'canManageSettings' ),
			)
		);
	}

	/**
	 * Return the local sync health snapshot.
	 */
	public function getHealth(): WP_REST_Response {
		return rest_ensure_response( $this->snapshot->build( get_current_user_id() ) );
	}

	/**
	 * Ask WordPress to spawn due cron without running hooks in this request.
	 */
	public function runCron(): WP_REST_Response {
		SyncCron::spawnScheduledSyncs();

		return rest_ensure_response(
			array(
				'requested' => true,
			)
		);
	}
}
