<?php
/**
 * Local administrator sync health checks.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Settings;

use DocSyncWP\Auth\GoogleConnectionDirectory;
use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Auth\TokenStore;
use DocSyncWP\Sync\FolderWatchService;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the sync health response from local WordPress state.
 *
 * No check calls Google. Quota stays unknown. Access-token freshness uses the
 * stored expiry only; a refresh token is never given a guessed expiry.
 */
final class SyncHealthSnapshot {
	private const MIN_WORDPRESS                       = '6.4';
	private const MIN_PHP                             = '8.1';
	private const ACCESS_TOKEN_FRESHNESS_SKEW_SECONDS = 60;
	private const ACTION_CRON                         = 'cron';
	private const ACTION_CONNECTIONS                  = 'connections';
	private const ACTION_CREDENTIALS                  = 'credentials';
	private const ACTION_ACCOUNT                      = 'account';
	private const ACTION_QUOTA                        = 'quota';

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Token store.
	 *
	 * @var TokenStore
	 */
	private TokenStore $token_store;

	/**
	 * Connection directory.
	 *
	 * @var GoogleConnectionDirectory
	 */
	private GoogleConnectionDirectory $directory;

	/**
	 * Folder watch service.
	 *
	 * @var FolderWatchService
	 */
	private FolderWatchService $folder_watches;

	/**
	 * Activity summary.
	 *
	 * @var SyncHealthActivity
	 */
	private SyncHealthActivity $activity;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository        $settings       Settings repository.
	 * @param TokenStore                $token_store    Token store.
	 * @param GoogleConnectionDirectory $directory      Connection directory.
	 * @param FolderWatchService        $folder_watches Folder watch service.
	 * @param SyncHealthActivity        $activity       Activity summary.
	 */
	public function __construct(
		SettingsRepository $settings,
		TokenStore $token_store,
		GoogleConnectionDirectory $directory,
		FolderWatchService $folder_watches,
		SyncHealthActivity $activity
	) {
		$this->settings       = $settings;
		$this->token_store    = $token_store;
		$this->directory      = $directory;
		$this->folder_watches = $folder_watches;
		$this->activity       = $activity;
	}

	/**
	 * Build the health payload for one administrator.
	 *
	 * @param int $user_id Current user ID.
	 * @return array<string,mixed>
	 */
	public function build( int $user_id ): array {
		$now      = time();
		$activity = $this->activity->build( $user_id, $now );
		$versions = $this->versions();

		return array(
			'checkedAt'    => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
			'checks'       => array(
				$this->cronCheck(),
				$this->connectionsCheck(),
				$this->credentialsCheck(),
				$this->accountCheck( $user_id ),
				$this->quotaCheck(),
				$this->uploadsCheck(),
				$this->versionsCheck( $versions ),
			),
			'activity'     => $activity['activity'],
			'versions'     => $versions,
			'reportEvents' => $activity['reportEvents'],
		);
	}

