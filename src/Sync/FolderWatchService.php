<?php
/**
 * Creates and manages Drive folder watches.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Cron\CronHeartbeat;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Google\DriveFolderInventory;
use DocSyncWP\Rest\RestPermissions;
use DocSyncWP\Settings\SettingsRepository;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Folder-watch application service.
 */
final class FolderWatchService {
	public const IMPORT_HOOK  = 'docsync_wp_import_folder';
	public const SCAN_HOOK    = 'docsync_wp_scan_folder';
	public const IMPORT_BATCH = 5;

	/**
	 * Watch repository.
	 *
	 * @var FolderWatchRepository
	 */
	private FolderWatchRepository $watches;

	/**
	 * Folder inventory.
	 *
	 * @var DriveFolderInventory
	 */
	private DriveFolderInventory $inventory;

	/**
	 * Drive client.
	 *
	 * @var DriveClient
	 */
	private DriveClient $drive_client;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Sync service.
	 *
	 * @var SyncService
	 */
	private SyncService $sync_service;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Schedule resolver.
	 *
	 * @var SourceScheduleResolver|null
	 */
	private ?SourceScheduleResolver $schedule;

	/**
	 * Import lock.
	 *
	 * @var FolderWatchLock
	 */
	private FolderWatchLock $lock;

	/**
	 * Constructor.
	 *
	 * @param FolderWatchRepository       $watches      Watch repository.
	 * @param DriveFolderInventory        $inventory    Folder inventory.
	 * @param DriveClient                 $drive_client Drive client.
	 * @param SourceRepository            $sources      Source repository.
	 * @param SyncService                 $sync_service Sync service.
	 * @param SettingsRepository          $settings     Settings repository.
	 * @param SourceScheduleResolver|null $schedule     Schedule resolver.
	 */
	public function __construct(
		FolderWatchRepository $watches,
		DriveFolderInventory $inventory,
		DriveClient $drive_client,
		SourceRepository $sources,
		SyncService $sync_service,
		SettingsRepository $settings,
		?SourceScheduleResolver $schedule = null
	) {
		$this->watches      = $watches;
		$this->inventory    = $inventory;
		$this->drive_client = $drive_client;
		$this->sources      = $sources;
		$this->sync_service = $sync_service;
		$this->settings     = $settings;
		$this->schedule     = $schedule;
		$this->lock         = new FolderWatchLock();
	}

	/**
	 * Register cron hooks.
	 */
	public function register(): void {
		add_action( 'update_option_docsync_wp_settings', array( $this, 'syncAllSchedules' ), 20, 0 );
		add_action( self::IMPORT_HOOK, array( $this, 'runImport' ), 10, 1 );
		add_action( self::SCAN_HOOK, array( $this, 'runScan' ), 10, 1 );
		add_action( 'init', array( $this, 'syncAllSchedules' ) );
	}

	/**
	 * List watches visible to a user.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function listForUser( int $user_id ): array {
		$visible = array();

		foreach ( $this->watches->all() as $watch ) {
			if ( $this->userCanAccess( $watch, $user_id ) ) {
				$visible[] = $this->formatWatch( $watch );
			}
		}

		return $visible;
	}

	/**
	 * Get a formatted watch when the user can access it.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getForUser( string $watch_id, int $user_id ): array|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		return is_wp_error( $watch ) ? $watch : $this->formatWatch( $watch );
	}

	/**
	 * Safe workspace counts for the current user.
	 *
	 * @param int $user_id User ID.
	 * @return array{importing:int,watching:int,attention:int,imported:int,truncated:bool}
	 */
	public function summarizeForUser( int $user_id ): array {
		$importing = 0;
		$watching  = 0;
		$attention = 0;
		$imported  = 0;

		foreach ( $this->listForUser( $user_id ) as $watch ) {
			$status    = (string) ( $watch['status'] ?? '' );
			$imported += absint( $watch['importedCount'] ?? 0 );

			if ( 'importing' === $status ) {
				++$importing;
			} elseif ( 'watching' === $status ) {
				++$watching;
			} else {
				++$attention;
			}
		}

		return array(
			'importing' => $importing,
			'watching'  => $watching,
			'attention' => $attention,
			'imported'  => $imported,
			'truncated' => false,
		);
	}

