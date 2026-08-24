<?php
/**
 * Google Docs source attach and import service.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync;

use DocSyncWP\Google\DriveClient;
use DocSyncWP\Sync\Elementor\DataConverter as ElementorDataConverter;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetConversionService;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\Elementor\PostUpdater as ElementorPostUpdater;
use DocSyncWP\Sync\Elementor\SyncDecider as ElementorSyncDecider;
use DocSyncWP\Sync\Layout\LayoutConversionService;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinates source metadata, Google export, content conversion, and post updates.
 */
final class SyncService {
	public const STATUS_LINKED  = 'linked';
	public const STATUS_SYNCING = 'syncing';
	public const STATUS_SYNCED  = 'synced';
	public const STATUS_SKIPPED = 'skipped';
	public const STATUS_ERROR   = 'error';

	private const EXPORT_FORMAT_HTML_ZIP = 'html_zip';
	private const SYNC_METHOD_HTML_ZIP   = 'html_zip';
	private const SYNC_METHOD_DOCS_API   = 'docs_api_fallback';

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Drive client.
	 *
	 * @var DriveClient
	 */
	private DriveClient $drive_client;

	/**
	 * HTML ZIP importer.
	 *
	 * @var HtmlZipImporter
	 */
	private HtmlZipImporter $html_zip_importer;

	/**
	 * Docs API fallback importer.
	 *
	 * @var DocsApiHtmlImporter
	 */
	private DocsApiHtmlImporter $docs_api_importer;

	/**
	 * Layout conversion service.
	 *
	 * @var LayoutConversionService
	 */
	private LayoutConversionService $layout_converter;

	/**
	 * Sync lock.
	 *
	 * @var SyncLock
	 */
	private SyncLock $sync_lock;

	/**
	 * Elementor sync decider.
	 *
	 * @var ElementorSyncDecider|null
	 */
	private ?ElementorSyncDecider $elementor_decider;

	/**
	 * Elementor data converter.
	 *
	 * @var ElementorDataConverter
	 */
	private ElementorDataConverter $elementor_converter;

	/**
	 * Elementor preset conversion service.
	 *
	 * @var ElementorPresetConversionService
	 */
	private ElementorPresetConversionService $elementor_preset_converter;

	/**
	 * Elementor post updater.
	 *
	 * @var ElementorPostUpdater
	 */
	private ElementorPostUpdater $elementor_updater;

	/**
	 * Schedule resolver.
	 *
	 * @var SourceScheduleResolver|null
	 */
	private ?SourceScheduleResolver $schedule = null;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository                      $source_repository   Source repository.
	 * @param DriveClient                           $drive_client        Drive client.
	 * @param HtmlZipImporter                       $html_zip_importer   HTML ZIP importer.
	 * @param DocsApiHtmlImporter                   $docs_api_importer   Docs API fallback importer.
	 * @param LayoutConversionService               $layout_converter    Layout conversion service.
	 * @param SyncLock                              $sync_lock           Sync lock.
	 * @param ElementorSyncDecider|null             $elementor_decider   Elementor sync decider.
	 * @param ElementorDataConverter|null           $elementor_converter        Elementor data converter.
	 * @param ElementorPostUpdater|null             $elementor_updater          Elementor post updater.
	 * @param ElementorPresetConversionService|null $elementor_preset_converter Elementor preset converter.
	 * @param SourceScheduleResolver|null           $schedule                   Schedule resolver.
	 */
	public function __construct(
		SourceRepository $source_repository,
		DriveClient $drive_client,
		HtmlZipImporter $html_zip_importer,
		DocsApiHtmlImporter $docs_api_importer,
		LayoutConversionService $layout_converter,
		SyncLock $sync_lock,
		?ElementorSyncDecider $elementor_decider = null,
		?ElementorDataConverter $elementor_converter = null,
		?ElementorPostUpdater $elementor_updater = null,
		?ElementorPresetConversionService $elementor_preset_converter = null,
		?SourceScheduleResolver $schedule = null
	) {
		$this->source_repository          = $source_repository;
		$this->drive_client               = $drive_client;
		$this->html_zip_importer          = $html_zip_importer;
		$this->docs_api_importer          = $docs_api_importer;
		$this->layout_converter           = $layout_converter;
		$this->sync_lock                  = $sync_lock;
		$this->elementor_decider          = $elementor_decider;
		$this->elementor_converter        = $elementor_converter ?? new ElementorDataConverter();
		$this->elementor_updater          = $elementor_updater ?? new ElementorPostUpdater();
		$this->elementor_preset_converter = $elementor_preset_converter ?? new ElementorPresetConversionService();
		$this->schedule                   = $schedule;
	}

