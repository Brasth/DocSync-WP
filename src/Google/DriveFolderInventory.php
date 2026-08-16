<?php
/**
 * Inventories Google Docs in a Drive folder.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Google;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Walks Drive pages and optional subfolders to list Google Docs.
 */
final class DriveFolderInventory {
	public const MAX_DOCUMENTS = 50;
	public const MAX_DEPTH     = 3;

	private const LIST_PAGE_SIZE = 50;
	private const MAX_PAGES      = 20;

	/**
	 * Drive client.
	 *
	 * @var DriveClient
	 */
	private DriveClient $drive_client;

	/**
	 * Constructor.
	 *
	 * @param DriveClient $drive_client Drive client.
	 */
	public function __construct( DriveClient $drive_client ) {
		$this->drive_client = $drive_client;
	}

	/**
	 * List Google Docs in a folder, optionally including nested folders.
	 *
	 * @param int    $user_id             User ID.
	 * @param string $folder_id           Folder ID or `root`.
	 * @param string $drive_id            Optional shared drive ID.
	 * @param bool   $include_subfolders  Whether to walk child folders.
	 * @return array{documents:array<int,array<string,mixed>>,folderId:string,driveId:string,overflow:bool,includeSubfolders:bool,scannedFolderCount:int}|WP_Error
	 */
	public function listDocuments(
		int $user_id,
		string $folder_id,
		string $drive_id = '',
		bool $include_subfolders = false
	): array|WP_Error {
		$folder_id = '' === trim( $folder_id ) ? 'root' : trim( $folder_id );
		$drive_id  = trim( $drive_id );
		$queue     = array(
			array(
				'folderId' => $folder_id,
				'path'     => '',
				'depth'    => 0,
			),
		);
		$seen      = array( $folder_id => true );
		$documents = array();
		$overflow  = false;
		$scanned   = 0;

		while ( array() !== $queue ) {
			$current = array_shift( $queue );

			if ( ! is_array( $current ) ) {
				continue;
			}

			$page = $this->collectFolderPage(
				$user_id,
				$drive_id,
				$include_subfolders,
				$current,
				$queue,
				$seen,
				$documents,
				$overflow
			);

			if ( is_wp_error( $page ) ) {
				return $page;
			}

			++$scanned;

			if ( $overflow ) {
				break;
			}
		}

		return array(
			'documents'          => $documents,
			'folderId'           => $folder_id,
			'driveId'            => $drive_id,
			'overflow'           => $overflow,
			'includeSubfolders'  => $include_subfolders,
			'scannedFolderCount' => $scanned,
		);
	}

	/**
	 * Page through one folder and enqueue child folders.
	 *
	 * @param int                                          $user_id            User ID.
	 * @param string                                       $drive_id           Shared drive ID.
	 * @param bool                                         $include_subfolders Whether to walk children.
	 * @param array{folderId:string,path:string,depth:int} $current Current folder.
	 * @param array<int,array<string,mixed>>               $queue              Remaining folders.
	 * @param array<string,bool>                           $seen               Visited folder IDs.
	 * @param array<int,array<string,mixed>>               $documents          Collected Docs.
	 * @param bool                                         $overflow           Whether the cap was exceeded.
	 * @return true|WP_Error
	 */
	private function collectFolderPage(
		int $user_id,
		string $drive_id,
		bool $include_subfolders,
		array $current,
		array &$queue,
		array &$seen,
		array &$documents,
		bool &$overflow
	): bool|WP_Error {
		$page_token = '';
		$pages      = 0;

		do {
			$listing = $this->drive_client->listDriveItems(
				$user_id,
				(string) $current['folderId'],
				$drive_id,
				'',
				$page_token,
				self::LIST_PAGE_SIZE
			);

			if ( is_wp_error( $listing ) ) {
				return $listing;
			}

			$this->ingestListingItems(
				$listing['items'],
				$include_subfolders,
				$current,
				$queue,
				$seen,
				$documents,
				$overflow
			);

			$page_token = (string) $listing['nextPageToken'];
			++$pages;
		} while ( '' !== $page_token && $pages < self::MAX_PAGES && ! $overflow );

		return true;
	}

	/**
	 * Add Docs and child folders from one Drive page.
	 *
	 * @param array<int,array<string,mixed>>               $items              Drive items.
	 * @param bool                                         $include_subfolders Whether to walk children.
	 * @param array{folderId:string,path:string,depth:int} $current Current folder.
	 * @param array<int,array<string,mixed>>               $queue              Remaining folders.
	 * @param array<string,bool>                           $seen               Visited folder IDs.
	 * @param array<int,array<string,mixed>>               $documents          Collected Docs.
	 * @param bool                                         $overflow           Whether the cap was exceeded.
	 */
	private function ingestListingItems(
		array $items,
		bool $include_subfolders,
		array $current,
		array &$queue,
		array &$seen,
		array &$documents,
		bool &$overflow
	): void {
		$path  = (string) $current['path'];
		$depth = absint( $current['depth'] );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$item_type = isset( $item['itemType'] ) ? (string) $item['itemType'] : '';
			$file_id   = isset( $item['fileId'] ) ? (string) $item['fileId'] : '';
			$name      = isset( $item['name'] ) ? (string) $item['name'] : '';

			if ( '' === $file_id ) {
				continue;
			}

			if ( 'folder' === $item_type ) {
				$this->enqueueChildFolder( $include_subfolders, $depth, $file_id, $name, $path, $queue, $seen );
				continue;
			}

			if ( 'document' !== $item_type ) {
				continue;
			}

			if ( count( $documents ) >= self::MAX_DOCUMENTS ) {
				$overflow = true;
				return;
			}

			$item['folderPath'] = $path;
			$documents[]        = $item;
		}
	}

	/**
	 * Queue a nested folder when recursion is enabled.
	 *
	 * @param bool                           $include_subfolders Whether to walk children.
	 * @param int                            $depth              Current depth.
	 * @param string                         $file_id            Folder ID.
	 * @param string                         $name               Folder name.
	 * @param string                         $path               Parent path.
	 * @param array<int,array<string,mixed>> $queue            Remaining folders.
	 * @param array<string,bool>             $seen               Visited folder IDs.
	 */
	private function enqueueChildFolder(
		bool $include_subfolders,
		int $depth,
		string $file_id,
		string $name,
		string $path,
		array &$queue,
		array &$seen
	): void {
		if ( ! $include_subfolders || $depth >= self::MAX_DEPTH || isset( $seen[ $file_id ] ) ) {
			return;
		}

		$seen[ $file_id ] = true;
		$queue[]          = array(
			'folderId' => $file_id,
			'path'     => '' === $path ? $name : $path . ' / ' . $name,
			'depth'    => $depth + 1,
		);
	}
}
