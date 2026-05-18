<?php
/**
 * Scheduled source synchronization.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Cron;

use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and executes the DocSync WP cron job.
 */
final class SyncCron {
	public const HOOK = 'docsync_wp_sync_sources';

	private const BATCH_SIZE = 20;

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
	private SourceRepository $source_repository;

	/**
	 * Sync service.
	 *
	 * @var SyncService
	 */
	private SyncService $sync_service;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings          Settings repository.
	 * @param SourceRepository   $source_repository Source repository.
	 * @param SyncService        $sync_service      Sync service.
	 */
	public function __construct( SettingsRepository $settings, SourceRepository $source_repository, SyncService $sync_service ) {
		$this->settings          = $settings;
		$this->source_repository = $source_repository;
		$this->sync_service      = $sync_service;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'syncSchedule' ) );
		add_action( 'update_option_docsync_wp_settings', array( $this, 'syncSchedule' ), 10, 0 );
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Schedule or unschedule based on current settings.
	 */
	public function syncSchedule(): void {
		$interval = $this->getInterval();

		if ( 'off' === $interval ) {
			self::unschedule();
			return;
		}

		$current_schedule = wp_get_schedule( self::HOOK );

		if ( false !== $current_schedule && $current_schedule !== $interval ) {
			self::unschedule();
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::HOOK );
		}
	}

	/**
	 * Run a small batch of source syncs.
	 */
	public function run(): void {
		$post_ids = $this->getLinkedPostIds();

		foreach ( $post_ids as $post_id ) {
			$source = $this->source_repository->getSource( $post_id );

			if ( null === $source ) {
				continue;
			}

			$owner_user_id = absint( $source['sync_owner_user_id'] ?? 0 );

			if ( $owner_user_id <= 0 ) {
				continue;
			}

			$this->sync_service->syncPost( $post_id, $owner_user_id );
		}
	}

	/**
	 * Unschedule all DocSync cron events.
	 */
	public static function unschedule(): void {
		while ( false !== ( $timestamp = wp_next_scheduled( self::HOOK ) ) ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Get configured interval.
	 */
	private function getInterval(): string {
		$settings = $this->settings->get();
		$interval = isset( $settings['sync_interval'] ) ? sanitize_key( (string) $settings['sync_interval'] ) : 'off';

		return in_array( $interval, array( 'off', 'hourly', 'twicedaily', 'daily' ), true ) ? $interval : 'off';
	}

	/**
	 * Get linked source post IDs for enabled post types.
	 *
	 * @return array<int,int>
	 */
	private function getLinkedPostIds(): array {
		return $this->source_repository->listDueSourcePostIds(
			$this->source_repository->getEnabledPostTypes(),
			self::BATCH_SIZE,
			current_time( 'mysql', true )
		);
	}
}
