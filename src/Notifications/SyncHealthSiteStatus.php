<?php
/**
 * Site Health test for Brasth Document Sync.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Notifications;

use DocSyncWP\Admin\AdminPage;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\FolderWatchService;
use DocSyncWP\Sync\SourceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reports stalled WP-Cron and sources in error through Site Health.
 */
final class SyncHealthSiteStatus {
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
	 * Folder watch service (cron health snapshot).
	 *
	 * @var FolderWatchService
	 */
	private FolderWatchService $folder_watches;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings       Settings repository.
	 * @param SourceRepository   $sources        Source repository.
	 * @param FolderWatchService $folder_watches Folder watch service.
	 */
	public function __construct( SettingsRepository $settings, SourceRepository $sources, FolderWatchService $folder_watches ) {
		$this->settings       = $settings;
		$this->sources        = $sources;
		$this->folder_watches = $folder_watches;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'addTest' ) );
	}

	/**
	 * Add the direct Site Health test.
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public function addTest( array $tests ): array {
		$tests['direct']['docsync_wp_sync_health'] = array(
			'label' => __( 'Google Docs sync', 'brasth-document-sync-for-google-docs' ),
			'test'  => array( $this, 'runTest' ),
		);

		return $tests;
	}

	/**
	 * Run the test for the current administrator.
	 *
	 * @return array<string,mixed>
	 */
	public function runTest(): array {
		$result = array(
			'label'       => __( 'Google Docs sync is healthy', 'brasth-document-sync-for-google-docs' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Google Docs sync', 'brasth-document-sync-for-google-docs' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Scheduled syncs are running and no linked source reports an error.', 'brasth-document-sync-for-google-docs' ) . '</p>',
			'actions'     => '',
			'test'        => 'docsync_wp_sync_health',
		);

		if ( ! $this->settings->hasRequiredOAuthConfiguration() ) {
			return $result;
		}

		$sources_url = admin_url( 'admin.php?page=' . AdminPage::SOURCES_MENU_SLUG . '&status=attention' );
		$cron        = $this->folder_watches->cronHealth();
		$summary     = $this->sources->getAccessibleSourceSummary( get_current_user_id() );

		if ( ! empty( $cron['stalled'] ) ) {
			$result['status']      = 'critical';
			$result['label']       = __( 'Google Docs scheduled sync is not running', 'brasth-document-sync-for-google-docs' );
			$result['description'] = '<p>' . esc_html__( 'WP-Cron has not run recently, so scheduled Google Docs syncs are stalled. Low-traffic sites and sites with DISABLE_WP_CRON need a real server cron job that calls wp-cron.php.', 'brasth-document-sync-for-google-docs' ) . '</p>';
		} elseif ( $summary['attention'] > 0 ) {
			$result['status']      = 'recommended';
			$result['label']       = __( 'Some Google Docs sources need attention', 'brasth-document-sync-for-google-docs' );
			$result['description'] = '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of sources. */
					_n( '%d linked source has a sync error, is waiting for an update review, or needs a reconnect.', '%d linked sources have a sync error, are waiting for an update review, or need a reconnect.', $summary['attention'], 'brasth-document-sync-for-google-docs' ),
					$summary['attention']
				)
			) . '</p>';
			$result['actions'] = '<p><a href="' . esc_url( $sources_url ) . '">' . esc_html__( 'Review sources', 'brasth-document-sync-for-google-docs' ) . '</a></p>';
		}

		return $result;
	}
}
