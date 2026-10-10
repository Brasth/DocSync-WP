<?php
/**
 * Multi-Doc source batches and attach-only links.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Google\DocumentIdParser;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds several Google Docs at once and links existing posts without writing content.
 *
 * New targets create a draft (or published) post and queue a background sync.
 * Existing targets are attach-only: source metadata is saved with status
 * `linked` and nothing is queued. Batches are idempotent per owner and key:
 * the record is durable (option, 24 h), each item's result is saved as soon as
 * it finishes, and a retried request resumes without creating a second post.
 *
 * Every post a batch item inserts carries a temporary slug derived from the
 * batch ID and item index from its very first database row. A request that
 * stops after the insert but before the post ID is saved therefore finds that
 * exact post on replay and finishes it, instead of creating another one or
 * adopting an unrelated post. The normal slug is restored only after the post
 * ID is durably saved.
 */
final class SourceBatchService {
	public const MAX_ITEMS = 20;

	public const OPTION_PREFIX = 'docsync_wp_source_batch_';
	public const INDEX_OPTION  = 'docsync_wp_source_batch_index';

	private const RECORD_VERSION         = 1;
	private const RECORD_TTL_SECONDS     = DAY_IN_SECONDS;
	private const LOCK_TTL_SECONDS       = 300;
	private const EXPORT_FORMAT_HTML_ZIP = 'html_zip';
	private const SYNC_MODE_BACKGROUND   = 'background';
	private const SYNC_MODE_ATTACH_ONLY  = 'attach_only';
	private const KEY_PATTERN            = '/^[A-Za-z0-9-]{16,64}$/';
	private const ITEM_FIELDS            = array( 'fileId', 'target', 'syncMode', 'layoutPreset', 'elementorSync', 'elementorPreset', 'transferOwnership' );
	private const TARGET_FIELDS          = array( 'mode', 'postType', 'postStatus', 'postId' );
	private const ITEM_PENDING           = 'pending';
	private const POST_MARKER_PREFIX     = 'docsync-batch-';

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
	 * Document ID parser.
	 *
	 * @var DocumentIdParser
	 */
	private DocumentIdParser $document_id_parser;

	/**
	 * Layout preset registry.
	 *
	 * @var LayoutPresetRegistry
	 */
	private LayoutPresetRegistry $layout_presets;

	/**
	 * Elementor preset registry.
	 *
	 * @var ElementorPresetRegistry
	 */
	private ElementorPresetRegistry $elementor_presets;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository        $source_repository  Source repository.
	 * @param SyncService             $sync_service       Sync service.
	 * @param DocumentIdParser        $document_id_parser Document ID parser.
	 * @param LayoutPresetRegistry    $layout_presets     Layout preset registry.
	 * @param ElementorPresetRegistry $elementor_presets  Elementor preset registry.
	 */
	public function __construct(
		SourceRepository $source_repository,
		SyncService $sync_service,
		DocumentIdParser $document_id_parser,
		LayoutPresetRegistry $layout_presets,
		ElementorPresetRegistry $elementor_presets
	) {
		$this->source_repository  = $source_repository;
		$this->sync_service       = $sync_service;
		$this->document_id_parser = $document_id_parser;
		$this->layout_presets     = $layout_presets;
		$this->elementor_presets  = $elementor_presets;
	}

	/**
	 * Create or resume an idempotent source batch.
	 *
	 * @param int                            $user_id         User ID.
	 * @param array<int,array<string,mixed>> $items           Batch items (1 to 20).
	 * @param string                         $idempotency_key Client idempotency key.
	 * @return array{batchId:string,results:array<int,array<string,mixed>>}|WP_Error
	 */
	public function createBatch( int $user_id, array $items, string $idempotency_key ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'You must be logged in before adding Google Docs.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		if ( 1 !== preg_match( self::KEY_PATTERN, $idempotency_key ) ) {
			return $this->invalidBatchError( null );
		}

		$normalized = $this->normalizeItems( $items );

		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$record_hash  = hash( 'sha256', $user_id . '|' . $idempotency_key );
		$request_hash = hash( 'sha256', (string) wp_json_encode( $normalized ) );
		$existing     = $this->readRecord( $record_hash, $user_id );

		if ( null !== $existing ) {
			if ( $existing['requestHash'] !== $request_hash ) {
				return $this->idempotencyConflictError();
			}

			if ( 'complete' === $existing['status'] ) {
				return $this->formatBatch( $existing );
			}
		}

		$lock_token = $this->acquireLock( $record_hash );

		if ( null === $lock_token ) {
			return $this->batchInProgressError();
		}