	/**
	 * Cron liveness from the shared heartbeat.
	 *
	 * A pass requires a recorded tick that is not stalled. Requesting cron does
	 * not create that tick.
	 *
	 * @return array<string,mixed>
	 */
	private function cronCheck(): array {
		$health   = $this->folder_watches->cronHealth();
		$last_run = $this->safeTimestamp( isset( $health['lastRunAt'] ) ? (string) $health['lastRunAt'] : '' );
		$stalled  = isset( $health['stalled'] ) && true === $health['stalled'];
		$disabled = isset( $health['wpCronDisabled'] ) && true === $health['wpCronDisabled'];
		$title    = __( 'Cron liveness', 'brasth-document-sync-for-google-docs' );
		$fix      = array(
			__( 'Point a system scheduler at WordPress cron. WP-Cron does not run by itself on a quiet site.', 'brasth-document-sync-for-google-docs' ),
			__( 'Refresh sync health after a tick is recorded. Asking WordPress to spawn cron does not mean the tick finished.', 'brasth-document-sync-for-google-docs' ),
		);

		if ( $stalled ) {
			$description = '' !== $last_run
				? sprintf(
					/* translators: %s: UTC timestamp of the last recorded cron tick. */
					__( 'Scheduled sync looks stalled. The last recorded tick was %s.', 'brasth-document-sync-for-google-docs' ),
					$last_run
				)
				: __( 'Scheduled sync looks stalled. No tick is recorded inside the stall window.', 'brasth-document-sync-for-google-docs' );

			return $this->check( self::ACTION_CRON, 'warning', $title, $description, self::ACTION_CRON, $fix );
		}

		if ( '' === $last_run ) {
			$description = $disabled
				? __( 'WordPress cron spawning is disabled, and no Brasth Document Sync tick is recorded yet.', 'brasth-document-sync-for-google-docs' )
				: __( 'No Brasth Document Sync cron tick is recorded. That is normal when nothing is scheduled, or before the first tick is due.', 'brasth-document-sync-for-google-docs' );
			$status      = $disabled ? 'warning' : 'unknown';

			return $this->check( self::ACTION_CRON, $status, $title, $description, self::ACTION_CRON, $fix );
		}

		if ( $disabled ) {
			return $this->check(
				self::ACTION_CRON,
				'pass',
				$title,
				sprintf(
					/* translators: %s: UTC timestamp of the last recorded cron tick. */
					__( 'WordPress cron spawning is disabled. A tick was still recorded at %s, so an external scheduler appears to be running.', 'brasth-document-sync-for-google-docs' ),
					$last_run
				)
			);
		}

		return $this->check(
			self::ACTION_CRON,
			'pass',
			$title,
			sprintf(
				/* translators: %s: UTC timestamp of the last recorded cron tick. */
				__( 'A Brasth Document Sync cron tick was recorded at %s and is inside the stall window.', 'brasth-document-sync-for-google-docs' ),
				$last_run
			)
		);
	}

	/**
	 * Eligible editor connections from the directory summary.
	 *
	 * @return array<string,mixed>
	 */
	private function connectionsCheck(): array {
		$listed      = $this->directory->listConnections( 1, 1 );
		$summary     = isset( $listed['summary'] ) && is_array( $listed['summary'] ) ? $listed['summary'] : array();
		$connected   = isset( $summary['connected'] ) ? absint( $summary['connected'] ) : 0;
		$missing     = isset( $summary['notConnected'] ) ? absint( $summary['notConnected'] ) : 0;
		$reconnect   = isset( $summary['reconnectRequired'] ) ? absint( $summary['reconnectRequired'] ) : 0;
		$title       = __( 'Editor connections', 'brasth-document-sync-for-google-docs' );
		$description = sprintf(
			/* translators: 1: connected editors, 2: editors without a connection, 3: editors who need to reconnect. */
			__( 'Eligible editors: %1$d connected, %2$d not connected, %3$d need to reconnect. Refresh-token expiry is unavailable.', 'brasth-document-sync-for-google-docs' ),
			$connected,
			$missing,
			$reconnect
		);

		if ( $reconnect > 0 ) {
			return $this->check( self::ACTION_CONNECTIONS, 'error', $title, $description, self::ACTION_CONNECTIONS, $this->connectionFix() );
		}

		if ( $missing > 0 ) {
			return $this->check( self::ACTION_CONNECTIONS, 'warning', $title, $description, self::ACTION_CONNECTIONS, $this->connectionFix() );
		}

		return $this->check( self::ACTION_CONNECTIONS, 'pass', $title, $description );
	}

	/**
	 * Saved OAuth client configuration, without contacting Google Cloud.
	 *
	 * @return array<string,mixed>
	 */
	private function credentialsCheck(): array {
		$title = __( 'OAuth configuration', 'brasth-document-sync-for-google-docs' );

		if ( $this->settings->hasRequiredOAuthConfiguration() ) {
			return $this->check(
				self::ACTION_CREDENTIALS,
				'pass',
				$title,
				__( 'Google OAuth client credentials are saved in WordPress. This check does not verify them with Google Cloud.', 'brasth-document-sync-for-google-docs' )
			);
		}

		return $this->check(
			self::ACTION_CREDENTIALS,
			'error',
			$title,
			__( 'Google OAuth client credentials are not saved in WordPress. This check does not contact Google Cloud.', 'brasth-document-sync-for-google-docs' ),
			self::ACTION_CREDENTIALS,
			array(
				__( 'Save the OAuth client ID and client secret in Setup.', 'brasth-document-sync-for-google-docs' ),
			)
		);
	}

