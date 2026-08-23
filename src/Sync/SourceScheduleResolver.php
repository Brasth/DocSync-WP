<?php
/**
 * Resolves effective sync intervals for linked sources.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Override, then folder watch, then site interval.
 */
final class SourceScheduleResolver {
	/**
	 * Allowed source and site intervals.
	 *
	 * @var array<int,string>
	 */
	public const INTERVALS = array( 'off', 'hourly', 'twicedaily', 'daily', 'weekly' );

	/**
	 * Seconds added for each interval.
	 *
	 * @var array<string,int>
	 */
	private const SECONDS = array(
		'hourly'     => HOUR_IN_SECONDS,
		'twicedaily' => 12 * HOUR_IN_SECONDS,
		'daily'      => DAY_IN_SECONDS,
		'weekly'     => 7 * DAY_IN_SECONDS,
	);

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Folder watch repository.
	 *
	 * @var FolderWatchRepository
	 */
	private FolderWatchRepository $watches;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository    $settings Settings repository.
	 * @param FolderWatchRepository $watches  Folder watch repository.
	 */
	public function __construct( SettingsRepository $settings, FolderWatchRepository $watches ) {
		$this->settings = $settings;
		$this->watches  = $watches;
	}

	/**
	 * Resolve the effective interval from a source row and collaborators.
	 *
	 * @param array<string,mixed>      $source        Source row.
	 * @param array<string,mixed>|null $watch         Watch record or null.
	 * @param string                   $site_interval Site sync interval.
	 */
	public static function resolve( array $source, ?array $watch, string $site_interval ): string {
		$source_interval = self::sanitizeInterval( (string) ( $source['sync_interval'] ?? '' ) );

		if ( '' !== $source_interval ) {
			return $source_interval;
		}

		$watch_interval = self::sanitizeInterval( (string) ( $watch['syncInterval'] ?? '' ) );

		if ( '' !== $watch_interval ) {
			return $watch_interval;
		}

		$site = self::sanitizeInterval( $site_interval );

		return '' !== $site ? $site : 'off';
	}

	/**
	 * Next UTC mysql datetime for an interval, or empty when off.
	 *
	 * @param string $from_mysql_utc UTC mysql datetime.
	 * @param string $interval       Effective interval.
	 */
	public static function nextSyncAt( string $from_mysql_utc, string $interval ): string {
		$interval = self::sanitizeInterval( $interval );

		if ( '' === $interval || 'off' === $interval || ! isset( self::SECONDS[ $interval ] ) ) {
			return '';
		}

		$from = strtotime( $from_mysql_utc . ' UTC' );

		if ( false === $from ) {
			return '';
		}

		return gmdate( 'Y-m-d H:i:s', $from + self::SECONDS[ $interval ] );
	}

	/**
	 * Resolve the effective interval for a stored source.
	 *
	 * @param array<string,mixed> $source Source row.
	 */
	public function resolveInterval( array $source ): string {
		$watch_id = sanitize_key( (string) ( $source['folder_watch_id'] ?? '' ) );
		$watch    = '' === $watch_id ? null : $this->watches->get( $watch_id );
		$settings = $this->settings->get();
		$site     = isset( $settings['sync_interval'] ) ? sanitize_key( (string) $settings['sync_interval'] ) : 'off';

		return self::resolve( $source, $watch, $site );
	}

	/**
	 * Sanitize a known interval. Empty means inherit.
	 *
	 * @param string $interval Raw interval.
	 */
	private static function sanitizeInterval( string $interval ): string {
		$interval = strtolower( preg_replace( '/[^a-z0-9_-]/', '', $interval ) ?? '' );

		return in_array( $interval, self::INTERVALS, true ) ? $interval : '';
	}
}
