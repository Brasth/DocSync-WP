<?php
/**
 * Asynchronous, idempotent import commits.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;
use Throwable;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Turns accepted previews into WordPress drafts.
 *
 * The request validates synchronously (idempotency key, fresh fingerprints,
 * permissions, presets) and moves the session to `committing`; the work runs
 * on `docsync_wp_import_commit`. For each file the worker inserts exactly one
 * draft, sideloads the referenced private assets, saves the same
 * CanonicalRenderer output the preview showed, links Keep-synced DOCX with
 * SyncService::attachSource (metadata only, no queued sync), and saves
 * provenance. `commitState` is written before and after every step, and the
 * draft carries a temporary slug marker, so a retried worker either finishes
 * a fully saved file or rolls the attempt back; it never creates a second
 * post. Failures delete that attempt's post and attachments and trash the
 * file's remaining Google temporaries; other files continue.
 */
final class ImportCommitter {
	public const COMMIT_HOOK = 'docsync_wp_import_commit';

	private const EXPORT_FORMAT_HTML_ZIP = 'html_zip';
	private const CAS_ATTEMPTS           = 8;
	private const KEY_PATTERN            = '/^[A-Za-z0-9-]{16,64}$/';
	private const FILE_ID_PATTERN        = '/^f_[a-f0-9]{16}$/';
	private const FINGERPRINT_PATTERN    = '/^[a-f0-9]{64}$/';
	private const MARKER_PREFIX          = 'docsync-import-';
	private const ONE_TIME_CONVERTERS    = array(
		'docx' => 'googleDocsOneTime',
		'pptx' => 'googleSlidesOneTime',
		'pdf'  => 'localPdf',
	);
	private const MIME_EXTENSIONS        = array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Session repository.
	 *
	 * @var ImportSessionRepository
	 */
	private ImportSessionRepository $sessions;

	/**
	 * Private asset store.
	 *
	 * @var PrivateAssetStore
	 */
	private PrivateAssetStore $assets;

	/**
	 * Canonical renderer.
	 *
	 * @var CanonicalRenderer
	 */
	private CanonicalRenderer $renderer;

	/**
	 * Provenance repository.
	 *
	 * @var ImportProvenanceRepository
	 */
	private ImportProvenanceRepository $provenance;

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
	 * Import service (formatting and Google temporary trashing).
	 *
	 * @var ImportService
	 */
	private ImportService $imports;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionRepository    $sessions          Session repository.
	 * @param PrivateAssetStore          $assets            Private asset store.
	 * @param CanonicalRenderer          $renderer          Canonical renderer.
	 * @param ImportProvenanceRepository $provenance        Provenance repository.
	 * @param SourceRepository           $source_repository Source repository.
	 * @param SyncService                $sync_service      Sync service.
	 * @param ImportService              $imports           Import service.
	 */
	public function __construct(
		ImportSessionRepository $sessions,
		PrivateAssetStore $assets,
		CanonicalRenderer $renderer,
		ImportProvenanceRepository $provenance,
		SourceRepository $source_repository,
		SyncService $sync_service,
		ImportService $imports
	) {
		$this->sessions          = $sessions;
		$this->assets            = $assets;
		$this->renderer          = $renderer;
		$this->provenance        = $provenance;
		$this->source_repository = $source_repository;
		$this->sync_service      = $sync_service;
		$this->imports           = $imports;
	}

	/**
	 * Register the commit worker hook.
	 */
	public function register(): void {
		add_action( self::COMMIT_HOOK, array( $this, 'runCommit' ), 10, 1 );
	}

