<?php
/**
 * Scheduled source synchronization.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Cron;

use DocSyncWP\Cron\CronHeartbeat;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and executes the Brasth Document Sync cron job.
 */
final class SyncCron {
	public const HOOK          = 'docsync_wp_sync_sources';
	public const SOURCE_HOOK   = 'docsync_wp_sync_source';
	public const CONTINUE_HOOK = 'docsync_wp_sync_sources_continue';

	private const BATCH_SIZE        = 20;
	private const MAX_CONTINUATIONS = 20;
	private const CONTINUE_OPTION   = 'docsync_wp_sync_continuations';

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
		add_action( self::CONTINUE_HOOK, array( $this, 'runContinue' ) );
		add_action( self::SOURCE_HOOK, array( $this, 'runSingle' ), 10, 2 );
	}

	/**
	 * Schedule or unschedule based on current settings.
	 */
	public function syncSchedule(): void {
		$interval = $this->getInterval();

		if ( ! $this->settings->hasRequiredOAuthConfiguration() || 'off' === $interval ) {
			self::unscheduleRecurring();
			return;
		}

		$current_schedule = wp_get_schedule( self::HOOK );

		if ( false !== $current_schedule && $current_schedule !== $interval ) {
			self::unscheduleRecurring();
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::HOOK );
		}
	}

	/**
	 * Run a small batch of source syncs.
	 */
	public function run(): void {
		if ( ! $this->settings->hasRequiredOAuthConfiguration() ) {
			return;
		}

		update_option( self::CONTINUE_OPTION, 0, false );
		$this->processDueBatch();
	}

	/**
	 * Continue draining due sources after a full batch.
	 */
	public function runContinue(): void {
		if ( ! $this->settings->hasRequiredOAuthConfiguration() ) {
			return;
		}

		$count = absint( get_option( self::CONTINUE_OPTION, 0 ) ) + 1;
		update_option( self::CONTINUE_OPTION, $count, false );

		if ( $count > self::MAX_CONTINUATIONS ) {
			return;
		}

		$this->processDueBatch();
	}

	/**
	 * Sync one due batch and schedule a continuation when the batch is full.
	 */
	private function processDueBatch(): void {
		( new CronHeartbeat() )->mark();

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

		if ( count( $post_ids ) === self::BATCH_SIZE ) {
			$this->scheduleContinuation();
		}
	}

	/**
	 * Queue one continuation tick when none is already pending.
	 */
	private function scheduleContinuation(): void {
		if ( false !== wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + 30, self::CONTINUE_HOOK );
		self::spawnScheduledSyncs();
	}

	/**
	 * Schedule one source sync as soon as WP-Cron can run.
	 *
	 * @param int  $post_id Post ID.
	 * @param int  $user_id User ID whose Google token should run the sync.
	 * @param bool $spawn  Whether to spawn WP-Cron immediately.
	 * @return bool|WP_Error
	 */
	public static function scheduleSourceSync( int $post_id, int $user_id, bool $spawn = true ): bool|WP_Error {
		$post_id = absint( $post_id );
		$user_id = absint( $user_id );

		if ( $post_id <= 0 || $user_id <= 0 ) {
			return new WP_Error(
				'docsync_wp_invalid_background_sync',
				__( 'Brasth Document Sync could not queue this sync request.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$args = array( $post_id, $user_id );

		if ( false === wp_next_scheduled( self::SOURCE_HOOK, $args ) ) {
			$scheduled = wp_schedule_single_event( time(), self::SOURCE_HOOK, $args, true );

			if ( is_wp_error( $scheduled ) ) {
				return $scheduled;
			}

			if ( false === $scheduled ) {
				return new WP_Error(
					'docsync_wp_background_sync_not_scheduled',
					__( 'Brasth Document Sync could not queue this sync request.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 500 )
				);
			}
		}

		if ( $spawn ) {
			self::spawnScheduledSyncs();
		}

		return true;
	}

	/**
	 * Ask WordPress to run due sync events.
	 */
	public static function spawnScheduledSyncs(): void {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Whether a single-source sync event is already scheduled.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID whose Google token should run the sync.
	 */
	public static function hasScheduledSourceSync( int $post_id, int $user_id ): bool {
		return false !== self::getScheduledSourceSyncTimestamp( $post_id, $user_id );
	}

	/**
	 * Get the timestamp for a queued source sync event.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID whose Google token should run the sync.
	 * @return int|false Unix timestamp when scheduled, otherwise false.
	 */
	public static function getScheduledSourceSyncTimestamp( int $post_id, int $user_id ): int|false {
		$post_id = absint( $post_id );
		$user_id = absint( $user_id );

		if ( $post_id <= 0 || $user_id <= 0 ) {
			return false;
		}

		return wp_next_scheduled( self::SOURCE_HOOK, array( $post_id, $user_id ) );
	}

	/**
	 * Remove queued source sync events for one source owner pair.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID whose Google token would run the sync.
	 */
	public static function unscheduleSourceSync( int $post_id, int $user_id ): void {
		$post_id = absint( $post_id );
		$user_id = absint( $user_id );

		if ( $post_id <= 0 || $user_id <= 0 ) {
			return;
		}

		$args      = array( $post_id, $user_id );
		$timestamp = wp_next_scheduled( self::SOURCE_HOOK, $args );

		while ( false !== $timestamp ) {
			$unscheduled = wp_unschedule_event( $timestamp, self::SOURCE_HOOK, $args );

			if ( false === $unscheduled || is_wp_error( $unscheduled ) ) {
				return;
			}

			$timestamp = wp_next_scheduled( self::SOURCE_HOOK, $args );
		}
	}

	/**
	 * Run a queued source sync.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID whose Google token should run the sync.
	 */
	public function runSingle( int $post_id, int $user_id ): void {
		$post_id = absint( $post_id );
		$user_id = absint( $user_id );

		if ( $post_id <= 0 || $user_id <= 0 ) {
			return;
		}

		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source || SyncService::STATUS_SYNCING !== (string) $source['sync_status'] ) {
			return;
		}

		if ( ! $this->settings->hasRequiredOAuthConfiguration() ) {
			$this->sync_service->markSyncError(
				$post_id,
				__( 'Brasth Document Sync could not run this background sync because the site OAuth configuration is incomplete.', 'brasth-document-sync-for-google-docs' )
			);
			return;
		}

		if ( ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) ) {
			$this->sync_service->markSyncError(
				$post_id,
				__( 'Brasth Document Sync could not run this background sync because permission changed.', 'brasth-document-sync-for-google-docs' )
			);
			return;
		}

		$result = $this->sync_service->syncPost( $post_id, $user_id );

		if (
			is_wp_error( $result )
			&& ! in_array( $result->get_error_code(), array( 'docsync_wp_sync_locked', 'docsync_wp_source_changed', 'docsync_wp_source_not_found' ), true )
		) {
			$this->sync_service->markSyncError( $post_id, $result );
		}
	}

	/**
	 * Unschedule all plugin cron events.
	 */
	public static function unschedule(): void {
		self::unscheduleRecurring();
		wp_clear_scheduled_hook( self::SOURCE_HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
		delete_option( self::CONTINUE_OPTION );
	}

	/**
	 * Unschedule recurring source sync events.
	 */
	private static function unscheduleRecurring(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Get configured interval.
	 */
	private function getInterval(): string {
		$settings = $this->settings->get();
		$interval = isset( $settings['sync_interval'] ) ? sanitize_key( (string) $settings['sync_interval'] ) : 'off';

		return in_array( $interval, array( 'off', 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $interval : 'off';
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