	/**
	 * Current administrator token, classified locally.
	 *
	 * @param int $user_id Current user ID.
	 * @return array<string,mixed>
	 */
	private function accountCheck( int $user_id ): array {
		$title  = __( 'Your Google connection', 'brasth-document-sync-for-google-docs' );
		$record = get_user_meta( $user_id, TokenStore::META_KEY, true );

		if ( $this->tokenRecordIsMissing( $record ) ) {
			return $this->accountNeedsConnection( $title, 'warning' );
		}

		if ( ! is_array( $record ) || $this->tokenRecordIsMalformed( $record ) ) {
			return $this->accountNeedsReconnect( $title );
		}

		$token = $this->token_store->get( $user_id );

		if ( is_wp_error( $token ) || ! is_array( $token ) ) {
			return $this->accountNeedsReconnect( $title );
		}

		$scope          = isset( $token['scope'] ) ? (string) $token['scope'] : '';
		$refresh        = isset( $token['refresh_token'] ) ? (string) $token['refresh_token'] : '';
		$access         = isset( $token['access_token'] ) ? (string) $token['access_token'] : '';
		$expires_at     = isset( $token['expires_at'] ) ? absint( $token['expires_at'] ) : 0;
		$has_scope      = GoogleOAuthService::hasRequiredScope( $scope );
		$has_refresh    = '' !== $refresh;
		$access_current = '' !== $access && $expires_at > time() + self::ACCESS_TOKEN_FRESHNESS_SKEW_SECONDS;

		if ( $has_scope && ( $has_refresh || $access_current ) ) {
			return $this->check(
				self::ACTION_ACCOUNT,
				'pass',
				$title,
				__( 'Local Drive read-only connection is ready. Not checked with Google.', 'brasth-document-sync-for-google-docs' )
			);
		}

		if ( ! $has_scope && ! $has_refresh && '' === $access ) {
			return $this->accountNeedsConnection( $title, 'warning' );
		}

		return $this->accountNeedsReconnect( $title );
	}

	/**
	 * Quota cannot be known without Google, so it stays unknown.
	 *
	 * @return array<string,mixed>
	 */
	private function quotaCheck(): array {
		return $this->check(
			self::ACTION_QUOTA,
			'unknown',
			__( 'Google API quota', 'brasth-document-sync-for-google-docs' ),
			__( 'WordPress does not know the Google Drive or Docs quota. Review it in the Google Cloud console for the OAuth project.', 'brasth-document-sync-for-google-docs' ),
			self::ACTION_QUOTA,
			array(
				__( 'Open the Google Cloud console for the OAuth project.', 'brasth-document-sync-for-google-docs' ),
				__( 'Review the Drive API and Docs API quotas there.', 'brasth-document-sync-for-google-docs' ),
			)
		);
	}

	/**
	 * Uploads directory writability and the WordPress upload cap.
	 *
	 * @return array<string,mixed>
	 */
	private function uploadsCheck(): array {
		$title = __( 'Uploads directory', 'brasth-document-sync-for-google-docs' );
		$max   = function_exists( 'wp_max_upload_size' ) ? size_format( (int) wp_max_upload_size() ) : false;
		$label = is_string( $max ) && '' !== $max ? $max : __( 'unknown', 'brasth-document-sync-for-google-docs' );

		if ( $this->uploadsAreWritable() ) {
			return $this->check(
				'uploads',
				'pass',
				$title,
				sprintf(
					/* translators: %s: maximum upload size. */
					__( 'The uploads directory is writable. The maximum upload size is %s.', 'brasth-document-sync-for-google-docs' ),
					$label
				)
			);
		}

		return $this->check(
			'uploads',
			'error',
			$title,
			sprintf(
				/* translators: %s: maximum upload size. */
				__( 'The uploads directory is not writable. The maximum upload size is %s.', 'brasth-document-sync-for-google-docs' ),
				$label
			),
			null,
			array(
				__( 'Make the WordPress uploads directory writable by the web server.', 'brasth-document-sync-for-google-docs' ),
				__( 'Raise the upload size limit if large Google Doc exports fail.', 'brasth-document-sync-for-google-docs' ),
			)
		);
	}