	/**
	 * Validate and queue a commit.
	 *
	 * @param string                         $session_id      Session ID.
	 * @param int                            $user_id         Caller.
	 * @param array<int,array<string,mixed>> $files           Listed files: fileId, previewFingerprint.
	 * @param string                         $idempotency_key Idempotency key.
	 * @return array<string,mixed>|WP_Error Formatted session.
	 */
	public function commit( string $session_id, int $user_id, array $files, string $idempotency_key ): array|WP_Error {
		$listed = $this->normalizeFiles( $files );

		if ( 1 !== preg_match( self::KEY_PATTERN, $idempotency_key ) || null === $listed ) {
			return new WP_Error(
				'docsync_wp_import_invalid_commit',
				__( 'Brasth Document Sync received an invalid commit request.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		$request_hash = hash( 'sha256', (string) wp_json_encode( $listed ) );
		$session      = $this->sessions->get( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$replay = $this->replay( $session, $idempotency_key, $request_hash );

		if ( null !== $replay ) {
			return $replay;
		}

		if ( ImportSessionRepository::STATUS_OPEN !== $session['status'] ) {
			return $this->notOpenError();
		}

		$checked = $this->validateListed( $session, $listed, $user_id );

		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		$now      = time();
		$not_open = $this->notOpenError();
		$stale    = $this->staleError( array() );
		$saved    = $this->mutate(
			$session_id,
			function ( array $current ) use ( $listed, $checked, $idempotency_key, $request_hash, $now, $not_open, $stale ): array|WP_Error {
				if ( ImportSessionRepository::STATUS_OPEN !== $current['status'] ) {
					return $not_open;
				}

				$wanted = array_column( $listed, 'previewFingerprint', 'fileId' );

				foreach ( $current['files'] as $index => $file ) {
					if ( isset( $wanted[ $file['fileId'] ] ) ) {
						if ( ImportService::STATUS_READY !== $file['status'] || $file['options'] !== $checked[ $file['fileId'] ] ) {
							return $stale;
						}

						$current['files'][ $index ]['status']      = ImportService::STATUS_COMMITTING;
						$current['files'][ $index ]['commitState'] = null;
					} else {
						$current['files'][ $index ]['status'] = ImportService::STATUS_SKIPPED;
					}
				}

				$current['status'] = ImportSessionRepository::STATUS_COMMITTING;
				$current['commit'] = array(
					'idempotencyKey' => $idempotency_key,
					'requestHash'    => $request_hash,
					'files'          => $listed,
					'startedAt'      => $now,
				);
				$current['result'] = array(
					'idempotencyKey' => $idempotency_key,
					'startedAt'      => gmdate( 'Y-m-d\TH:i:s\Z', $now ),
					'finishedAt'     => null,
					'files'          => array(),
				);

				return $current;
			}
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$this->schedule( $session_id, time() );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $this->imports->formatSession( $saved );
	}

	/**
	 * Commit worker (cron): process every listed file, then finish the session.
	 *
	 * @param string $session_id Session ID.
	 */
	public function runCommit( string $session_id ): void {
		$session = $this->sessions->getForWorker( $session_id );

		if ( null === $session || ImportSessionRepository::STATUS_COMMITTING !== $session['status'] ) {
			return;
		}

		if ( ! $this->sessions->lock( $session_id ) ) {
			$this->schedule( $session_id, time() + 30 );

			return;
		}

		$owner         = absint( $session['ownerUserId'] );
		$previous_user = get_current_user_id();
		$switched      = function_exists( 'switch_to_user_locale' ) && switch_to_user_locale( $owner );

		wp_set_current_user( $owner );

		try {
			$this->loadMediaApi();

			foreach ( (array) ( $session['commit']['files'] ?? array() ) as $entry ) {
				$this->sessions->lock( $session_id );

				$fresh = $this->sessions->getForWorker( $session_id );
				$file  = null !== $fresh ? $this->findFile( $fresh, (string) $entry['fileId'] ) : null;

				if ( null === $file || ImportService::STATUS_COMMITTING !== $file['status'] ) {
					continue;
				}

				try {
					$this->commitFile( $session_id, $owner, $entry, $file );
				} catch ( Throwable $exception ) {
					$this->failFile(
						$session_id,
						$owner,
						(string) $entry['fileId'],
						new WP_Error( 'docsync_wp_import_commit_failed', __( 'This file could not be added to WordPress.', 'brasth-document-sync-for-google-docs' ), array( 'status' => 500 ) )
					);
				}
			}

			$this->finishCommit( $session_id, $owner );
		} finally {
			wp_set_current_user( $previous_user );

			if ( $switched ) {
				restore_previous_locale();
			}

			$this->sessions->unlock( $session_id );
		}
	}

	/**
	 * Commit one file, resuming or rolling back a previous attempt.
	 *
	 * The draft keeps its marker slug until provenance is durably recorded.
	 * Every step persists `commitState` before the next irreversible step;
	 * when a state save fails, the attempt is rolled back at once and the file
	 * stays `committing`, so the rescheduled worker retries from a clean slate.
	 *
	 * @param string              $session_id Session ID.
	 * @param int                 $owner      Owner user ID.
	 * @param array<string,mixed> $entry      Listed entry: fileId, previewFingerprint.
	 * @param array<string,mixed> $file       File record.
	 */
	private function commitFile( string $session_id, int $owner, array $entry, array $file ): void {
		$file_id   = (string) $file['fileId'];
		$state     = is_array( $file['commitState'] ?? null ) ? $file['commitState'] : null;
		$marker    = $this->marker( $session_id, $file_id );
		$post_type = (string) $file['options']['target']['postType'];

		if ( null !== $state && in_array( $state['step'] ?? '', array( 'provenance', 'finalize' ), true ) ) {
			$this->finalizeFile( $session_id, $owner, $file_id );

			return;
		}

		if ( ! $this->rollbackAttempt( $owner, $state, $marker, $post_type ) ) {
			$this->failFile( $session_id, $owner, $file_id, $this->commitFailedError() );

			return;
		}

		$kind  = UploadValidator::FORMAT_DOCX === $file['format'] && true === ( $file['options']['docx']['keepSynced'] ?? false ) ? ImportProvenanceRepository::KIND_SYNCED_WORD : ImportProvenanceRepository::KIND_ONE_TIME;
		$state = array(
			'postId'        => null,
			'attachmentIds' => array(),
			'step'          => 'start',
			'kind'          => $kind,
		);

		if ( ! $this->saveState( $session_id, $file_id, $state ) ) {
			return;
		}

		if ( ! user_can( $owner, 'upload_files' ) || ! $this->source_repository->userCanCreateSyncedPost( $post_type, $owner ) ) {
			$this->failFile( $session_id, $owner, $file_id, $this->forbiddenError() );

			return;
		}

		$effective = $this->effectiveDocument( $session_id, $file );

		if ( is_wp_error( $effective ) ) {
			$this->failFile( $session_id, $owner, $file_id, $effective );

			return;
		}

		$preset = $this->renderer->resolvePreset( (string) $file['options']['layoutPreset'] );

		if ( $this->renderer->previewFingerprint( $effective, $file['options'], $preset ) !== (string) $entry['previewFingerprint'] ) {
			$this->failFile( $session_id, $owner, $file_id, $this->staleError( array() ) );

			return;
		}

		$post_id = $this->createMarkedDraft( $owner, $post_type, (string) $file['options']['title'], $marker );

		if ( is_wp_error( $post_id ) ) {
			$this->failFile( $session_id, $owner, $file_id, $post_id );

			return;
		}

		$state['postId'] = $post_id;
		$state['step']   = 'post';

		if ( ! $this->saveState( $session_id, $file_id, $state ) ) {
			$this->rollbackAttempt( $owner, $state, $marker, $post_type );

			return;
		}

		$urls = array();

		foreach ( $this->referencedAssets( $effective ) as $asset_id => $asset ) {
			$attachment = $this->sideload( $session_id, $asset, $post_id, $owner, (string) $file['options']['title'] );

			if ( is_wp_error( $attachment ) ) {
				$this->failFile( $session_id, $owner, $file_id, $attachment );

				return;
			}

			$state['attachmentIds'][] = $attachment;

			if ( ! $this->saveState( $session_id, $file_id, $state ) ) {
				$this->rollbackAttempt( $owner, $state, $marker, $post_type );

				return;
			}

			$url = wp_get_attachment_url( $attachment );

			if ( ! is_string( $url ) || '' === $url ) {
				$this->failFile( $session_id, $owner, $file_id, $this->commitFailedError() );

				return;
			}

			$urls[ $asset_id ] = $url;
		}

		$markup = $this->renderer->renderBlocks( $effective, $urls, $preset );

		if ( is_wp_error( $markup ) ) {
			$this->failFile( $session_id, $owner, $file_id, $markup );

			return;
		}

		// The marker slug stays in place: updating content leaves post_name untouched.
		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_content' => $markup,
				)
			),
			true
		);

		if ( is_wp_error( $updated ) || 0 === $updated ) {
			$this->failFile( $session_id, $owner, $file_id, is_wp_error( $updated ) ? $updated : $this->commitFailedError() );

			return;
		}

		$state['step'] = 'content';

		if ( ! $this->saveState( $session_id, $file_id, $state ) ) {
			$this->rollbackAttempt( $owner, $state, $marker, $post_type );

			return;
		}

		$google_id = (string) $file['googleFileId'];

		if ( ImportProvenanceRepository::KIND_SYNCED_WORD === $kind ) {
			$linked = '' !== $google_id ? $this->source_repository->findPostIdByGoogleFileId( $google_id ) : null;

			if ( '' === $google_id || ( null !== $linked && $post_id !== $linked ) ) {
				$this->failFile(
					$session_id,
					$owner,
					$file_id,
					new WP_Error( 'docsync_wp_source_already_linked', __( 'The converted Google Doc is already linked to another post.', 'brasth-document-sync-for-google-docs' ), array( 'status' => 409 ) )
				);

				return;
			}

			$attached = $this->sync_service->attachSource( $post_id, $owner, $google_id, self::EXPORT_FORMAT_HTML_ZIP, false, $preset, '' );

			if ( is_wp_error( $attached ) ) {
				$this->failFile( $session_id, $owner, $file_id, $attached );

				return;
			}

			$state['step'] = 'source';

			if ( ! $this->saveState( $session_id, $file_id, $state ) ) {
				$this->rollbackAttempt( $owner, $state, $marker, $post_type );

				return;
			}
		}

		$saved = $this->provenance->save(
			$post_id,
			array(
				'kind'             => $kind,
				'format'           => (string) $file['format'],
				'originalName'     => (string) $file['originalName'],
				'sha256'           => (string) $file['sha256'],
				'converter'        => ImportProvenanceRepository::KIND_SYNCED_WORD === $kind ? 'googleDocsSynced' : self::ONE_TIME_CONVERTERS[ $file['format'] ],
				'googleFileId'     => ImportProvenanceRepository::KIND_SYNCED_WORD === $kind ? $google_id : '',
				'importedAt'       => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'importedByUserId' => $owner,
				'sessionId'        => $session_id,
			)
		);

		if ( is_wp_error( $saved ) ) {
			$this->failFile( $session_id, $owner, $file_id, $saved );

			return;
		}

		$state['step'] = 'provenance';

		if ( ! $this->saveState( $session_id, $file_id, $state ) ) {
			$this->rollbackAttempt( $owner, $state, $marker, $post_type );

			return;
		}

		$this->finalizeFile( $session_id, $owner, $file_id );
	}

	/**
	 * Insert the empty draft with the file's marker as its first slug.
	 *
	 * The marker is applied in `wp_insert_post_data` at the actual first write,
	 * so slug sanitization or generation can never change it. The filter only
	 * touches the single new row inserted here (no ID, the owner, the target
	 * type, empty content) and is removed before returning.
	 *
	 * @param int    $owner     Owner user ID.
	 * @param string $post_type Target post type.
	 * @param string $title     Draft title.
	 * @param string $marker    Marker slug.
	 * @return int|WP_Error Post ID.
	 */
	private function createMarkedDraft( int $owner, string $post_type, string $title, string $marker ): int|WP_Error {
		$applied   = false;
		$mark_slug = static function ( array $data, array $postarr ) use ( &$applied, $marker, $owner, $post_type ): array {
			if (
				$applied
				|| ! empty( $postarr['ID'] )
				|| absint( $data['post_author'] ?? 0 ) !== $owner
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
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $post_type,
						'post_status'  => 'draft',
						'post_title'   => $title,
						'post_content' => '',
						'post_author'  => $owner,
					)
				),
				true
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $mark_slug, PHP_INT_MAX );
		}

		if ( is_wp_error( $post_id ) ) {
			$this->rollbackAttempt( $owner, null, $marker, $post_type );

			return $post_id;
		}

		$post = $this->findMarkedPost( $marker, $owner, $post_type );

		if ( ! $applied || $post_id <= 0 || null === $post || $post->ID !== (int) $post_id ) {
			$this->rollbackAttempt(
				$owner,
				array(
					'postId'        => (int) $post_id,
					'attachmentIds' => array(),
				),
				$marker,
				$post_type
			);

			return $this->commitFailedError();
		}

		return (int) $post_id;
	}

