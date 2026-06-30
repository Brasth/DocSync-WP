<?php
/**
 * Scheduled anonymous telemetry check-ins.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Telemetry;

use DocSyncWP\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the optional weekly telemetry cron event.
 */
final class TelemetryCron {
	public const HOOK = 'docsync_wp_telemetry_checkin';

	private const SCHEDULE = 'weekly';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Telemetry service.
	 *
	 * @var TelemetryService
	 */
	private TelemetryService $telemetry;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings  Settings repository.
	 * @param TelemetryService   $telemetry Telemetry service.
	 */
	public function __construct( SettingsRepository $settings, TelemetryService $telemetry ) {
		$this->settings  = $settings;
		$this->telemetry = $telemetry;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'addWeeklySchedule' ) );
		add_action( 'init', array( $this, 'syncSchedule' ) );
		add_action( 'update_option_docsync_wp_settings', array( $this, 'syncSchedule' ), 10, 0 );
		add_action( self::HOOK, array( $this->telemetry, 'checkIn' ) );
	}

	/**
	 * Add a weekly interval for telemetry check-ins.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules Existing schedules.
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function addWeeklySchedule( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'display'  => __( 'Once weekly', 'brasth-document-sync-for-google-docs' ),
				'interval' => WEEK_IN_SECONDS,
			);
		}

		return $schedules;
	}

	/**
	 * Schedule or unschedule based on telemetry consent.
	 */
	public function syncSchedule(): void {
		if ( ! $this->settings->isTelemetryEnabled() ) {
			self::unschedule();
			return;
		}

		$current_schedule = wp_get_schedule( self::HOOK );

		if ( false !== $current_schedule && self::SCHEDULE !== $current_schedule ) {
			self::unschedule();
		}

		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/**
	 * Unschedule telemetry cron events.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}
}