	/**
	 * Get the Elementor sync decider if available.
	 *
	 * @return ElementorSyncDecider|null
	 */
	public function getElementorDecider(): ?ElementorSyncDecider {
		return $this->elementor_decider;
	}

	/**
	 * Attach a Google Doc source to an existing post without importing content.
	 *
	 * @param int       $post_id       Post ID.
	 * @param int       $user_id       User ID.
	 * @param string    $file_id       Google Drive file ID.
	 * @param string    $export_format Export format.
	 * @param bool|null $elementor_sync Whether to sync this post as an Elementor layout. Null falls back to detection.
	 * @param string    $layout_preset  Optional Gutenberg layout preset override.
	 * @param string    $elementor_preset Optional Elementor layout preset.
	 * @return array<string,mixed>|WP_Error
	 */
	public function attachSource(
		int $post_id,
		int $user_id,
		string $file_id,
		string $export_format = self::EXPORT_FORMAT_HTML_ZIP,
		?bool $elementor_sync = null,
		string $layout_preset = '',
		string $elementor_preset = ''
	): array|WP_Error {
		$export_format = $this->sanitizeExportFormat( $export_format );

		if ( is_wp_error( $export_format ) ) {
			return $export_format;
		}

		$current_source = $this->source_repository->getSource( $post_id );

		if ( is_array( $current_source ) && self::STATUS_SYNCING === (string) $current_source['sync_status'] ) {
			return new WP_Error(
				'docsync_wp_source_syncing',
				__( 'Wait for the current Google Doc sync to finish before changing this source.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		$metadata = $this->drive_client->getMetadata( $user_id, $file_id );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$can_sync = $this->assertMetadataCanDownload( $metadata );

		if ( is_wp_error( $can_sync ) ) {
			return $can_sync;
		}

		if ( null === $elementor_sync && null !== $this->elementor_decider ) {
			$elementor_sync = $this->elementor_decider->getDefaultElementorSync( $post_id );
		}

		$current_uses_elementor    = is_array( $current_source )
			&& null !== $this->elementor_decider
			&& $this->elementor_decider->shouldUseElementor( $post_id );
		$preserve_legacy_elementor = is_array( $current_source )
			&& $current_uses_elementor
			&& '' === (string) ( $current_source['elementor_preset'] ?? '' )
			&& true === $elementor_sync
			&& '' === $elementor_preset;

		if ( true === $elementor_sync && '' === $elementor_preset && ! $preserve_legacy_elementor ) {
			$elementor_preset = ElementorPresetRegistry::DEFAULT_PRESET;
		}

		$saved = $this->source_repository->saveSource(
			$post_id,
			array_merge(
				$this->sourceFromMetadata( $metadata ),
				array(
					'last_hash'          => '',
					'last_synced_at'     => '',
					'last_sync_method'   => '',
					'layout_preset'      => $layout_preset,
					'elementor_preset'   => $elementor_preset,
					'sync_owner_user_id' => $user_id,
					'export_format'      => $export_format,
					'elementor_sync'     => $elementor_sync,
					'sync_status'        => self::STATUS_LINKED,
					'sync_error'         => '',
					'sync_progress'      => 0,
					'sync_step'          => 'linked',
					'sync_message'       => __( 'Linked and ready to sync.', 'brasth-document-sync-for-google-docs' ),
					'sync_started_at'    => '',
					'sync_updated_at'    => '',
					'sync_error_code'    => '',
				)
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->formatResult( $post_id, self::STATUS_LINKED, false );
	}

	/**
	 * Create a draft post, attach the source, and optionally sync it immediately.
	 *
	 * @param int       $user_id          User ID.
	 * @param string    $file_id          Google Drive file ID.
	 * @param string    $post_type        Post type.
	 * @param string    $export_format    Export format.
	 * @param bool      $sync_immediately Whether to sync before returning.
	 * @param bool|null $elementor_sync   Whether to sync this post as an Elementor layout. Null defaults to false for new drafts.
	 * @param string    $layout_preset    Optional Gutenberg layout preset override.
	 * @param string    $elementor_preset Optional Elementor layout preset.
	 * @param string    $post_status      New post status. Draft or publish.
	 * @param string    $folder_watch_id  Optional parent folder watch.
	 * @return array<string,mixed>|WP_Error
	 */
	public function createDraftFromSource(
		int $user_id,
		string $file_id,
		string $post_type,
		string $export_format = self::EXPORT_FORMAT_HTML_ZIP,
		bool $sync_immediately = true,
		?bool $elementor_sync = null,
		string $layout_preset = '',
		string $elementor_preset = '',
		string $post_status = 'draft',
		string $folder_watch_id = ''
	): array|WP_Error {
		$export_format = $this->sanitizeExportFormat( $export_format );

		if ( is_wp_error( $export_format ) ) {
			return $export_format;
		}

		$metadata = $this->drive_client->getMetadata( $user_id, $file_id );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$can_sync = $this->assertMetadataCanDownload( $metadata );

		if ( is_wp_error( $can_sync ) ) {
			return $can_sync;
		}

		$elementor_sync = $elementor_sync ?? false;

		if ( true === $elementor_sync && '' === $elementor_preset ) {
			$elementor_preset = ElementorPresetRegistry::DEFAULT_PRESET;
		}

		$post_status = in_array( $post_status, array( 'draft', 'publish' ), true ) ? $post_status : 'draft';

		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_author'  => $user_id,
					'post_content' => '',
					'post_status'  => $post_status,
					'post_title'   => $metadata['name'],
					'post_type'    => $post_type,
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new WP_Error(
				'docsync_wp_create_post_failed',
				__( 'Brasth Document Sync could not create a draft post for this Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		$saved = $this->source_repository->saveSource(
			(int) $post_id,
			array_merge(
				$this->sourceFromMetadata( $metadata ),
				array(
					'last_hash'          => '',
					'last_synced_at'     => '',
					'last_sync_method'   => '',
					'layout_preset'      => $layout_preset,
					'elementor_preset'   => $elementor_preset,
					'sync_owner_user_id' => $user_id,
					'export_format'      => $export_format,
					'elementor_sync'     => $elementor_sync,
					'sync_status'        => self::STATUS_LINKED,
					'sync_error'         => '',
					'sync_progress'      => 0,
					'sync_step'          => 'linked',
					'sync_message'       => __( 'Linked and ready to sync.', 'brasth-document-sync-for-google-docs' ),
					'sync_started_at'    => '',
					'sync_updated_at'    => '',
					'sync_error_code'    => '',
					'folder_watch_id'    => $folder_watch_id,
				)
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( ! $sync_immediately ) {
			$result            = $this->formatResult( (int) $post_id, self::STATUS_LINKED, false );
			$result['created'] = true;

			return $result;
		}

		$synced = $this->syncPost( (int) $post_id, $user_id );

		if ( is_wp_error( $synced ) ) {
			$data = $synced->get_error_data();

			if ( ! is_array( $data ) ) {
				$data = array();
			}

			$data['postId'] = (int) $post_id;
			$synced->add_data( $data );

			return $synced;
		}

		$synced['created'] = true;

		return $synced;
	}

	/**
	 * Mark a linked source as queued for background sync.
	 *
	 * @param int  $post_id           Post ID.
	 * @param int  $user_id           User ID that owns the queued sync.
	 * @param bool $has_pending_event Whether a matching cron event is already pending.
	 * @return array<string,mixed>|WP_Error
	 */
	public function markSyncQueued( int $post_id, int $user_id, bool $has_pending_event = false ): array|WP_Error {
		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( self::STATUS_SYNCING === (string) $source['sync_status'] && ( $has_pending_event || $this->sync_lock->isActive( $post_id ) ) ) {
			$result                  = $this->formatResult( $post_id, 'queued', false, true );
			$result['alreadyQueued'] = true;

			return $result;
		}

		$saved = $this->saveProgressState(
			$post_id,
			$source,
			self::STATUS_SYNCING,
			0,
			'queued',
			__( 'Sync queued.', 'brasth-document-sync-for-google-docs' ),
			array(
				'sync_owner_user_id' => $user_id,
				'sync_error'         => '',
			),
			array( 'hasCronEvent' => $has_pending_event )
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->formatResult( $post_id, 'queued', false, true );
	}

	/**
	 * Persist a background sync failure.
	 *
	 * @param int                 $post_id Post ID.
	 * @param string|WP_Error     $error   Error message or object.
	 * @param array<string,mixed> $context Safe diagnostic context flags.
	 * @return array<string,mixed>|WP_Error
	 */
	public function markSyncError( int $post_id, string|WP_Error $error, array $context = array() ): array|WP_Error {
		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		$message  = is_wp_error( $error ) ? $error->get_error_message() : $error;
		$code     = is_wp_error( $error ) ? $error->get_error_code() : 'docsync_wp_background_sync_failed';
		$progress = isset( $source['sync_progress'] ) ? (int) $source['sync_progress'] : 0;
		$saved    = $this->saveProgressState(
			$post_id,
			$source,
			self::STATUS_ERROR,
			$progress,
			'error',
			$message,
			array(
				'last_synced_at'  => current_time( 'mysql', true ),
				'sync_error'      => $message,
				'sync_error_code' => $code,
			),
			$context
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->formatResult( $post_id, self::STATUS_ERROR, false );
	}

	/**
	 * Sync a linked post from Google Docs.
	 *
	 * @param int  $post_id Post ID.
	 * @param int  $user_id User ID making the request.
	 * @param bool $force   Whether to force import even if unchanged.
	 * @return array<string,mixed>|WP_Error
	 */
	public function syncPost( int $post_id, int $user_id, bool $force = false ): array|WP_Error {
		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			return new WP_Error(
				'docsync_wp_source_not_found',
				__( 'This post is not linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 404 )
			);
		}

		if ( ! $this->sync_lock->acquire( $post_id ) ) {
			return new WP_Error(
				'docsync_wp_sync_locked',
				__( 'This Google Doc sync is already running.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		try {
			$source = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCING,
				10,
				'checking_google',
				__( 'Checking Google Doc metadata.', 'brasth-document-sync-for-google-docs' ),
				array( 'sync_error' => '' )
			);

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			$sync_user_id = $this->getSyncUserId( $source, $user_id );
			$metadata     = $this->drive_client->getMetadata( $sync_user_id, (string) $source['google_file_id'] );

			if ( is_wp_error( $metadata ) ) {
				return $this->markError( $post_id, $source, $metadata );
			}

			$can_sync = $this->assertMetadataCanDownload( $metadata );

			if ( is_wp_error( $can_sync ) ) {
				return $this->markError( $post_id, $source, $can_sync );
			}

			$previous_source = $source;
			$source          = array_merge( $source, $this->sourceFromMetadata( $metadata ) );
			$use_elementor   = null !== $this->elementor_decider && $this->elementor_decider->shouldUseElementor( $post_id );
			$layout_hash     = $use_elementor
				? $this->elementor_preset_converter->fingerprintForSource( $source )
				: $this->layout_converter->fingerprintForSource( $source );

			if (
				! $force
				&& '' !== (string) $source['last_hash']
				&& (string) $previous_source['google_modified_time'] === (string) $metadata['modifiedTime']
				&& (string) $previous_source['google_version'] === (string) $metadata['version']
				&& $this->isLayoutFingerprintCurrent( $source, $layout_hash, $use_elementor )
			) {
				$skip_updates = array(
					'last_synced_at'     => current_time( 'mysql', true ),
					'sync_owner_user_id' => $sync_user_id,
					'sync_error'         => '',
				);

				if ( '' !== $layout_hash ) {
					$skip_updates['last_layout_hash'] = $layout_hash;
				}

				$source = $this->saveProgressState(
					$post_id,
					$source,
					self::STATUS_SKIPPED,
					100,
					'complete',
					__( 'Google Doc has not changed.', 'brasth-document-sync-for-google-docs' ),
					$skip_updates
				);

				if ( is_wp_error( $source ) ) {
					return $source;
				}

				return $this->formatResult( $post_id, self::STATUS_SKIPPED, false );
			}

			$source = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCING,
				25,
				'exporting',
				__( 'Exporting Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array(
					'sync_owner_user_id' => $sync_user_id,
					'sync_error'         => '',
				)
			);

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			$import = $this->importSourceHtml( $sync_user_id, (string) $source['google_file_id'], $post_id, $source );

			if ( is_wp_error( $import ) ) {
				return $this->markError( $post_id, $source, $import );
			}

			$source = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCING,
				70,
				'converting',
				$use_elementor
				? __( 'Converting content to Elementor layout.', 'brasth-document-sync-for-google-docs' )
				: __( 'Converting content to WordPress blocks.', 'brasth-document-sync-for-google-docs' ),
				array( 'sync_error' => '' )
			);

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			if ( $use_elementor ) {
				$elementor_preset = $this->elementor_preset_converter->resolvePresetForSource( $source );
				$elementor_json   = '' !== $elementor_preset
					? $this->elementor_preset_converter->convert( $import['html'], $post_id, $elementor_preset )
					: $this->elementor_converter->convert( $import['html'], $post_id );

				if ( is_wp_error( $elementor_json ) ) {
					return $this->markError( $post_id, $source, $elementor_json );
				}

				$content = $elementor_json;
			} else {
				$block_content = $this->layout_converter->convertForSource( $import['html'], $source );

				if ( is_wp_error( $block_content ) ) {
					return $this->markError( $post_id, $source, $block_content );
				}

				$content = $block_content;
			}

			$hash = hash( 'sha256', $content );

			if ( ! $force && hash_equals( (string) $source['last_hash'], $hash ) ) {
				$skip_updates = array(
					'last_hash'          => $hash,
					'last_synced_at'     => current_time( 'mysql', true ),
					'last_sync_method'   => $import['method'],
					'sync_owner_user_id' => $sync_user_id,
					'sync_error'         => '',
				);

				if ( '' !== $layout_hash ) {
					$skip_updates['last_layout_hash'] = $layout_hash;
				}

				$source = $this->saveProgressState(
					$post_id,
					$source,
					self::STATUS_SKIPPED,
					100,
					'complete',
					__( 'Imported content matches the current WordPress post.', 'brasth-document-sync-for-google-docs' ),
					$skip_updates
				);

				if ( is_wp_error( $source ) ) {
					return $source;
				}

				return $this->formatResult( $post_id, self::STATUS_SKIPPED, false );
			}

			$source = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCING,
				90,
				'updating_post',
				__( 'Updating the WordPress post.', 'brasth-document-sync-for-google-docs' ),
				array( 'sync_error' => '' )
			);

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			$current_source = $this->assertCurrentSource( $post_id, $source );

			if ( is_wp_error( $current_source ) ) {
				return $current_source;
			}

			if ( $use_elementor ) {
				$updated = $this->elementor_updater->update( $post_id, (string) $content );

				if ( is_wp_error( $updated ) ) {
					return $this->markError( $post_id, $source, $updated );
				}

				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => '',
						)
					),
					true
				);
			} else {
				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $content,
						)
					),
					true
				);
			}

			if ( is_wp_error( $updated ) ) {
				return $this->markError(
					$post_id,
					$source,
					new WP_Error(
						'docsync_wp_update_post_failed',
						__( 'Brasth Document Sync could not update this post with Google Docs content.', 'brasth-document-sync-for-google-docs' ),
						array( 'status' => 500 )
					)
				);
			}

			$success_updates = array(
				'last_hash'          => $hash,
				'last_synced_at'     => current_time( 'mysql', true ),
				'last_sync_method'   => $import['method'],
				'sync_owner_user_id' => $sync_user_id,
				'sync_error'         => '',
			);

			if ( '' !== $layout_hash ) {
				$success_updates['last_layout_hash'] = $layout_hash;
			}

			$source = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCED,
				100,
				'complete',
				__( 'Sync complete.', 'brasth-document-sync-for-google-docs' ),
				$success_updates
			);

			if ( is_wp_error( $source ) ) {
				return $source;
			}

			return $this->formatResult( $post_id, self::STATUS_SYNCED, true );
		} finally {
			$this->sync_lock->release( $post_id );
		}
	}

	/**
	 * Whether a post has an unexpired sync lock.
	 *
	 * @param int $post_id Post ID.
	 */
	public function hasActiveSyncLock( int $post_id ): bool {
		return $this->sync_lock->isActive( $post_id );
	}

	/**
	 * Store source progress and state updates.
	 *
	 * @param int                 $post_id  Post ID.
	 * @param array<string,mixed> $source   Current source.
	 * @param string              $status   Sync status.
	 * @param int                 $progress Progress percent.
	 * @param string              $step     Progress step.
	 * @param string              $message  Progress message.
	 * @param array<string,mixed> $updates  Extra source updates.
	 * @param array<string,mixed> $event_context Safe diagnostic context flags.
	 * @param string              $event_error_code Optional event-only error code.
	 * @return array<string,mixed>|WP_Error
	 */
	private function saveProgressState(
		int $post_id,
		array $source,
		string $status,
		int $progress,
		string $step,
		string $message,
		array $updates = array(),
		array $event_context = array(),
		string $event_error_code = ''
	): array|WP_Error {
		$latest_source = $this->source_repository->getSource( $post_id );

		if ( ! $this->isSameSource( $latest_source, $source ) ) {
			return $this->sourceChangedError();
		}

		$now         = current_time( 'mysql', true );
		$next_source = array_merge(
			$latest_source,
			$source,
			$updates,
			array(
				'sync_status'     => $status,
				'sync_progress'   => $progress,
				'sync_step'       => $step,
				'sync_message'    => $message,
				'sync_updated_at' => $now,
			)
		);

		if ( self::STATUS_SYNCING === $status ) {
			$was_syncing = self::STATUS_SYNCING === (string) ( $latest_source['sync_status'] ?? '' );

			if ( ! $was_syncing || '' === (string) ( $next_source['sync_started_at'] ?? '' ) ) {
				$next_source['sync_started_at'] = $now;
			}

			$next_source['sync_error_code'] = '';
		}

		if (
			null !== $this->schedule
			&& in_array( $status, array( self::STATUS_SYNCED, self::STATUS_SKIPPED, self::STATUS_ERROR ), true )
		) {
			$interval                    = $this->schedule->resolveInterval( $next_source );
			$from                        = isset( $next_source['last_synced_at'] ) && '' !== (string) $next_source['last_synced_at']
				? (string) $next_source['last_synced_at']
				: $now;
			$next_source['next_sync_at'] = SourceScheduleResolver::nextSyncAt( $from, $interval );
		}

		$saved = $this->source_repository->saveSource( $post_id, $next_source );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		if ( self::STATUS_SYNCING === $status ) {
			$this->sync_lock->refresh( $post_id );
		}

		$this->source_repository->appendSyncEvent(
			$post_id,
			array(
				'timestamp'     => $now,
				'level'         => $this->syncEventLevel( $status, $step ),
				'status'        => $status,
				'step'          => $step,
				'progress'      => $progress,
				'message'       => $message,
				'errorCode'     => '' !== $event_error_code ? $event_error_code : (string) ( $next_source['sync_error_code'] ?? '' ),
				'syncStartedAt' => (string) ( $next_source['sync_started_at'] ?? '' ),
				'syncUpdatedAt' => (string) ( $next_source['sync_updated_at'] ?? '' ),
				'context'       => array_merge( $event_context, $this->syncOutputContext( $post_id, $next_source ) ),
			)
		);

		return $next_source;
	}

	/**
	 * Build safe diagnostic context for the effective output path.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $source  Source metadata.
	 * @return array<string,string>
	 */
	private function syncOutputContext( int $post_id, array $source ): array {
		$use_elementor = null !== $this->elementor_decider && $this->elementor_decider->shouldUseElementor( $post_id );

		if ( $use_elementor ) {
			$elementor_preset = $this->elementor_preset_converter->resolvePresetForSource( $source );

			if ( '' === $elementor_preset ) {
				return array(
					'outputType'    => 'elementor',
					'elementorMode' => 'legacy',
				);
			}

			return array(
				'outputType'      => 'elementor',
				'elementorMode'   => 'preset',
				'elementorPreset' => $elementor_preset,
			);
		}

		return array(
			'outputType'   => 'gutenberg',
			'layoutPreset' => $this->layout_converter->resolvePresetForSource( $source ),
		);
	}

	/**
	 * Whether the stored layout fingerprint matches current output policy.
	 *
	 * Legacy sources synced before 1.1.0 have no layout fingerprint. Treat those
	 * as current only when the effective preset is still the legacy plain_blocks
	 * preset. Legacy Elementor sources without an explicit preset have no current
	 * fingerprint and keep the pre-1.1.2 skip behavior.
	 *
	 * @param array<string,mixed> $source      Source metadata.
	 * @param string              $layout_hash Current layout fingerprint.
	 * @param bool                $elementor   Whether Elementor conversion is active.
	 */
	private function isLayoutFingerprintCurrent( array $source, string $layout_hash, bool $elementor ): bool {
		if ( '' === $layout_hash ) {
			return true;
		}

		$last_layout_hash = isset( $source['last_layout_hash'] ) ? (string) $source['last_layout_hash'] : '';

		if ( '' === $last_layout_hash ) {
			if ( $elementor ) {
				return false;
			}

			return LayoutPresetRegistry::PRESET_PLAIN_BLOCKS === $this->layout_converter->resolvePresetForSource( $source );
		}

		return hash_equals( $last_layout_hash, $layout_hash );
	}

	/**
	 * Choose a safe diagnostic level for a sync state event.
	 *
	 * @param string $status Sync status.
	 * @param string $step   Sync step.
	 */
	private function syncEventLevel( string $status, string $step ): string {
		if ( self::STATUS_ERROR === $status ) {
			return 'error';
		}

		if ( 'large_doc_fallback' === $step ) {
			return 'warning';
		}

		return 'info';
	}

	/**
	 * Ensure the linked source still matches the sync that is running.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $source  Source captured by this sync.
	 * @return bool|WP_Error
	 */
	private function assertCurrentSource( int $post_id, array $source ): bool|WP_Error {
		$latest_source = $this->source_repository->getSource( $post_id );

		if ( $this->isSameSource( $latest_source, $source ) ) {
			return true;
		}

		return $this->sourceChangedError();
	}

	/**
	 * Check whether a freshly loaded source is the same Google Doc.
	 *
	 * @param array<string,mixed>|null $latest_source Fresh source state.
	 * @param array<string,mixed>      $source        Source captured by this sync.
	 */
	private function isSameSource( ?array $latest_source, array $source ): bool {
		return is_array( $latest_source )
			&& isset( $latest_source['google_file_id'], $source['google_file_id'] )
			&& (string) $latest_source['google_file_id'] === (string) $source['google_file_id'];
	}

	/**
	 * Build the source-changed error used to stop stale background work.
	 */
	private function sourceChangedError(): WP_Error {
		return new WP_Error(
			'docsync_wp_source_changed',
			__( 'The linked Google Doc changed while this sync was running. Start a new sync if needed.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Persist a sync error and return it.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $source  Current source.
	 * @param WP_Error            $error   Error to store.
	 */
	private function markError( int $post_id, array $source, WP_Error $error ): WP_Error {
		$latest   = $this->source_repository->getSource( $post_id );
		$progress = isset( $source['sync_progress'] ) ? (int) $source['sync_progress'] : 0;

		if ( is_array( $latest ) && isset( $latest['sync_progress'] ) ) {
			$progress = max( $progress, (int) $latest['sync_progress'] );
		}

		$saved = $this->saveProgressState(
			$post_id,
			$source,
			self::STATUS_ERROR,
			$progress,
			'error',
			$error->get_error_message(),
			array(
				'last_synced_at'  => current_time( 'mysql', true ),
				'sync_error'      => $error->get_error_message(),
				'sync_error_code' => $error->get_error_code(),
			)
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $error;
	}

	/**
	 * Import source HTML with Docs API fallback for oversized Drive exports.
	 *
	 * @param int                 $user_id        Sync owner user ID.
	 * @param string              $google_file_id Google Drive file ID.
	 * @param int                 $post_id        Target post ID.
	 * @param array<string,mixed> $source         Current source.
	 * @return array{html:string,method:string}|WP_Error
	 */
	private function importSourceHtml( int $user_id, string $google_file_id, int $post_id, array &$source ): array|WP_Error {
		$zip_bytes = $this->drive_client->exportHtmlZip( $user_id, $google_file_id );

		if ( ! is_wp_error( $zip_bytes ) ) {
			$progress_source = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCING,
				55,
				'importing',
				__( 'Importing Google Doc HTML and assets.', 'brasth-document-sync-for-google-docs' ),
				array( 'sync_error' => '' )
			);

			if ( is_wp_error( $progress_source ) ) {
				return $progress_source;
			}

			$source = $progress_source;
			$html   = $this->html_zip_importer->import( $zip_bytes, $google_file_id, $post_id, $user_id );

			if ( is_wp_error( $html ) ) {
				return $html;
			}

			return array(
				'html'   => $html,
				'method' => self::SYNC_METHOD_HTML_ZIP,
			);
		}

		if ( 'docsync_wp_export_too_large' !== $zip_bytes->get_error_code() ) {
			return $zip_bytes;
		}

		$progress_source = $this->saveProgressState(
			$post_id,
			$source,
			self::STATUS_SYNCING,
			35,
			'large_doc_fallback',
			__( 'Drive export was too large. Switching to the large-doc fallback.', 'brasth-document-sync-for-google-docs' ),
			array( 'sync_error' => '' ),
			array(),
			$zip_bytes->get_error_code()
		);

		if ( is_wp_error( $progress_source ) ) {
			return $progress_source;
		}

		$source          = $progress_source;
		$progress_source = $this->saveProgressState(
			$post_id,
			$source,
			self::STATUS_SYNCING,
			55,
			'importing',
			__( 'Importing through the large-doc fallback.', 'brasth-document-sync-for-google-docs' ),
			array( 'sync_error' => '' )
		);

		if ( is_wp_error( $progress_source ) ) {
			return $progress_source;
		}

		$source  = $progress_source;
		$options = $this->canProgressivelyUpdateDraft( $post_id )
			? array( 'flush_callback' => $this->progressiveFallbackFlushCallback( $post_id, $source ) )
			: array();
		$html    = $this->docs_api_importer->import( $user_id, $google_file_id, $post_id, $options );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		return array(
			'html'   => $html,
			'method' => self::SYNC_METHOD_DOCS_API,
		);
	}

	/**
	 * Whether fallback import can safely write partial content.
	 *
	 * @param int $post_id Target post ID.
	 */
	private function canProgressivelyUpdateDraft( int $post_id ): bool {
		$post = get_post( $post_id );

		return null !== $post
			&& in_array( $post->post_status, array( 'draft', 'auto-draft' ), true )
			&& '' === trim( (string) $post->post_content );
	}

	/**
	 * Build the Docs API fallback partial content writer.
	 *
	 * @param int                 $post_id Target post ID.
	 * @param array<string,mixed> $source  Current source.
	 */
	private function progressiveFallbackFlushCallback( int $post_id, array &$source ): callable {
		$last_hash        = '';
		$use_elementor    = null !== $this->elementor_decider && $this->elementor_decider->shouldUseElementor( $post_id );
		$elementor_preset = $use_elementor ? $this->elementor_preset_converter->resolvePresetForSource( $source ) : '';

		return function ( string $html, int $rendered, int $total ) use ( $post_id, &$source, &$last_hash, $use_elementor, $elementor_preset ): bool|WP_Error {
			$sanitized_html = wp_kses_post( $html );
			$partial_hash   = hash( 'sha256', $sanitized_html );

			if ( '' === trim( $sanitized_html ) || hash_equals( $last_hash, $partial_hash ) ) {
				return true;
			}

			if ( $use_elementor ) {
				$elementor_json = '' !== $elementor_preset
					? $this->elementor_preset_converter->convert( $sanitized_html, $post_id, $elementor_preset )
					: $this->elementor_converter->convert( $sanitized_html, $post_id );

				if ( is_wp_error( $elementor_json ) ) {
					return $elementor_json;
				}

				if ( '' === trim( $elementor_json ) ) {
					return true;
				}
			} else {
				$block_content = $this->layout_converter->convertForSource( $sanitized_html, $source );

				if ( is_wp_error( $block_content ) ) {
					return $block_content;
				}

				if ( '' === trim( $block_content ) ) {
					return true;
				}
			}

			$current_source = $this->assertCurrentSource( $post_id, $source );

			if ( is_wp_error( $current_source ) ) {
				return $current_source;
			}

			if ( $use_elementor ) {
				$updated = $this->elementor_updater->update( $post_id, (string) $elementor_json );

				if ( is_wp_error( $updated ) ) {
					return $this->markError( $post_id, $source, $updated );
				}

				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => '',
						)
					),
					true
				);
			} else {
				$updated = wp_update_post(
					wp_slash(
						array(
							'ID'           => $post_id,
							'post_content' => $block_content,
						)
					),
					true
				);
			}

			if ( is_wp_error( $updated ) ) {
				return new WP_Error(
					'docsync_wp_partial_update_post_failed',
					__( 'Brasth Document Sync could not save imported large-doc content yet.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 500 )
				);
			}

			$progress = max(
				(int) ( $source['sync_progress'] ?? 0 ),
				min( 69, 55 + (int) floor( 14 * min( 1, $rendered / max( 1, $total ) ) ) )
			);
			$saved    = $this->saveProgressState(
				$post_id,
				$source,
				self::STATUS_SYNCING,
				$progress,
				'large_doc_partial_import',
				__( 'Imported part of the large Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'sync_error' => '' )
			);

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}

			$source    = $saved;
			$last_hash = $partial_hash;

			return true;
		};
	}

	/**
	 * Ensure Drive metadata allows download/export.
	 *
	 * @param array<string,mixed> $metadata Drive metadata.
	 * @return bool|WP_Error
	 */
	private function assertMetadataCanDownload( array $metadata ): bool|WP_Error {
		$compatibility = isset( $metadata['syncCompatibility'] ) && is_array( $metadata['syncCompatibility'] )
			? $metadata['syncCompatibility']
			: array();

		if ( isset( $compatibility['canDownload'] ) && false === $compatibility['canDownload'] ) {
			return new WP_Error(
				'docsync_wp_drive_download_blocked',
				__( 'Google says this Doc cannot be downloaded by the connected account. Adjust sharing or choose another Doc before syncing.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Get the user whose Google token should run the sync.
	 *
	 * @param array<string,mixed> $source          Source.
	 * @param int                 $fallback_user_id Fallback user ID.
	 */
	private function getSyncUserId( array $source, int $fallback_user_id ): int {
		$owner_user_id = isset( $source['sync_owner_user_id'] ) ? absint( $source['sync_owner_user_id'] ) : 0;

		return $owner_user_id > 0 ? $owner_user_id : $fallback_user_id;
	}

	/**
	 * Convert Drive metadata to source metadata.
	 *
	 * @param array<string,mixed> $metadata Drive metadata.
	 * @return array<string,string>
	 */
	private function sourceFromMetadata( array $metadata ): array {
		return array(
			'google_file_id'       => (string) $metadata['fileId'],
			'google_doc_url'       => (string) $metadata['webViewLink'],
			'google_title'         => (string) $metadata['name'],
			'google_modified_time' => (string) $metadata['modifiedTime'],
			'google_version'       => (string) $metadata['version'],
		);
	}

	/**
	 * Format a sync response.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  Sync status.
	 * @param bool   $changed Whether post content changed.
	 * @param bool   $queued  Whether sync has been queued.
	 * @return array<string,mixed>
	 */
	private function formatResult( int $post_id, string $status, bool $changed, bool $queued = false ): array {
		$result = array(
			'postId'  => $post_id,
			'status'  => $status,
			'changed' => $changed,
			'source'  => $this->source_repository->formatSource( $post_id ),
		);

		if ( is_array( $result['source'] ) ) {
			$result['lastSyncMethod'] = $result['source']['lastSyncMethod'] ?? null;
		} else {
			$result['lastSyncMethod'] = null;
		}

		if ( $queued ) {
			$result['queued'] = true;
		}

		return $result;
	}

	/**
	 * Validate export format.
	 *
	 * @param string $export_format Export format.
	 * @return string|WP_Error
	 */
	private function sanitizeExportFormat( string $export_format ): string|WP_Error {
		$export_format = sanitize_key( $export_format );

		if ( self::EXPORT_FORMAT_HTML_ZIP === $export_format || 'markdown' === $export_format ) {
			return self::EXPORT_FORMAT_HTML_ZIP;
		}

		return new WP_Error(
			'docsync_wp_invalid_export_format',
			__( 'Brasth Document Sync only supports HTML ZIP exports.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