	/**
	 * Finish a fully saved file: release or trash its Google temporaries and record the result.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $owner      Owner user ID.
	 * @param string $file_id    File ID.
	 */
	private function finalizeFile( string $session_id, int $owner, string $file_id ): void {
		$session = $this->sessions->getForWorker( $session_id );
		$file    = null !== $session ? $this->findFile( $session, $file_id ) : null;

		if ( null === $file || ! is_array( $file['commitState'] ?? null ) ) {
			return;
		}

		$state     = $file['commitState'];
		$post_id   = absint( $state['postId'] ?? 0 );
		$synced    = ImportProvenanceRepository::KIND_SYNCED_WORD === ( $state['kind'] ?? '' );
		$google_id = (string) $file['googleFileId'];
		$resolved  = array();
		$post      = get_post( $post_id );

		// Provenance is durable now, so the marker slug can go; a failure retries on the next run.
		if ( ! $post instanceof WP_Post || ! $this->restoreSlug( $post, $this->marker( $session_id, $file_id ) ) ) {
			$this->schedule( $session_id, time() + 30 );

			return;
		}

		$temporaries = array_values( array_map( 'strval', (array) $file['googleTemporaries'] ) );

		if ( $synced ) {
			// The retained Keep-synced Doc leaves the cleanup set only now that the draft, link, and provenance
			// exist; leftovers from earlier conversion attempts are still trashed.
			$others   = array_values( array_diff( $temporaries, array( $google_id ) ) );
			$resolved = array_merge( array( $google_id ), array_values( array_diff( $others, $this->imports->trashTemporaries( $owner, $others ) ) ) );
		} else {
			$resolved = array_values( array_diff( $temporaries, $this->imports->trashTemporaries( $owner, $temporaries ) ) );
		}

		$source  = $synced ? $this->source_repository->formatSource( $post_id ) : null;
		$doc_url = is_array( $source ) && '' !== (string) ( $source['googleDocUrl'] ?? '' ) ? (string) $source['googleDocUrl'] : 'https://docs.google.com/document/d/' . rawurlencode( $google_id ) . '/edit';
		$entry   = array(
			'fileId'       => $file_id,
			'status'       => 'created',
			'postId'       => $post_id,
			'provenance'   => $synced ? ImportProvenanceRepository::KIND_SYNCED_WORD : ImportProvenanceRepository::KIND_ONE_TIME,
			'googleFileId' => $synced ? $google_id : null,
			'googleDocUrl' => $synced ? esc_url_raw( $doc_url ) : null,
			'error'        => null,
		);

		$this->mutate(
			$session_id,
			function ( array $current ) use ( $file_id, $resolved, $entry ): array {
				$index = $this->fileIndex( $current, $file_id );

				if ( null === $index ) {
					return $current;
				}

				$current['files'][ $index ]['googleTemporaries']   = array_values( array_diff( (array) $current['files'][ $index ]['googleTemporaries'], $resolved ) );
				$current['files'][ $index ]['status']              = ImportService::STATUS_COMMITTED;
				$current['files'][ $index ]['commitState']['step'] = 'done';
				$current['result']['files']                        = $this->withResultEntry( (array) ( $current['result']['files'] ?? array() ), $entry );

				return $current;
			}
		);
	}