	/**
	 * Minimum WordPress and PHP versions. This does not claim the site is current.
	 *
	 * @param array<string,string> $versions WordPress, PHP, and plugin version strings.
	 * @return array<string,mixed>
	 */
	private function versionsCheck( array $versions ): array {
		$title        = __( 'Runtime versions', 'brasth-document-sync-for-google-docs' );
		$wordpress    = '' !== $versions['wordpress'] ? $versions['wordpress'] : __( 'unavailable', 'brasth-document-sync-for-google-docs' );
		$php          = '' !== $versions['php'] ? $versions['php'] : __( 'unavailable', 'brasth-document-sync-for-google-docs' );
		$wordpress_ok = '' !== $versions['wordpress'] && version_compare( $versions['wordpress'], self::MIN_WORDPRESS, '>=' );
		$php_ok       = '' !== $versions['php'] && version_compare( $versions['php'], self::MIN_PHP, '>=' );

		if ( $wordpress_ok && $php_ok ) {
			return $this->check(
				'versions',
				'pass',
				$title,
				sprintf(
					/* translators: 1: WordPress version, 2: PHP version, 3: minimum WordPress version, 4: minimum PHP version. */
					__( 'WordPress %1$s and PHP %2$s meet the minimum versions required by this plugin (WordPress %3$s and PHP %4$s).', 'brasth-document-sync-for-google-docs' ),
					$wordpress,
					$php,
					self::MIN_WORDPRESS,
					self::MIN_PHP
				)
			);
		}

		$problems = array();
		$fix      = array();

		if ( ! $wordpress_ok ) {
			$problems[] = sprintf(
				/* translators: 1: WordPress version, 2: minimum WordPress version. */
				__( 'WordPress %1$s needs %2$s or newer.', 'brasth-document-sync-for-google-docs' ),
				$wordpress,
				self::MIN_WORDPRESS
			);
			$fix[] = __( 'Upgrade WordPress to the minimum version or newer.', 'brasth-document-sync-for-google-docs' );
		}

		if ( ! $php_ok ) {
			$problems[] = sprintf(
				/* translators: 1: PHP version, 2: minimum PHP version. */
				__( 'PHP %1$s needs %2$s or newer.', 'brasth-document-sync-for-google-docs' ),
				$php,
				self::MIN_PHP
			);
			$fix[] = __( 'Upgrade PHP to the minimum version or newer.', 'brasth-document-sync-for-google-docs' );
		}

		return $this->check( 'versions', 'error', $title, implode( ' ', $problems ), null, $fix );
	}

	/**
	 * Public runtime versions with unexpected text removed.
	 *
	 * @return array<string,string>
	 */
	private function versions(): array {
		global $wp_version;

		return array(
			'wordpress' => $this->publicVersion( is_string( $wp_version ) ? $wp_version : '' ),
			'php'       => $this->publicVersion( PHP_VERSION ),
			'plugin'    => $this->publicVersion( defined( 'DOCSYNC_WP_VERSION' ) ? (string) DOCSYNC_WP_VERSION : '' ),
		);
	}

	/**
	 * Whether the uploads directory can accept imported media.
	 */
	private function uploadsAreWritable(): bool {
		if ( ! function_exists( 'wp_upload_dir' ) || ! function_exists( 'wp_is_writable' ) ) {
			return false;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! is_array( $uploads ) ) {
			return false;
		}

		if ( isset( $uploads['error'] ) && is_string( $uploads['error'] ) && '' !== $uploads['error'] ) {
			return false;
		}

		$basedir = isset( $uploads['basedir'] ) && is_string( $uploads['basedir'] ) ? $uploads['basedir'] : '';

		return '' !== $basedir && wp_is_writable( $basedir );
	}