		try {
			return $this->runLocked( $user_id, $record_hash, $request_hash, $normalized, $lock_token );
		} finally {
			$this->releaseLock( $record_hash, $lock_token );
		}
	}

	/**
	 * Link an existing post to a Doc without writing content or queueing a sync.
	 *
	 * The post must be editable and either unlinked or already linked to this same
	 * Doc. The Doc must not be linked to another post. Relinking a source that
	 * another editor owns requires `transferOwnership`.
	 *
	 * @param int                 $user_id User ID.
	 * @param int                 $post_id Existing post ID.
	 * @param string              $file_id Google Doc ID or URL.
	 * @param array<string,mixed> $options {transferOwnership?:bool,layoutPreset?:string,elementorSync?:bool|null,elementorPreset?:string}.
	 * @return array<string,mixed>|WP_Error
	 */
	public function attachOnly( int $user_id, int $post_id, string $file_id, array $options = array() ): array|WP_Error {
		$file_id = $this->document_id_parser->parse( $file_id, 'file_id' );

		if ( is_wp_error( $file_id ) ) {
			return $file_id;
		}

		$layout_preset = $this->validateLayoutPreset( $options['layoutPreset'] ?? '' );

		if ( is_wp_error( $layout_preset ) ) {
			return $layout_preset;
		}

		$elementor_preset = array_key_exists( 'elementorPreset', $options ) ? $this->validateElementorPreset( $options['elementorPreset'] ) : '';

		if ( is_wp_error( $elementor_preset ) ) {
			return $elementor_preset;
		}

		$elementor_sync = isset( $options['elementorSync'] ) && is_bool( $options['elementorSync'] ) ? $options['elementorSync'] : null;
		$allowed        = $this->validateEditablePost( $post_id, $user_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$current = $this->source_repository->getSource( $post_id );

		if ( is_array( $current ) && (string) $current['google_file_id'] !== $file_id ) {
			return new WP_Error(
				'docsync_wp_post_already_linked',
				__( 'This post is already linked to a different Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		$linked_post_id = $this->source_repository->findPostIdByGoogleFileId( $file_id );

		if ( null !== $linked_post_id && $linked_post_id !== $post_id ) {
			return $this->sourceAlreadyLinkedError();
		}

		if ( is_array( $current ) ) {
			if ( SyncService::STATUS_SYNCING === (string) $current['sync_status'] ) {
				return new WP_Error(
					'docsync_wp_source_syncing',
					__( 'Wait for the current Google Doc sync to finish before changing this source.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 409 )
				);
			}

			$current_owner = absint( $current['sync_owner_user_id'] ?? 0 );

			if ( $current_owner === $user_id ) {
				return array(
					'postId'         => $post_id,
					'status'         => SyncService::STATUS_LINKED,
					'changed'        => false,
					'source'         => $this->source_repository->formatSource( $post_id ),
					'lastSyncMethod' => null,
				);
			}

			if ( $current_owner > 0 && true !== ( $options['transferOwnership'] ?? false ) ) {
				return new WP_Error(
					'docsync_wp_source_owner_transfer_required',
					__( 'This source uses another editor\'s Google connection for scheduled syncs. Confirm the ownership transfer before relinking it to your connection.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 409 )
				);
			}
		}

		return $this->sync_service->attachSource(
			$post_id,
			$user_id,
			$file_id,
			self::EXPORT_FORMAT_HTML_ZIP,
			$elementor_sync,
			$layout_preset,
			$elementor_preset
		);
	}

	/**
	 * Delete batch records and locks older than 24 hours.
	 *
	 * A replay of an expired key may be recreating its record right now, so an
	 * entry whose lease is still active is skipped, a record recreated with a
	 * later expiry is kept, and every delete applies only to the exact expired
	 * row read here.
	 *
	 * @return int Number of removed batch records.
	 */
	public function purgeExpired(): int {
		$index   = $this->readIndex();
		$now     = time();
		$removed = 0;
		$changed = false;

		foreach ( $index as $record_hash => $expires_at ) {
			if ( $expires_at > $now ) {
				continue;
			}

			$lock_name = $this->lockOptionName( $record_hash );
			$lock_raw  = $this->readRawOption( $lock_name );

			if ( null !== $lock_raw && $this->lockExpiry( maybe_unserialize( $lock_raw ) ) > $now ) {
				continue;
			}

			$record_expiry = $this->deleteExpiredRecord( $record_hash, $now );

			if ( null !== $record_expiry ) {
				if ( $record_expiry > $now ) {
					$index[ $record_hash ] = $record_expiry;
					$changed               = true;
				}

				continue;
			}

			if ( null !== $lock_raw && ! $this->deleteOptionIfUnchanged( $lock_name, $lock_raw ) && null !== $this->readRawOption( $lock_name ) ) {
				continue;
			}

			unset( $index[ $record_hash ] );
			$changed = true;
			++$removed;
		}

		if ( $changed ) {
			$this->writeIndex( $index );
		}

		return $removed;
	}

	/**
	 * Process every unfinished item while the batch lock is held.
	 *
	 * The lease is renewed before each item because every item may wait on
	 * Google. Every state change is saved and read back before the next step;
	 * when the lease is lost or a save fails the batch stops, leaving the
	 * pending item and any marked post for the next attempt to recover.
	 *
	 * @param int                            $user_id      User ID.
	 * @param string                         $record_hash  Owner-bound key hash.
	 * @param string                         $request_hash Normalized body hash.
	 * @param array<int,array<string,mixed>> $items        Normalized items.
	 * @param string                         $lock_token   Token of the held lease.
	 * @return array{batchId:string,results:array<int,array<string,mixed>>}|WP_Error
	 */
	private function runLocked( int $user_id, string $record_hash, string $request_hash, array $items, string $lock_token ): array|WP_Error {
		$record = $this->readRecord( $record_hash, $user_id );

		if ( null !== $record && $record['requestHash'] !== $request_hash ) {
			return $this->idempotencyConflictError();
		}

		if ( null === $record ) {
			$record = $this->createRecord( $user_id, $record_hash, $request_hash );

			if ( is_wp_error( $record ) ) {
				return $record;
			}
		}

		if ( 'complete' === $record['status'] ) {
			return $this->formatBatch( $record );
		}

		$queued = false;

		foreach ( $items as $index => $item ) {
			$previous = $record['results'][ $index ] ?? null;

			if ( is_array( $previous ) && self::ITEM_PENDING !== ( $previous['status'] ?? '' ) ) {
				continue;
			}

			if ( ! $this->renewLock( $record_hash, $lock_token ) ) {
				return $this->batchInProgressError();
			}

			if ( 'new' === $item['mode'] ) {
				$result = $this->processNewItem( $user_id, $record_hash, $record, $index, $item );
			} else {
				$result = $this->processAttachItem( $user_id, $index, $item );
			}

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$queued                      = $queued || 'queued' === $result['status'];
			$record['results'][ $index ] = $result;

			if ( ! $this->saveRecord( $record_hash, $record ) ) {
				return $this->storageError();
			}
		}

		$record['status'] = 'complete';
		$saved            = $this->saveRecord( $record_hash, $record );

		if ( $queued ) {
			SyncCron::spawnScheduledSyncs();
		}

		if ( ! $saved ) {
			return $this->storageError();
		}

		return $this->formatBatch( $record );
	}

	/**
	 * Create a draft or published post and queue its first background sync.
	 *
	 * Recovery order on replay: a post ID already saved for this item, then the
	 * post carrying this item's marker slug. A Doc linked to any other post
	 * fails the item; that post is never adopted.
	 *
	 * @param int                 $user_id     User ID.
	 * @param string              $record_hash Owner-bound key hash.
	 * @param array<string,mixed> $record      Batch record, updated in place.
	 * @param int                 $index       Item index.
	 * @param array<string,mixed> $item        Normalized item.
	 * @return array<string,mixed>|WP_Error Item result, or WP_Error to stop the batch.
	 */
	private function processNewItem( int $user_id, string $record_hash, array &$record, int $index, array $item ): array|WP_Error {
		$post_type = (string) $item['postType'];
		$file_id   = (string) $item['fileId'];

		if ( ! $this->source_repository->isPostTypeEnabled( $post_type ) ) {
			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_post_type_disabled',
					__( 'Brasth Document Sync is not enabled for this post type.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 400 )
				)
			);
		}

		if ( ! $this->source_repository->userCanCreateSyncedPost( $post_type, $user_id ) ) {
			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_forbidden',
					__( 'You do not have permission to create this post type.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 403 )
				)
			);
		}

		if ( 'publish' === $item['postStatus'] && ! $this->source_repository->userCanPublishSyncedPost( $post_type, $user_id ) ) {
			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_forbidden',
					__( 'You do not have permission to publish this post type.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 403 )
				)
			);
		}

		$marker   = $this->postMarker( (string) $record['batchId'], $index );
		$previous = $record['results'][ $index ] ?? null;
		$saved_id = is_array( $previous ) ? absint( $previous['postId'] ?? 0 ) : 0;

		if ( $saved_id > 0 ) {
			return $this->finishSavedPost( $user_id, $index, $item, $saved_id, $marker );
		}

		if ( ! $this->savePendingItem( $record_hash, $record, $index, $file_id, null ) ) {
			return $this->storageError();
		}

		$marked = $this->findMarkedPost( $marker, $user_id, $post_type );

		if ( $marked instanceof WP_Post ) {
			$relinked = $this->relinkMarkedPost( $user_id, $index, $item, $marked, $marker );

			if ( null !== $relinked ) {
				return $relinked;
			}

			return $this->finishCreatedPost( $user_id, $record_hash, $record, $index, $item, $marked->ID, $marker );
		}

		if ( null !== $this->source_repository->findPostIdByGoogleFileId( $file_id ) ) {
			return $this->failedResult( $index, $file_id, $this->sourceAlreadyLinkedError() );
		}

		$created = $this->createMarkedDraft( $user_id, $item, $marker );

		if ( is_wp_error( $created ) ) {
			$orphan = $this->findMarkedPost( $marker, $user_id, $post_type );

			if ( $orphan instanceof WP_Post ) {
				return $this->failedWithMarkedPost( $index, $file_id, $created, $orphan, $marker );
			}

			return $this->failedResult( $index, $file_id, $created );
		}

		return $this->finishCreatedPost( $user_id, $record_hash, $record, $index, $item, absint( $created['postId'] ?? 0 ), $marker );
	}

	/**
	 * Insert the draft through SyncService with the item marker as its first slug.
	 *
	 * The filter only touches the single new row SyncService inserts for this
	 * item: a new post (no ID) of the item's type, by the batch owner, with the
	 * empty content SyncService always writes. It is removed before returning.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $item    Normalized item.
	 * @param string              $marker  Item marker slug.
	 * @return array<string,mixed>|WP_Error
	 */
	private function createMarkedDraft( int $user_id, array $item, string $marker ): array|WP_Error {
		$post_type = (string) $item['postType'];
		$applied   = false;
		$mark_slug = static function ( array $data, array $postarr ) use ( &$applied, $marker, $user_id, $post_type ): array {
			if (
				$applied
				|| ! empty( $postarr['ID'] )
				|| absint( $data['post_author'] ?? 0 ) !== $user_id
				|| ( $data['post_type'] ?? '' ) !== $post_type
				|| '' !== ( $data['post_content'] ?? null )
			) {
				return $data;
			}

			$applied           = true;
			$data['post_name'] = $marker;

			return $data;
		};

		add_filter( 'wp_insert_post_data', $mark_slug, PHP_INT_MAX, 2 );

		try {
			return $this->sync_service->createDraftFromSource(
				$user_id,
				(string) $item['fileId'],
				$post_type,
				self::EXPORT_FORMAT_HTML_ZIP,
				false,
				$item['elementorSync'],
				(string) $item['layoutPreset'],
				(string) $item['elementorPreset'],
				(string) $item['postStatus']
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $mark_slug, PHP_INT_MAX );
		}
	}

	/**
	 * Complete the link of a marked post left by an interrupted attempt.
	 *
	 * Nothing was queued for it yet, so the full linked source state is written
	 * again with the item's options; a partial save is overwritten.
	 *
	 * @param int                 $user_id User ID.
	 * @param int                 $index   Item index.
	 * @param array<string,mixed> $item    Normalized item.
	 * @param WP_Post             $post    Marked post.
	 * @param string              $marker  Item marker slug.
	 * @return array<string,mixed>|null Failed result, or null when the post is linked.
	 */
	private function relinkMarkedPost( int $user_id, int $index, array $item, WP_Post $post, string $marker ): ?array {
		$file_id = (string) $item['fileId'];
		$source  = $this->source_repository->getSource( $post->ID );

		if ( is_array( $source ) && (string) $source['google_file_id'] !== $file_id ) {
			$this->restoreSlug( $post, $marker );

			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_post_already_linked',
					__( 'This post is already linked to a different Google Doc.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 409 )
				),
				$post->ID
			);
		}

		$linked_post_id = $this->source_repository->findPostIdByGoogleFileId( $file_id );

		if ( null !== $linked_post_id && $linked_post_id !== $post->ID ) {
			return $this->failedWithMarkedPost( $index, $file_id, $this->sourceAlreadyLinkedError(), $post, $marker );
		}

		$attached = $this->sync_service->attachSource(
			$post->ID,
			$user_id,
			$file_id,
			self::EXPORT_FORMAT_HTML_ZIP,
			$item['elementorSync'] ?? false,
			(string) $item['layoutPreset'],
			(string) $item['elementorPreset']
		);

		if ( is_wp_error( $attached ) ) {
			return $this->failedWithMarkedPost( $index, $file_id, $attached, $post, $marker );
		}

		return null;
	}

	/**
	 * Save the new post ID, then restore its slug and queue its first sync.
	 *
	 * @param int                 $user_id     User ID.
	 * @param string              $record_hash Owner-bound key hash.
	 * @param array<string,mixed> $record      Batch record, updated in place.
	 * @param int                 $index       Item index.
	 * @param array<string,mixed> $item        Normalized item.
	 * @param int                 $post_id     Created post ID.
	 * @param string              $marker      Item marker slug.
	 * @return array<string,mixed>|WP_Error
	 */
	private function finishCreatedPost( int $user_id, string $record_hash, array &$record, int $index, array $item, int $post_id, string $marker ): array|WP_Error {
		if ( $post_id <= 0 ) {
			return $this->failedResult(
				$index,
				$item['fileId'],
				new WP_Error(
					'docsync_wp_create_post_failed',
					__( 'Brasth Document Sync could not create a draft post for this Google Doc.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 500 )
				)
			);
		}

		if ( ! $this->savePendingItem( $record_hash, $record, $index, (string) $item['fileId'], $post_id ) ) {
			return $this->storageError();
		}

		return $this->finishSavedPost( $user_id, $index, $item, $post_id, $marker );
	}

	/**
	 * Restore the slug of a post whose ID is saved for this item and queue its first sync.
	 *
	 * @param int                 $user_id User ID.
	 * @param int                 $index   Item index.
	 * @param array<string,mixed> $item    Normalized item.
	 * @param int                 $post_id Saved post ID.
	 * @param string              $marker  Item marker slug.
	 * @return array<string,mixed>|WP_Error
	 */
	private function finishSavedPost( int $user_id, int $index, array $item, int $post_id, string $marker ): array|WP_Error {
		$file_id = (string) $item['fileId'];
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || absint( $post->post_author ) !== $user_id || $post->post_type !== $item['postType'] ) {
			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_invalid_post',
					__( 'Brasth Document Sync could not find that post.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 404 )
				)
			);
		}

		$source = $this->source_repository->getSource( $post_id );

		if ( ! is_array( $source ) ) {
			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_source_not_found',
					__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 404 )
				),
				$post_id
			);
		}

		if ( (string) $source['google_file_id'] !== $file_id ) {
			return $this->failedResult(
				$index,
				$file_id,
				new WP_Error(
					'docsync_wp_post_already_linked',
					__( 'This post is already linked to a different Google Doc.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 409 )
				),
				$post_id
			);
		}

		if ( ! $this->restoreSlug( $post, $marker ) ) {
			return $this->storageError();
		}

		return $this->queueCreated( $user_id, $index, $file_id, $post_id );
	}

	/**
	 * Fail an item whose marked post could not be finished.
	 *
	 * The post is deleted when it is still exactly what this item inserted;
	 * otherwise it is kept, its slug restored, and reported with the failure.
	 *
	 * @param int      $index   Item index.
	 * @param string   $file_id Google Doc ID.
	 * @param WP_Error $error   Failure.
	 * @param WP_Post  $post    Marked post.
	 * @param string   $marker  Item marker slug.
	 * @return array<string,mixed>
	 */
	private function failedWithMarkedPost( int $index, string $file_id, WP_Error $error, WP_Post $post, string $marker ): array {
		if ( $this->discardMarkedPost( $post, $marker, $file_id ) ) {
			return $this->failedResult( $index, $file_id, $error );
		}

		$this->restoreSlug( $post, $marker );

		return $this->failedResult( $index, $file_id, $error, $post->ID );
	}

	/**
	 * Permanently delete a marked post that never received content or another Doc.
	 *
	 * @param WP_Post $post    Marked post.
	 * @param string  $marker  Item marker slug.
	 * @param string  $file_id Google Doc ID of the item.
	 */
	private function discardMarkedPost( WP_Post $post, string $marker, string $file_id ): bool {
		clean_post_cache( $post->ID );
		$post = get_post( $post->ID );

		if ( ! $post instanceof WP_Post ) {
			return true;
		}

		$source = $this->source_repository->getSource( $post->ID );

		if (
			$post->post_name !== $marker
			|| '' !== $post->post_content
			|| ( is_array( $source ) && (string) $source['google_file_id'] !== $file_id )
			|| ( is_array( $source ) && SyncService::STATUS_LINKED !== (string) $source['sync_status'] && '' !== (string) $source['sync_status'] )
		) {
			return false;
		}

		wp_delete_post( $post->ID, true );
		clean_post_cache( $post->ID );

		return null === get_post( $post->ID );
	}

	/**
	 * Find the post an earlier attempt inserted for this item.
	 *
	 * @param string $marker    Item marker slug.
	 * @param int    $user_id   Batch owner.
	 * @param string $post_type Item post type.
	 */
	private function findMarkedPost( string $marker, int $user_id, string $post_type ): ?WP_Post {
		$posts = get_posts(
			array(
				'name'                   => $marker,
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'author'                 => $user_id,
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$post = $posts[0] ?? null;

		return $post instanceof WP_Post && $post->post_name === $marker ? $post : null;
	}

	/**
	 * Replace the marker slug with the slug WordPress would normally assign.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $marker Item marker slug.
	 * @return bool False when the marker is still in place afterwards.
	 */
	private function restoreSlug( WP_Post $post, string $marker ): bool {
		clean_post_cache( $post->ID );
		$current = get_post( $post->ID );

		if ( ! $current instanceof WP_Post || $current->post_name !== $marker ) {
			return true;
		}

		$updated = wp_update_post(
			array(
				'ID'        => $post->ID,
				'post_name' => '',
			),
			true
		);

		if ( is_wp_error( $updated ) || 0 === $updated ) {
			return false;
		}

		clean_post_cache( $post->ID );
		$current = get_post( $post->ID );

		return $current instanceof WP_Post && $current->post_name !== $marker;
	}

	/**
	 * Deterministic temporary slug for one batch item.
	 *
	 * @param string $batch_id Batch ID.
	 * @param int    $index    Item index.
	 */
	private function postMarker( string $batch_id, int $index ): string {
		return self::POST_MARKER_PREFIX . substr( hash( 'sha256', $batch_id . '|' . $index ), 0, 32 );
	}

	/**
	 * Save an item as pending, optionally with the post it created.
	 *
	 * @param string              $record_hash Owner-bound key hash.
	 * @param array<string,mixed> $record      Batch record, updated in place.
	 * @param int                 $index       Item index.
	 * @param string              $file_id     Google Doc ID.
	 * @param int|null            $post_id     Created post ID, if any.
	 */
	private function savePendingItem( string $record_hash, array &$record, int $index, string $file_id, ?int $post_id ): bool {
		$pending = array(
			'index'  => $index,
			'fileId' => $file_id,
			'status' => self::ITEM_PENDING,
		);

		if ( null !== $post_id ) {
			$pending['postId'] = $post_id;
		}

		$record['results'][ $index ] = $pending;

		return $this->saveRecord( $record_hash, $record );
	}

	/**
	 * Queue the first background sync for a post created by this batch.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $index   Item index.
	 * @param string $file_id Google Doc ID.
	 * @param int    $post_id Created post ID.
	 * @return array<string,mixed>
	 */
	private function queueCreated( int $user_id, int $index, string $file_id, int $post_id ): array {
		$has_event = SyncCron::hasScheduledSourceSync( $post_id, $user_id );
		$queued    = $this->sync_service->markSyncQueued( $post_id, $user_id, $has_event );

		if ( is_wp_error( $queued ) ) {
			return $this->failedResult( $index, $file_id, $queued, $post_id );
		}

		if ( empty( $queued['alreadyQueued'] ) ) {
			$scheduled = SyncCron::scheduleSourceSync( $post_id, $user_id, false );

			if ( is_wp_error( $scheduled ) ) {
				$this->sync_service->markSyncError( $post_id, $scheduled );

				return $this->failedResult( $index, $file_id, $scheduled, $post_id );
			}
		}

		return array(
			'index'  => $index,
			'fileId' => $file_id,
			'status' => 'queued',
			'postId' => $post_id,
			'source' => $this->source_repository->formatSource( $post_id ),
			'error'  => null,
		);
	}

	/**
	 * Attach-only item for an existing post.
	 *
	 * @param int                 $user_id User ID.
	 * @param int                 $index   Item index.
	 * @param array<string,mixed> $item    Normalized item.
	 * @return array<string,mixed>
	 */
	private function processAttachItem( int $user_id, int $index, array $item ): array {
		$options = array(
			'transferOwnership' => (bool) $item['transferOwnership'],
			'layoutPreset'      => (string) $item['layoutPreset'],
			'elementorSync'     => $item['elementorSync'],
		);

		if ( null !== $item['elementorPreset'] ) {
			$options['elementorPreset'] = $item['elementorPreset'];
		}

		$post_id = absint( $item['postId'] );
		$result  = $this->attachOnly( $user_id, $post_id, (string) $item['fileId'], $options );

		if ( is_wp_error( $result ) ) {
			return $this->failedResult( $index, $item['fileId'], $result, null );
		}

		return array(
			'index'  => $index,
			'fileId' => $item['fileId'],
			'status' => SyncService::STATUS_LINKED,
			'postId' => $post_id,
			'source' => $this->source_repository->formatSource( $post_id ),
			'error'  => null,
		);
	}

	/**
	 * Validate and normalize batch items; every shape error is request-level.
	 *
	 * @param array<int|string,mixed> $items Raw items.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function normalizeItems( array $items ): array|WP_Error {
		if ( ! array_is_list( $items ) || array() === $items || count( $items ) > self::MAX_ITEMS ) {
			return $this->invalidBatchError( null );
		}

		$normalized = array();
		$seen       = array();

		foreach ( $items as $index => $item ) {
			$item = $this->normalizeItem( $item );

			if ( null === $item ) {
				return $this->invalidBatchError( $index );
			}

			if ( isset( $seen[ $item['fileId'] ] ) ) {
				return new WP_Error(
					'docsync_wp_source_batch_duplicate_file',
					__( 'The same Google Doc appears more than once in this batch.', 'brasth-document-sync-for-google-docs' ),
					array(
						'status' => 400,
						'index'  => $index,
					)
				);
			}

			$seen[ $item['fileId'] ] = true;
			$normalized[]            = $item;
		}

		return $normalized;
	}

	/**
	 * Normalize one item, or null when its shape is invalid.
	 *
	 * @param mixed $item Raw item.
	 * @return array<string,mixed>|null
	 */
	private function normalizeItem( mixed $item ): ?array {
		if ( ! is_array( $item ) || array() !== array_diff( array_keys( $item ), self::ITEM_FIELDS ) ) {
			return null;
		}

		$target = $item['target'] ?? null;

		if ( ! is_array( $target ) || array() !== array_diff( array_keys( $target ), self::TARGET_FIELDS ) ) {
			return null;
		}

		$file_id = $this->document_id_parser->parse( $item['fileId'] ?? '', 'file_id' );

		if ( is_wp_error( $file_id ) ) {
			return null;
		}

		$sync_mode      = $item['syncMode'] ?? null;
		$mode           = $target['mode'] ?? null;
		$layout_preset  = $this->validateLayoutPreset( $item['layoutPreset'] ?? '' );
		$elementor_sync = $item['elementorSync'] ?? null;
		$transfer_owner = $item['transferOwnership'] ?? false;

		if ( is_wp_error( $layout_preset ) || ( null !== $elementor_sync && ! is_bool( $elementor_sync ) ) || ! is_bool( $transfer_owner ) ) {
			return null;
		}

		$elementor_preset = null;

		if ( array_key_exists( 'elementorPreset', $item ) ) {
			$elementor_preset = $this->validateElementorPreset( $item['elementorPreset'] );

			if ( is_wp_error( $elementor_preset ) ) {
				return null;
			}
		}

		$normalized = array(
			'fileId'            => $file_id,
			'mode'              => $mode,
			'postType'          => '',
			'postStatus'        => '',
			'postId'            => 0,
			'layoutPreset'      => $layout_preset,
			'elementorSync'     => $elementor_sync,
			'elementorPreset'   => $elementor_preset,
			'transferOwnership' => $transfer_owner,
		);

		if ( 'new' === $mode && self::SYNC_MODE_BACKGROUND === $sync_mode && ! isset( $target['postId'] ) ) {
			$post_type   = $target['postType'] ?? 'post';
			$post_status = $target['postStatus'] ?? 'draft';

			if ( ! is_string( $post_type ) || sanitize_key( $post_type ) !== $post_type || '' === $post_type || ! in_array( $post_status, array( 'draft', 'publish' ), true ) ) {
				return null;
			}

			$normalized['postType']        = $post_type;
			$normalized['postStatus']      = $post_status;
			$normalized['elementorPreset'] = $elementor_preset ?? '';

			return $normalized;
		}

		if ( 'existing' === $mode && self::SYNC_MODE_ATTACH_ONLY === $sync_mode && ! isset( $target['postType'] ) && ! isset( $target['postStatus'] ) ) {
			$post_id = $target['postId'] ?? null;

			if ( ! is_int( $post_id ) && ! ( is_string( $post_id ) && ctype_digit( $post_id ) ) ) {
				return null;
			}

			$normalized['postId'] = absint( $post_id );

			return $normalized['postId'] > 0 ? $normalized : null;
		}

		return null;
	}

	/**
	 * Validate an optional Gutenberg layout preset ('' means the site default).
	 *
	 * @param mixed $value Raw value.
	 * @return string|WP_Error
	 */
	private function validateLayoutPreset( mixed $value ): string|WP_Error {
		if ( null === $value || '' === $value ) {
			return '';
		}

		if ( is_string( $value ) && sanitize_key( $value ) === $value && $this->layout_presets->isValidPresetId( $value ) ) {
			return $value;
		}

		return new WP_Error(
			'docsync_wp_invalid_layout_preset',
			__( 'Brasth Document Sync received an unsupported layout preset.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Validate an explicit Elementor preset; empty input resolves to the default preset.
	 *
	 * @param mixed $value Raw value.
	 * @return string|WP_Error
	 */
	private function validateElementorPreset( mixed $value ): string|WP_Error {
		if ( null === $value || '' === $value ) {
			return ElementorPresetRegistry::DEFAULT_PRESET;
		}

		if ( is_string( $value ) && sanitize_key( $value ) === $value && $this->elementor_presets->isValidPresetId( $value ) ) {
			return $value;
		}

		return new WP_Error(
			'docsync_wp_invalid_elementor_preset',
			__( 'Brasth Document Sync received an unsupported Elementor layout preset.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Validate post edit access with the same errors as the source routes.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id User ID.
	 * @return bool|WP_Error
	 */
	private function validateEditablePost( int $post_id, int $user_id ): bool|WP_Error {
		$post = $post_id > 0 ? get_post( $post_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error(
				'docsync_wp_invalid_post',
				__( 'Brasth Document Sync could not find that post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->source_repository->isPostTypeEnabled( $post->post_type ) ) {
			return new WP_Error(
				'docsync_wp_post_type_disabled',
				__( 'Brasth Document Sync is not enabled for this post type.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
			return new WP_Error(
				'docsync_wp_forbidden',
				__( 'You do not have permission to edit this post.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Read an unexpired batch record owned by the user.
	 *
	 * @param string $record_hash Owner-bound key hash.
	 * @param int    $user_id     User ID.
	 * @return array<string,mixed>|null
	 */
	private function readRecord( string $record_hash, int $user_id ): ?array {
		$record = $this->readFreshOption( self::OPTION_PREFIX . $record_hash );

		if ( ! is_array( $record ) || self::RECORD_VERSION !== ( $record['version'] ?? null ) ) {
			return null;
		}

		if ( absint( $record['expiresAt'] ?? 0 ) <= time() ) {
			$this->deleteExpiredRecord( $record_hash, time() );

			return null;
		}

		if ( absint( $record['userId'] ?? 0 ) !== $user_id || ! is_string( $record['requestHash'] ?? null ) || ! is_array( $record['results'] ?? null ) ) {
			return null;
		}

		return $record;
	}

	/**
	 * Delete a batch record only while the stored row is the expired one read here.
	 *
	 * A record recreated in between by a replay under the lease is never removed.
	 *
	 * @param string $record_hash Owner-bound key hash.
	 * @param int    $now         Current time.
	 * @return int|null Null when no record row remains; the expiry of a live
	 *                  record that was kept; 0 when an expired row changed
	 *                  concurrently and is left for the next purge.
	 */
	private function deleteExpiredRecord( string $record_hash, int $now ): ?int {
		$name = self::OPTION_PREFIX . $record_hash;
		$raw  = $this->readRawOption( $name );

		if ( null === $raw ) {
			return null;
		}

		$record = maybe_unserialize( $raw );

		if ( is_array( $record ) && self::RECORD_VERSION === ( $record['version'] ?? null ) && absint( $record['expiresAt'] ?? 0 ) > $now ) {
			return absint( $record['expiresAt'] );
		}

		if ( $this->deleteOptionIfUnchanged( $name, $raw ) || null === $this->readRawOption( $name ) ) {
			return null;
		}

		return 0;
	}

	/**
	 * Create and index a durable batch record.
	 *
	 * @param int    $user_id      User ID.
	 * @param string $record_hash  Owner-bound key hash.
	 * @param string $request_hash Normalized body hash.
	 * @return array<string,mixed>|WP_Error
	 */
	private function createRecord( int $user_id, string $record_hash, string $request_hash ): array|WP_Error {
		$now    = time();
		$record = array(
			'version'     => self::RECORD_VERSION,
			'userId'      => $user_id,
			'batchId'     => wp_generate_uuid4(),
			'requestHash' => $request_hash,
			'status'      => 'running',
			'createdAt'   => $now,
			'expiresAt'   => $now + self::RECORD_TTL_SECONDS,
			'results'     => array(),
		);

		$name = self::OPTION_PREFIX . $record_hash;

		delete_option( $name );
		add_option( $name, $record, '', false );

		if ( $this->readFreshOption( $name ) !== $record ) {
			delete_option( $name );

			return $this->storageError();
		}

		$index                 = $this->readIndex();
		$index[ $record_hash ] = $record['expiresAt'];

		if ( ! $this->writeIndex( $index ) ) {
			delete_option( $name );

			return $this->storageError();
		}

		return $record;
	}

	/**
	 * Persist a batch record and confirm the stored value.
	 *
	 * `update_option()` returns false both for "unchanged" and "failed", so the
	 * stored row is read back without the request cache.
	 *
	 * @param string              $record_hash Owner-bound key hash.
	 * @param array<string,mixed> $record      Record.
	 */
	private function saveRecord( string $record_hash, array $record ): bool {
		$name = self::OPTION_PREFIX . $record_hash;

		if ( update_option( $name, $record, false ) ) {
			return true;
		}

		return $this->readFreshOption( $name ) === $record;
	}

	/**
	 * Format a record for the REST response.
	 *
	 * @param array<string,mixed> $record Record.
	 * @return array{batchId:string,results:array<int,array<string,mixed>>}
	 */
	private function formatBatch( array $record ): array {
		$results = array_values(
			array_filter(
				(array) $record['results'],
				static function ( $result ): bool {
					return is_array( $result ) && self::ITEM_PENDING !== ( $result['status'] ?? '' );
				}
			)
		);

		return array(
			'batchId' => (string) $record['batchId'],
			'results' => $results,
		);
	}

	/**
	 * Failed item result.
	 *
	 * @param int      $index   Item index.
	 * @param mixed    $file_id Google Doc ID.
	 * @param WP_Error $error   Failure.
	 * @param int|null $post_id Post created before the failure, if any.
	 * @return array<string,mixed>
	 */
	private function failedResult( int $index, mixed $file_id, WP_Error $error, ?int $post_id = null ): array {
		$data = $error->get_error_data();

		return array(
			'index'  => $index,
			'fileId' => (string) $file_id,
			'status' => 'failed',
			'postId' => $post_id,
			'source' => null !== $post_id ? $this->source_repository->formatSource( $post_id ) : null,
			'error'  => array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
				'status'  => is_array( $data ) && isset( $data['status'] ) ? absint( $data['status'] ) : 500,
			),
		);
	}

	/**
	 * Acquire the per-batch lease, replacing an expired one.
	 *
	 * The lease stores a random token so only its holder can renew or release
	 * it. A legacy integer expiry is honored until it lapses. A missing lease
	 * is created with an insert that fails when the row already exists, and an
	 * expired lease is replaced only while its stored row is still the exact
	 * one read here, so two requests that both saw a missing or expired lease
	 * can never both acquire it or remove each other's new lease.
	 *
	 * @param string $record_hash Owner-bound key hash.
	 * @return string|null Lease token, or null when another request holds the lease.
	 */
	private function acquireLock( string $record_hash ): ?string {
		$name = $this->lockOptionName( $record_hash );
		$raw  = $this->readRawOption( $name );
		$lock = array(
			'token'     => wp_generate_uuid4(),
			'expiresAt' => time() + self::LOCK_TTL_SECONDS,
		);

		if ( null === $raw ) {
			$acquired = $this->insertOptionIfAbsent( $name, $lock );
		} elseif ( $this->lockExpiry( maybe_unserialize( $raw ) ) > time() ) {
			return null;
		} else {
			$acquired = $this->replaceOptionIfUnchanged( $name, $raw, $lock );
		}

		return $acquired ? $lock['token'] : null;
	}

	/**
	 * Extend the lease by another 300 seconds while this request still holds it.
	 *
	 * The new expiry is written only while the stored row is still the exact
	 * lease read here, so a lease taken over in between is never overwritten.
	 *
	 * @param string $record_hash Owner-bound key hash.
	 * @param string $token       Lease token.
	 */
	private function renewLock( string $record_hash, string $token ): bool {
		$name = $this->lockOptionName( $record_hash );
		$raw  = $this->readRawOption( $name );

		if ( null === $raw ) {
			return false;
		}

		$current = maybe_unserialize( $raw );

		if ( ! is_array( $current ) || ( $current['token'] ?? null ) !== $token ) {
			return false;
		}

		$lock = array(
			'token'     => $token,
			'expiresAt' => time() + self::LOCK_TTL_SECONDS,
		);

		if ( $lock === $current ) {
			return true;
		}

		return $this->replaceOptionIfUnchanged( $name, $raw, $lock );
	}

	/**
	 * Release the per-batch lease if this request still holds it.
	 *
	 * The row is deleted only while it is still the exact lease read here, so a
	 * lease taken over in between survives.
	 *
	 * @param string $record_hash Owner-bound key hash.
	 * @param string $token       Lease token.
	 */
	private function releaseLock( string $record_hash, string $token ): void {
		$name = $this->lockOptionName( $record_hash );
		$raw  = $this->readRawOption( $name );

		if ( null === $raw ) {
			return;
		}

		$current = maybe_unserialize( $raw );

		if ( is_array( $current ) && ( $current['token'] ?? null ) === $token ) {
			$this->deleteOptionIfUnchanged( $name, $raw );
		}
	}

	/**
	 * Read the stored option row directly, without option caches or filters.
	 *
	 * Compare-and-swap writes compare against this exact serialized value.
	 *
	 * @param string $name Option name.
	 * @return string|null Serialized stored value, or null when the row is missing.
	 */
	private function readRawOption( string $name ): ?string {
		global $wpdb;

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$name
			)
		);

		return is_string( $value ) ? $value : null;
	}

	/**
	 * Insert a non-autoloaded option only when no row with that name exists.
	 *
	 * `add_option()` upserts at the SQL level, so it cannot decide a race
	 * between two requests that both saw the option missing.
	 *
	 * @param string $name  Option name.
	 * @param mixed  $value Value to store.
	 * @return bool True when this call created the row.
	 */
	private function insertOptionIfAbsent( string $name, mixed $value ): bool {
		global $wpdb;

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
				$name,
				maybe_serialize( $value ),
				'no'
			)
		);

		$this->clearOptionCache( $name );

		return 1 === $inserted;
	}

	/**
	 * Replace an option only while its stored row equals the expected bytes.
	 *
	 * The comparison is binary so collation rules never treat a different
	 * stored value as equal. The new value must differ from the expected one.
	 *
	 * @param string $name     Option name.
	 * @param string $expected Serialized value read earlier.
	 * @param mixed  $value    New value.
	 * @return bool True when this call replaced the expected row.
	 */
	private function replaceOptionIfUnchanged( string $name, string $expected, mixed $value ): bool {
		global $wpdb;

		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
				maybe_serialize( $value ),
				$name,
				$expected
			)
		);

		$this->clearOptionCache( $name );

		return 1 === $updated;
	}

	/**
	 * Delete an option only while its stored row equals the expected bytes.
	 *
	 * @param string $name     Option name.
	 * @param string $expected Serialized value read earlier.
	 * @return bool True when this call deleted the expected row.
	 */
	private function deleteOptionIfUnchanged( string $name, string $expected ): bool {
		global $wpdb;

		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS BINARY) = CAST(%s AS BINARY)",
				$name,
				$expected
			)
		);

		$this->clearOptionCache( $name );

		return 1 === $deleted;
	}

	/**
	 * Clear option caches after a direct compare-and-swap write.
	 *
	 * @param string $name Option name.
	 */
	private function clearOptionCache( string $name ): void {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Expiry timestamp of a stored lease; malformed values count as expired.
	 *
	 * @param mixed $lock Stored lease.
	 */
	private function lockExpiry( mixed $lock ): int {
		if ( is_array( $lock ) ) {
			return absint( $lock['expiresAt'] ?? 0 );
		}

		return is_int( $lock ) || ( is_string( $lock ) && ctype_digit( $lock ) ) ? absint( $lock ) : 0;
	}

	/**
	 * Read an option from the database, bypassing values cached by this request.
	 *
	 * Batch records, leases, and the index are shared between concurrent
	 * requests, so decisions must not rely on a copy read earlier.
	 *
	 * @param string $name Option name.
	 * @return mixed Null when the option does not exist.
	 */
	private function readFreshOption( string $name ): mixed {
		wp_cache_delete( $name, 'options' );

		$notoptions = wp_cache_get( 'notoptions', 'options' );

		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}

		return get_option( $name, null );
	}

	/**
	 * Lock option name (shares the batch option prefix for uninstall cleanup).
	 *
	 * @param string $record_hash Owner-bound key hash.
	 */
	private function lockOptionName( string $record_hash ): string {
		return self::OPTION_PREFIX . $record_hash . '_lock';
	}

	/**
	 * Read the batch expiry index.
	 *
	 * @return array<string,int>
	 */
	private function readIndex(): array {
		$index = $this->readFreshOption( self::INDEX_OPTION );
		$valid = array();

		if ( ! is_array( $index ) ) {
			return $valid;
		}

		foreach ( $index as $record_hash => $expires_at ) {
			if ( is_string( $record_hash ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $record_hash ) ) {
				$valid[ $record_hash ] = absint( $expires_at );
			}
		}

		return $valid;
	}

	/**
	 * Persist the batch expiry index and confirm the stored value.
	 *
	 * @param array<string,int> $index Index.
	 */
	private function writeIndex( array $index ): bool {
		if ( array() === $index ) {
			delete_option( self::INDEX_OPTION );

			return null === $this->readFreshOption( self::INDEX_OPTION );
		}

		update_option( self::INDEX_OPTION, $index, false );

		return $this->readFreshOption( self::INDEX_OPTION ) === $index;
	}

	/**
	 * Another request holds this batch's lease.
	 */
	private function batchInProgressError(): WP_Error {
		return new WP_Error(
			'docsync_wp_source_batch_in_progress',
			__( 'These Google Docs are still being added. Wait a moment, then try again.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Batch state could not be saved; the next attempt resumes from the last saved state.
	 */
	private function storageError(): WP_Error {
		return new WP_Error(
			'docsync_wp_source_batch_storage_failed',
			__( 'Brasth Document Sync could not save this batch. Try again.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 500 )
		);
	}
	/**
	 * Doc linked to another post error.
	 */
	private function sourceAlreadyLinkedError(): WP_Error {
		return new WP_Error(
			'docsync_wp_source_already_linked',
			__( 'This Google Doc is already linked to another post.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Idempotency key reused with a different body.
	 */
	private function idempotencyConflictError(): WP_Error {
		return new WP_Error(
			'docsync_wp_idempotency_conflict',
			__( 'This request key was already used for different Google Docs.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Malformed batch error.
	 *
	 * @param int|null $index Offending item index, if any.
	 */
	private function invalidBatchError( ?int $index ): WP_Error {
		$data = array( 'status' => 400 );

		if ( null !== $index ) {
			$data['index'] = $index;
		}

		return new WP_Error(
			'docsync_wp_source_batch_invalid',
			__( 'Brasth Document Sync received an invalid batch of Google Docs.', 'brasth-document-sync-for-google-docs' ),
			$data
		);
	}
}
