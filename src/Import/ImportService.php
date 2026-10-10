<?php
/**
 * Import session orchestration: uploads, conversion, options, previews, assets.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Google\DriveWriteClient;
use DocSyncWP\Rest\RestServiceProvider;
use DocSyncWP\Sync\SourceRepository;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Runs everything before commit for private, owner-bound import sessions.
 *
 * Nothing here creates posts, drafts, revisions, attachments, or Media
 * Library files. Conversions run on `docsync_wp_import_convert`; every
 * Google file a converter creates is persisted in the file's cleanup set
 * through the converter callback before any Docs or Slides read, and is
 * trashed (only when app-created and unlinked) on retry, failure, or cancel.
 */
final class ImportService {
	public const CONVERT_HOOK = 'docsync_wp_import_convert';

	public const STATUS_CONVERTING   = 'converting';
	public const STATUS_AWAITING     = 'awaitingGoogleWrite';
	public const STATUS_NEEDS_RENDER = 'needsRender';
	public const STATUS_READY        = 'ready';
	public const STATUS_FAILED       = 'failed';
	public const STATUS_COMMITTING   = 'committing';
	public const STATUS_COMMITTED    = 'committed';
	public const STATUS_SKIPPED      = 'skipped';

	private const MAX_CONVERSION_ATTEMPTS = 5;
	private const CAS_ATTEMPTS            = 8;
	private const MAX_TITLE_LENGTH        = 200;
	private const SESSION_PATTERN         = '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/';
	private const FILE_ID_PATTERN         = '/^f_[a-f0-9]{16}$/';
	private const ASSET_ID_PATTERN        = '/^a_[a-f0-9]{16}$/';
	private const SUPERSEDED_CODE         = 'docsync_wp_import_conversion_superseded';

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
	 * Upload validator.
	 *
	 * @var UploadValidator
	 */
	private UploadValidator $validator;

	/**
	 * DOCX converter.
	 *
	 * @var DocxConverter
	 */
	private DocxConverter $docx;

	/**
	 * PPTX converter.
	 *
	 * @var DeckConverter
	 */
	private DeckConverter $deck;

	/**
	 * PDF converter.
	 *
	 * @var PdfConverter
	 */
	private PdfConverter $pdf;

	/**
	 * Canonical renderer.
	 *
	 * @var CanonicalRenderer
	 */
	private CanonicalRenderer $renderer;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Drive write client.
	 *
	 * @var DriveWriteClient
	 */
	private DriveWriteClient $drive_write;

	/**
	 * Constructor.
	 *
	 * @param ImportSessionRepository $sessions          Session repository.
	 * @param PrivateAssetStore       $assets            Private asset store.
	 * @param UploadValidator         $validator         Upload validator.
	 * @param DocxConverter           $docx              DOCX converter.
	 * @param DeckConverter           $deck              PPTX converter.
	 * @param PdfConverter            $pdf               PDF converter.
	 * @param CanonicalRenderer       $renderer          Canonical renderer.
	 * @param SourceRepository        $source_repository Source repository.
	 * @param DriveWriteClient        $drive_write       Drive write client.
	 */
	public function __construct(
		ImportSessionRepository $sessions,
		PrivateAssetStore $assets,
		UploadValidator $validator,
		DocxConverter $docx,
		DeckConverter $deck,
		PdfConverter $pdf,
		CanonicalRenderer $renderer,
		SourceRepository $source_repository,
		DriveWriteClient $drive_write
	) {
		$this->sessions          = $sessions;
		$this->assets            = $assets;
		$this->validator         = $validator;
		$this->docx              = $docx;
		$this->deck              = $deck;
		$this->pdf               = $pdf;
		$this->renderer          = $renderer;
		$this->source_repository = $source_repository;
		$this->drive_write       = $drive_write;
	}

	/**
	 * Register the conversion hook and the OAuth continuation listener.
	 */
	public function register(): void {
		add_action( self::CONVERT_HOOK, array( $this, 'runConversion' ), 10, 2 );
		add_action( GoogleOAuthService::CONTINUATION_ACTION, array( $this, 'onContinuationConsumed' ), 10, 2 );
	}

