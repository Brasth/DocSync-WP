<?php
/**
 * Imports and scans Drive folder watches.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Google\DriveFolderInventory;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Processes pending folder-watch file IDs.
 */
final class FolderWatchRunner {
	/**
	 * Error codes that stop the whole watch.
	 *
	 * @var array<int,string>
	 */
	private const HARD_FAIL_CODES = array(
		'docsync_wp_access_denied',
		'docsync_wp_not_connected',
		'docsync_wp_rest_nonce_required',
		'docsync_wp_google_not_connected',
	);

	/**
	 * Folder inventory.
	 *
	 * @var DriveFolderInventory
	 */
	private DriveFolderInventory $inventory;

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
	 * Constructor.
	 *
	 * @param DriveFolderInventory $inventory    Folder inventory.
	 * @param SourceRepository     $sources      Source repository.
	 * @param SyncService          $sync_service Sync service.
	 */
	public function __construct(
		DriveFolderInventory $inventory,
		SourceRepository $sources,
		SyncService $sync_service
	) {
		$this->inventory    = $inventory;
		$this->sources      = $sources;
		$this->sync_service = $sync_service;
	}

	/**
	 * Create drafts for the next pending file IDs.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 * @param int                 $limit Batch size.
	 * @return array<string,mixed>
	 */
	public function importBatch( array $watch, int $limit ): array {
		$pending = array_values( (array) ( $watch['pendingFileIds'] ?? array() ) );
		$batch   = array_splice( $pending, 0, max( 1, $limit ) );
		$owner   = absint( $watch['ownerUserId'] ?? 0 );

		foreach ( $batch as $file_id ) {
			$result = $this->importFile( $watch, $owner, (string) $file_id );

			if ( is_wp_error( $result ) && $this->isHardFailure( $result ) ) {
				$watch['pendingFileIds'] = array_merge( array( $file_id ), $pending );
				$watch['status']         = 'error';
				$watch['lastError']      = $result->get_error_message();
				return $watch;
			}

			if ( is_wp_error( $result ) ) {
				$watch['failed'][] = array(
					'fileId'  => (string) $file_id,
					'name'    => '',
					'code'    => $result->get_error_code(),
					'message' => $result->get_error_message(),
				);
				continue;
			}

			$watch['importedCount'] = absint( $watch['importedCount'] ?? 0 ) + 1;
		}

		$watch['pendingFileIds'] = $pending;
		$watch['status']         = array() === $pending ? 'watching' : 'importing';
		$watch['lastError']      = array() === $pending ? '' : (string) ( $watch['lastError'] ?? '' );

		return $watch;
	}

	/**
	 * Discover new Docs and enqueue them.
	 *
	 * @param array<string,mixed> $watch Watch record.
	 * @return array<string,mixed>
	 */
	public function scan( array $watch ): array {
		$owner   = absint( $watch['ownerUserId'] ?? 0 );
		$listing = $this->inventory->listDocuments(
			$owner,
			(string) ( $watch['folderId'] ?? 'root' ),
			(string) ( $watch['driveId'] ?? '' ),
			! empty( $watch['includeSubfolders'] )
		);

		if ( is_wp_error( $listing ) ) {
			if ( $this->isHardFailure( $listing ) ) {
				$watch['status']    = 'error';
				$watch['lastError'] = $listing->get_error_message();
			}

			return $watch;
		}

		$excluded = array_values( (array) ( $watch['excludedFileIds'] ?? array() ) );
		$pending  = array_values( (array) ( $watch['pendingFileIds'] ?? array() ) );
		$added    = 0;

		foreach ( $listing['documents'] as $document ) {
			$file_id = isset( $document['fileId'] ) ? sanitize_text_field( (string) $document['fileId'] ) : '';

			if ( '' === $file_id || in_array( $file_id, $excluded, true ) || in_array( $file_id, $pending, true ) ) {
				continue;
			}

			if ( false === ( $document['selectable'] ?? true ) ) {
				continue;
			}

			if ( null !== $this->sources->findPostIdByGoogleFileId( $file_id ) ) {
				continue;
			}

			$pending[] = $file_id;
			++$added;
		}

		$watch['pendingFileIds'] = $pending;
		$watch['totalCount']     = absint( $watch['totalCount'] ?? 0 ) + $added;
		$watch['overflow']       = ! empty( $listing['overflow'] );
		$watch['lastScanAt']     = gmdate( 'c' );
		$watch['lastError']      = '';
		$watch['status']         = array() === $pending ? 'watching' : 'importing';

		return $watch;
	}

	/**
	 * Create one draft and queue its first sync.
	 *
	 * @param array<string,mixed> $watch   Watch record.
	 * @param int                 $user_id Owner user ID.
	 * @param string              $file_id Google file ID.
	 * @return true|WP_Error
	 */
	private function importFile( array $watch, int $user_id, string $file_id ): bool|WP_Error {
		if ( $user_id <= 0 || '' === $file_id ) {
			return new WP_Error(
				'docsync_wp_invalid_folder_import',
				__( 'Brasth Document Sync could not import this Google Doc from the folder watch.', 'brasth-document-sync-for-google-docs' )
			);
		}

		if ( null !== $this->sources->findPostIdByGoogleFileId( $file_id ) ) {
			return true;
		}

		$result = $this->sync_service->createDraftFromSource(
			$user_id,
			$file_id,
			sanitize_key( (string) ( $watch['postType'] ?? 'post' ) ),
			'html_zip',
			false,
			! empty( $watch['elementorSync'] ),
			sanitize_key( (string) ( $watch['layoutPreset'] ?? '' ) ),
			sanitize_key( (string) ( $watch['elementorPreset'] ?? '' ) ),
			$this->sanitizePostStatus( $watch['postStatus'] ?? 'draft' ),
			sanitize_key( (string) ( $watch['id'] ?? '' ) )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = absint( $result['postId'] ?? 0 );

		if ( $post_id > 0 ) {
			SyncCron::scheduleSourceSync( $post_id, $user_id, false );
		}

		return true;
	}

	/**
	 * Whether an error should stop the watch.
	 *
	 * @param WP_Error $error Error.
	 */
	private function isHardFailure( WP_Error $error ): bool {
		return in_array( $error->get_error_code(), self::HARD_FAIL_CODES, true );
	}

	/**
	 * Sanitize create status.
	 *
	 * @param mixed $status Raw status.
	 */
	private function sanitizePostStatus( mixed $status ): string {
		$status = sanitize_key( (string) $status );

		return in_array( $status, array( 'draft', 'publish' ), true ) ? $status : 'draft';
	}
}