	/**
	 * Not-connected state for the current administrator.
	 *
	 * @param string $title  Check title.
	 * @param string $status warning or error.
	 * @return array<string,mixed>
	 */
	private function accountNeedsConnection( string $title, string $status ): array {
		return $this->check(
			self::ACTION_ACCOUNT,
			$status,
			$title,
			__( 'Your WordPress user does not have a local Google connection.', 'brasth-document-sync-for-google-docs' ),
			self::ACTION_ACCOUNT,
			array(
				__( 'Connect a Google account from Setup.', 'brasth-document-sync-for-google-docs' ),
			)
		);
	}

	/**
	 * Reconnect state for the current administrator.
	 *
	 * @param string $title Check title.
	 * @return array<string,mixed>
	 */
	private function accountNeedsReconnect( string $title ): array {
		return $this->check(
			self::ACTION_ACCOUNT,
			'error',
			$title,
			__( 'Your local Google connection needs to be connected again. Stored token text is not shown here.', 'brasth-document-sync-for-google-docs' ),
			self::ACTION_ACCOUNT,
			array(
				__( 'Reconnect the Google account from Setup.', 'brasth-document-sync-for-google-docs' ),
			)
		);
	}

	/**
	 * Steps that open the real connection directory in the admin UI.
	 *
	 * @return array<int,string>
	 */
	private function connectionFix(): array {
		return array(
			__( 'Open the connection directory.', 'brasth-document-sync-for-google-docs' ),
			__( 'Reconnect editors whose local connection is unusable, and ask unconnected editors to connect.', 'brasth-document-sync-for-google-docs' ),
		);
	}

	/**
	 * Whether stored meta is absent rather than a broken connection.
	 *
	 * Matches GoogleConnectionDirectory: an empty record is not connected.
	 *
	 * @param mixed $record User meta value.
	 */
	private function tokenRecordIsMissing( mixed $record ): bool {
		return array() === $record || false === $record || null === $record || ( is_string( $record ) && '' === $record );
	}

	/**
	 * Whether a stored array is not a token record.
	 *
	 * @param array<mixed> $record Stored user meta.
	 */
	private function tokenRecordIsMalformed( array $record ): bool {
		foreach ( array( 'encrypted_refresh_token', 'encrypted_access_token' ) as $field ) {
			if ( ! array_key_exists( $field, $record ) || ! is_string( $record[ $field ] ) ) {
				return true;
			}
		}

		return array_key_exists( 'scope', $record ) && ! is_string( $record['scope'] );
	}

	/**
	 * Keep only a UTC timestamp this plugin generated.
	 *
	 * @param string $value Candidate timestamp.
	 */
	private function safeTimestamp( string $value ): string {
		$value = trim( $value );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|\+00:00)$/', $value ) ) {
			return $value;
		}

		return '';
	}

	/**
	 * Keep a version string, or blank when it contains unexpected text.
	 *
	 * @param string $version Raw version.
	 */
	private function publicVersion( string $version ): string {
		$version = trim( $version );

		if ( preg_match( '/^[0-9]+(?:\.[0-9A-Za-z_+-]+)*$/', $version ) ) {
			return $version;
		}

		return '';
	}

	/**
	 * One health check object.
	 *
	 * @param string            $id          Stable check ID.
	 * @param string            $status      pass, warning, error, or unknown.
	 * @param string            $title       Short title.
	 * @param string            $description Safe description.
	 * @param string|null       $action      Optional admin action.
	 * @param array<int,string> $fix         Optional fix steps.
	 * @return array<string,mixed>
	 */
	private function check( string $id, string $status, string $title, string $description, ?string $action = null, array $fix = array() ): array {
		$check = array(
			'id'          => $id,
			'status'      => $status,
			'title'       => $title,
			'description' => $description,
		);

		if ( null !== $action ) {
			$check['action'] = $action;
		}

		if ( array() !== $fix ) {
			$check['fix'] = array_values( $fix );
		}

		return $check;
	}
}
