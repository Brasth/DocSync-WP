<?php
/**
 * Records scheduled sync failures and emails a daily digest.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Notifications;

use DocSyncWP\Admin\AdminPage;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\SourceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Collects failures from the scheduled sync hook and sends one digest per day.
 */
final class SyncFailureDigest {
	public const FAILED_ACTION  = 'docsync_wp_scheduled_sync_failed';
	public const CRON_HOOK      = 'docsync_wp_failure_digest';
	public const PENDING_OPTION = 'docsync_wp_pending_failures';

	private const MAX_LISTED = 20;

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
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Settings repository.
	 * @param SourceRepository   $sources  Source repository.
	 */
	public function __construct( SettingsRepository $settings, SourceRepository $sources ) {
		$this->settings = $settings;
		$this->sources  = $sources;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( self::FAILED_ACTION, array( $this, 'record' ), 10, 2 );
		add_action( 'init', array( $this, 'syncSchedule' ) );
		add_action( 'update_option_docsync_wp_settings', array( $this, 'syncSchedule' ), 10, 0 );
		add_action( self::CRON_HOOK, array( $this, 'send' ) );
	}

	/**
	 * Record one scheduled sync failure.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $code    Error code.
	 */
	public function record( int $post_id, string $code ): void {
		if ( 'off' === $this->mode() ) {
			return;
		}

		$pending = FailureDigestPlanner::record( $this->pending(), absint( $post_id ), sanitize_key( $code ), time() );

		update_option( self::PENDING_OPTION, $pending, false );
	}

	/**
	 * Schedule or unschedule the daily digest.
	 */
	public function syncSchedule(): void {
		if ( 'off' === $this->mode() || ! $this->settings->hasRequiredOAuthConfiguration() ) {
			self::unschedule();
			return;
		}

		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Send pending failures and clear the list.
	 */
	public function send(): void {
		$pending = $this->pending();

		if ( array() === $pending ) {
			return;
		}

		delete_option( self::PENDING_OPTION );

		$admin_ids = array_map(
			'absint',
			get_users(
				array(
					'capability' => 'manage_options',
					'fields'     => 'ID',
				)
			)
		);
		$plan      = FailureDigestPlanner::plan(
			$pending,
			$this->mode(),
			$admin_ids,
			function ( int $post_id ): int {
				$source = $this->sources->getSource( $post_id );

				return null === $source ? 0 : absint( $source['sync_owner_user_id'] ?? 0 );
			},
			fn ( int $user_id, int $post_id ): bool => $this->sources->userCanSyncPost( $post_id, $user_id )
		);

		foreach ( $plan as $user_id => $entries ) {
			$user = get_userdata( $user_id );

			if ( false === $user || '' === $user->user_email || array() === $this->stillFailing( $entries ) ) {
				continue;
			}

			wp_mail(
				$user->user_email,
				self::subject(),
				$this->body( $entries )
			);
		}
	}

	/**
	 * Unschedule digest events.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Email subject.
	 */
	private static function subject(): string {
		return sprintf(
			/* translators: %s: site name. */
			__( '[%s] Google Docs sync needs attention', 'brasth-document-sync-for-google-docs' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * Build the plain-text email body.
	 *
	 * @param array<int,array{post_id:int,code:string,at:int}> $entries Failures for one recipient.
	 */
	private function body( array $entries ): string {
		$lines = array( __( 'These posts failed their last scheduled sync from Google Docs. Your published content was not changed.', 'brasth-document-sync-for-google-docs' ), '' );

		$entries = $this->stillFailing( $entries );

		foreach ( array_slice( $entries, 0, self::MAX_LISTED ) as $entry ) {
			$source  = $this->sources->getSource( $entry['post_id'] );
			$title   = wp_specialchars_decode( get_the_title( $entry['post_id'] ), ENT_QUOTES );
			$message = null !== $source ? (string) ( $source['sync_error'] ?? '' ) : '';
			$lines[] = '- ' . ( '' !== $title ? $title : '#' . $entry['post_id'] ) . ( '' !== $message ? ': ' . $message : '' );
		}

		$extra = count( $entries ) - self::MAX_LISTED;

		if ( $extra > 0 ) {
			/* translators: %d: number of additional failing posts. */
			$lines[] = sprintf( _n( 'and %d more post.', 'and %d more posts.', $extra, 'brasth-document-sync-for-google-docs' ), $extra );
		}

		$lines[] = '';
		$lines[] = __( 'Review them here:', 'brasth-document-sync-for-google-docs' );
		$lines[] = admin_url( 'admin.php?page=' . AdminPage::SOURCES_MENU_SLUG . '&status=error' );
		$lines[] = '';
		$lines[] = __( 'To stop these emails, change "Email me when scheduled sync fails" in Brasth Document Sync settings.', 'brasth-document-sync-for-google-docs' );

		return implode( "\n", $lines );
	}

	/**
	 * Drop posts that recovered since the failure and list each post once.
	 *
	 * @param array<int,array{post_id:int,code:string,at:int}> $entries Failures.
	 * @return array<int,array{post_id:int,code:string,at:int}>
	 */
	private function stillFailing( array $entries ): array {
		$seen   = array();
		$result = array();

		foreach ( $entries as $entry ) {
			$source = $this->sources->getSource( $entry['post_id'] );

			if ( isset( $seen[ $entry['post_id'] ] ) || null === $source || 'error' !== (string) ( $source['sync_status'] ?? '' ) ) {
				continue;
			}

			$seen[ $entry['post_id'] ] = true;
			$result[]                  = $entry;
		}

		return $result;
	}

	/**
	 * Current alert mode.
	 */
	private function mode(): string {
		$settings = $this->settings->get();

		return (string) ( $settings['failure_alerts'] ?? 'owners_and_admin' );
	}

	/**
	 * Pending failures from storage.
	 *
	 * @return array<int,array{post_id:int,code:string,at:int}>
	 */
	private function pending(): array {
		$stored = get_option( self::PENDING_OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}
}