	/**
	 * Create a watch and queue its first import.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $input   Validated input.
	 * @return array<string,mixed>|WP_Error
	 */
	public function create( int $user_id, array $input ): array|WP_Error {
		$folder_id   = isset( $input['folderId'] ) ? sanitize_text_field( (string) $input['folderId'] ) : '';
		$drive_id    = isset( $input['driveId'] ) ? sanitize_text_field( (string) $input['driveId'] ) : '';
		$folder_id   = '' === $folder_id ? 'root' : $folder_id;
		$post_type   = sanitize_key( (string) ( $input['postType'] ?? 'post' ) );
		$post_status = $this->sanitizePostStatus( $input['postStatus'] ?? 'draft' );

		if ( 'publish' === $post_status && ! $this->sources->userCanPublishSyncedPost( $post_type, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_cannot_publish_post',
				__( 'You do not have permission to publish synced posts for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->isRootFolder( $folder_id, $drive_id ) && true !== ( $input['confirmRoot'] ?? false ) ) {
			return new WP_Error(
				'docsync_wp_folder_root_confirm_required',
				__( 'Watching the top of this Drive can import many Google Docs. Confirm that you want the root folder.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$existing = $this->watches->findByFolder( $user_id, $folder_id, $drive_id );

		if ( null !== $existing ) {
			return new WP_Error(
				'docsync_wp_folder_watch_exists',
				__( 'This Google Drive folder is already being watched.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		if ( count( $this->watches->all() ) >= FolderWatchRepository::MAX_WATCHES ) {
			return new WP_Error(
				'docsync_wp_folder_watch_limit',
				__( 'Brasth Document Sync can watch 10 Drive folders on this site.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$folder = $this->drive_client->getDriveItem( $user_id, $folder_id );

		if ( is_wp_error( $folder ) ) {
			return $folder;
		}

		if ( 'folder' !== (string) ( $folder['itemType'] ?? '' ) ) {
			return new WP_Error(
				'docsync_wp_folder_required',
				__( 'Choose a Google Drive folder to watch.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$include_subfolders = ! empty( $input['includeSubfolders'] );
		$listing            = $this->inventory->listDocuments( $user_id, $folder_id, $drive_id, $include_subfolders );

		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$excluded = $this->sanitizeIdList( $input['excludeFileIds'] ?? array() );
		$pending  = $this->collectPendingFileIds( $listing['documents'], $excluded );
		$watch    = $this->buildWatchRecord( $user_id, $input, $folder, $listing, $excluded, $pending );

		if ( ! $this->watches->save( $watch ) ) {
			return new WP_Error(
				'docsync_wp_folder_watch_not_saved',
				__( 'Brasth Document Sync could not save this folder watch.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$this->syncWatchSchedule( $watch );
		$this->recomputeMemberSchedules( (string) $watch['id'] );
		$this->scheduleImport( (string) $watch['id'] );

		$saved = $this->watches->get( (string) $watch['id'] );

		return $this->formatWatch( is_array( $saved ) ? $saved : $watch );
	}

	/**
	 * Update editable watch fields without recreating the watch.
	 *
	 * @param string              $watch_id Watch ID.
	 * @param int                 $user_id  User ID.
	 * @param array<string,mixed> $input    Whitelisted fields.
	 * @return array<string,mixed>|WP_Error
	 */
	public function update( string $watch_id, int $user_id, array $input ): array|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		if ( is_wp_error( $watch ) ) {
			return $watch;
		}

		if ( isset( $input['postStatus'] ) ) {
			$post_status = $this->sanitizePostStatus( $input['postStatus'] );
			$post_type   = sanitize_key( (string) ( $watch['postType'] ?? 'post' ) );

			if ( 'publish' === $post_status && ! $this->sources->userCanPublishSyncedPost( $post_type, $user_id ) ) {
				return new WP_Error(
					'docsync_wp_cannot_publish_post',
					__( 'You do not have permission to publish synced posts for this post type.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 403 )
				);
			}

			$watch['postStatus'] = $post_status;
		}

		$interval_changed = false;

		if ( isset( $input['syncInterval'] ) ) {
			$current_interval      = (string) ( $watch['syncInterval'] ?? 'site' );
			$next_interval         = $this->sanitizeWatchInterval( $input['syncInterval'] );
			$interval_changed      = $current_interval !== $next_interval;
			$watch['syncInterval'] = $next_interval;
		}

		if ( isset( $input['layoutPreset'] ) ) {
			$watch['layoutPreset'] = sanitize_key( (string) $input['layoutPreset'] );
		}

		if ( array_key_exists( 'elementorSync', $input ) ) {
			$watch['elementorSync'] = ! empty( $input['elementorSync'] );
		}

		if ( isset( $input['elementorPreset'] ) ) {
			$watch['elementorPreset'] = sanitize_key( (string) $input['elementorPreset'] );
		}

		$needs_reconcile = false;

		if ( array_key_exists( 'includeSubfolders', $input ) ) {
			$include_subfolders         = ! empty( $input['includeSubfolders'] );
			$current_include_subfolders = ! empty( $watch['includeSubfolders'] );

			if ( $include_subfolders !== $current_include_subfolders ) {
				$watch['includeSubfolders'] = $include_subfolders;
				$needs_reconcile            = true;
			}
		}

		if ( array_key_exists( 'excludedFileIds', $input ) ) {
			$excluded_file_ids = $this->sanitizeIdList( $input['excludedFileIds'] );
			$current_excluded  = $this->sanitizeIdList( $watch['excludedFileIds'] ?? array() );

			sort( $excluded_file_ids );
			sort( $current_excluded );

			if ( $excluded_file_ids !== $current_excluded ) {
				$watch['excludedFileIds'] = $excluded_file_ids;
				$needs_reconcile          = true;
			}
		}

		if ( $needs_reconcile ) {
			$reconciled = $this->reconcileWatchInventory( $watch );

			if ( is_wp_error( $reconciled ) ) {
				return $reconciled;
			}

			$watch = $reconciled;
		}

		if ( ! $this->watches->save( $watch ) ) {
			return new WP_Error(
				'docsync_wp_folder_watch_not_saved',
				__( 'Brasth Document Sync could not save this folder watch.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$saved = $this->watches->get( (string) $watch['id'] );
		$watch = is_array( $saved ) ? $saved : $watch;

		$this->syncWatchSchedule( $watch );

		if ( $interval_changed ) {
			$this->recomputeMemberSchedules( (string) $watch['id'] );
		}

		if ( array() !== (array) ( $watch['pendingFileIds'] ?? array() ) && 'paused' !== (string) ( $watch['status'] ?? '' ) ) {
			$this->scheduleImport( (string) $watch['id'] );
		}

		return $this->formatWatch( $watch );
	}

	/**
	 * Safe cron-health snapshot for the workspace route.
	 *
	 * @return array{lastRunAt:string,stalled:bool}
	 */
	public function cronHealth(): array {
		$intervals = array();
		$settings  = $this->settings->get();
		$site      = isset( $settings['sync_interval'] ) ? sanitize_key( (string) $settings['sync_interval'] ) : 'off';

		if ( in_array( $site, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ) {
			$intervals[] = $site;
		}

		foreach ( $this->watches->all() as $watch ) {
			if ( 'paused' === (string) ( $watch['status'] ?? '' ) ) {
				continue;
			}

			$interval = $this->effectiveInterval( $watch );

			if ( 'off' !== $interval ) {
				$intervals[] = $interval;
			}
		}

		return ( new CronHeartbeat() )->snapshot( $intervals );
	}

	/**
	 * Pause a watch and drop its cron events.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function pause( string $watch_id, int $user_id ): array|WP_Error {
		return $this->setStatus( $watch_id, $user_id, 'paused' );
	}

	/**
	 * Resume a paused watch.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function resume( string $watch_id, int $user_id ): array|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		if ( is_wp_error( $watch ) ) {
			return $watch;
		}

		$watch['status']    = array() === (array) ( $watch['pendingFileIds'] ?? array() ) ? 'watching' : 'importing';
		$watch['lastError'] = '';
		$this->watches->save( $watch );
		$this->syncWatchSchedule( $watch );

		if ( 'importing' === $watch['status'] ) {
			$this->scheduleImport( (string) $watch['id'] );
		}

		return $this->formatWatch( $watch );
	}

	/**
	 * Delete a watch and keep linked posts.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return true|WP_Error
	 */
	public function delete( string $watch_id, int $user_id ): bool|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		if ( is_wp_error( $watch ) ) {
			return $watch;
		}

		$this->unscheduleWatch( (string) $watch['id'] );
		$this->watches->delete( (string) $watch['id'] );

		return true;
	}

	/**
	 * Re-queue failed file IDs.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function retryFailed( string $watch_id, int $user_id ): array|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		if ( is_wp_error( $watch ) ) {
			return $watch;
		}

		$retry_ids = $this->retryableFailedFileIds( $watch );

		if ( is_wp_error( $retry_ids ) ) {
			return $retry_ids;
		}

		$pending = array_values(
			array_unique(
				array_merge(
					array_values( (array) ( $watch['pendingFileIds'] ?? array() ) ),
					$retry_ids
				)
			)
		);

		$watch['pendingFileIds'] = $pending;
		$watch['failed']         = array();
		$watch['lastError']      = '';
		$watch['status']         = array() === $pending ? 'watching' : 'importing';
		$this->watches->save( $watch );

		if ( 'importing' === $watch['status'] ) {
			$this->scheduleImport( (string) $watch['id'] );
		}

		return $this->formatWatch( $watch );
	}

	/**
	 * Queue a scan for new Docs.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function requestScan( string $watch_id, int $user_id ): array|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		if ( is_wp_error( $watch ) ) {
			return $watch;
		}

		if ( 'paused' === (string) ( $watch['status'] ?? '' ) ) {
			return new WP_Error(
				'docsync_wp_folder_watch_paused',
				__( 'Resume this folder watch before scanning for new Google Docs.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		$this->runScan( (string) $watch['id'], true );
		$saved = $this->watches->get( (string) $watch['id'] );

		return $this->formatWatch( is_array( $saved ) ? $saved : $watch );
	}

	/**
	 * Whether a folder ID is the My Drive or shared-drive root.
	 *
	 * @param string $folder_id Folder ID.
	 * @param string $drive_id  Shared drive ID.
	 */
	public function isRootFolder( string $folder_id, string $drive_id ): bool {
		return 'root' === $folder_id || ( '' !== $drive_id && $folder_id === $drive_id );
	}

	/**
	 * Format a watch for REST.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 * @return array<string,mixed>
	 */
	public function formatWatch( array $watch ): array {
		$watch_id   = sanitize_key( (string) ( $watch['id'] ?? '' ) );
		$next_scan  = '' === $watch_id ? false : wp_next_scheduled( self::SCAN_HOOK, array( $watch_id ) );
		$owner      = get_userdata( absint( $watch['ownerUserId'] ?? 0 ) );
		$owner_name = $owner instanceof WP_User ? (string) $owner->display_name : '';

		return array(
			'id'                => (string) ( $watch['id'] ?? '' ),
			'ownerUserId'       => absint( $watch['ownerUserId'] ?? 0 ),
			'folderId'          => (string) ( $watch['folderId'] ?? '' ),
			'driveId'           => (string) ( $watch['driveId'] ?? '' ),
			'folderName'        => (string) ( $watch['folderName'] ?? '' ),
			'webViewLink'       => (string) ( $watch['webViewLink'] ?? '' ),
			'includeSubfolders' => ! empty( $watch['includeSubfolders'] ),
			'postType'          => (string) ( $watch['postType'] ?? 'post' ),
			'postStatus'        => (string) ( $watch['postStatus'] ?? 'draft' ),
			'syncInterval'      => (string) ( $watch['syncInterval'] ?? 'site' ),
			'effectiveInterval' => $this->effectiveInterval( $watch ),
			'layoutPreset'      => (string) ( $watch['layoutPreset'] ?? '' ),
			'elementorSync'     => ! empty( $watch['elementorSync'] ),
			'elementorPreset'   => (string) ( $watch['elementorPreset'] ?? '' ),
			'status'            => (string) ( $watch['status'] ?? 'watching' ),
			'pendingCount'      => count( (array) ( $watch['pendingFileIds'] ?? array() ) ),
			'importedCount'     => absint( $watch['importedCount'] ?? 0 ),
			'totalCount'        => absint( $watch['totalCount'] ?? 0 ),
			'overflow'          => ! empty( $watch['overflow'] ),
			'failed'            => array_values( (array) ( $watch['failed'] ?? array() ) ),
			'excludedFileIds'   => array_values( (array) ( $watch['excludedFileIds'] ?? array() ) ),
			'lastScanAt'        => (string) ( $watch['lastScanAt'] ?? '' ),
			'nextScanAt'        => false === $next_scan ? '' : gmdate( 'c', (int) $next_scan ),
			'ownerDisplayName'  => $owner_name,
			'lastError'         => (string) ( $watch['lastError'] ?? '' ),
			'createdAt'         => (string) ( $watch['createdAt'] ?? '' ),
		);
	}

	/**
	 * Import the next pending Docs for a watch.
	 *
	 * @param string $watch_id Watch ID.
	 */
	public function runImport( string $watch_id ): void {
		$watch_id = sanitize_key( $watch_id );
		$watch    = $this->watches->get( $watch_id );

		if ( null === $watch || 'paused' === (string) ( $watch['status'] ?? '' ) ) {
			return;
		}

		if ( ! $this->lock->acquire( $watch_id ) ) {
			$this->scheduleImport( $watch_id );
			return;
		}

		$runner = new FolderWatchRunner(
			$this->inventory,
			$this->sources,
			$this->sync_service
		);
		$watch  = $runner->importBatch( $watch, self::IMPORT_BATCH );
		$this->watches->save( $watch );
		$this->lock->release( $watch_id );

		if ( 'importing' === (string) ( $watch['status'] ?? '' ) && array() !== (array) ( $watch['pendingFileIds'] ?? array() ) ) {
			$this->scheduleImport( $watch_id );
		}
	}

	/**
	 * Scan one watch for new Docs.
	 *
	 * @param string $watch_id         Watch ID.
	 * @param bool   $ignore_interval  Whether to skip the recurring-interval guard.
	 */
	public function runScan( string $watch_id, bool $ignore_interval = false ): void {
		$watch = $this->watches->get( sanitize_key( $watch_id ) );

		if ( null === $watch || 'paused' === (string) ( $watch['status'] ?? '' ) ) {
			return;
		}

		if ( ! $ignore_interval && 'off' === $this->effectiveInterval( $watch ) ) {
			return;
		}

		if ( ! $ignore_interval ) {
			( new CronHeartbeat() )->mark();
		}

		$runner = new FolderWatchRunner(
			$this->inventory,
			$this->sources,
			$this->sync_service
		);
		$watch  = $runner->scan( $watch );
		$this->watches->save( $watch );

		if ( array() !== (array) ( $watch['pendingFileIds'] ?? array() ) ) {
			$this->scheduleImport( (string) $watch['id'] );
		}
	}

	/**
	 * Reconcile recurring scan events for every watch.
	 */
	public function syncAllSchedules(): void {
		foreach ( $this->watches->all() as $watch ) {
			$this->syncWatchSchedule( $watch );
			$this->recomputeMemberSchedules( (string) ( $watch['id'] ?? '' ) );
		}
	}

	/**
	 * Rewrite next_sync_at for every member source of a watch.
	 *
	 * @param string $watch_id Watch ID.
	 */
	public function recomputeMemberSchedules( string $watch_id ): void {
		if ( null === $this->schedule ) {
			return;
		}

		$watch_id = sanitize_key( $watch_id );
		$page     = 1;
		$now      = current_time( 'mysql', true );

		do {
			$post_ids = $this->sources->listPostIdsForFolderWatch( $watch_id, $page, 100 );

			foreach ( $post_ids as $post_id ) {
				$source = $this->sources->getSource( $post_id );

				if ( null === $source ) {
					continue;
				}

				$interval               = $this->schedule->resolveInterval( $source );
				$source['next_sync_at'] = SourceScheduleResolver::nextSyncAt( $now, $interval );
				$this->sources->saveSource( $post_id, $source );
			}

			$found = count( $post_ids );
			++$page;
		} while ( 100 === $found );
	}

	/**
	 * Clear folder-watch cron events on uninstall.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::IMPORT_HOOK );
		wp_clear_scheduled_hook( self::SCAN_HOOK );
	}

	/**
	 * Build the stored watch record.
	 *
	 * @param int                 $user_id  Owner user ID.
	 * @param array<string,mixed> $input    Validated input.
	 * @param array<string,mixed> $folder   Drive folder item.
	 * @param array<string,mixed> $listing  Inventory listing.
	 * @param array<int,string>   $excluded Excluded file IDs.
	 * @param array<int,string>   $pending  Pending file IDs.
	 * @return array<string,mixed>
	 */
	private function buildWatchRecord(
		int $user_id,
		array $input,
		array $folder,
		array $listing,
		array $excluded,
		array $pending
	): array {
		return array(
			'id'                => wp_generate_uuid4(),
			'ownerUserId'       => $user_id,
			'folderId'          => (string) $listing['folderId'],
			'driveId'           => (string) $listing['driveId'],
			'folderName'        => (string) ( $folder['name'] ?? '' ),
			'webViewLink'       => (string) ( $folder['webViewLink'] ?? '' ),
			'includeSubfolders' => ! empty( $input['includeSubfolders'] ),
			'confirmRoot'       => ! empty( $input['confirmRoot'] ),
			'postType'          => sanitize_key( (string) ( $input['postType'] ?? 'post' ) ),
			'postStatus'        => $this->sanitizePostStatus( $input['postStatus'] ?? 'draft' ),
			'syncInterval'      => $this->sanitizeWatchInterval( $input['syncInterval'] ?? 'site' ),
			'layoutPreset'      => sanitize_key( (string) ( $input['layoutPreset'] ?? '' ) ),
			'elementorSync'     => ! empty( $input['elementorSync'] ),
			'elementorPreset'   => sanitize_key( (string) ( $input['elementorPreset'] ?? '' ) ),
			'status'            => array() === $pending ? 'watching' : 'importing',
			'pendingFileIds'    => $pending,
			'excludedFileIds'   => $excluded,
			'failed'            => array(),
			'importedCount'     => 0,
			'totalCount'        => count( $pending ),
			'overflow'          => ! empty( $listing['overflow'] ),
			'lastScanAt'        => gmdate( 'c' ),
			'lastError'         => '',
			'createdAt'         => gmdate( 'c' ),
		);
	}

	/**
	 * Re-inventory a watch and rebuild pending IDs after an edit.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 * @return array<string,mixed>|WP_Error
	 */
	private function reconcileWatchInventory( array $watch ): array|WP_Error {
		$listing = $this->inventory->listDocuments(
			absint( $watch['ownerUserId'] ?? 0 ),
			(string) ( $watch['folderId'] ?? 'root' ),
			(string) ( $watch['driveId'] ?? '' ),
			! empty( $watch['includeSubfolders'] )
		);

		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$in_scope = array();
		$linked   = array();

		foreach ( (array) ( $listing['documents'] ?? array() ) as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$file_id = isset( $document['fileId'] ) ? sanitize_text_field( (string) $document['fileId'] ) : '';

			if ( '' === $file_id || false === ( $document['selectable'] ?? true ) ) {
				continue;
			}

			$in_scope[] = $file_id;

			if ( null !== $this->sources->findPostIdByGoogleFileId( $file_id ) ) {
				$linked[] = $file_id;
			}
		}

		$excluded = $this->sanitizeIdList( $watch['excludedFileIds'] ?? array() );

		$watch['pendingFileIds'] = ( new FolderWatchReconciler() )->reconcilePending(
			(array) ( $watch['pendingFileIds'] ?? array() ),
			$excluded,
			$in_scope,
			$linked
		);
		$watch['overflow']       = ! empty( $listing['overflow'] );
		$watch['totalCount']     = $this->selectedInventoryCount( $in_scope, $excluded );
		$watch['importedCount']  = min( absint( $watch['importedCount'] ?? 0 ), $watch['totalCount'] );
		$watch['failed']         = $this->filterFailedEntries( (array) ( $watch['failed'] ?? array() ), $in_scope, $excluded );

		$status = (string) ( $watch['status'] ?? '' );

		if ( ! in_array( $status, array( 'paused', 'error' ), true ) ) {
			$watch['status'] = array() === (array) $watch['pendingFileIds'] ? 'watching' : 'importing';
		}

		return $watch;
	}

	/**
	 * Failed file IDs that are still in scope and not excluded.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 * @return array<int,string>|WP_Error
	 */
	private function retryableFailedFileIds( array $watch ): array|WP_Error {
		$in_scope = $this->resolveInScopeFileIds( $watch );

		if ( is_wp_error( $in_scope ) ) {
			return $in_scope;
		}

		$excluded     = $this->sanitizeIdList( $watch['excludedFileIds'] ?? array() );
		$retry_ids    = array();
		$excluded_set = array_fill_keys( $excluded, true );
		$in_scope_set = array_fill_keys( $in_scope, true );

		foreach ( (array) ( $watch['failed'] ?? array() ) as $failed ) {
			if ( ! is_array( $failed ) || ! isset( $failed['fileId'] ) ) {
				continue;
			}

			$file_id = sanitize_text_field( (string) $failed['fileId'] );

			if ( '' === $file_id || isset( $excluded_set[ $file_id ] ) || ! isset( $in_scope_set[ $file_id ] ) ) {
				continue;
			}

			$retry_ids[] = $file_id;
		}

		return array_values( array_unique( $retry_ids ) );
	}

	/**
	 * List selectable file IDs currently in the watched folder tree.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 * @return array<int,string>|WP_Error
	 */
	private function resolveInScopeFileIds( array $watch ): array|WP_Error {
		$listing = $this->inventory->listDocuments(
			absint( $watch['ownerUserId'] ?? 0 ),
			(string) ( $watch['folderId'] ?? 'root' ),
			(string) ( $watch['driveId'] ?? '' ),
			! empty( $watch['includeSubfolders'] )
		);

		if ( is_wp_error( $listing ) ) {
			return $listing;
		}

		$in_scope = array();

		foreach ( (array) ( $listing['documents'] ?? array() ) as $document ) {
			if ( ! is_array( $document ) ) {
				continue;
			}

			$file_id = isset( $document['fileId'] ) ? sanitize_text_field( (string) $document['fileId'] ) : '';

			if ( '' === $file_id || false === ( $document['selectable'] ?? true ) ) {
				continue;
			}

			$in_scope[] = $file_id;
		}

		return array_values( array_unique( $in_scope ) );
	}

	/**
	 * Count Docs in scope that are not excluded.
	 *
	 * @param array<int,string> $in_scope_file_ids In-scope file IDs.
	 * @param array<int,string> $excluded          Excluded file IDs.
	 */
	private function selectedInventoryCount( array $in_scope_file_ids, array $excluded ): int {
		$excluded_set = array_fill_keys( $excluded, true );
		$count        = 0;

		foreach ( $in_scope_file_ids as $file_id ) {
			if ( ! isset( $excluded_set[ $file_id ] ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Drop failed entries that are excluded or no longer in scope.
	 *
	 * @param array<int,mixed>  $failed    Failed entries.
	 * @param array<int,string> $in_scope  In-scope file IDs.
	 * @param array<int,string> $excluded  Excluded file IDs.
	 * @return array<int,array<string,mixed>>
	 */
	private function filterFailedEntries( array $failed, array $in_scope, array $excluded ): array {
		$excluded_set = array_fill_keys( $excluded, true );
		$in_scope_set = array_fill_keys( $in_scope, true );
		$remaining    = array();

		foreach ( $failed as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['fileId'] ) ) {
				continue;
			}

			$file_id = sanitize_text_field( (string) $entry['fileId'] );

			if ( '' === $file_id || isset( $excluded_set[ $file_id ] ) || ! isset( $in_scope_set[ $file_id ] ) ) {
				continue;
			}

			$remaining[] = $entry;
		}

		return $remaining;
	}

	/**
	 * Pending file IDs that are not excluded and not already linked.
	 *
	 * @param array<int,array<string,mixed>> $documents Inventory Docs.
	 * @param array<int,string>              $excluded  Excluded IDs.
	 * @return array<int,string>
	 */
	private function collectPendingFileIds( array $documents, array $excluded ): array {
		$pending = array();

		foreach ( $documents as $document ) {
			$file_id = isset( $document['fileId'] ) ? sanitize_text_field( (string) $document['fileId'] ) : '';

			if ( '' === $file_id || in_array( $file_id, $excluded, true ) ) {
				continue;
			}

			if ( false === ( $document['selectable'] ?? true ) ) {
				continue;
			}

			if ( null !== $this->sources->findPostIdByGoogleFileId( $file_id ) ) {
				continue;
			}

			$pending[] = $file_id;
		}

		return array_values( array_unique( $pending ) );
	}

	/**
	 * Load a watch the user can operate.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function requireWatch( string $watch_id, int $user_id ): array|WP_Error {
		$watch = $this->watches->get( $watch_id );

		if ( null === $watch ) {
			return new WP_Error(
				'docsync_wp_folder_watch_not_found',
				__( 'Brasth Document Sync could not find that folder watch.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->userCanAccess( $watch, $user_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to use Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return $watch;
	}

	/**
	 * Whether the user owns the watch or can manage the site.
	 *
	 * @param array<string,mixed> $watch   Watch record.
	 * @param int                 $user_id User ID.
	 */
	private function userCanAccess( array $watch, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		return absint( $watch['ownerUserId'] ?? 0 ) === $user_id
			&& RestPermissions::userCanUsePostType( $user_id, (string) ( $watch['postType'] ?? 'post' ) );
	}

	/**
	 * Set a simple status and reschedule.
	 *
	 * @param string $watch_id Watch ID.
	 * @param int    $user_id  User ID.
	 * @param string $status   New status.
	 * @return array<string,mixed>|WP_Error
	 */
	private function setStatus( string $watch_id, int $user_id, string $status ): array|WP_Error {
		$watch = $this->requireWatch( $watch_id, $user_id );

		if ( is_wp_error( $watch ) ) {
			return $watch;
		}

		$watch['status'] = sanitize_key( $status );
		$this->watches->save( $watch );
		$this->syncWatchSchedule( $watch );

		return $this->formatWatch( $watch );
	}

	/**
	 * Schedule or clear the recurring scan for one watch.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 */
	private function syncWatchSchedule( array $watch ): void {
		$watch_id = sanitize_key( (string) ( $watch['id'] ?? '' ) );
		$interval = $this->effectiveInterval( $watch );
		$args     = array( $watch_id );

		if ( '' === $watch_id || 'off' === $interval || 'paused' === (string) ( $watch['status'] ?? '' ) ) {
			$this->unscheduleHook( self::SCAN_HOOK, $args );
			return;
		}

		$current_schedule = wp_get_schedule( self::SCAN_HOOK, $args );

		if ( false !== $current_schedule && $current_schedule !== $interval ) {
			$this->unscheduleHook( self::SCAN_HOOK, $args );
		}

		if ( false === wp_next_scheduled( self::SCAN_HOOK, $args ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, $interval, self::SCAN_HOOK, $args );
		}
	}

	/**
	 * Queue an import tick.
	 *
	 * @param string $watch_id Watch ID.
	 */
	private function scheduleImport( string $watch_id ): void {
		$watch_id = sanitize_key( $watch_id );
		$args     = array( $watch_id );

		if ( false === wp_next_scheduled( self::IMPORT_HOOK, $args ) ) {
			wp_schedule_single_event( time(), self::IMPORT_HOOK, $args, true );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Remove cron events for one watch.
	 *
	 * @param string $watch_id Watch ID.
	 */
	private function unscheduleWatch( string $watch_id ): void {
		$args = array( sanitize_key( $watch_id ) );
		$this->unscheduleHook( self::IMPORT_HOOK, $args );
		$this->unscheduleHook( self::SCAN_HOOK, $args );
	}

	/**
	 * Clear every scheduled instance of a hook with args.
	 *
	 * @param string            $hook Hook name.
	 * @param array<int,string> $args Hook args.
	 */
	private function unscheduleHook( string $hook, array $args ): void {
		$timestamp = wp_next_scheduled( $hook, $args );

		while ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, $hook, $args );
			$timestamp = wp_next_scheduled( $hook, $args );
		}
	}

	/**
	 * Resolve the cron interval for a watch.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 */
	private function effectiveInterval( array $watch ): string {
		$interval = $this->sanitizeWatchInterval( $watch['syncInterval'] ?? 'site' );

		if ( 'site' === $interval ) {
			$settings = $this->settings->get();
			$interval = isset( $settings['sync_interval'] ) ? sanitize_key( (string) $settings['sync_interval'] ) : 'off';
		}

		return in_array( $interval, array( 'off', 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $interval : 'off';
	}

	/**
	 * Sanitize new-post status.
	 *
	 * @param mixed $status Raw status.
	 */
	private function sanitizePostStatus( mixed $status ): string {
		$status = sanitize_key( (string) $status );

		return in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
	}

	/**
	 * Sanitize a folder schedule value.
	 *
	 * @param mixed $interval Raw interval.
	 */
	private function sanitizeWatchInterval( mixed $interval ): string {
		$interval = sanitize_key( (string) $interval );

		return in_array( $interval, array( 'site', 'off', 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $interval : 'site';
	}

	/**
	 * Sanitize a list of Drive file IDs.
	 *
	 * @param mixed $value Raw list.
	 * @return array<int,string>
	 */
	private function sanitizeIdList( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$item = sanitize_text_field( (string) $item );

				if ( '' !== $item ) {
					$ids[] = $item;
				}
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