	/**
	 * Fail one file: roll back this attempt and trash its Google temporaries.
	 *
	 * @param string   $session_id Session ID.
	 * @param int      $owner      Owner user ID.
	 * @param string   $file_id    File ID.
	 * @param WP_Error $error      Failure.
	 */
	private function failFile( string $session_id, int $owner, string $file_id, WP_Error $error ): void {
		$session = $this->sessions->getForWorker( $session_id );
		$file    = null !== $session ? $this->findFile( $session, $file_id ) : null;

		if ( null === $file ) {
			return;
		}

		$state = is_array( $file['commitState'] ?? null ) ? $file['commitState'] : null;

		$this->rollbackAttempt( $owner, $state, $this->marker( $session_id, $file_id ), (string) $file['options']['target']['postType'] );

		$temporaries = array_values( array_map( 'strval', (array) $file['googleTemporaries'] ) );
		$resolved    = array_values( array_diff( $temporaries, $this->imports->trashTemporaries( $owner, $temporaries ) ) );
		$entry       = array(
			'fileId'       => $file_id,
			'status'       => 'failed',
			'postId'       => null,
			'provenance'   => null,
			'googleFileId' => null,
			'googleDocUrl' => null,
			'error'        => array(
				'code'    => (string) $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
		);

		$this->mutate(
			$session_id,
			function ( array $current ) use ( $file_id, $resolved, $entry ): array {
				$index = $this->fileIndex( $current, $file_id );

				if ( null === $index ) {
					return $current;
				}

				$current['files'][ $index ]['googleTemporaries'] = array_values( array_diff( (array) $current['files'][ $index ]['googleTemporaries'], $resolved ) );
				$current['files'][ $index ]['status']            = ImportService::STATUS_FAILED;
				$current['files'][ $index ]['error']             = $entry['error'];
				$current['files'][ $index ]['commitState']       = array(
					'postId'        => null,
					'attachmentIds' => array(),
					'step'          => 'failed',
				);
				$current['result']['files']                      = $this->withResultEntry( (array) ( $current['result']['files'] ?? array() ), $entry );

				return $current;
			}
		);
	}

	/**
	 * Mark the session committed, trash skipped files' temporaries, and purge private bytes.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $owner      Owner user ID.
	 */
	private function finishCommit( string $session_id, int $owner ): void {
		$session = $this->sessions->getForWorker( $session_id );

		if ( null === $session ) {
			return;
		}

		$resolved = array();

		foreach ( $session['files'] as $file ) {
			if ( ImportService::STATUS_SKIPPED !== $file['status'] ) {
				continue;
			}

			$temporaries                 = array_values( array_map( 'strval', (array) $file['googleTemporaries'] ) );
			$resolved[ $file['fileId'] ] = array_values( array_diff( $temporaries, $this->imports->trashTemporaries( $owner, $temporaries ) ) );
		}

		$now   = time();
		$saved = $this->mutate(
			$session_id,
			function ( array $current ) use ( $resolved, $now ): array {
				if ( ImportSessionRepository::STATUS_COMMITTING !== $current['status'] ) {
					return $current;
				}

				$entries = array();
				$stored  = array_column( (array) ( $current['result']['files'] ?? array() ), null, 'fileId' );

				foreach ( $current['files'] as $index => $file ) {
					if ( isset( $resolved[ $file['fileId'] ] ) ) {
						$current['files'][ $index ]['googleTemporaries'] = array_values( array_diff( (array) $file['googleTemporaries'], $resolved[ $file['fileId'] ] ) );
					}

					if ( ImportService::STATUS_COMMITTING === $file['status'] ) {
						continue;
					}

					$entries[] = $stored[ $file['fileId'] ] ?? array(
						'fileId'       => $file['fileId'],
						'status'       => 'skipped',
						'postId'       => null,
						'provenance'   => null,
						'googleFileId' => null,
						'googleDocUrl' => null,
						'error'        => null,
					);
				}

				foreach ( $current['files'] as $file ) {
					if ( ImportService::STATUS_COMMITTING === $file['status'] ) {
						return $current;
					}
				}

				$current['status']               = ImportSessionRepository::STATUS_COMMITTED;
				$current['result']['files']      = $entries;
				$current['result']['finishedAt'] = gmdate( 'Y-m-d\TH:i:s\Z', $now );

				return $current;
			}
		);

		if ( ! is_wp_error( $saved ) && ImportSessionRepository::STATUS_COMMITTED === $saved['status'] ) {
			$this->assets->deleteSession( $session_id );

			return;
		}

		$this->schedule( $session_id, time() + 30 );
	}

	/**
	 * Delete the post and attachments of an unfinished attempt.
	 *
	 * Only the exact marked draft is touched: the session owner's draft of the
	 * target type whose slug is still this file's marker, found through
	 * commitState or the marker itself when the worker died before saving the
	 * ID. Its attachments (recorded or parented to it) are deleted first.
	 *
	 * @param int                      $owner     Owner user ID.
	 * @param array<string,mixed>|null $state     Commit state.
	 * @param string                   $marker    Slug marker.
	 * @param string                   $post_type Target post type.
	 * @return bool False when a marked draft could not be removed.
	 */
	private function rollbackAttempt( int $owner, ?array $state, string $marker, string $post_type ): bool {
		$candidates = array();
		$marked     = $this->findMarkedPost( $marker, $owner, $post_type );

		if ( null !== $marked ) {
			$candidates[ $marked->ID ] = $marked;
		}

		$saved_id = absint( $state['postId'] ?? 0 );

		if ( $saved_id > 0 && ! isset( $candidates[ $saved_id ] ) ) {
			clean_post_cache( $saved_id );
			$saved = get_post( $saved_id );

			if ( $saved instanceof WP_Post && $saved->post_name === $marker && absint( $saved->post_author ) === $owner && $saved->post_type === $post_type ) {
				$candidates[ $saved->ID ] = $saved;
			}
		}

		foreach ( (array) ( $state['attachmentIds'] ?? array() ) as $attachment_id ) {
			$attachment = get_post( absint( $attachment_id ) );

			if ( $attachment instanceof WP_Post && 'attachment' === $attachment->post_type && absint( $attachment->post_author ) === $owner && isset( $candidates[ absint( $attachment->post_parent ) ] ) ) {
				wp_delete_attachment( $attachment->ID, true );
			}
		}

		$clean = true;

		foreach ( $candidates as $post ) {
			if ( 'draft' !== $post->post_status ) {
				$clean = false;
				continue;
			}

			foreach ( get_children(
				array(
					'post_parent' => $post->ID,
					'post_type'   => 'attachment',
					'fields'      => 'ids',
				)
			) as $child_id ) {
				wp_delete_attachment( absint( $child_id ), true );
			}

			wp_delete_post( $post->ID, true );
			clean_post_cache( $post->ID );

			$clean = $clean && null === get_post( $post->ID );
		}

		return $clean;
	}

	/**
	 * Find the draft an attempt inserted for this file.
	 *
	 * @param string $marker    Marker slug.
	 * @param int    $owner     Owner user ID.
	 * @param string $post_type Target post type.
	 */
	private function findMarkedPost( string $marker, int $owner, string $post_type ): ?WP_Post {
		$posts = get_posts(
			array(
				'name'                   => $marker,
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'author'                 => $owner,
				'posts_per_page'         => 1,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'suppress_filters'       => false,
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
	 * @param string  $marker Marker slug.
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
	 * Sideload one private asset into the Media Library, attached to the draft.
	 *
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $asset      Asset with alt text.
	 * @param int                 $post_id    Draft ID.
	 * @param int                 $owner      Owner user ID.
	 * @param string              $title      Draft title (file name seed).
	 * @return int|WP_Error Attachment ID.
	 */
	private function sideload( string $session_id, array $asset, int $post_id, int $owner, string $title ): int|WP_Error {
		$bytes = $this->assets->read( $session_id, (string) $asset['assetId'] );

		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}

		$extension = self::MIME_EXTENSIONS[ $asset['mimeType'] ] ?? 'png';
		$slug      = sanitize_title( $title );
		$name      = sanitize_file_name( ( '' !== $slug ? substr( $slug, 0, 60 ) : 'import' ) . '-' . substr( (string) $asset['assetId'], 2, 8 ) . '.' . $extension );
		$temp      = wp_tempnam( $name );

		if ( ! is_string( $temp ) || '' === $temp ) {
			return $this->commitFailedError();
		}

		$filesystem = $this->filesystem();

		if ( ! $filesystem->put_contents( $temp, $bytes, 0600 ) ) {
			$filesystem->delete( $temp );

			return $this->commitFailedError();
		}

		$attachment = media_handle_sideload(
			array(
				'name'     => $name,
				'type'     => (string) $asset['mimeType'],
				'tmp_name' => $temp,
				'error'    => 0,
				'size'     => strlen( $bytes ),
			),
			$post_id,
			null,
			array( 'post_author' => $owner )
		);

		if ( $filesystem->exists( $temp ) ) {
			$filesystem->delete( $temp );
		}

		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}

		$alt = sanitize_text_field( (string) ( $asset['alt'] ?? '' ) );

		if ( '' !== $alt ) {
			update_post_meta( (int) $attachment, '_wp_attachment_image_alt', wp_slash( $alt ) );
		}

		return (int) $attachment;
	}

	/**
	 * Ready assets referenced by image blocks, in document order, with the first alt text.
	 *
	 * @param CanonicalDocument $effective Effective document.
	 * @return array<string,array<string,mixed>>
	 */
	private function referencedAssets( CanonicalDocument $effective ): array {
		$assets = array();

		foreach ( $effective->getAssets() as $asset ) {
			$assets[ $asset['assetId'] ] = $asset;
		}

		$referenced = array();

		foreach ( $effective->getSections() as $section ) {
			foreach ( $section['blocks'] as $block ) {
				$asset_id = 'image' === $block['type'] ? (string) $block['assetId'] : '';

				if ( '' === $asset_id || isset( $referenced[ $asset_id ] ) || 'ready' !== ( $assets[ $asset_id ]['status'] ?? '' ) ) {
					continue;
				}

				$referenced[ $asset_id ] = array_merge( $assets[ $asset_id ], array( 'alt' => (string) $block['alt'] ) );
			}
		}

		return $referenced;
	}

	/**
	 * Effective document for a file, with render statuses from its asset index.
	 *
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $file       File record.
	 * @return CanonicalDocument|WP_Error
	 */
	private function effectiveDocument( string $session_id, array $file ): CanonicalDocument|WP_Error {
		$canonical = $this->assets->read( $session_id, (string) $file['canonicalKey'] );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$data     = json_decode( $canonical, true );
		$document = is_array( $data ) ? CanonicalDocument::fromArray( $data ) : $this->commitFailedError();

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		foreach ( $document->getAssets() as $asset ) {
			$entry = $file['assetIndex'][ $asset['assetId'] ] ?? null;

			if ( 'pdfPageRender' === $asset['kind'] && is_array( $entry ) && 'ready' === ( $entry['status'] ?? '' ) && 'ready' !== $asset['status'] ) {
				$document = $document->withAsset(
					array_merge(
						$asset,
						array(
							'status'   => 'ready',
							'width'    => isset( $entry['width'] ) ? absint( $entry['width'] ) : null,
							'height'   => isset( $entry['height'] ) ? absint( $entry['height'] ) : null,
							'byteSize' => isset( $entry['byteSize'] ) ? absint( $entry['byteSize'] ) : null,
							'sha256'   => is_string( $entry['sha256'] ?? null ) ? $entry['sha256'] : null,
						)
					)
				);
			}
		}

		return $document->applyOptions( $file['options'] );
	}

	/**
	 * Validate listed files synchronously; returns their options keyed by file ID.
	 *
	 * @param array<string,mixed>            $session Session.
	 * @param array<int,array<string,mixed>> $listed  Listed files.
	 * @param int                            $user_id Caller.
	 * @return array<string,array<string,mixed>>|WP_Error
	 */
	private function validateListed( array $session, array $listed, int $user_id ): array|WP_Error {
		$options   = array();
		$not_ready = array();
		$stale     = array();

		foreach ( $listed as $entry ) {
			$file = $this->findFile( $session, $entry['fileId'] );

			if ( null === $file ) {
				return new WP_Error(
					'docsync_wp_import_file_not_found',
					__( 'Brasth Document Sync could not find a listed file in this import.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 404 )
				);
			}

			if ( ImportService::STATUS_READY !== $file['status'] ) {
				$not_ready[] = $entry['fileId'];
				continue;
			}

			if ( ! $this->source_repository->userCanCreateSyncedPost( (string) $file['options']['target']['postType'], $user_id ) ) {
				return new WP_Error(
					'docsync_wp_import_post_type_forbidden',
					__( 'You cannot create drafts of this content type.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 403 )
				);
			}

			$effective = $this->effectiveDocument( (string) $session['sessionId'], $file );

			if ( is_wp_error( $effective ) ) {
				return $effective;
			}

			$requested = (string) $file['options']['layoutPreset'];
			$preset    = $this->renderer->resolvePreset( $requested );
			$current   = $this->renderer->previewFingerprint( $effective, $file['options'], $preset );

			if ( ( '' !== $requested && $preset !== $requested ) || $current !== $entry['previewFingerprint'] ) {
				$stale[] = array(
					'fileId'             => $entry['fileId'],
					'previewFingerprint' => $current,
				);
			}

			$options[ $entry['fileId'] ] = $file['options'];
		}

		if ( array() !== $not_ready ) {
			return new WP_Error(
				'docsync_wp_import_file_not_ready',
				__( 'Some files are not ready yet.', 'brasth-document-sync-for-google-docs' ),
				array(
					'status' => 409,
					'files'  => $not_ready,
				)
			);
		}

		if ( array() !== $stale ) {
			return $this->staleError( $stale );
		}

		return $options;
	}

	/**
	 * Replay result for a reused idempotency key, or null when the key is new.
	 *
	 * @param array<string,mixed> $session         Session.
	 * @param string              $idempotency_key Key.
	 * @param string              $request_hash    Normalized body hash.
	 * @return array<string,mixed>|WP_Error|null
	 */
	private function replay( array $session, string $idempotency_key, string $request_hash ): array|WP_Error|null {
		$commit = $session['commit'] ?? null;

		if ( ! is_array( $commit ) || ( $commit['idempotencyKey'] ?? '' ) !== $idempotency_key ) {
			return null;
		}

		if ( ( $commit['requestHash'] ?? '' ) !== $request_hash ) {
			return new WP_Error(
				'docsync_wp_idempotency_conflict',
				__( 'This request key was already used for a different commit.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		return $this->imports->formatSession( $session );
	}

	/**
	 * Normalize the listed files; null when malformed.
	 *
	 * @param array<int,mixed> $files Raw files.
	 * @return array<int,array{fileId:string,previewFingerprint:string}>|null
	 */
	private function normalizeFiles( array $files ): ?array {
		if ( ! array_is_list( $files ) || array() === $files || count( $files ) > UploadValidator::MAX_FILES ) {
			return null;
		}

		$listed = array();
		$seen   = array();

		foreach ( $files as $entry ) {
			if (
				! is_array( $entry )
				|| array( 'fileId', 'previewFingerprint' ) !== array_keys( $entry )
				|| ! is_string( $entry['fileId'] )
				|| ! is_string( $entry['previewFingerprint'] )
				|| 1 !== preg_match( self::FILE_ID_PATTERN, $entry['fileId'] )
				|| 1 !== preg_match( self::FINGERPRINT_PATTERN, $entry['previewFingerprint'] )
				|| isset( $seen[ $entry['fileId'] ] )
			) {
				return null;
			}

			$seen[ $entry['fileId'] ] = true;
			$listed[]                 = array(
				'fileId'             => $entry['fileId'],
				'previewFingerprint' => $entry['previewFingerprint'],
			);
		}

		return $listed;
	}

	/**
	 * Replace or append a result entry by file ID.
	 *
	 * @param array<int,array<string,mixed>> $entries Entries.
	 * @param array<string,mixed>            $entry   Entry.
	 * @return array<int,array<string,mixed>>
	 */
	private function withResultEntry( array $entries, array $entry ): array {
		foreach ( $entries as $index => $existing ) {
			if ( ( $existing['fileId'] ?? '' ) === $entry['fileId'] ) {
				$entries[ $index ] = $entry;

				return $entries;
			}
		}

		$entries[] = $entry;

		return $entries;
	}

	/**
	 * Persist a file's commit state.
	 *
	 * @param string              $session_id Session ID.
	 * @param string              $file_id    File ID.
	 * @param array<string,mixed> $state      Commit state.
	 */
	private function saveState( string $session_id, string $file_id, array $state ): bool {
		$saved = $this->mutate(
			$session_id,
			function ( array $current ) use ( $file_id, $state ): array {
				$index = $this->fileIndex( $current, $file_id );

				if ( null !== $index ) {
					$current['files'][ $index ]['commitState'] = $state;
				}

				return $current;
			}
		);

		return ! is_wp_error( $saved );
	}

	/**
	 * Read-modify-write a session with compare-and-swap retries.
	 *
	 * @param string   $session_id Session ID.
	 * @param callable $change     Side-effect-free change; returns the session or WP_Error.
	 * @return array<string,mixed>|WP_Error
	 */
	private function mutate( string $session_id, callable $change ): array|WP_Error {
		for ( $attempt = 0; $attempt < self::CAS_ATTEMPTS; ++$attempt ) {
			$session = $this->sessions->getForWorker( $session_id );

			if ( null === $session ) {
				return new WP_Error(
					'docsync_wp_import_session_not_found',
					__( 'Brasth Document Sync could not find this import.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 404 )
				);
			}

			$next = $change( $session );

			if ( is_wp_error( $next ) || $next === $session ) {
				return $next;
			}

			$saved = $this->sessions->save( $next );

			if ( true === $saved ) {
				return $this->sessions->getForWorker( $session_id ) ?? $next;
			}

			if ( is_wp_error( $saved ) && 'docsync_wp_import_session_conflict' !== $saved->get_error_code() ) {
				return $saved;
			}

			usleep( wp_rand( 10000, 60000 ) );
		}

		return new WP_Error(
			'docsync_wp_import_session_conflict',
			__( 'This import is busy. Try again in a moment.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * File record by ID.
	 *
	 * @param array<string,mixed> $session Session.
	 * @param string              $file_id File ID.
	 * @return array<string,mixed>|null
	 */
	private function findFile( array $session, string $file_id ): ?array {
		$index = $this->fileIndex( $session, $file_id );

		return null !== $index ? $session['files'][ $index ] : null;
	}

	/**
	 * Position of a file in a session.
	 *
	 * @param array<string,mixed> $session Session.
	 * @param string              $file_id File ID.
	 */
	private function fileIndex( array $session, string $file_id ): ?int {
		foreach ( $session['files'] as $index => $file ) {
			if ( ( $file['fileId'] ?? '' ) === $file_id ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Temporary slug marker for a file's draft.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 */
	private function marker( string $session_id, string $file_id ): string {
		return self::MARKER_PREFIX . substr( hash( 'sha256', $session_id . '|' . $file_id ), 0, 24 );
	}

	/**
	 * Schedule the commit worker.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $timestamp  Run time.
	 */
	private function schedule( string $session_id, int $timestamp ): void {
		$args     = array( $session_id );
		$existing = wp_next_scheduled( self::COMMIT_HOOK, $args );

		if ( false !== $existing && $existing <= $timestamp ) {
			return;
		}

		if ( false !== $existing ) {
			wp_unschedule_event( $existing, self::COMMIT_HOOK, $args );
		}

		wp_schedule_single_event( $timestamp, self::COMMIT_HOOK, $args );
	}

	/**
	 * Load the admin media APIs used for sideloading in cron.
	 */
	private function loadMediaApi(): void {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
	}

	/**
	 * Direct filesystem instance.
	 */
	private function filesystem(): \WP_Filesystem_Direct {
		if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}

		return new \WP_Filesystem_Direct( null );
	}

	/**
	 * Stale preview error.
	 *
	 * @param array<int,array<string,string>> $files Current fingerprints of stale files.
	 */
	private function staleError( array $files ): WP_Error {
		return new WP_Error(
			'docsync_wp_import_preview_stale',
			__( 'The preview changed since you reviewed it. Check the updated preview, then commit again.', 'brasth-document-sync-for-google-docs' ),
			array(
				'status' => 409,
				'files'  => $files,
			)
		);
	}

	/**
	 * Session not open error.
	 */
	private function notOpenError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_session_not_open',
			__( 'This import is no longer open for changes.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Owner lost permission error.
	 */
	private function forbiddenError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_forbidden',
			__( 'The import owner can no longer upload files or create drafts of this content type.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Generic commit failure.
	 */
	private function commitFailedError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_commit_failed',
			__( 'This file could not be added to WordPress.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 500 )
		);
	}
}