	/**
	 * Create a session for the user.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,mixed>|WP_Error Formatted session.
	 */
	public function createSession( int $user_id ): array|WP_Error {
		if ( ! $this->assets->isAvailable() ) {
			return new WP_Error(
				'docsync_wp_import_storage_unavailable',
				__( 'Private upload storage is unavailable. Define DOCSYNC_WP_PRIVATE_STORAGE_DIR outside the web root, or enable OpenSSL so uploads can be encrypted.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 503 )
			);
		}

		$session = $this->sessions->create( $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$mode    = $this->assets->storageMode();
		$session = $this->mutate(
			$session['sessionId'],
			static function ( array $current ) use ( $mode ): array {
				$current['storageMode'] = $mode;

				return $current;
			}
		);

		return is_wp_error( $session ) ? $session : $this->formatSession( $session );
	}

	/**
	 * Summaries of the user's open and committing sessions, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,array<string,mixed>>
	 */
	public function listSessions( int $user_id ): array {
		$summaries = array();

		foreach ( $this->sessions->listOpenForUser( $user_id ) as $session ) {
			$summaries[] = array(
				'sessionId' => $session['sessionId'],
				'status'    => $session['status'],
				'createdAt' => $this->iso( (int) $session['createdAt'] ),
				'expiresAt' => $this->iso( (int) $session['expiresAt'] ),
				'fileCount' => count( $session['files'] ),
			);
		}

		return $summaries;
	}

	/**
	 * Read one session.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $user_id    Caller.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getSession( string $session_id, int $user_id ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );

		return is_wp_error( $session ) ? $session : $this->formatSession( $session );
	}

	/**
	 * Cancel a session: purge bytes and trash every remaining app-created Google file.
	 *
	 * The record is first marked cancelled with `cleanupPending`, so it is
	 * invisible to session routes and retried by cleanup even if this request
	 * dies. It is deleted only once no Google file ID remains.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $user_id    Caller.
	 * @return array{sessionId:string,status:string,cleanupPending:bool}|WP_Error
	 */
	public function cancelSession( string $session_id, int $user_id ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$committing = $this->committingError();
		$now        = time();
		$cancelled  = $this->mutate(
			$session_id,
			static function ( array $current ) use ( $committing, $now ): array|WP_Error {
				if ( ImportSessionRepository::STATUS_COMMITTING === $current['status'] ) {
					return $committing;
				}

				$current['status']          = ImportSessionRepository::STATUS_CANCELLED;
				$current['cleanupPending']  = true;
				$current['cleanupAttempts'] = 0;
				$current['nextCleanupAt']   = $now + HOUR_IN_SECONDS;

				return $current;
			}
		);

		if ( is_wp_error( $cancelled ) ) {
			return $cancelled;
		}

		return array(
			'sessionId'      => $session_id,
			'status'         => ImportSessionRepository::STATUS_CANCELLED,
			'cleanupPending' => $this->releaseCancelled( $session_id, $user_id ),
		);
	}

	/**
	 * Accept uploaded files into an open session.
	 *
	 * @param string                         $session_id     Session ID.
	 * @param int                            $user_id        Caller.
	 * @param array<int,array<string,mixed>> $uploaded_files Normalized `$_FILES` entries.
	 * @return array{session:array<string,mixed>,added:array<int,string>,rejected:array<int,array<string,string>>}|WP_Error
	 */
	public function addFiles( string $session_id, int $user_id, array $uploaded_files ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( ImportSessionRepository::STATUS_OPEN !== $session['status'] ) {
			return $this->notOpenError();
		}

		if ( array() === $uploaded_files || count( $uploaded_files ) > UploadValidator::MAX_FILES ) {
			return new WP_Error(
				'docsync_wp_import_invalid_upload',
				__( 'Upload between 1 and 20 files at a time.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( count( $session['files'] ) + count( $uploaded_files ) > UploadValidator::MAX_FILES ) {
			return $this->fileLimitError();
		}

		$post_type = $this->defaultPostType( $user_id );

		if ( '' === $post_type ) {
			return $this->postTypeForbiddenError();
		}

		$has_scope = $this->drive_write->hasWriteScope( $user_id );
		$accepted  = count( $session['files'] );
		$new_files = array();
		$rejected  = array();

		foreach ( $uploaded_files as $upload ) {
			$name      = isset( $upload['name'] ) && is_string( $upload['name'] ) ? sanitize_text_field( wp_basename( $upload['name'] ) ) : '';
			$validated = $this->validator->validate( $upload, $accepted );

			if ( is_wp_error( $validated ) ) {
				$rejected[] = $this->rejection( $name, $validated );
				continue;
			}

			$file_id = $this->newId( 'f_' );
			$stored  = $this->assets->storeUpload( $session_id, $file_id, $validated['tmpPath'] );

			if ( is_wp_error( $stored ) ) {
				$rejected[] = $this->rejection( $validated['originalName'], $stored );
				continue;
			}

			$new_files[] = $this->newFileRecord( $file_id, $validated, $stored, $post_type, $has_scope );
			++$accepted;
		}

		$limit_error = $this->fileLimitError();
		$not_open    = $this->notOpenError();
		$saved       = array() === $new_files ? $session : $this->mutate(
			$session_id,
			static function ( array $current ) use ( $new_files, $limit_error, $not_open ): array|WP_Error {
				if ( ImportSessionRepository::STATUS_OPEN !== $current['status'] ) {
					return $not_open;
				}

				if ( count( $current['files'] ) + count( $new_files ) > UploadValidator::MAX_FILES ) {
					return $limit_error;
				}

				$current['files'] = array_merge( $current['files'], $new_files );

				return $current;
			}
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		foreach ( $new_files as $file ) {
			if ( self::STATUS_CONVERTING === $file['status'] ) {
				$this->scheduleConversion( $session_id, $file['fileId'], time() );
			}
		}

		$this->spawnCron();

		return array(
			'session'  => $this->formatSession( $saved ),
			'added'    => array_column( $new_files, 'fileId' ),
			'rejected' => $rejected,
		);
	}

	/**
	 * Run one bounded conversion tick for a file (cron).
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 */
	public function runConversion( string $session_id, string $file_id ): void {
		$session = $this->sessions->getForWorker( $session_id );
		$file    = null !== $session ? $this->findFile( $session, $file_id ) : null;

		if ( null === $session || null === $file || ! $this->isLiveOpen( $session ) || self::STATUS_CONVERTING !== $file['status'] ) {
			return;
		}

		$not_before = absint( $file['nextConversionAt'] ?? 0 );

		if ( $not_before > time() + 5 ) {
			$this->scheduleConversion( $session_id, $file_id, $not_before );

			return;
		}

		if ( ! $this->sessions->lock( $session_id ) ) {
			$this->scheduleConversion( $session_id, $file_id, time() + 30 );

			return;
		}

		$owner    = absint( $session['ownerUserId'] );
		$switched = function_exists( 'switch_to_user_locale' ) && switch_to_user_locale( $owner );

		try {
			$this->convertLocked( $session_id, $file_id, $owner );
		} catch ( Throwable $exception ) {
			$this->failFile( $session_id, $file_id, -1, new WP_Error( 'docsync_wp_import_conversion_failed', __( 'This file could not be converted.', 'brasth-document-sync-for-google-docs' ), array( 'status' => 500 ) ) );
		} finally {
			$this->sessions->unlock( $session_id );

			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Update a file's options.
	 *
	 * `pdf.pages` and `pdf.renderMode` changes reconvert the file; every other
	 * change (including all PPTX options) only recomputes the fingerprint.
	 *
	 * @param string              $session_id Session ID.
	 * @param string              $file_id    File ID.
	 * @param int                 $user_id    Caller.
	 * @param array<string,mixed> $options    Partial options.
	 * @return array{file:array<string,mixed>}|WP_Error
	 */
	public function updateOptions( string $session_id, string $file_id, int $user_id, array $options ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$file = $this->findFile( $session, $file_id );

		if ( null === $file ) {
			return $this->fileNotFoundError();
		}

		if ( ImportSessionRepository::STATUS_OPEN !== $session['status'] ) {
			return $this->notOpenError();
		}

		$change = $this->validateOptionChange( $file, $options, $user_id );

		if ( is_wp_error( $change ) ) {
			return $change;
		}

		$canonical = in_array( $file['status'], array( self::STATUS_NEEDS_RENDER, self::STATUS_READY ), true ) && ! $change['reconvert']
			? $this->assets->read( $session_id, (string) $file['canonicalKey'] )
			: '';
		$canonical = is_wp_error( $canonical ) ? '' : $canonical;
		$not_open  = $this->notOpenError();
		$saved     = $this->mutate(
			$session_id,
			function ( array $current ) use ( $file_id, $change, $canonical, $not_open ): array|WP_Error {
				$index = $this->fileIndex( $current, $file_id );

				if ( null === $index || ImportSessionRepository::STATUS_OPEN !== $current['status'] ) {
					return $not_open;
				}

				$next                   = $current['files'][ $index ];
				$next['options']        = $this->mergeOptions( $next['options'], $change['options'] );
				$next['optionsTouched'] = array_merge( (array) ( $next['optionsTouched'] ?? array() ), $change['touched'] );

				if ( $change['reconvert'] && self::STATUS_AWAITING !== $next['status'] ) {
					$next['status']               = self::STATUS_CONVERTING;
					$next['error']                = null;
					$next['conversionGeneration'] = absint( $next['conversionGeneration'] ?? 0 ) + 1;
					$next['conversionAttempts']   = 0;
					$next['nextConversionAt']     = 0;
					$next['conversionProgress']   = $this->progress( 'extracting', 0, 1 );
					$next['previewFingerprint']   = null;
					$next['pendingRenders']       = array();
				} elseif ( '' !== $canonical && in_array( $next['status'], array( self::STATUS_NEEDS_RENDER, self::STATUS_READY ), true ) ) {
					$document = $this->documentFor( $next, $canonical );

					if ( ! is_wp_error( $document ) ) {
						$next = $this->refreshDerived( $next, $document );
					}
				}

				$current['files'][ $index ] = $next;

				return $current;
			}
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$updated = $this->findFile( $saved, $file_id );

		if ( $change['reconvert'] && null !== $updated && self::STATUS_CONVERTING === $updated['status'] ) {
			$this->scheduleConversion( $session_id, $file_id, time() );
			$this->spawnCron();
		}

		return array( 'file' => $this->formatFile( (array) $updated ) );
	}

	/**
	 * Canonical document and rendered preview for a converted file.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 * @param int    $user_id    Caller.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getPreview( string $session_id, string $file_id, int $user_id ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$file = $this->findFile( $session, $file_id );

		if ( null === $file ) {
			return $this->fileNotFoundError();
		}

		if ( ! in_array( $file['status'], array( self::STATUS_NEEDS_RENDER, self::STATUS_READY ), true ) ) {
			return $this->fileNotReadyError();
		}

		$canonical = $this->assets->read( $session_id, (string) $file['canonicalKey'] );
		$document  = is_wp_error( $canonical ) ? $canonical : $this->documentFor( $file, $canonical );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$effective = $document->applyOptions( $file['options'] );
		$preset    = $this->renderer->resolvePreset( (string) $file['options']['layoutPreset'] );
		$markup    = $this->renderer->renderBlocks( $effective, $this->assetUrls( $session_id, $file_id, $effective ), $preset );

		if ( is_wp_error( $markup ) ) {
			return $markup;
		}

		$fingerprint = $this->renderer->previewFingerprint( $effective, $file['options'], $preset );

		if ( $fingerprint !== $file['previewFingerprint'] ) {
			$this->mutate(
				$session_id,
				function ( array $current ) use ( $file_id, $fingerprint, $file ): array {
					$index = $this->fileIndex( $current, $file_id );

					if ( null !== $index && $current['files'][ $index ]['options'] === $file['options'] && $current['files'][ $index ]['assetIndex'] === $file['assetIndex'] ) {
						$current['files'][ $index ]['previewFingerprint'] = $fingerprint;
					}

					return $current;
				}
			);
		}

		return array(
			'fileId'             => $file_id,
			'previewFingerprint' => $fingerprint,
			'title'              => (string) $file['options']['title'],
			'layoutPreset'       => $preset,
			'document'           => $effective->toArray(),
			'blockMarkup'        => $markup,
			'html'               => wp_kses_post( do_blocks( $markup ) ),
			'warnings'           => $effective->getWarnings(),
			'statistics'         => $effective->getStatistics(),
		);
	}

	/**
	 * Read a private asset (or a PDF's private original) for its owner.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 * @param string $asset_id   Asset ID.
	 * @param int    $user_id    Caller.
	 * @return array{bytes:string,mimeType:string}|WP_Error
	 */
	public function readAsset( string $session_id, string $file_id, string $asset_id, int $user_id ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );
		$file    = is_wp_error( $session ) ? null : $this->findFile( $session, $file_id );

		if ( null === $file || 1 !== preg_match( self::ASSET_ID_PATTERN, $asset_id ) ) {
			return $this->assetNotFoundError();
		}

		if ( null !== $file['originalAssetId'] && $asset_id === $file['originalAssetId'] ) {
			$key  = (string) $file['originalKey'];
			$mime = 'application/pdf';
		} else {
			$entry = $file['assetIndex'][ $asset_id ] ?? null;

			if ( ! is_array( $entry ) || 'ready' !== ( $entry['status'] ?? '' ) ) {
				return $this->assetNotFoundError();
			}

			$key  = $asset_id;
			$mime = (string) $entry['mimeType'];
		}

		$bytes = $this->assets->read( $session_id, $key );

		if ( is_wp_error( $bytes ) ) {
			return $this->assetNotFoundError();
		}

		return array(
			'bytes'    => $bytes,
			'mimeType' => $mime,
		);
	}

	/**
	 * Store a browser-rendered PDF page or crop PNG.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 * @param string $asset_id   Pending asset ID.
	 * @param int    $user_id    Caller.
	 * @param string $png_bytes  Raw PNG bytes.
	 * @return array{asset:array<string,mixed>,file:array<string,mixed>}|WP_Error
	 */
	public function storeRenderedAsset( string $session_id, string $file_id, string $asset_id, int $user_id, string $png_bytes ): array|WP_Error {
		$session = $this->sessions->get( $session_id, $user_id );

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		$file  = $this->findFile( $session, $file_id );
		$entry = null !== $file ? ( $file['assetIndex'][ $asset_id ] ?? null ) : null;

		if ( null === $file || ! is_array( $entry ) || 'pdfPageRender' !== ( $entry['kind'] ?? '' ) ) {
			return $this->assetNotFoundError();
		}

		if ( ImportSessionRepository::STATUS_OPEN !== $session['status'] ) {
			return $this->notOpenError();
		}

		if ( 'pendingRender' !== $entry['status'] || ! in_array( $file['status'], array( self::STATUS_NEEDS_RENDER, self::STATUS_READY ), true ) ) {
			return $this->assetNotPendingError();
		}

		$validated = $this->validator->validatePng(
			$png_bytes,
			array(
				'widthPt'  => (float) ( $entry['widthPt'] ?? 0 ),
				'heightPt' => (float) ( $entry['heightPt'] ?? 0 ),
			)
		);

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$stored = $this->assets->put( $session_id, $asset_id, $validated['bytes'] );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		$canonical = $this->assets->read( $session_id, (string) $file['canonicalKey'] );

		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}

		$not_pending = $this->assetNotPendingError();
		$saved       = $this->mutate(
			$session_id,
			function ( array $current ) use ( $file_id, $asset_id, $validated, $stored, $canonical, $not_pending ): array|WP_Error {
				$index = $this->fileIndex( $current, $file_id );
				$next  = null !== $index ? $current['files'][ $index ] : null;

				if ( null === $next || 'pendingRender' !== ( $next['assetIndex'][ $asset_id ]['status'] ?? '' ) || ! in_array( $next['status'], array( self::STATUS_NEEDS_RENDER, self::STATUS_READY ), true ) ) {
					return $not_pending;
				}

				$next['assetIndex'][ $asset_id ] = array_merge(
					$next['assetIndex'][ $asset_id ],
					array(
						'status'   => 'ready',
						'width'    => $validated['width'],
						'height'   => $validated['height'],
						'byteSize' => $stored['byteSize'],
						'sha256'   => $stored['sha256'],
					)
				);

				$document = $this->documentFor( $next, $canonical );

				if ( is_wp_error( $document ) ) {
					return $document;
				}

				$current['files'][ $index ] = $this->refreshDerived( $next, $document );

				return $current;
			}
		);

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$updated = (array) $this->findFile( $saved, $file_id );
		$asset   = array();

		$document = $this->documentFor( $updated, $canonical );

		if ( ! is_wp_error( $document ) ) {
			foreach ( $document->getAssets() as $candidate ) {
				if ( $candidate['assetId'] === $asset_id ) {
					$asset = $candidate;
				}
			}
		}

		return array(
			'asset' => $asset,
			'file'  => $this->formatFile( $updated ),
		);
	}

	/**
	 * Trash app-created Google files, returning the IDs whose trash failed.
	 *
	 * IDs linked to any post are never trashed. Trashed, already trashed,
	 * missing (404), and not-app-created IDs are resolved and not returned.
	 *
	 * @param int               $user_id  Owner whose Drive holds the files.
	 * @param array<int,string> $file_ids Google file IDs.
	 * @return array<int,string> IDs to keep for a retry.
	 */
	public function trashTemporaries( int $user_id, array $file_ids ): array {
		$failed = array();

		foreach ( array_values( array_unique( array_map( 'strval', $file_ids ) ) ) as $google_id ) {
			if ( '' === $google_id || null !== $this->source_repository->findPostIdByGoogleFileId( $google_id ) ) {
				continue;
			}

			$trashed = $this->drive_write->trashAppCreatedFile( $user_id, $google_id );

			if ( true === $trashed ) {
				continue;
			}

			$data = is_wp_error( $trashed ) ? $trashed->get_error_data() : null;

			if ( is_array( $data ) && ! empty( $data['resolved'] ) ) {
				continue;
			}

			$failed[] = $google_id;
		}

		return $failed;
	}

	/**
	 * Wire shape of a stored session.
	 *
	 * @param array<string,mixed> $session Stored session.
	 * @return array<string,mixed>
	 */
	public function formatSession( array $session ): array {
		$limits = $this->validator->limits();
		$owner  = absint( $session['ownerUserId'] );

		return array(
			'sessionId'   => (string) $session['sessionId'],
			'version'     => 1,
			'status'      => (string) $session['status'],
			'createdAt'   => $this->iso( (int) $session['createdAt'] ),
			'updatedAt'   => $this->iso( (int) $session['updatedAt'] ),
			'expiresAt'   => $this->iso( (int) $session['expiresAt'] ),
			'limits'      => array(
				'maxFiles'       => UploadValidator::MAX_FILES,
				'maxFileBytes'   => $limits['maxFileBytes'],
				'remainingFiles' => max( 0, UploadValidator::MAX_FILES - count( $session['files'] ) ),
			),
			'storageMode' => in_array( $session['storageMode'] ?? '', array( PrivateAssetStore::MODE_OUTSIDE_WEBROOT, PrivateAssetStore::MODE_ENCRYPTED ), true ) ? $session['storageMode'] : $this->assets->storageMode(),
			'googleWrite' => array(
				'hasDriveFileScope' => $this->drive_write->hasWriteScope( $owner ),
				'importFolderName'  => DriveWriteClient::IMPORT_FOLDER_NAME,
			),
			'files'       => $this->formatSessionFiles( $session ),
			'result'      => $this->formatResult( $session['result'] ?? null ),
		);
	}

	/**
	 * Queue conversion for files waiting on `drive.file` once the scope is granted.
	 *
	 * @param int                 $user_id      Owner.
	 * @param array<string,mixed> $continuation Consumed continuation.
	 */
	public function onContinuationConsumed( int $user_id, array $continuation ): void {
		if ( $user_id <= 0 || ! $this->drive_write->hasWriteScope( $user_id ) ) {
			return;
		}

		unset( $continuation );

		$queued = false;

		// Every open session of this owner can convert now, not only the one the continuation resumes.
		foreach ( $this->sessions->listOpenForUser( $user_id ) as $session ) {
			$session_id = (string) $session['sessionId'];
			$waiting    = array();
			$saved      = $this->mutate(
				$session_id,
				function ( array $current ) use ( &$waiting ): array {
					$waiting = array();

					if ( ImportSessionRepository::STATUS_OPEN !== $current['status'] ) {
						return $current;
					}

					foreach ( $current['files'] as $index => $file ) {
						if ( self::STATUS_AWAITING === $file['status'] ) {
							$current['files'][ $index ]['status']             = self::STATUS_CONVERTING;
							$current['files'][ $index ]['conversionProgress'] = $this->progress( 'uploading', 0, 1 );
							$waiting[]                                        = $file['fileId'];
						}
					}

					return $current;
				}
			);

			if ( is_wp_error( $saved ) ) {
				continue;
			}

			foreach ( $waiting as $file_id ) {
				$this->scheduleConversion( $session_id, $file_id, time() );
				$queued = true;
			}
		}

		if ( $queued ) {
			$this->spawnCron();
		}
	}

	/**
	 * Convert one file while holding the session lease.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 * @param int    $owner      Owner user ID.
	 */
	private function convertLocked( string $session_id, string $file_id, int $owner ): void {
		$session = $this->sessions->getForWorker( $session_id );
		$file    = null !== $session ? $this->findFile( $session, $file_id ) : null;

		if ( null === $session || null === $file || ! $this->isLiveOpen( $session ) || self::STATUS_CONVERTING !== $file['status'] ) {
			return;
		}

		$generation = absint( $file['conversionGeneration'] ?? 0 );
		$file       = $this->prepareRetry( $session_id, $file, $owner );

		if ( null === $file ) {
			return;
		}

		$step = 'pdf' === $file['format'] ? 'extracting' : ( '' === (string) $file['googleFileId'] ? 'uploading' : 'thumbnails' );

		$this->updateFile(
			$session_id,
			$file_id,
			function ( array $current ) use ( $generation, $step ): array {
				if ( absint( $current['conversionGeneration'] ?? 0 ) === $generation && self::STATUS_CONVERTING === $current['status'] && 'thumbnails' !== ( $current['conversionProgress']['step'] ?? '' ) ) {
					$current['conversionProgress'] = $this->progress( $step, 0, 1 );
				}

				return $current;
			}
		);

		$callback = function ( string $google_file_id ) use ( $session_id, $file_id, $generation ): bool|WP_Error {
			$added = $this->sessions->addGoogleTemporary( $session_id, $file_id, $google_file_id );

			if ( is_wp_error( $added ) ) {
				return $added;
			}

			$fresh   = $this->sessions->getForWorker( $session_id );
			$current = null !== $fresh ? $this->findFile( $fresh, $file_id ) : null;

			if ( null === $fresh || null === $current || ! $this->isLiveOpen( $fresh ) || self::STATUS_CONVERTING !== $current['status'] || absint( $current['conversionGeneration'] ?? 0 ) !== $generation ) {
				return new WP_Error( self::SUPERSEDED_CODE, __( 'This conversion was cancelled or replaced.', 'brasth-document-sync-for-google-docs' ), array( 'status' => 409 ) );
			}

			return true;
		};

		switch ( $file['format'] ) {
			case UploadValidator::FORMAT_DOCX:
				$result = $this->docx->convert( $owner, $session_id, $file, $file['options'], $callback );
				break;
			case UploadValidator::FORMAT_PPTX:
				$result = $this->deck->convert( $owner, $session_id, $file, $file['options'], $callback );
				break;
			default:
				$result = $this->pdf->convert( $owner, $session_id, $file, $file['options'] );
				break;
		}

		if ( is_wp_error( $result ) ) {
			$this->handleConversionError( $session_id, $file_id, $generation, $owner, $result );

			return;
		}

		$this->applyConversion( $session_id, $file_id, $generation, $owner, $result );
	}

	/**
	 * Before a retry, trash the previous attempt's temporaries.
	 *
	 * DOCX always starts over. PPTX keeps its presentation so cached
	 * thumbnails are reused; only other leftover IDs are trashed.
	 *
	 * @param string              $session_id Session ID.
	 * @param array<string,mixed> $file       File record.
	 * @param int                 $owner      Owner user ID.
	 * @return array<string,mixed>|null Refreshed file, or null when it vanished.
	 */
	private function prepareRetry( string $session_id, array $file, int $owner ): ?array {
		$temporaries = array_values( array_map( 'strval', (array) ( $file['googleTemporaries'] ?? array() ) ) );
		$keep        = UploadValidator::FORMAT_PPTX === $file['format'] ? (string) $file['googleFileId'] : '';
		$stale       = array_values( array_diff( $temporaries, array( $keep ) ) );

		if ( UploadValidator::FORMAT_PDF === $file['format'] || array() === $stale ) {
			return $file;
		}

		$failed   = $this->trashTemporaries( $owner, $stale );
		$resolved = array_values( array_diff( $stale, $failed ) );
		$is_docx  = UploadValidator::FORMAT_DOCX === $file['format'];
		$saved    = $this->updateFile(
			$session_id,
			(string) $file['fileId'],
			static function ( array $current ) use ( $resolved, $is_docx ): array {
				$current['googleTemporaries'] = array_values( array_diff( (array) $current['googleTemporaries'], $resolved ) );

				if ( $is_docx ) {
					$current['googleFileId'] = '';
				}

				return $current;
			}
		);

		return is_wp_error( $saved ) ? null : $this->findFile( $saved, (string) $file['fileId'] );
	}

	/**
	 * Record a conversion failure, retry later, or fail the file.
	 *
	 * @param string   $session_id Session ID.
	 * @param string   $file_id    File ID.
	 * @param int      $generation Conversion generation.
	 * @param int      $owner      Owner user ID.
	 * @param WP_Error $error      Converter error.
	 */
	private function handleConversionError( string $session_id, string $file_id, int $generation, int $owner, WP_Error $error ): void {
		$data    = $error->get_error_data();
		$created = is_array( $data ) && isset( $data['googleTemporaries'] ) && is_array( $data['googleTemporaries'] ) ? array_values( array_map( 'strval', $data['googleTemporaries'] ) ) : array();

		if ( self::SUPERSEDED_CODE === $error->get_error_code() ) {
			$this->removeResolved( $session_id, $file_id, $created, $this->trashTemporaries( $owner, $created ) );

			return;
		}

		$retryable = is_array( $data ) && ( ! empty( $data['retryable'] ) || in_array( absint( $data['status'] ?? 0 ), array( 429, 503 ), true ) );
		$now       = time();
		$retry_at  = 0;
		$saved     = $this->updateFile(
			$session_id,
			$file_id,
			function ( array $current ) use ( $created, $generation, $retryable, $now, $error, &$retry_at ): array {
				$retry_at                     = 0;
				$current['googleTemporaries'] = array_values( array_unique( array_merge( (array) $current['googleTemporaries'], $created ) ) );

				if ( absint( $current['conversionGeneration'] ?? 0 ) !== $generation || self::STATUS_CONVERTING !== $current['status'] ) {
					return $current;
				}

				$attempts = absint( $current['conversionAttempts'] ?? 0 ) + 1;

				if ( $retryable && $attempts < self::MAX_CONVERSION_ATTEMPTS ) {
					$retry_at                      = $now + min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** ( $attempts - 1 ) ) );
					$current['conversionAttempts'] = $attempts;
					$current['nextConversionAt']   = $retry_at;

					return $current;
				}

				return $this->failedFile( $current, $error );
			}
		);

		if ( is_wp_error( $saved ) ) {
			return;
		}

		if ( $retry_at > 0 ) {
			$this->scheduleConversion( $session_id, $file_id, $retry_at );

			return;
		}

		$failed_file = $this->findFile( $saved, $file_id );

		if ( null !== $failed_file && self::STATUS_FAILED === $failed_file['status'] ) {
			$temporaries = array_values( array_map( 'strval', (array) $failed_file['googleTemporaries'] ) );
			$this->removeResolved( $session_id, $file_id, $temporaries, $this->trashTemporaries( $owner, $temporaries ) );
		}
	}

	/**
	 * Apply a converter result to the file record.
	 *
	 * @param string              $session_id Session ID.
	 * @param string              $file_id    File ID.
	 * @param int                 $generation Conversion generation.
	 * @param int                 $owner      Owner user ID.
	 * @param array<string,mixed> $result     Converter result.
	 */
	private function applyConversion( string $session_id, string $file_id, int $generation, int $owner, array $result ): void {
		$temporaries = array_values( array_map( 'strval', (array) ( $result['googleTemporaries'] ?? array() ) ) );
		$google_id   = (string) ( $result['googleFileId'] ?? '' );

		if ( false === ( $result['complete'] ?? true ) ) {
			$retry_after = absint( $result['retryAfter'] ?? 0 );
			$next_tick   = time() + ( $retry_after > 0 ? $retry_after : 1 );

			$this->updateFile(
				$session_id,
				$file_id,
				function ( array $current ) use ( $temporaries, $google_id, $result, $generation, $retry_after, $next_tick ): array {
					$current['googleTemporaries'] = array_values( array_unique( array_merge( (array) $current['googleTemporaries'], $temporaries ) ) );

					if ( absint( $current['conversionGeneration'] ?? 0 ) !== $generation || self::STATUS_CONVERTING !== $current['status'] ) {
						return $current;
					}

					$current['googleFileId']       = $google_id;
					$current['thumbnailCache']     = (array) ( $result['thumbnailCache'] ?? array() );
					$current['imageCache']         = (array) ( $result['imageCache'] ?? array() );
					$current['sourceCount']        = array( 'slides' => absint( $result['slideCount'] ?? 0 ) );
					$current['conversionProgress'] = $this->progress( 'thumbnails', absint( $result['progress']['done'] ?? 0 ), absint( $result['progress']['total'] ?? 0 ) );
					$current['nextConversionAt']   = $retry_after > 0 ? $next_tick : 0;

					return $current;
				}
			);

			$this->scheduleConversion( $session_id, $file_id, $next_tick );
			$this->spawnCron();

			return;
		}

		$document = $result['document'] ?? null;

		if ( ! $document instanceof CanonicalDocument ) {
			$this->handleConversionError( $session_id, $file_id, $generation, $owner, $this->withCreated( new WP_Error( 'docsync_wp_import_conversion_failed', __( 'This file could not be converted.', 'brasth-document-sync-for-google-docs' ), array( 'status' => 500 ) ), $temporaries ) );

			return;
		}

		$session = $this->sessions->getForWorker( $session_id );
		$file    = null !== $session ? $this->findFile( $session, $file_id ) : null;

		if ( null === $file ) {
			return;
		}

		$stored = $this->assets->put( $session_id, (string) $file['canonicalKey'], (string) wp_json_encode( $document->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION ) );

		if ( is_wp_error( $stored ) ) {
			$this->handleConversionError( $session_id, $file_id, $generation, $owner, $this->withCreated( $stored, $temporaries ) );

			return;
		}

		$this->updateFile(
			$session_id,
			$file_id,
			function ( array $current ) use ( $temporaries, $google_id, $result, $generation, $document ): array {
				$current['googleTemporaries'] = array_values( array_unique( array_merge( (array) $current['googleTemporaries'], $temporaries ) ) );

				if ( absint( $current['conversionGeneration'] ?? 0 ) !== $generation || self::STATUS_CONVERTING !== $current['status'] ) {
					return $current;
				}

				return $this->convertedFile( $current, $document, $google_id, $result );
			}
		);
	}

	/**
	 * File record after a complete conversion, with defaults and derived data.
	 *
	 * @param array<string,mixed> $file      File record.
	 * @param CanonicalDocument   $document  Stored document.
	 * @param string              $google_id Converted Google file ID ('' for PDF).
	 * @param array<string,mixed> $result    Converter result.
	 * @return array<string,mixed>
	 */
	private function convertedFile( array $file, CanonicalDocument $document, string $google_id, array $result ): array {
		$touched = (array) ( $file['optionsTouched'] ?? array() );
		$options = $file['options'];

		if ( empty( $touched['title'] ) && '' !== trim( $document->getTitle() ) ) {
			$options['title'] = $this->limitTitle( $document->getTitle() );
		}

		$source = $document->toArray()['source'];

		if ( UploadValidator::FORMAT_PPTX === $file['format'] ) {
			$detection         = CanonicalDocument::detectPptx( $document );
			$file['detection'] = $detection;
			$slide_count       = absint( $source['slides'] ?? 0 );

			if ( empty( $touched['pptxSlides'] ) ) {
				$skipped                   = array_column( $detection['suggestedSkips'], 'slide' );
				$options['pptx']['slides'] = array_values( array_diff( range( 1, max( 1, $slide_count ) ), $skipped ) );
			}

			if ( empty( $touched['pptxIncludeNotes'] ) ) {
				$options['pptx']['includeNotes'] = $detection['hasNotes'];
			}

			$file['sourceCount']     = array( 'slides' => $slide_count );
			$file['thumbnailCache']  = (array) ( $result['thumbnailCache'] ?? array() );
			$file['imageCache']      = (array) ( $result['imageCache'] ?? array() );
			$file['slideThumbnails'] = (array) ( $result['slideThumbnails'] ?? array() );
		}

		if ( UploadValidator::FORMAT_PDF === $file['format'] ) {
			$page_count          = absint( $result['pageCount'] ?? ( $source['pages'] ?? 0 ) );
			$file['sourceCount'] = array( 'pages' => $page_count );

			if ( empty( $touched['pdfPages'] ) || array() === (array) $options['pdf']['pages'] ) {
				$options['pdf']['pages'] = range( 1, max( 1, $page_count ) );
			}
		}

		$index = array();

		foreach ( $document->getAssets() as $asset ) {
			$index[ $asset['assetId'] ] = array(
				'kind'     => $asset['kind'],
				'mimeType' => $asset['mimeType'],
				'status'   => $asset['status'],
				'width'    => $asset['width'],
				'height'   => $asset['height'],
				'byteSize' => $asset['byteSize'],
				'sha256'   => $asset['sha256'],
				'page'     => isset( $asset['origin']['page'] ) ? (int) $asset['origin']['page'] : null,
				'widthPt'  => isset( $asset['origin']['bounds'] ) ? (float) $asset['origin']['bounds']['width'] : null,
				'heightPt' => isset( $asset['origin']['bounds'] ) ? (float) $asset['origin']['bounds']['height'] : null,
			);
		}

		$file['options']            = $options;
		$file['assetIndex']         = $index;
		$file['googleFileId']       = $google_id;
		$file['conversionProgress'] = null;
		$file['conversionAttempts'] = 0;
		$file['nextConversionAt']   = 0;
		$file['error']              = null;
		$file['status']             = self::STATUS_READY;

		return $this->refreshDerived( $file, $document );
	}

	/**
	 * Recompute statistics, warnings, pending renders, slide warning numbers, status, and fingerprint.
	 *
	 * @param array<string,mixed> $file     File record (options and asset index current).
	 * @param CanonicalDocument   $document Stored document with current asset statuses.
	 * @return array<string,mixed>
	 */
	private function refreshDerived( array $file, CanonicalDocument $document ): array {
		$effective = $document->applyOptions( $file['options'] );
		$preset    = $this->renderer->resolvePreset( (string) $file['options']['layoutPreset'] );
		$pending   = array();

		foreach ( $effective->getAssets() as $asset ) {
			if ( 'pdfPageRender' === $asset['kind'] && 'pendingRender' === $asset['status'] ) {
				$pending[] = $this->pendingRender( $asset );
			}
		}

		$by_slide = array();

		foreach ( $effective->getWarnings() as $warning ) {
			if ( isset( $warning['origin']['slide'] ) ) {
				$by_slide[ (int) $warning['origin']['slide'] ][] = (int) $warning['number'];
			}
		}

		foreach ( (array) ( $file['slideThumbnails'] ?? array() ) as $position => $thumbnail ) {
			$file['slideThumbnails'][ $position ]['warningNumbers'] = $by_slide[ (int) $thumbnail['slide'] ] ?? array();
		}

		$file['statistics']         = $effective->getStatistics();
		$file['warnings']           = $effective->getWarnings();
		$file['pendingRenders']     = $pending;
		$file['previewFingerprint'] = $this->renderer->previewFingerprint( $effective, $file['options'], $preset );

		if ( in_array( $file['status'], array( self::STATUS_NEEDS_RENDER, self::STATUS_READY ), true ) ) {
			$file['status'] = array() === $pending ? self::STATUS_READY : self::STATUS_NEEDS_RENDER;
		}

		return $file;
	}

	/**
	 * One pending PDF render for the browser.
	 *
	 * Keeps assetId, page, widthPt, and heightPt. Adds x, y, width, and height
	 * only when the canonical top-left bounds are finite and inside the
	 * converter page limit. width and height repeat widthPt and heightPt so
	 * the cropped canvas matches the PNG aspect check.
	 *
	 * @param array<string,mixed> $asset Canonical pdfPageRender asset.
	 * @return array<string,mixed>
	 */
	private function pendingRender( array $asset ): array {
		$origin = is_array( $asset['origin'] ?? null ) ? $asset['origin'] : array();
		$bounds = is_array( $origin['bounds'] ?? null ) ? $origin['bounds'] : array();
		$row    = array(
			'assetId'  => $asset['assetId'] ?? '',
			'page'     => (int) ( $origin['page'] ?? 0 ),
			'widthPt'  => isset( $bounds['width'] ) && is_numeric( $bounds['width'] ) ? (float) $bounds['width'] : 0.0,
			'heightPt' => isset( $bounds['height'] ) && is_numeric( $bounds['height'] ) ? (float) $bounds['height'] : 0.0,
		);
		$crop   = $this->validatedCropBounds( $bounds );

		if ( null !== $crop ) {
			$row['x']      = $crop['x'];
			$row['y']      = $crop['y'];
			$row['width']  = $crop['width'];
			$row['height'] = $crop['height'];
		}

		return $row;
	}

	/**
	 * Top-left crop in points, or null when the bounds cannot be rendered.
	 *
	 * @param array<string,mixed> $bounds Origin bounds.
	 * @return array{x:float,y:float,width:float,height:float}|null
	 */
	private function validatedCropBounds( array $bounds ): ?array {
		$x      = isset( $bounds['x'] ) && is_numeric( $bounds['x'] ) ? (float) $bounds['x'] : null;
		$y      = isset( $bounds['y'] ) && is_numeric( $bounds['y'] ) ? (float) $bounds['y'] : null;
		$width  = isset( $bounds['width'] ) && is_numeric( $bounds['width'] ) ? (float) $bounds['width'] : null;
		$height = isset( $bounds['height'] ) && is_numeric( $bounds['height'] ) ? (float) $bounds['height'] : null;

		if ( null === $x || null === $y || null === $width || null === $height ) {
			return null;
		}

		foreach ( array( $x, $y, $width, $height ) as $value ) {
			if ( ! is_finite( $value ) ) {
				return null;
			}
		}

		if ( $x < 0 || $y < 0 || $width <= 0 || $height <= 0 ) {
			return null;
		}

		if ( $x > 20000 || $y > 20000 || $width > 20000 || $height > 20000 ) {
			return null;
		}

		return array(
			'x'      => $x,
			'y'      => $y,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * Stored document with render statuses from the file's asset index applied.
	 *
	 * @param array<string,mixed> $file      File record.
	 * @param string              $canonical Canonical JSON.
	 * @return CanonicalDocument|WP_Error
	 */
	private function documentFor( array $file, string $canonical ): CanonicalDocument|WP_Error {
		$data     = json_decode( $canonical, true );
		$document = is_array( $data ) ? CanonicalDocument::fromArray( $data ) : new WP_Error( 'docsync_wp_import_canonical_invalid', __( 'Brasth Document Sync could not read the converted document.', 'brasth-document-sync-for-google-docs' ), array( 'status' => 500 ) );

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

		return $document;
	}

	/**
	 * Private asset URLs (with REST nonce) for every ready asset.
	 *
	 * @param string            $session_id Session ID.
	 * @param string            $file_id    File ID.
	 * @param CanonicalDocument $document   Effective document.
	 * @return array<string,string>
	 */
	private function assetUrls( string $session_id, string $file_id, CanonicalDocument $document ): array {
		$nonce = wp_create_nonce( 'wp_rest' );
		$urls  = array();

		foreach ( $document->getAssets() as $asset ) {
			if ( 'ready' !== $asset['status'] ) {
				continue;
			}

			$urls[ $asset['assetId'] ] = add_query_arg(
				'_wpnonce',
				$nonce,
				rest_url( sprintf( '%s/imports/sessions/%s/files/%s/assets/%s', RestServiceProvider::NAMESPACE, $session_id, $file_id, $asset['assetId'] ) )
			);
		}

		return $urls;
	}

	/**
	 * Validate a partial option change.
	 *
	 * @param array<string,mixed> $file    File record.
	 * @param array<string,mixed> $options Partial options.
	 * @param int                 $user_id Caller.
	 * @return array{options:array<string,mixed>,touched:array<string,bool>,reconvert:bool}|WP_Error
	 */
	private function validateOptionChange( array $file, array $options, int $user_id ): array|WP_Error {
		$format  = (string) $file['format'];
		$allowed = array( 'title', 'target', 'layoutPreset', $format );

		if ( array() === $options || array() !== array_diff( array_keys( $options ), $allowed ) ) {
			return $this->invalidOptionsError();
		}

		$change    = array();
		$touched   = array();
		$reconvert = false;

		if ( array_key_exists( 'title', $options ) ) {
			$title = is_string( $options['title'] ) ? $this->limitTitle( sanitize_text_field( $options['title'] ) ) : '';

			if ( '' === $title ) {
				return $this->invalidOptionsError();
			}

			$change['title']  = $title;
			$touched['title'] = true;
		}

		if ( array_key_exists( 'target', $options ) ) {
			$target = $options['target'];

			if ( ! is_array( $target ) || array( 'postType' ) !== array_keys( $target ) || ! is_string( $target['postType'] ) ) {
				return $this->invalidOptionsError();
			}

			$post_type = sanitize_key( $target['postType'] );

			if ( ! $this->source_repository->userCanCreateSyncedPost( $post_type, $user_id ) ) {
				return $this->postTypeForbiddenError();
			}

			$change['target'] = array( 'postType' => $post_type );
		}

		if ( array_key_exists( 'layoutPreset', $options ) ) {
			$preset = is_string( $options['layoutPreset'] ) ? sanitize_key( $options['layoutPreset'] ) : null;

			if ( null === $preset || ( '' !== $preset && $this->renderer->resolvePreset( $preset ) !== $preset ) ) {
				return $this->invalidOptionsError();
			}

			$change['layoutPreset'] = $preset;
		}

		if ( array_key_exists( $format, $options ) ) {
			$specific = $options[ $format ];

			if ( ! is_array( $specific ) || array() === $specific ) {
				return $this->invalidOptionsError();
			}

			$checked = match ( $format ) {
				UploadValidator::FORMAT_DOCX => $this->validateDocxOptions( $specific, $user_id ),
				UploadValidator::FORMAT_PPTX => $this->validatePptxOptions( $specific, absint( $file['sourceCount']['slides'] ?? 0 ) ),
				default => $this->validatePdfOptions( $specific, absint( $file['sourceCount']['pages'] ?? 0 ) ),
			};

			if ( is_wp_error( $checked ) ) {
				return $checked;
			}

			$stored_pdf        = isset( $file['options']['pdf'] ) && is_array( $file['options']['pdf'] ) ? $file['options']['pdf'] : array();
			$stored_pages      = $stored_pdf['pages'] ?? array();
			$stored_mode       = $stored_pdf['renderMode'] ?? '';
			$change[ $format ] = $checked['options'];
			$touched           = array_merge( $touched, $checked['touched'] );
			$reconvert         = UploadValidator::FORMAT_PDF === $format
				&& ( ( isset( $checked['options']['pages'] ) && $checked['options']['pages'] !== $stored_pages )
					|| ( isset( $checked['options']['renderMode'] ) && $checked['options']['renderMode'] !== $stored_mode ) );
		}

		return array(
			'options'   => $change,
			'touched'   => $touched,
			'reconvert' => $reconvert,
		);
	}

	/**
	 * Validate DOCX options.
	 *
	 * @param array<string,mixed> $options DOCX options.
	 * @param int                 $user_id Caller.
	 * @return array{options:array<string,mixed>,touched:array<string,bool>}|WP_Error
	 */
	private function validateDocxOptions( array $options, int $user_id ): array|WP_Error {
		if ( array( 'keepSynced' ) !== array_keys( $options ) || ! is_bool( $options['keepSynced'] ) ) {
			return $this->invalidOptionsError();
		}

		if ( $options['keepSynced'] && ! $this->drive_write->hasWriteScope( $user_id ) ) {
			return new WP_Error(
				'docsync_wp_google_write_scope_required',
				__( 'Allow Brasth Document Sync to create files in your Google Drive to keep this document synced.', 'brasth-document-sync-for-google-docs' ),
				array(
					'status'    => 403,
					'reconnect' => array( 'scopeSet' => 'driveFile' ),
				)
			);
		}

		return array(
			'options' => array( 'keepSynced' => $options['keepSynced'] ),
			'touched' => array(),
		);
	}

	/**
	 * Validate PPTX options.
	 *
	 * @param array<string,mixed> $options     PPTX options.
	 * @param int                 $slide_count Slide count (0 when not converted yet).
	 * @return array{options:array<string,mixed>,touched:array<string,bool>}|WP_Error
	 */
	private function validatePptxOptions( array $options, int $slide_count ): array|WP_Error {
		if ( array() !== array_diff( array_keys( $options ), array( 'slides', 'includeNotes', 'addSlideImages', 'mergeConsecutiveTitles' ) ) ) {
			return $this->invalidOptionsError();
		}

		$clean   = array();
		$touched = array();

		if ( array_key_exists( 'slides', $options ) ) {
			$slides = $this->validateNumberList( $options['slides'], $slide_count > 0 ? $slide_count : 200 );

			if ( null === $slides ) {
				return $this->invalidOptionsError();
			}

			$clean['slides']       = $slides;
			$touched['pptxSlides'] = true;
		}

		foreach ( array( 'includeNotes', 'addSlideImages', 'mergeConsecutiveTitles' ) as $flag ) {
			if ( array_key_exists( $flag, $options ) ) {
				if ( ! is_bool( $options[ $flag ] ) ) {
					return $this->invalidOptionsError();
				}

				$clean[ $flag ] = $options[ $flag ];

				if ( 'includeNotes' === $flag ) {
					$touched['pptxIncludeNotes'] = true;
				}
			}
		}

		return array(
			'options' => $clean,
			'touched' => $touched,
		);
	}

	/**
	 * Validate PDF options.
	 *
	 * @param array<string,mixed> $options    PDF options.
	 * @param int                 $page_count Page count (0 when not converted yet).
	 * @return array{options:array<string,mixed>,touched:array<string,bool>}|WP_Error
	 */
	private function validatePdfOptions( array $options, int $page_count ): array|WP_Error {
		if ( array() !== array_diff( array_keys( $options ), array( 'pages', 'renderMode' ) ) ) {
			return $this->invalidOptionsError();
		}

		$clean   = array();
		$touched = array();

		if ( array_key_exists( 'pages', $options ) ) {
			$pages = $this->validateNumberList( $options['pages'], $page_count > 0 ? $page_count : PdfConverter::MAX_PAGES );

			if ( null === $pages ) {
				return $this->invalidOptionsError();
			}

			$clean['pages']      = $pages;
			$touched['pdfPages'] = true;
		}

		if ( array_key_exists( 'renderMode', $options ) ) {
			if ( ! in_array( $options['renderMode'], array( 'auto', 'text', 'image' ), true ) ) {
				return $this->invalidOptionsError();
			}

			$clean['renderMode'] = $options['renderMode'];
		}

		return array(
			'options' => $clean,
			'touched' => $touched,
		);
	}

	/**
	 * Non-empty, unique, sorted list of integers within 1..$max.
	 *
	 * @param mixed $values Values.
	 * @param int   $max    Maximum.
	 * @return array<int,int>|null
	 */
	private function validateNumberList( mixed $values, int $max ): ?array {
		if ( ! is_array( $values ) || ! array_is_list( $values ) || array() === $values || count( $values ) > $max ) {
			return null;
		}

		$clean = array();

		foreach ( $values as $value ) {
			if ( ! is_int( $value ) || $value < 1 || $value > $max || isset( $clean[ $value ] ) ) {
				return null;
			}

			$clean[ $value ] = $value;
		}

		ksort( $clean );

		return array_values( $clean );
	}

	/**
	 * Merge a validated partial change into stored options.
	 *
	 * @param array<string,mixed> $options Stored options.
	 * @param array<string,mixed> $change  Validated change.
	 * @return array<string,mixed>
	 */
	private function mergeOptions( array $options, array $change ): array {
		foreach ( $change as $key => $value ) {
			$options[ $key ] = is_array( $value ) && isset( $options[ $key ] ) && is_array( $options[ $key ] ) && ! array_is_list( $value )
				? array_merge( $options[ $key ], $value )
				: $value;
		}

		return $options;
	}

	/**
	 * New stored file record.
	 *
	 * @param string              $file_id   File ID.
	 * @param array<string,mixed> $validated Validator result.
	 * @param array<string,mixed> $stored    Storage result.
	 * @param string              $post_type Default post type.
	 * @param bool                $has_scope Whether the owner has drive.file.
	 * @return array<string,mixed>
	 */
	private function newFileRecord( string $file_id, array $validated, array $stored, string $post_type, bool $has_scope ): array {
		$format  = (string) $validated['format'];
		$hex     = substr( $file_id, 2 );
		$options = array(
			'title'        => $this->limitTitle( (string) preg_replace( '/\.[A-Za-z0-9]{1,5}$/', '', (string) $validated['originalName'] ) ),
			'target'       => array( 'postType' => $post_type ),
			'layoutPreset' => '',
		);

		if ( '' === $options['title'] ) {
			$options['title'] = __( 'Untitled', 'brasth-document-sync-for-google-docs' );
		}

		if ( UploadValidator::FORMAT_DOCX === $format ) {
			$options['docx'] = array( 'keepSynced' => true );
		} elseif ( UploadValidator::FORMAT_PPTX === $format ) {
			$options['pptx'] = array(
				'slides'                 => array(),
				'includeNotes'           => false,
				'addSlideImages'         => false,
				'mergeConsecutiveTitles' => false,
			);
		} else {
			$options['pdf'] = array(
				'pages'      => array(),
				'renderMode' => 'auto',
			);
		}

		$needs_google = UploadValidator::FORMAT_PDF !== $format;
		$status       = $needs_google && ! $has_scope ? self::STATUS_AWAITING : self::STATUS_CONVERTING;

		return array(
			'fileId'               => $file_id,
			'originalName'         => (string) $validated['originalName'],
			'format'               => $format,
			'mimeType'             => (string) $validated['mimeType'],
			'byteSize'             => absint( $stored['byteSize'] ),
			'sha256'               => (string) $stored['sha256'],
			'originalKey'          => (string) $stored['key'],
			'canonicalKey'         => 'c_' . $hex . '.json',
			'status'               => $status,
			'error'                => null,
			'options'              => $options,
			'optionsTouched'       => array(),
			'detection'            => null,
			'statistics'           => null,
			'warnings'             => array(),
			'pendingRenders'       => array(),
			'previewFingerprint'   => null,
			'sourceCount'          => array(),
			'conversionProgress'   => self::STATUS_CONVERTING === $status ? $this->progress( UploadValidator::FORMAT_PDF === $format ? 'extracting' : 'uploading', 0, 1 ) : null,
			'slideThumbnails'      => array(),
			'originalAssetId'      => UploadValidator::FORMAT_PDF === $format ? 'a_' . $hex : null,
			'assetIndex'           => array(),
			'googleTemporaries'    => array(),
			'googleFileId'         => '',
			'commitState'          => null,
			'thumbnailCache'       => array(),
			'imageCache'           => array(),
			'conversionGeneration' => 0,
			'conversionAttempts'   => 0,
			'nextConversionAt'     => 0,
		);
	}

	/**
	 * Wire files for a session.
	 *
	 * Pending renders saved before crop bounds were stored are filled from the
	 * canonical asset origin so an open PDF can render the real crop.
	 *
	 * @param array<string,mixed> $session Stored session.
	 * @return array<int,array<string,mixed>>
	 */
	private function formatSessionFiles( array $session ): array {
		$files      = array();
		$session_id = (string) ( $session['sessionId'] ?? '' );

		foreach ( (array) ( $session['files'] ?? array() ) as $file ) {
			if ( ! is_array( $file ) ) {
				continue;
			}

			$files[] = $this->formatFile( $this->attachStoredCropBounds( $file, $session_id ) );
		}

		return $files;
	}

	/**
	 * Add canonical top-left crop bounds to pending rows that do not have them.
	 *
	 * Stored rows keep assetId, page, widthPt, and heightPt. A canonical read
	 * failure leaves the stored rows unchanged.
	 *
	 * @param array<string,mixed> $file       Stored file.
	 * @param string              $session_id Session ID.
	 * @return array<string,mixed>
	 */
	private function attachStoredCropBounds( array $file, string $session_id ): array {
		$pending = $file['pendingRenders'] ?? null;

		if ( ! is_array( $pending ) || array() === $pending || '' === $session_id ) {
			return $file;
		}

		$missing = false;

		foreach ( $pending as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['x'], $row['y'], $row['width'], $row['height'] ) ) {
				$missing = true;
				break;
			}
		}

		if ( ! $missing ) {
			return $file;
		}

		$canonical = $this->assets->read( $session_id, (string) ( $file['canonicalKey'] ?? '' ) );

		if ( is_wp_error( $canonical ) || ! is_string( $canonical ) || '' === $canonical ) {
			return $file;
		}

		$document = $this->documentFor( $file, $canonical );

		if ( is_wp_error( $document ) ) {
			return $file;
		}

		$by_id = array();

		foreach ( $document->getAssets() as $asset ) {
			if ( isset( $asset['assetId'] ) ) {
				$by_id[ (string) $asset['assetId'] ] = $asset;
			}
		}

		foreach ( $pending as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$asset = $by_id[ (string) ( $row['assetId'] ?? '' ) ] ?? null;

			if ( ! is_array( $asset ) ) {
				continue;
			}

			$crop = $this->validatedCropBounds( is_array( $asset['origin']['bounds'] ?? null ) ? $asset['origin']['bounds'] : array() );

			if ( null === $crop ) {
				continue;
			}

			foreach ( array( 'x', 'y', 'width', 'height' ) as $key ) {
				if ( ! isset( $row[ $key ] ) ) {
					$row[ $key ] = $crop[ $key ];
				}
			}

			$pending[ $index ] = $row;
		}

		$file['pendingRenders'] = $pending;

		return $file;
	}

	/**
	 * Wire shape of a stored file.
	 *
	 * @param array<string,mixed> $file Stored file.
	 * @return array<string,mixed>
	 */
	private function formatFile( array $file ): array {
		$format  = (string) ( $file['format'] ?? '' );
		$options = (array) ( $file['options'] ?? array() );
		$wire    = array(
			'title'        => (string) ( $options['title'] ?? '' ),
			'target'       => array( 'postType' => (string) ( $options['target']['postType'] ?? '' ) ),
			'layoutPreset' => (string) ( $options['layoutPreset'] ?? '' ),
		);

		if ( isset( $options[ $format ] ) && is_array( $options[ $format ] ) ) {
			$wire[ $format ] = $options[ $format ];
		}

		$status = (string) ( $file['status'] ?? '' );

		return array(
			'fileId'             => (string) ( $file['fileId'] ?? '' ),
			'originalName'       => (string) ( $file['originalName'] ?? '' ),
			'format'             => $format,
			'byteSize'           => absint( $file['byteSize'] ?? 0 ),
			'sha256'             => (string) ( $file['sha256'] ?? '' ),
			'status'             => $status,
			'error'              => is_array( $file['error'] ?? null ) ? $file['error'] : null,
			'options'            => $wire,
			'detection'          => UploadValidator::FORMAT_PPTX === $format && is_array( $file['detection'] ?? null ) ? $file['detection'] : null,
			'statistics'         => is_array( $file['statistics'] ?? null ) ? $file['statistics'] : null,
			'warnings'           => (array) ( $file['warnings'] ?? array() ),
			'pendingRenders'     => (array) ( $file['pendingRenders'] ?? array() ),
			'previewFingerprint' => is_string( $file['previewFingerprint'] ?? null ) ? $file['previewFingerprint'] : null,
			'sourceCount'        => (object) ( (array) ( $file['sourceCount'] ?? array() ) ),
			'conversionProgress' => self::STATUS_CONVERTING === $status && is_array( $file['conversionProgress'] ?? null ) ? $file['conversionProgress'] : null,
			'slideThumbnails'    => UploadValidator::FORMAT_PPTX === $format ? array_values( (array) ( $file['slideThumbnails'] ?? array() ) ) : array(),
			'originalAssetId'    => UploadValidator::FORMAT_PDF === $format ? ( $file['originalAssetId'] ?? null ) : null,
		);
	}

	/**
	 * Wire shape of a stored commit result.
	 *
	 * @param mixed $result Stored result.
	 * @return array<string,mixed>|null
	 */
	private function formatResult( mixed $result ): ?array {
		if ( ! is_array( $result ) ) {
			return null;
		}

		$files = array();

		foreach ( (array) ( $result['files'] ?? array() ) as $entry ) {
			$post_id   = isset( $entry['postId'] ) ? absint( $entry['postId'] ) : 0;
			$edit_link = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : null;
			$synced    = 'syncedWord' === ( $entry['provenance'] ?? null );

			$files[] = array(
				'fileId'       => (string) ( $entry['fileId'] ?? '' ),
				'status'       => (string) ( $entry['status'] ?? 'skipped' ),
				'postId'       => $post_id > 0 ? $post_id : null,
				'editUrl'      => is_string( $edit_link ) ? esc_url_raw( $edit_link ) : null,
				'postStatus'   => $post_id > 0 && 'created' === ( $entry['status'] ?? '' ) ? 'draft' : null,
				'provenance'   => in_array( $entry['provenance'] ?? null, array( 'syncedWord', 'oneTime' ), true ) ? $entry['provenance'] : null,
				'googleFileId' => $synced ? (string) $entry['googleFileId'] : null,
				'googleDocUrl' => $synced ? (string) $entry['googleDocUrl'] : null,
				'sourceStatus' => $synced ? 'linked' : null,
				'error'        => is_array( $entry['error'] ?? null ) ? $entry['error'] : null,
			);
		}

		return array(
			'idempotencyKey' => (string) ( $result['idempotencyKey'] ?? '' ),
			'startedAt'      => (string) ( $result['startedAt'] ?? '' ),
			'finishedAt'     => isset( $result['finishedAt'] ) && is_string( $result['finishedAt'] ) ? $result['finishedAt'] : null,
			'files'          => $files,
		);
	}

	/**
	 * Purge a cancelled session's bytes and Google temporaries.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $owner      Owner user ID.
	 * @return bool Whether cleanup is still pending.
	 */
	private function releaseCancelled( string $session_id, int $owner ): bool {
		$locked = $this->sessions->lock( $session_id );

		try {
			$this->assets->deleteSession( $session_id );

			$session = $this->sessions->getForWorker( $session_id );

			if ( null === $session ) {
				return false;
			}

			$ids = array();

			foreach ( $session['files'] as $file ) {
				$ids = array_merge( $ids, array_map( 'strval', (array) ( $file['googleTemporaries'] ?? array() ) ) );
			}

			$failed    = $this->trashTemporaries( $owner, $ids );
			$resolved  = array_values( array_diff( $ids, $failed ) );
			$remaining = false;
			$saved     = $this->mutate(
				$session_id,
				static function ( array $current ) use ( $resolved, &$remaining ): array {
					$remaining = false;

					foreach ( $current['files'] as $index => $file ) {
						$current['files'][ $index ]['googleTemporaries'] = array_values( array_diff( (array) $file['googleTemporaries'], $resolved ) );
						$remaining                                       = $remaining || array() !== $current['files'][ $index ]['googleTemporaries'];
					}

					return $current;
				}
			);

			if ( is_wp_error( $saved ) ) {
				return true;
			}

			if ( ! $remaining && $locked && $this->sessions->delete( $session_id ) ) {
				$locked = false;

				return false;
			}

			return true;
		} finally {
			if ( $locked ) {
				$this->sessions->unlock( $session_id );
			}
		}
	}

	/**
	 * Remove resolved IDs from a file's cleanup set (failed IDs stay).
	 *
	 * @param string            $session_id Session ID.
	 * @param string            $file_id    File ID.
	 * @param array<int,string> $ids        IDs that were trashed or attempted.
	 * @param array<int,string> $failed     IDs whose trash failed.
	 */
	private function removeResolved( string $session_id, string $file_id, array $ids, array $failed ): void {
		$resolved = array_values( array_diff( $ids, $failed ) );

		if ( array() === $resolved ) {
			return;
		}

		$this->updateFile(
			$session_id,
			$file_id,
			static function ( array $current ) use ( $resolved ): array {
				$current['googleTemporaries'] = array_values( array_diff( (array) $current['googleTemporaries'], $resolved ) );

				if ( in_array( (string) $current['googleFileId'], $resolved, true ) && self::STATUS_COMMITTED !== $current['status'] ) {
					$current['googleFileId'] = '';
				}

				return $current;
			}
		);
	}

	/**
	 * Mark a file failed outside the normal error path.
	 *
	 * @param string   $session_id Session ID.
	 * @param string   $file_id    File ID.
	 * @param int      $generation Generation, or -1 for any.
	 * @param WP_Error $error      Error.
	 */
	private function failFile( string $session_id, string $file_id, int $generation, WP_Error $error ): void {
		$this->updateFile(
			$session_id,
			$file_id,
			function ( array $current ) use ( $generation, $error ): array {
				if ( self::STATUS_CONVERTING !== $current['status'] || ( $generation >= 0 && absint( $current['conversionGeneration'] ?? 0 ) !== $generation ) ) {
					return $current;
				}

				return $this->failedFile( $current, $error );
			}
		);
	}

	/**
	 * File record in failed state.
	 *
	 * @param array<string,mixed> $file  File.
	 * @param WP_Error            $error Error.
	 * @return array<string,mixed>
	 */
	private function failedFile( array $file, WP_Error $error ): array {
		$file['status']             = self::STATUS_FAILED;
		$file['conversionProgress'] = null;
		$file['previewFingerprint'] = null;
		$file['error']              = array(
			'code'    => $error->get_error_code(),
			'message' => $error->get_error_message(),
		);

		return $file;
	}

	/**
	 * Apply a change to one file with compare-and-swap retries.
	 *
	 * @param string   $session_id Session ID.
	 * @param string   $file_id    File ID.
	 * @param callable $change     Receives and returns the file record.
	 * @return array<string,mixed>|WP_Error Saved session.
	 */
	private function updateFile( string $session_id, string $file_id, callable $change ): array|WP_Error {
		return $this->mutate(
			$session_id,
			function ( array $current ) use ( $file_id, $change ): array {
				$index = $this->fileIndex( $current, $file_id );

				if ( null !== $index ) {
					$current['files'][ $index ] = $change( $current['files'][ $index ] );
				}

				return $current;
			}
		);
	}

	/**
	 * Read-modify-write a session with compare-and-swap retries.
	 *
	 * The change callback must be free of side effects: it can run more than
	 * once when another request saves the session in between.
	 *
	 * @param string   $session_id Session ID.
	 * @param callable $change     Receives the session; returns the new session or WP_Error.
	 * @return array<string,mixed>|WP_Error Saved session.
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

			if ( is_wp_error( $next ) ) {
				return $next;
			}

			if ( $next === $session ) {
				return $session;
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
	 * Whether a session is open and not expired.
	 *
	 * @param array<string,mixed> $session Session.
	 */
	private function isLiveOpen( array $session ): bool {
		return ImportSessionRepository::STATUS_OPEN === $session['status'] && empty( $session['cleanupPending'] ) && absint( $session['expiresAt'] ) > time();
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
		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
			return null;
		}

		foreach ( $session['files'] as $index => $file ) {
			if ( ( $file['fileId'] ?? '' ) === $file_id ) {
				return (int) $index;
			}
		}

		return null;
	}

	/**
	 * Default target post type: `post` when allowed, else the first creatable enabled type.
	 *
	 * @param int $user_id User ID.
	 */
	private function defaultPostType( int $user_id ): string {
		$types = $this->source_repository->getEnabledPostTypes();

		if ( in_array( 'post', $types, true ) && $this->source_repository->userCanCreateSyncedPost( 'post', $user_id ) ) {
			return 'post';
		}

		foreach ( $types as $type ) {
			if ( $this->source_repository->userCanCreateSyncedPost( (string) $type, $user_id ) ) {
				return (string) $type;
			}
		}

		return '';
	}

	/**
	 * Schedule a conversion tick.
	 *
	 * @param string $session_id Session ID.
	 * @param string $file_id    File ID.
	 * @param int    $timestamp  Run time.
	 */
	private function scheduleConversion( string $session_id, string $file_id, int $timestamp ): void {
		if ( 1 !== preg_match( self::SESSION_PATTERN, $session_id ) || 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
			return;
		}

		$args     = array( $session_id, $file_id );
		$existing = wp_next_scheduled( self::CONVERT_HOOK, $args );

		if ( false !== $existing && $existing <= $timestamp ) {
			return;
		}

		if ( false !== $existing ) {
			wp_unschedule_event( $existing, self::CONVERT_HOOK, $args );
		}

		wp_schedule_single_event( $timestamp, self::CONVERT_HOOK, $args );
	}

	/**
	 * Spawn WP-Cron so queued work starts promptly.
	 */
	private function spawnCron(): void {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Conversion progress value.
	 *
	 * @param string $step  uploading, converting, thumbnails, or extracting.
	 * @param int    $done  Completed units.
	 * @param int    $total Total units.
	 * @return array{step:string,done:int,total:int}
	 */
	private function progress( string $step, int $done, int $total ): array {
		return array(
			'step'  => $step,
			'done'  => $done,
			'total' => max( $done, $total ),
		);
	}

	/**
	 * Per-file rejection entry.
	 *
	 * @param string   $name  Original name.
	 * @param WP_Error $error Error.
	 * @return array{originalName:string,code:string,message:string}
	 */
	private function rejection( string $name, WP_Error $error ): array {
		return array(
			'originalName' => $name,
			'code'         => (string) $error->get_error_code(),
			'message'      => $error->get_error_message(),
		);
	}

	/**
	 * Attach created IDs to an error.
	 *
	 * @param WP_Error          $error       Error.
	 * @param array<int,string> $temporaries IDs.
	 */
	private function withCreated( WP_Error $error, array $temporaries ): WP_Error {
		$data                      = $error->get_error_data();
		$data                      = is_array( $data ) ? $data : array( 'status' => 500 );
		$data['googleTemporaries'] = $temporaries;

		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}

	/**
	 * Title limited to 200 characters.
	 *
	 * @param string $title Title.
	 */
	private function limitTitle( string $title ): string {
		$title = trim( $title );

		return function_exists( 'mb_substr' ) ? mb_substr( $title, 0, self::MAX_TITLE_LENGTH ) : substr( $title, 0, self::MAX_TITLE_LENGTH );
	}

	/**
	 * Random prefixed 16-hex ID.
	 *
	 * @param string $prefix Prefix.
	 */
	private function newId( string $prefix ): string {
		try {
			return $prefix . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $exception ) {
			return $prefix . substr( hash( 'sha256', wp_generate_uuid4() ), 0, 16 );
		}
	}

	/**
	 * ISO-8601 UTC timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function iso( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
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
	 * Session committing error.
	 */
	private function committingError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_session_committing',
			__( 'This import is being added to WordPress and cannot be cancelled now.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * File limit error.
	 */
	private function fileLimitError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_file_limit',
			__( 'An import can hold at most 20 files.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Missing file error.
	 */
	private function fileNotFoundError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_file_not_found',
			__( 'Brasth Document Sync could not find this file in the import.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * File not ready error.
	 */
	private function fileNotReadyError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_file_not_ready',
			__( 'This file is not ready yet.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Asset not found error.
	 */
	private function assetNotFoundError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_asset_not_found',
			__( 'Brasth Document Sync could not find this private file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Asset not pending error.
	 */
	private function assetNotPendingError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_asset_not_pending',
			__( 'This page image is not waiting for a render.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Invalid options error.
	 */
	private function invalidOptionsError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_invalid_options',
			__( 'These import options are not valid for this file.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Post type forbidden error.
	 */
	private function postTypeForbiddenError(): WP_Error {
		return new WP_Error(
			'docsync_wp_import_post_type_forbidden',
			__( 'You cannot create drafts of this content type.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 403 )
		);
	}
}
