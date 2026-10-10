<?php
/**
 * Bulk linking of existing posts to Google Docs.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Matching;

use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Google\DocsClient;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Google\DriveWriteClient;
use DocSyncWP\Sync\SourceBatchService;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Finds Google Docs that match unlinked posts and links chosen pairs attach-only.
 *
 * A job runs in bounded background ticks. The inventory phase lists the scope
 * recursively through a FIFO queue of folder pages (every descendant folder,
 * every result page) within fixed limits; any truncation records a warning and
 * makes the inventory incomplete. The matching phase reads each candidate Doc
 * once per version and scores it against every post.
 *
 * A row is preselected only for the unique exact normalized-title match, or
 * else the unique exact first-100-token match with at least 20 tokens on both
 * sides, when that Doc is claimed by no other post, is not linked elsewhere,
 * and the inventory is complete. Duplicates, rule disagreements, and
 * approximate matches are never preselected. Commits are one-to-one and use
 * `SourceBatchService::attachOnly`, which saves source metadata only: post
 * content, revisions, and the modified date are never touched by a link.
 */
final class MatchingService {
	public const RUN_HOOK = 'docsync_wp_matching_run';

	public const LIST_PAGES_PER_TICK = 5;
	public const MATCH_DOCS_PER_TICK = 10;
	public const LIST_RETRY_LIMIT    = 3;
	public const MAX_CANDIDATE_DOCS  = 200;
	public const MAX_FOLDERS         = 500;
	public const MAX_FOLDER_DEPTH    = 10;

	private const MAX_POSTS              = 100;
	private const MAX_FILE_IDS           = 200;
	private const MAX_CANDIDATES         = 5;
	private const MAX_WARNINGS           = 100;
	private const MAX_COMPARES           = 300;
	private const MAX_IDEMPOTENCY        = 50;
	private const POST_SCAN_LIMIT        = 2000;
	private const POST_FEATURES_PER_TICK = 50;
	private const LIST_PAGE_SIZE         = 50;
	private const DIFF_TOKENS            = 2000;
	private const EXCERPT_WORDS          = 55;
	private const RETRY_BASE_SECONDS     = 30;
	private const LOCK_RETRY_SECONDS     = 30;
	private const SAVE_RETRY_SECONDS     = 60;
	private const CLEANUP_BASE_SECONDS   = HOUR_IN_SECONDS;
	private const CLEANUP_MAX_SECONDS    = DAY_IN_SECONDS;
	private const DISCARD_SAVE_ATTEMPTS  = 3;
	private const KEY_PATTERN            = '/^[A-Za-z0-9-]{16,64}$/';
	private const FILE_ID_PATTERN        = '/^[A-Za-z0-9_-]{10,200}$/';
	private const FINGERPRINT_PATTERN    = '/^[a-f0-9]{64}$/';
	private const LOCATIONS              = array( 'myDrive', 'sharedWithMe', 'sharedDrive', 'recent', 'starred' );
	private const FLAT_LOCATIONS         = array( 'sharedWithMe', 'recent', 'starred' );
	private const ACTIVE_STATUSES        = array( 'queued', 'listing', 'running' );
	private const REVIEW_STATUSES        = array( 'ready', 'committed' );
	private const EXCLUDED_POST_STATUSES = array( 'trash', 'auto-draft', 'inherit' );
	private const FATAL_ERROR_CODES      = array( 'docsync_wp_not_connected', 'docsync_wp_google_reconnect_required', 'docsync_wp_docs_api_unavailable' );
	private const KIND_RANK              = array(
		'exactTitle'         => 0,
		'exactLeadingTokens' => 1,
		'approximate'        => 2,
	);

	/**
	 * Job storage.
	 *
	 * @var MatchSessionRepository
	 */
	private MatchSessionRepository $jobs;

	/**
	 * Text normalizer.
	 *
	 * @var MatchNormalizer
	 */
	private MatchNormalizer $normalizer;

	/**
	 * Drive read client.
	 *
	 * @var DriveClient
	 */
	private DriveClient $drive_client;

	/**
	 * Docs API client.
	 *
	 * @var DocsClient
	 */
	private DocsClient $docs_client;

	/**
	 * Drive write client.
	 *
	 * @var DriveWriteClient
	 */
	private DriveWriteClient $drive_write;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Attach-only link service.
	 *
	 * @var SourceBatchService
	 */
	private SourceBatchService $source_batch;

	/**
	 * Constructor.
	 *
	 * @param MatchSessionRepository $jobs              Job storage.
	 * @param MatchNormalizer        $normalizer        Text normalizer.
	 * @param DriveClient            $drive_client      Drive read client.
	 * @param DocsClient             $docs_client       Docs API client.
	 * @param DriveWriteClient       $drive_write       Drive write client.
	 * @param SourceRepository       $source_repository Source repository.
	 * @param SourceBatchService     $source_batch      Attach-only link service.
	 */
	public function __construct(
		MatchSessionRepository $jobs,
		MatchNormalizer $normalizer,
		DriveClient $drive_client,
		DocsClient $docs_client,
		DriveWriteClient $drive_write,
		SourceRepository $source_repository,
		SourceBatchService $source_batch
	) {
		$this->jobs              = $jobs;
		$this->normalizer        = $normalizer;
		$this->drive_client      = $drive_client;
		$this->docs_client       = $docs_client;
		$this->drive_write       = $drive_write;
		$this->source_repository = $source_repository;
		$this->source_batch      = $source_batch;
	}

	/**
	 * Register the background tick hook.
	 */
	public function register(): void {
		add_action( self::RUN_HOOK, array( $this, 'runJob' ), 10, 1 );
	}

	/**
	 * Validate the request, choose the posts, and queue a new job.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $input   `{posts:{postType,postIds?},scope:{location,folderId,driveId,fileIds}}`.
	 * @return array<string,mixed>|WP_Error Formatted job.
	 */
	public function createJob( int $user_id, array $input ): array|WP_Error {
		if ( $user_id <= 0 ) {
			return new WP_Error(
				'docsync_wp_not_connected',
				__( 'You must be logged in before linking posts to Google Docs.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 401 )
			);
		}

		if ( array() !== array_diff( array_keys( $input ), array( 'posts', 'scope' ) ) || ! is_array( $input['posts'] ?? null ) || ! is_array( $input['scope'] ?? null ) ) {
			return $this->invalidInputError();
		}

		$posts = $this->resolvePosts( $user_id, $input['posts'] );

		if ( is_wp_error( $posts ) ) {
			return $posts;
		}

		$scope = $this->normalizeScope( $input['scope'] );

		if ( is_wp_error( $scope ) ) {
			return $scope;
		}

		$root_name = $this->resolveRootName( $user_id, $scope );

		if ( is_wp_error( $root_name ) ) {
			return $root_name;
		}

		$scope['rootName'] = $root_name;
		$job               = $this->jobs->create( $user_id, $this->initialState( $posts, $scope ) );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$this->scheduleRun( (string) $job['jobId'], 0 );
		SyncCron::spawnScheduledSyncs();

		return $this->formatJob( $job );
	}

	/**
	 * Read a job owned by the user, nudging its background ticks while it runs.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function getJob( string $job_id, int $user_id ): array|WP_Error {
		$job = $this->jobs->get( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( in_array( $job['status'], self::ACTIVE_STATUSES, true ) ) {
			$this->scheduleRun( $job_id, 0, false );
			SyncCron::spawnScheduledSyncs();
		}

		return $this->formatJob( $job );
	}

	/**
	 * Run one bounded tick of a job: inventory while listing, scoring while running.
	 *
	 * @param string $job_id Job ID.
	 */
	public function runJob( string $job_id ): void {
		$job = $this->jobs->getForWorker( $job_id );

		if ( null === $job ) {
			return;
		}

		if ( $this->isExpired( $job ) ) {
			$this->expireJob( $job );

			return;
		}

		if ( ! in_array( $job['status'], self::ACTIVE_STATUSES, true ) ) {
			return;
		}

		if ( ! $this->jobs->lock( $job_id ) ) {
			$this->scheduleRun( $job_id, self::LOCK_RETRY_SECONDS );

			return;
		}

		$delay = null;

		try {
			$job = $this->jobs->getForWorker( $job_id );

			if ( null === $job || $this->isExpired( $job ) || ! in_array( $job['status'], self::ACTIVE_STATUSES, true ) ) {
				return;
			}

			$delay = $this->runTick( $job );
			$saved = $this->jobs->save( $job );

			if ( is_wp_error( $saved ) ) {
				$delay = self::SAVE_RETRY_SECONDS;
			}

			if ( null !== $delay ) {
				$this->scheduleRun( $job_id, $delay );
			}
		} finally {
			$this->jobs->unlock( $job_id );
		}

		if ( 0 === $delay ) {
			SyncCron::spawnScheduledSyncs();
		}
	}

	/**
	 * Side-by-side comparison of one job post and any Google Doc the user can open.
	 *
	 * The Doc may be a listed candidate or one the user found manually. The
	 * issued `compareFingerprint` is recorded in the job so commit can require it.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @param int    $post_id Post ID.
	 * @param string $file_id Google Doc ID.
	 * @return array<string,mixed>|WP_Error
	 */
	public function compare( string $job_id, int $user_id, int $post_id, string $file_id ): array|WP_Error {
		$job = $this->reviewableJob( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
			return $this->invalidInputError();
		}

		$post = $this->jobPost( $job, $post_id, $user_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$metadata = $this->drive_client->getMetadata( $user_id, $file_id );

		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}

		$document = $this->docs_client->getDocument( $user_id, $file_id );

		if ( is_wp_error( $document ) ) {
			return $document;
		}

		$doc_html    = $this->normalizer->docsText( $document );
		$doc_tokens  = $this->normalizer->tokens( $doc_html );
		$post_html   = do_blocks( (string) $post->post_content );
		$post_tokens = $this->normalizer->tokens( $post_html );
		$post_title  = $this->normalizer->normalizeTitle( (string) $post->post_title );
		$doc_title   = $this->normalizer->normalizeTitle( (string) $metadata['name'] );
		$post_key    = $this->normalizer->leadingKey( $post_tokens );
		$fingerprint = $this->compareFingerprint( $post_id, $file_id, (string) $post->post_modified_gmt, (string) $metadata['version'] );

		if ( ! $this->jobs->lock( $job_id ) ) {
			return $this->jobBusyError();
		}

		try {
			$fresh = $this->reviewableJob( $job_id, $user_id );

			if ( is_wp_error( $fresh ) ) {
				return $fresh;
			}

			$compare_key = $post_id . '|' . $file_id;
			$compares    = is_array( $fresh['compares'] ?? null ) ? $fresh['compares'] : array();

			unset( $compares[ $compare_key ] );

			$compares[ $compare_key ] = array(
				'fingerprint' => $fingerprint,
				'issuedAt'    => time(),
			);
			$fresh['compares']        = $this->capMap( $compares, self::MAX_COMPARES );
			$saved                    = $this->jobs->save( $fresh );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		} finally {
			$this->jobs->unlock( $job_id );
		}

		return array(
			'postId'             => $post_id,
			'fileId'             => $file_id,
			'compareFingerprint' => $fingerprint,
			'titleMatch'         => '' !== $post_title && $post_title === $doc_title,
			'leadingTokensMatch' => null !== $post_key && $post_key === $this->normalizer->leadingKey( $doc_tokens ),
			'score'              => $this->normalizer->approximateScore( $post_title, $doc_title, $post_tokens, $doc_tokens ),
			'post'               => array(
				'title'      => $this->postTitle( $post ),
				'wordCount'  => count( $post_tokens ),
				'modifiedAt' => $this->postModifiedAt( $post ),
				'excerpt'    => $this->excerpt( $post_html ),
			),
			'doc'                => array(
				'name'         => (string) $metadata['name'],
				'wordCount'    => count( $doc_tokens ),
				'modifiedTime' => (string) $metadata['modifiedTime'],
				'webViewLink'  => (string) $metadata['webViewLink'],
				'excerpt'      => $this->excerpt( $doc_html ),
			),
			'diff'               => $this->normalizer->tokenDiff( $post_tokens, $doc_tokens, self::DIFF_TOKENS ),
		);
	}

	/**
	 * Attach-only commit of one-to-one post and Doc pairs.
	 *
	 * Every pair that is not the row's preselected or created match needs a
	 * current, issued `compareFingerprint`; a preselected pair whose post or
	 * Doc changed since matching needs one too. Any such gap rejects the whole
	 * request with 409 before anything is linked. Each pair's result is saved as
	 * soon as it finishes, so a replay with the same key resumes or returns the
	 * stored results.
	 *
	 * @param string                         $job_id          Job ID.
	 * @param int                            $user_id         User ID.
	 * @param array<int,array<string,mixed>> $pairs           `{postId,fileId,compareFingerprint?}` pairs.
	 * @param string                         $idempotency_key Client idempotency key.
	 * @return array{jobId:string,results:array<int,array<string,mixed>>}|WP_Error
	 */
	public function commit( string $job_id, int $user_id, array $pairs, string $idempotency_key ): array|WP_Error {
		if ( 1 !== preg_match( self::KEY_PATTERN, $idempotency_key ) ) {
			return $this->invalidInputError();
		}

		$pairs = $this->normalizePairs( $pairs );

		if ( is_wp_error( $pairs ) ) {
			return $pairs;
		}

		$job = $this->reviewableJob( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$key_hash     = hash( 'sha256', $user_id . '|' . $idempotency_key );
		$request_hash = hash( 'sha256', (string) wp_json_encode( $pairs ) );
		$replay       = $this->replayCommit( $job, $key_hash, $request_hash );

		if ( null !== $replay ) {
			return $replay;
		}

		if ( ! $this->jobs->lock( $job_id ) ) {
			return $this->jobBusyError();
		}

		try {
			return $this->commitLocked( $job_id, $user_id, $pairs, $key_hash, $request_hash );
		} finally {
			$this->jobs->unlock( $job_id );
		}
	}

	/**
	 * Create a Google Doc from a job post that has no match.
	 *
	 * The Doc is created in `$folder_id`, or in My Drive / Imported from
	 * WordPress when empty, recorded in the job's `googleTemporaries`, and
	 * preselected on the row. It is linked only by a later commit; otherwise it
	 * is trashed when the job expires.
	 *
	 * @param string $job_id          Job ID.
	 * @param int    $user_id         User ID.
	 * @param int    $post_id         Post ID.
	 * @param string $folder_id       Destination folder ID, or '' for the import folder.
	 * @param string $idempotency_key Client idempotency key.
	 * @return array{row:array<string,mixed>}|WP_Error
	 */
	public function createDoc( string $job_id, int $user_id, int $post_id, string $folder_id, string $idempotency_key ): array|WP_Error {
		$folder_id = trim( $folder_id );

		if ( 1 !== preg_match( self::KEY_PATTERN, $idempotency_key ) || $post_id <= 0 || ( '' !== $folder_id && 'root' !== $folder_id && 1 !== preg_match( self::FILE_ID_PATTERN, $folder_id ) ) ) {
			return $this->invalidInputError();
		}

		$job = $this->reviewableJob( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$key_hash     = hash( 'sha256', $user_id . '|' . $idempotency_key );
		$request_hash = hash( 'sha256', $post_id . '|' . $folder_id );
		$replay       = $this->replayCreateDoc( $job, $key_hash, $request_hash );

		if ( null !== $replay ) {
			return $replay;
		}

		$post = $this->jobPost( $job, $post_id, $user_id );

		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$allowed = $this->assertRowAcceptsCreatedDoc( $job, $post_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! $this->drive_write->hasWriteScope( $user_id ) ) {
			return new WP_Error(
				'docsync_wp_google_write_scope_required',
				__( 'Allow Brasth Document Sync to create files in your Google Drive, then try again.', 'brasth-document-sync-for-google-docs' ),
				array(
					'status'    => 403,
					'reconnect' => array( 'scopeSet' => 'driveFile' ),
				)
			);
		}

		$content = trim( wp_kses_post( do_blocks( (string) $post->post_content ) ) );

		if ( '' === $content ) {
			return new WP_Error(
				'docsync_wp_matching_post_empty',
				__( 'This post has no content to copy into a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 400 )
			);
		}

		if ( ! $this->jobs->lock( $job_id ) ) {
			return $this->jobBusyError();
		}

		try {
			return $this->createDocLocked( $job_id, $user_id, $post, $folder_id, $content, $key_hash, $request_hash );
		} finally {
			$this->jobs->unlock( $job_id );
		}
	}

	/**
	 * Trash the Docs a job created but never linked, then remove or park the record.
	 *
	 * A Doc is never trashed while any post links it, and only app-created files
	 * are trashed. IDs whose trash fails stay in the record, which is kept as an
	 * `expired` cleanup record with a doubling retry delay (1 hour, capped at 24).
	 * An unexpired job with a due `cleanupPending` retry only trashes the created
	 * Docs that no row offers any more and stays reviewable.
	 *
	 * @param array<string,mixed> $job Job (only `jobId` is trusted; the record is re-read).
	 */
	public function expireJob( array $job ): void {
		$job_id = isset( $job['jobId'] ) && is_string( $job['jobId'] ) ? $job['jobId'] : '';

		if ( '' === $job_id || ! $this->jobs->lock( $job_id ) ) {
			return;
		}

		try {
			$current = $this->jobs->getForWorker( $job_id );

			if ( null === $current ) {
				$this->jobs->delete( $job_id );

				return;
			}

			if ( ! $this->isExpired( $current ) ) {
				$this->retryOrphanCleanup( $current );

				return;
			}

			$remaining = $this->trashTemporaries( absint( $current['ownerUserId'] ), $this->stringList( $current['googleTemporaries'] ?? array() ) );

			if ( array() === $remaining ) {
				$this->jobs->delete( $job_id );

				return;
			}

			$record = $this->withCleanupBackoff(
				array(
					'version'           => $current['version'],
					'jobId'             => $job_id,
					'ownerUserId'       => absint( $current['ownerUserId'] ),
					'status'            => 'expired',
					'createdAt'         => absint( $current['createdAt'] ?? 0 ),
					'updatedAt'         => time(),
					'expiresAt'         => absint( $current['expiresAt'] ?? 0 ),
					'googleTemporaries' => $remaining,
					'cleanupPending'    => true,
					'cleanupAttempts'   => absint( $current['cleanupAttempts'] ?? 0 ),
					'nextCleanupAt'     => 0,
				)
			);

			$this->jobs->save( $record );
		} finally {
			$this->jobs->unlock( $job_id );
		}
	}

	/**
	 * Format a job for REST responses.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	public function formatJob( array $job ): array {
		$status    = $this->isExpired( $job ) ? 'expired' : (string) $job['status'];
		$inventory = is_array( $job['inventory'] ?? null ) ? $job['inventory'] : array();
		$rows      = array();

		if ( in_array( $status, self::REVIEW_STATUSES, true ) ) {
			foreach ( (array) ( $job['rows'] ?? array() ) as $row ) {
				$formatted = is_array( $row ) ? $this->formatRow( $job, $row ) : null;

				if ( null !== $formatted ) {
					$rows[] = $formatted;
				}
			}
		}

		return array(
			'jobId'     => (string) $job['jobId'],
			'version'   => MatchSessionRepository::RECORD_VERSION,
			'status'    => $status,
			'createdAt' => $this->isoTime( absint( $job['createdAt'] ?? 0 ) ),
			'expiresAt' => $this->isoTime( absint( $job['expiresAt'] ?? 0 ) ),
			'progress'  => array(
				'processed' => absint( $job['progress']['processed'] ?? 0 ),
				'total'     => absint( $job['progress']['total'] ?? 0 ),
			),
			'inventory' => array(
				'complete'       => true === ( $inventory['complete'] ?? false ),
				'docsFound'      => absint( $inventory['docsFound'] ?? 0 ),
				'foldersVisited' => absint( $inventory['foldersVisited'] ?? 0 ),
				'foldersQueued'  => count( (array) ( $inventory['queue'] ?? array() ) ),
				'pagesFetched'   => absint( $inventory['pagesFetched'] ?? 0 ),
				'warnings'       => array_values( (array) ( $inventory['warnings'] ?? array() ) ),
			),
			'error'     => is_array( $job['error'] ?? null ) ? $job['error'] : null,
			'rows'      => $rows,
		);
	}

	/**
	 * Advance a locked job by one bounded step.
	 *
	 * @param array<string,mixed> $job Job, updated in place.
	 * @return int|null Delay before the next tick, or null when the job is finished.
	 */
	private function runTick( array &$job ): ?int {
		if ( 'queued' === $job['status'] ) {
			$job['status'] = 'listing';
		}

		if ( 'listing' === $job['status'] ) {
			$delay = $this->listingTick( $job );

			if ( 'failed' === $job['status'] ) {
				return null;
			}

			if ( array() === $job['inventory']['queue'] && array() === $job['inventory']['fileIds'] ) {
				$job['status']                = 'running';
				$job['inventory']['complete'] = array() === $job['inventory']['warnings'];
				$job['progress']              = array(
					'processed' => 0,
					'total'     => count( $job['docs'] ),
				);

				return 0;
			}

			return $delay;
		}

		$this->computePostFeatures( $job );
		$delay = $this->matchingTick( $job );

		if ( 'failed' === $job['status'] ) {
			return null;
		}

		if ( $this->hasPendingPosts( $job ) || $this->hasPendingDocs( $job ) ) {
			return $delay;
		}

		$job['rows']   = $this->buildRows( $job );
		$job['status'] = 'ready';

		return null;
	}

	/**
	 * Read up to five Drive list pages, or up to ten explicit files.
	 *
	 * @param array<string,mixed> $job Job, updated in place.
	 * @return int Delay before the next tick.
	 */
	private function listingTick( array &$job ): int {
		$user_id = absint( $job['ownerUserId'] );

		if ( array() !== $job['inventory']['fileIds'] ) {
			return $this->explicitFilesTick( $job, $user_id );
		}

		for ( $page = 0; $page < self::LIST_PAGES_PER_TICK; $page++ ) {
			$entry = array_shift( $job['inventory']['queue'] );

			if ( ! is_array( $entry ) ) {
				break;
			}

			$result = $this->drive_client->searchDriveItems( $user_id, $this->listQuery( $entry ) );

			if ( is_wp_error( $result ) ) {
				if ( $this->isFatalGoogleError( $result ) ) {
					$this->failJob( $job, $result );

					return 0;
				}

				$entry['attempts'] = absint( $entry['attempts'] ?? 0 ) + 1;

				if ( $entry['attempts'] > self::LIST_RETRY_LIMIT ) {
					$this->addWarning(
						$job,
						'listingFailed',
						/* translators: %s: Drive folder path. */
						sprintf( __( 'Google Drive could not list "%s" after several tries. Docs in it were not checked.', 'brasth-document-sync-for-google-docs' ), (string) $entry['path'] ),
						'' !== $entry['folderId'] ? (string) $entry['folderId'] : null,
						null
					);
					continue;
				}

				array_unshift( $job['inventory']['queue'], $entry );

				return self::RETRY_BASE_SECONDS * $entry['attempts'];
			}

			$this->absorbListPage( $job, $entry, $result );
		}

		return 0;
	}

	/**
	 * Read explicit candidate files with `DriveClient::getMetadata`.
	 *
	 * @param array<string,mixed> $job     Job, updated in place.
	 * @param int                 $user_id Owner.
	 * @return int Delay before the next tick.
	 */
	private function explicitFilesTick( array &$job, int $user_id ): int {
		for ( $read = 0; $read < self::MATCH_DOCS_PER_TICK; $read++ ) {
			$file_id = $job['inventory']['fileIds'][0] ?? null;

			if ( ! is_string( $file_id ) ) {
				break;
			}

			$metadata = $this->drive_client->getMetadata( $user_id, $file_id );

			if ( is_wp_error( $metadata ) ) {
				if ( $this->isFatalGoogleError( $metadata ) ) {
					$this->failJob( $job, $metadata );

					return 0;
				}

				$attempts = absint( $job['inventory']['fileAttempts'][ $file_id ] ?? 0 ) + 1;

				if ( $this->isTransientGoogleError( $metadata ) && $attempts <= self::LIST_RETRY_LIMIT ) {
					$job['inventory']['fileAttempts'][ $file_id ] = $attempts;

					return self::RETRY_BASE_SECONDS * $attempts;
				}

				array_shift( $job['inventory']['fileIds'] );
				unset( $job['inventory']['fileAttempts'][ $file_id ] );
				$this->addFileUnavailable( $job, $file_id, $metadata->get_error_message() );
				continue;
			}

			array_shift( $job['inventory']['fileIds'] );
			unset( $job['inventory']['fileAttempts'][ $file_id ] );
			++$job['inventory']['pagesFetched'];

			if ( false === ( $metadata['syncCompatibility']['canDownload'] ?? null ) ) {
				$this->addFileUnavailable( $job, $file_id, __( 'Google says this Doc cannot be downloaded by the connected account.', 'brasth-document-sync-for-google-docs' ) );
				continue;
			}

			$this->addDoc(
				$job,
				array(
					'fileId'       => (string) $metadata['fileId'],
					'name'         => (string) $metadata['name'],
					'webViewLink'  => (string) $metadata['webViewLink'],
					'modifiedTime' => (string) $metadata['modifiedTime'],
					'version'      => (string) $metadata['version'],
				),
				''
			);
		}

		return 0;
	}

	/**
	 * Add one Drive list page to the inventory and queue its child folders and next page.
	 *
	 * @param array<string,mixed> $job    Job, updated in place.
	 * @param array<string,mixed> $entry  Queue entry that was read.
	 * @param array<string,mixed> $result `searchDriveItems` result.
	 */
	private function absorbListPage( array &$job, array $entry, array $result ): void {
		$folder_id = (string) $entry['folderId'];

		++$job['inventory']['pagesFetched'];

		if ( '' !== $folder_id && '' === (string) $entry['pageToken'] ) {
			++$job['inventory']['foldersVisited'];
		}

		if ( ! empty( $result['incompleteSearch'] ) ) {
			$this->addWarning(
				$job,
				'incompleteSearch',
				/* translators: %s: Drive folder path. */
				sprintf( __( 'Google Drive did not return every result for "%s".', 'brasth-document-sync-for-google-docs' ), (string) $entry['path'] ),
				'' !== $folder_id ? $folder_id : null,
				null
			);
		}

		foreach ( (array) ( $result['items'] ?? array() ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			if ( 'folder' === ( $item['itemType'] ?? '' ) ) {
				$this->queueFolder( $job, $entry, $item );
				continue;
			}

			if ( 'document' !== ( $item['itemType'] ?? '' ) || DriveClient::GOOGLE_DOC_MIME_TYPE !== ( $item['mimeType'] ?? '' ) || empty( $item['selectable'] ) ) {
				continue;
			}

			if ( ! $this->addDoc( $job, $item, (string) $entry['path'] ) ) {
				return;
			}
		}

		$next_token = (string) ( $result['nextPageToken'] ?? '' );

		if ( '' !== $next_token ) {
			$entry['pageToken']          = $next_token;
			$entry['attempts']           = 0;
			$job['inventory']['queue'][] = $entry;
		}
	}

	/**
	 * Queue a child folder once, within the folder and depth limits.
	 *
	 * @param array<string,mixed> $job    Job, updated in place.
	 * @param array<string,mixed> $parent_entry Parent queue entry.
	 * @param array<string,mixed> $item         Folder item.
	 */
	private function queueFolder( array &$job, array $parent_entry, array $item ): void {
		$folder_id = (string) ( $item['fileId'] ?? '' );

		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $folder_id ) || in_array( $folder_id, $job['inventory']['visitedFolderIds'], true ) ) {
			return;
		}

		$depth = absint( $parent_entry['depth'] ) + 1;
		$path  = $this->joinPath( (string) $parent_entry['path'], (string) ( $item['name'] ?? '' ) );

		if ( $depth > self::MAX_FOLDER_DEPTH ) {
			$this->addWarning(
				$job,
				'depthLimitReached',
				/* translators: 1: Drive folder path, 2: maximum folder depth. */
				sprintf( __( 'Folders inside "%1$s" are nested more than %2$d levels deep and were not checked.', 'brasth-document-sync-for-google-docs' ), (string) $parent_entry['path'], self::MAX_FOLDER_DEPTH ),
				'' !== (string) $parent_entry['folderId'] ? (string) $parent_entry['folderId'] : null,
				null
			);

			return;
		}

		if ( count( $job['inventory']['visitedFolderIds'] ) >= self::MAX_FOLDERS ) {
			$this->addWarning(
				$job,
				'folderLimitReached',
				/* translators: %d: maximum number of folders. */
				sprintf( __( 'Stopped after %d folders. Some folders were not checked.', 'brasth-document-sync-for-google-docs' ), self::MAX_FOLDERS ),
				null,
				null
			);

			return;
		}

		$job['inventory']['visitedFolderIds'][] = $folder_id;
		$job['inventory']['queue'][]            = array(
			'folderId'  => $folder_id,
			'driveId'   => (string) $parent_entry['driveId'],
			'pageToken' => '',
			'depth'     => $depth,
			'attempts'  => 0,
			'path'      => $path,
			'location'  => '',
		);
	}

	/**
	 * Add a candidate Doc once; at the Doc limit, stop the whole inventory.
	 *
	 * @param array<string,mixed> $job  Job, updated in place.
	 * @param array<string,mixed> $item Drive item or metadata.
	 * @param string              $path Folder path.
	 * @return bool False when the Doc limit stopped the inventory.
	 */
	private function addDoc( array &$job, array $item, string $path ): bool {
		$file_id = (string) ( $item['fileId'] ?? '' );

		if ( 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) || isset( $job['docs'][ $file_id ] ) ) {
			return true;
		}

		if ( count( $job['docs'] ) >= self::MAX_CANDIDATE_DOCS ) {
			$this->addWarning(
				$job,
				'docLimitReached',
				/* translators: %d: maximum number of candidate Docs. */
				sprintf( __( 'Stopped after %d Google Docs. Some Docs in this location were not checked.', 'brasth-document-sync-for-google-docs' ), self::MAX_CANDIDATE_DOCS ),
				null,
				null
			);
			$job['inventory']['queue']   = array();
			$job['inventory']['fileIds'] = array();

			return false;
		}

		$name                          = (string) ( $item['name'] ?? '' );
		$job['docs'][ $file_id ]       = array(
			'fileId'           => $file_id,
			'name'             => $name,
			'webViewLink'      => (string) ( $item['webViewLink'] ?? '' ),
			'modifiedTime'     => (string) ( $item['modifiedTime'] ?? '' ),
			'version'          => (string) ( $item['version'] ?? '' ),
			'ownerDisplayName' => (string) ( $item['ownerDisplayName'] ?? '' ),
			'ownedByMe'        => isset( $item['ownedByMe'] ) ? (bool) $item['ownedByMe'] : null,
			'path'             => $path,
			'status'           => 'pending',
			'attempts'         => 0,
			'title'            => $this->normalizer->normalizeTitle( $name ),
			'leadingKey'       => null,
			'intro'            => array(),
			'wordCount'        => null,
			'linkedPostId'     => null,
		);
		$job['inventory']['docsFound'] = count( $job['docs'] );

		return true;
	}

	/**
	 * Compute normalized features for up to 50 pending posts.
	 *
	 * @param array<string,mixed> $job Job, updated in place.
	 */
	private function computePostFeatures( array &$job ): void {
		$budget = self::POST_FEATURES_PER_TICK;

		foreach ( $job['posts'] as $index => $entry ) {
			if ( 'pending' !== ( $entry['status'] ?? '' ) ) {
				continue;
			}

			if ( $budget <= 0 ) {
				return;
			}

			--$budget;
			$post = get_post( absint( $entry['postId'] ) );

			if ( ! $post instanceof WP_Post || in_array( $post->post_status, self::EXCLUDED_POST_STATUSES, true ) ) {
				$job['posts'][ $index ]['status'] = 'missing';
				continue;
			}

			$tokens                 = $this->normalizer->tokens( do_blocks( (string) $post->post_content ) );
			$job['posts'][ $index ] = array(
				'postId'      => $post->ID,
				'status'      => 'ready',
				'title'       => $this->normalizer->normalizeTitle( (string) $post->post_title ),
				'leadingKey'  => $this->normalizer->leadingKey( $tokens ),
				'intro'       => array_slice( $tokens, 0, MatchNormalizer::INTRO_TOKENS ),
				'wordCount'   => count( $tokens ),
				'modifiedGmt' => (string) $post->post_modified_gmt,
			);
		}
	}

	/**
	 * Read and score up to ten pending Docs.
	 *
	 * @param array<string,mixed> $job Job, updated in place.
	 * @return int Delay before the next tick.
	 */
	private function matchingTick( array &$job ): int {
		$user_id = absint( $job['ownerUserId'] );
		$budget  = self::MATCH_DOCS_PER_TICK;

		foreach ( $job['docs'] as $file_id => $doc ) {
			if ( 'pending' !== $doc['status'] ) {
				continue;
			}

			if ( $budget <= 0 ) {
				break;
			}

			--$budget;
			$document = $this->docs_client->getDocument( $user_id, (string) $file_id );

			if ( is_wp_error( $document ) ) {
				if ( $this->isFatalGoogleError( $document ) ) {
					$this->failJob( $job, $document );

					return 0;
				}

				$attempts = absint( $doc['attempts'] ) + 1;

				if ( $this->isTransientGoogleError( $document ) && $attempts <= self::LIST_RETRY_LIMIT ) {
					$job['docs'][ $file_id ]['attempts'] = $attempts;

					return self::RETRY_BASE_SECONDS * $attempts;
				}

				$job['docs'][ $file_id ]['status'] = 'failed';
				++$job['progress']['processed'];
				$this->addFileUnavailable( $job, (string) $file_id, $document->get_error_message() );
				continue;
			}

			$tokens                  = $this->normalizer->tokens( $this->normalizer->docsText( $document ) );
			$job['docs'][ $file_id ] = array_merge(
				$doc,
				array(
					'status'     => 'done',
					'leadingKey' => $this->normalizer->leadingKey( $tokens ),
					'intro'      => array_slice( $tokens, 0, MatchNormalizer::INTRO_TOKENS ),
					'wordCount'  => count( $tokens ),
				)
			);
			++$job['progress']['processed'];
		}

		return 0;
	}

	/**
	 * Decide every row from the finished inventory.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<int,array<string,mixed>> Internal rows in post order.
	 */
	private function buildRows( array &$job ): array {
		$user_id     = absint( $job['ownerUserId'] );
		$docs        = $job['docs'];
		$title_index = array();
		$lead_index  = array();

		ksort( $docs, SORT_STRING );

		foreach ( $docs as $file_id => $doc ) {
			$file_id                                 = (string) $file_id;
			$job['docs'][ $file_id ]['linkedPostId'] = $this->source_repository->findPostIdByGoogleFileId( $file_id );
			$docs[ $file_id ]['linkedPostId']        = $job['docs'][ $file_id ]['linkedPostId'];

			if ( '' !== $doc['title'] ) {
				$title_index[ $doc['title'] ][] = $file_id;
			}

			if ( null !== $doc['leadingKey'] ) {
				$lead_index[ $doc['leadingKey'] ][] = $file_id;
			}
		}

		$rows         = array();
		$open_posts   = array();
		$title_claims = array();
		$lead_claims  = array();

		foreach ( $job['posts'] as $entry ) {
			$post_id = absint( $entry['postId'] ?? 0 );

			if ( 'ready' !== ( $entry['status'] ?? '' ) || ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) ) {
				continue;
			}

			if ( null !== $this->source_repository->getSource( $post_id ) ) {
				$rows[ $post_id ] = $this->row( $post_id, 'alreadyLinked', null, null, null, array() );
				continue;
			}

			$exact_title = '' !== $entry['title'] ? ( $title_index[ $entry['title'] ] ?? array() ) : array();
			$exact_lead  = null !== $entry['leadingKey'] ? ( $lead_index[ $entry['leadingKey'] ] ?? array() ) : array();

			foreach ( $exact_title as $file_id ) {
				$title_claims[ $file_id ][] = $post_id;
			}

			foreach ( $exact_lead as $file_id ) {
				$lead_claims[ $file_id ][] = $post_id;
			}

			$open_posts[ $post_id ] = array(
				'entry' => $entry,
				'title' => $exact_title,
				'lead'  => $exact_lead,
			);
			$rows[ $post_id ]       = null;
		}

		$decisions = array();

		foreach ( $open_posts as $post_id => $open ) {
			$decisions[ $post_id ] = $this->decide( $open['title'], $open['lead'], $title_claims, $lead_claims, $docs );
		}

		$selected_by = array();

		foreach ( $decisions as $post_id => $decision ) {
			if ( null !== $decision['fileId'] ) {
				$selected_by[ $decision['fileId'] ][] = $post_id;
			}
		}

		$complete = true === $job['inventory']['complete'];

		foreach ( $decisions as $post_id => $decision ) {
			$open       = $open_posts[ $post_id ];
			$candidates = $this->candidates( $open['entry'], $open['title'], $open['lead'], $docs );
			$state      = $decision['state'];
			$file_id    = $decision['fileId'];
			$kind       = $decision['kind'];
			$blocked    = null;

			if ( null !== $file_id && count( $selected_by[ $file_id ] ) > 1 ) {
				$state   = 'conflict';
				$file_id = null;
				$kind    = null;
			}

			if ( null !== $file_id && ! $complete ) {
				$state   = 'ambiguous';
				$file_id = null;
				$kind    = null;
				$blocked = 'inventoryIncomplete';
			}

			if ( null === $state ) {
				$state = array() === $candidates ? 'none' : 'approximate';
			}

			$rows[ $post_id ] = $this->row( $post_id, $state, $file_id, $kind, $blocked, $candidates );
		}

		return array_values( array_filter( $rows ) );
	}

	/**
	 * Preselection decision for one unlinked post.
	 *
	 * @param array<int,string>                 $exact_title  Docs with the same normalized title, sorted.
	 * @param array<int,string>                 $exact_lead   Docs with the same leading-token key, sorted.
	 * @param array<string,array<int,int>>      $title_claims Posts claiming each Doc by title.
	 * @param array<string,array<int,int>>      $lead_claims  Posts claiming each Doc by leading tokens.
	 * @param array<string,array<string,mixed>> $docs      Inventory Docs.
	 * @return array{state:string|null,fileId:string|null,kind:string|null} Null state means no exact match.
	 */
	private function decide( array $exact_title, array $exact_lead, array $title_claims, array $lead_claims, array $docs ): array {
		$none = array(
			'state'  => null,
			'fileId' => null,
			'kind'   => null,
		);

		if ( count( $exact_title ) > 1 || ( array() === $exact_title && count( $exact_lead ) > 1 ) ) {
			return array_merge( $none, array( 'state' => 'ambiguous' ) );
		}

		if ( 1 === count( $exact_title ) ) {
			$file_id = $exact_title[0];

			if ( array() !== $exact_lead && array( $file_id ) !== $exact_lead ) {
				return array_merge( $none, array( 'state' => in_array( $file_id, $exact_lead, true ) ? 'ambiguous' : 'conflict' ) );
			}

			$claims = $title_claims[ $file_id ] ?? array();
			$kind   = 'exactTitle';
		} elseif ( 1 === count( $exact_lead ) ) {
			$file_id = $exact_lead[0];
			$claims  = $lead_claims[ $file_id ] ?? array();
			$kind    = 'exactLeadingTokens';
		} else {
			return $none;
		}

		if ( count( $claims ) > 1 || null !== ( $docs[ $file_id ]['linkedPostId'] ?? null ) ) {
			return array_merge( $none, array( 'state' => 'conflict' ) );
		}

		return array(
			'state'  => 'preselected',
			'fileId' => $file_id,
			'kind'   => $kind,
		);
	}

	/**
	 * Up to five candidates: exact matches first, then approximate ones scoring at least 0.5.
	 *
	 * Ranking: exact title, exact leading tokens, approximate; then title
	 * similarity, intro similarity (both descending), and file ID.
	 *
	 * @param array<string,mixed>               $entry       Post features.
	 * @param array<int,string>                 $exact_title Exact-title Docs.
	 * @param array<int,string>                 $exact_lead  Exact-leading-token Docs.
	 * @param array<string,array<string,mixed>> $docs        Inventory Docs.
	 * @return array<int,array<string,mixed>>
	 */
	private function candidates( array $entry, array $exact_title, array $exact_lead, array $docs ): array {
		$candidates = array();

		foreach ( $docs as $file_id => $doc ) {
			$file_id     = (string) $file_id;
			$title_score = $this->normalizer->titleSimilarity( (string) $entry['title'], (string) $doc['title'] );
			$intro_score = $this->normalizer->introSimilarity( (array) $entry['intro'], (array) $doc['intro'] );
			$score       = max( $title_score, $intro_score );

			if ( in_array( $file_id, $exact_title, true ) ) {
				$kind = 'exactTitle';
			} elseif ( in_array( $file_id, $exact_lead, true ) ) {
				$kind = 'exactLeadingTokens';
			} elseif ( $score >= MatchNormalizer::APPROXIMATE_MIN_SCORE ) {
				$kind = 'approximate';
			} else {
				continue;
			}

			$candidates[] = array(
				'fileId'     => $file_id,
				'kind'       => $kind,
				'score'      => $score,
				'titleScore' => $title_score,
				'introScore' => $intro_score,
			);
		}

		usort( $candidates, array( $this, 'compareCandidates' ) );

		return array_slice( $candidates, 0, self::MAX_CANDIDATES );
	}

	/**
	 * Deterministic candidate order.
	 *
	 * @param array<string,mixed> $first  First candidate.
	 * @param array<string,mixed> $second Second candidate.
	 */
	private function compareCandidates( array $first, array $second ): int {
		$order = self::KIND_RANK[ $first['kind'] ] <=> self::KIND_RANK[ $second['kind'] ];

		if ( 0 === $order ) {
			$order = $second['titleScore'] <=> $first['titleScore'];
		}

		if ( 0 === $order ) {
			$order = $second['introScore'] <=> $first['introScore'];
		}

		return 0 !== $order ? $order : strcmp( (string) $first['fileId'], (string) $second['fileId'] );
	}

	/**
	 * Internal row.
	 *
	 * @param int                            $post_id    Post ID.
	 * @param string                         $state      Row state.
	 * @param string|null                    $file_id    Selected Doc.
	 * @param string|null                    $kind       Match kind of the selection.
	 * @param string|null                    $blocked    Preselection block reason.
	 * @param array<int,array<string,mixed>> $candidates Candidates.
	 * @return array<string,mixed>
	 */
	private function row( int $post_id, string $state, ?string $file_id, ?string $kind, ?string $blocked, array $candidates ): array {
		return array(
			'postId'           => $post_id,
			'state'            => $state,
			'selectedFileId'   => $file_id,
			'matchKind'        => $kind,
			'preselectBlocked' => $blocked,
			'candidates'       => $candidates,
		);
	}

	/**
	 * Run a commit while the job lease is held.
	 *
	 * @param string                         $job_id       Job ID.
	 * @param int                            $user_id      User ID.
	 * @param array<int,array<string,mixed>> $pairs        Normalized pairs.
	 * @param string                         $key_hash     Owner-bound key hash.
	 * @param string                         $request_hash Body hash.
	 * @return array{jobId:string,results:array<int,array<string,mixed>>}|WP_Error
	 */
	private function commitLocked( string $job_id, int $user_id, array $pairs, string $key_hash, string $request_hash ): array|WP_Error {
		$job = $this->reviewableJob( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$replay = $this->replayCommit( $job, $key_hash, $request_hash );

		if ( null !== $replay ) {
			return $replay;
		}

		$record   = is_array( $job['commits'][ $key_hash ] ?? null ) ? $job['commits'][ $key_hash ] : null;
		$done     = is_array( $record ) ? (array) $record['results'] : array();
		$metadata = array();
		$stale    = array();

		foreach ( $pairs as $index => $pair ) {
			if ( isset( $done[ $index ] ) ) {
				continue;
			}

			if ( ! $this->jobs->lock( $job_id ) ) {
				return $this->jobBusyError();
			}

			$metadata[ $index ] = $this->drive_client->getMetadata( $user_id, $pair['fileId'] );

			if ( ! is_wp_error( $metadata[ $index ] ) && ! $this->pairIsCurrent( $job, $pair, $metadata[ $index ] ) ) {
				$stale[] = array(
					'postId' => $pair['postId'],
					'fileId' => $pair['fileId'],
				);
			}
		}

		if ( array() !== $stale ) {
			return new WP_Error(
				'docsync_wp_matching_compare_required',
				__( 'Compare these posts and Google Docs again before linking them.', 'brasth-document-sync-for-google-docs' ),
				array(
					'status' => 409,
					'pairs'  => $stale,
				)
			);
		}

		if ( null === $record ) {
			$job['commits'][ $key_hash ] = array(
				'requestHash' => $request_hash,
				'status'      => 'running',
				'startedAt'   => time(),
				'results'     => array(),
			);
			$job['commits']              = $this->capMap( $job['commits'], self::MAX_IDEMPOTENCY );
			$saved                       = $this->jobs->save( $job );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		foreach ( $pairs as $index => $pair ) {
			if ( isset( $done[ $index ] ) ) {
				continue;
			}

			if ( ! $this->jobs->lock( $job_id ) ) {
				return $this->jobBusyError();
			}

			$job['commits'][ $key_hash ]['results'][ $index ] = $this->commitPair( $job, $user_id, $pair, $metadata[ $index ] );
			$saved = $this->jobs->save( $job );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		$job['commits'][ $key_hash ]['status'] = 'complete';
		$job['status']                         = 'committed';
		$saved                                 = $this->jobs->save( $job );

		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return $this->formatCommit( $job, $job['commits'][ $key_hash ] );
	}

	/**
	 * Whether a pair may be linked without a new comparison.
	 *
	 * A preselected or created row selection is current while the post and the
	 * Doc are unchanged since matching (created Docs: the post only). Any other
	 * pair needs a `compareFingerprint` that this job issued and that still
	 * matches the post's modified time and the Doc's version.
	 *
	 * @param array<string,mixed> $job      Job.
	 * @param array<string,mixed> $pair     Pair.
	 * @param array<string,mixed> $metadata Fresh Doc metadata.
	 */
	private function pairIsCurrent( array $job, array $pair, array $metadata ): bool {
		$post_id = $pair['postId'];
		$file_id = $pair['fileId'];
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return true;
		}

		$row      = $this->findRow( $job, $post_id );
		$features = $this->findPostFeatures( $job, $post_id );

		if ( is_array( $row ) && $row['selectedFileId'] === $file_id && is_array( $features ) ) {
			$post_unchanged = (string) $features['modifiedGmt'] === (string) $post->post_modified_gmt;

			if ( 'created' === $row['state'] && $post_unchanged ) {
				return true;
			}

			if ( 'preselected' === $row['state'] && $post_unchanged && (string) ( $job['docs'][ $file_id ]['version'] ?? '' ) === (string) $metadata['version'] ) {
				return true;
			}
		}

		$issued   = $job['compares'][ $post_id . '|' . $file_id ]['fingerprint'] ?? '';
		$expected = $this->compareFingerprint( $post_id, $file_id, (string) $post->post_modified_gmt, (string) $metadata['version'] );

		return '' !== $pair['compareFingerprint']
			&& is_string( $issued )
			&& hash_equals( $expected, $pair['compareFingerprint'] )
			&& hash_equals( $expected, $issued );
	}

	/**
	 * Revalidate and link one pair attach-only.
	 *
	 * @param array<string,mixed>          $job      Job, updated in place.
	 * @param int                          $user_id  User ID.
	 * @param array<string,mixed>          $pair     Pair.
	 * @param array<string,mixed>|WP_Error $metadata Fresh Doc metadata.
	 * @return array<string,mixed>
	 */
	private function commitPair( array &$job, int $user_id, array $pair, array|WP_Error $metadata ): array {
		$post_id = $pair['postId'];
		$file_id = $pair['fileId'];

		if ( is_wp_error( $metadata ) ) {
			return $this->pairResult( $post_id, $file_id, $metadata );
		}

		if ( null === $this->findPostFeatures( $job, $post_id ) ) {
			return $this->pairResult( $post_id, $file_id, $this->postNotInJobError() );
		}

		if ( ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) ) {
			return $this->pairResult(
				$post_id,
				$file_id,
				new WP_Error(
					'docsync_wp_forbidden',
					__( 'You do not have permission to edit this post.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 403 )
				)
			);
		}

		if ( (string) $metadata['fileId'] !== $file_id ) {
			return $this->pairResult(
				$post_id,
				$file_id,
				new WP_Error(
					'docsync_wp_matching_source_mismatch',
					__( 'Google returned a different Doc than the one chosen.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 409 )
				)
			);
		}

		if ( false === ( $metadata['syncCompatibility']['canDownload'] ?? null ) ) {
			return $this->pairResult(
				$post_id,
				$file_id,
				new WP_Error(
					'docsync_wp_drive_download_blocked',
					__( 'Google says this Doc cannot be downloaded by the connected account. Adjust sharing or choose another Doc before linking.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 403 )
				)
			);
		}

		$linked = $this->source_batch->attachOnly( $user_id, $post_id, $file_id );

		if ( is_wp_error( $linked ) ) {
			return $this->pairResult( $post_id, $file_id, $linked );
		}

		$job['googleTemporaries'] = array_values( array_diff( $this->stringList( $job['googleTemporaries'] ?? array() ), array( $file_id ) ) );

		if ( isset( $job['docs'][ $file_id ] ) ) {
			$job['docs'][ $file_id ]['linkedPostId'] = $post_id;
		}

		foreach ( $job['rows'] as $index => $row ) {
			if ( absint( $row['postId'] ) === $post_id ) {
				$job['rows'][ $index ] = array_merge(
					$row,
					array(
						'state'            => 'alreadyLinked',
						'selectedFileId'   => null,
						'matchKind'        => null,
						'preselectBlocked' => null,
						'linkedFileId'     => $file_id,
					)
				);
			}
		}

		return $this->pairResult( $post_id, $file_id, null );
	}

	/**
	 * Create the Doc and record it while the job lease is held.
	 *
	 * @param string  $job_id       Job ID.
	 * @param int     $user_id      User ID.
	 * @param WP_Post $post         Post.
	 * @param string  $folder_id    Destination folder, or ''.
	 * @param string  $content      Sanitized rendered post content.
	 * @param string  $key_hash     Owner-bound key hash.
	 * @param string  $request_hash Body hash.
	 * @return array{row:array<string,mixed>}|WP_Error
	 */
	private function createDocLocked( string $job_id, int $user_id, WP_Post $post, string $folder_id, string $content, string $key_hash, string $request_hash ): array|WP_Error {
		$job = $this->reviewableJob( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		$replay = $this->replayCreateDoc( $job, $key_hash, $request_hash );

		if ( null !== $replay ) {
			return $replay;
		}

		$allowed = $this->assertRowAcceptsCreatedDoc( $job, $post->ID );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$name    = $this->postTitle( $post );
		$name    = '' !== $name ? $name : __( 'Untitled post', 'brasth-document-sync-for-google-docs' );
		$html    = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html( $name ) . '</title></head><body>' . $content . '</body></html>';
		$created = $this->drive_write->createDocumentFromHtml( $user_id, $html, $name, $folder_id );

		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$file_id                        = (string) $created['fileId'];
		$original_row                   = (array) $this->findRow( $job, $post->ID );
		$tokens                         = $this->normalizer->tokens( $content );
		$job['googleTemporaries']       = array_values( array_unique( array_merge( $this->stringList( $job['googleTemporaries'] ?? array() ), array( $file_id ) ) ) );
		$job['createDocs']              = is_array( $job['createDocs'] ?? null ) ? $job['createDocs'] : array();
		$job['createDocs'][ $key_hash ] = array(
			'requestHash' => $request_hash,
			'postId'      => $post->ID,
			'fileId'      => $file_id,
		);
		$job['createDocs']              = $this->capMap( $job['createDocs'], self::MAX_IDEMPOTENCY );
		$job['docs'][ $file_id ]        = array(
			'fileId'           => $file_id,
			'name'             => (string) $created['name'],
			'webViewLink'      => (string) $created['webViewLink'],
			'modifiedTime'     => $this->isoTime( time() ),
			'version'          => (string) $created['version'],
			'ownerDisplayName' => '',
			'ownedByMe'        => true,
			'path'             => '' === $folder_id ? $this->joinPath( __( 'My Drive', 'brasth-document-sync-for-google-docs' ), DriveWriteClient::IMPORT_FOLDER_NAME ) : '',
			'status'           => 'created',
			'attempts'         => 0,
			'title'            => $this->normalizer->normalizeTitle( (string) $created['name'] ),
			'leadingKey'       => $this->normalizer->leadingKey( $tokens ),
			'intro'            => array_slice( $tokens, 0, MatchNormalizer::INTRO_TOKENS ),
			'wordCount'        => count( $tokens ),
			'linkedPostId'     => null,
		);

		foreach ( $job['rows'] as $index => $row ) {
			if ( absint( $row['postId'] ) !== $post->ID ) {
				continue;
			}

			$candidates = array_values(
				array_filter(
					(array) $row['candidates'],
					static function ( $candidate ) use ( $file_id ): bool {
						return is_array( $candidate ) && $candidate['fileId'] !== $file_id;
					}
				)
			);

			array_unshift(
				$candidates,
				array(
					'fileId'     => $file_id,
					'kind'       => 'exactTitle',
					'score'      => 1.0,
					'titleScore' => 1.0,
					'introScore' => 1.0,
				)
			);

			$job['rows'][ $index ] = $this->row( $post->ID, 'created', $file_id, 'created', null, array_slice( $candidates, 0, self::MAX_CANDIDATES ) );
		}

		$saved = $this->jobs->save( $job );

		if ( is_wp_error( $saved ) ) {
			$this->discardCreatedDoc( $job_id, $user_id, $post->ID, $file_id, $key_hash, $original_row );

			return $saved;
		}

		$row = $this->findRow( $job, $post->ID );

		return array( 'row' => is_array( $row ) ? (array) $this->formatRow( $job, $row ) : array() );
	}

	/**
	 * Undo a created Doc whose job save failed, keeping an untrashed ID for cleanup.
	 *
	 * The Doc is trashed first. The stored job is then re-read under a valid
	 * lease (re-acquired when the failed save lost it; a successor's lease is
	 * never overridden) and any part of the creation that reached storage is
	 * reverted. An ID whose trash failed stays in `googleTemporaries` with
	 * `cleanupPending` and a backoff, so a later cleanup retries it. Each write
	 * is retried a bounded number of times. When storage stays unavailable or
	 * another request holds the lease, nothing is written and the ID cannot be
	 * recorded.
	 *
	 * @param string              $job_id       Job ID.
	 * @param int                 $user_id      Owner.
	 * @param int                 $post_id      Post ID.
	 * @param string              $file_id      Created Doc ID.
	 * @param string              $key_hash     Owner-bound key hash.
	 * @param array<string,mixed> $original_row Row before the Doc was created.
	 */
	private function discardCreatedDoc( string $job_id, int $user_id, int $post_id, string $file_id, string $key_hash, array $original_row ): void {
		$remaining = $this->trashTemporaries( $user_id, array( $file_id ) );

		for ( $attempt = 0; $attempt < self::DISCARD_SAVE_ATTEMPTS; $attempt++ ) {
			if ( ! $this->jobs->lock( $job_id ) ) {
				continue;
			}

			$current = $this->jobs->getForWorker( $job_id );

			if ( null === $current ) {
				continue;
			}

			if ( absint( $current['ownerUserId'] ) !== $user_id ) {
				return;
			}

			$next = $this->withoutCreatedDoc( $current, $post_id, $file_id, $key_hash, $original_row, $remaining );

			if ( $next === $current || true === $this->jobs->save( $next ) ) {
				return;
			}
		}
	}

	/**
	 * A stored job with one discarded created Doc removed.
	 *
	 * Reverts the row, inventory entry, and replay record the creation wrote,
	 * and keeps the ID in `googleTemporaries` only while its trash failed.
	 *
	 * @param array<string,mixed> $job          Freshly read job.
	 * @param int                 $post_id      Post ID.
	 * @param string              $file_id      Created Doc ID.
	 * @param string              $key_hash     Owner-bound key hash.
	 * @param array<string,mixed> $original_row Row before the Doc was created.
	 * @param array<int,string>   $remaining    IDs whose trash failed.
	 * @return array<string,mixed>
	 */
	private function withoutCreatedDoc( array $job, int $post_id, string $file_id, string $key_hash, array $original_row, array $remaining ): array {
		if ( ( $job['createDocs'][ $key_hash ]['fileId'] ?? null ) === $file_id ) {
			unset( $job['createDocs'][ $key_hash ] );
		}

		if ( 'created' === ( $job['docs'][ $file_id ]['status'] ?? null ) ) {
			unset( $job['docs'][ $file_id ] );
		}

		foreach ( (array) ( $job['rows'] ?? array() ) as $index => $row ) {
			if ( is_array( $row ) && absint( $row['postId'] ?? 0 ) === $post_id && 'created' === ( $row['state'] ?? null ) && ( $row['selectedFileId'] ?? null ) === $file_id && array() !== $original_row ) {
				$job['rows'][ $index ] = $original_row;
			}
		}

		$temporaries = $this->stringList( $job['googleTemporaries'] ?? array() );
		$kept        = in_array( $file_id, $remaining, true );

		if ( in_array( $file_id, $temporaries, true ) !== $kept ) {
			$job['googleTemporaries'] = $kept ? array_merge( $temporaries, array( $file_id ) ) : array_values( array_diff( $temporaries, array( $file_id ) ) );
		}

		if ( $kept && true !== ( $job['cleanupPending'] ?? false ) ) {
			$job = $this->withCleanupBackoff( $job );
		}

		return $job;
	}

	/**
	 * Retry trashing the created Docs an active job no longer offers in any row.
	 *
	 * Docs that a `created` row still offers stay until commit or expiry.
	 *
	 * @param array<string,mixed> $job Freshly read, unexpired job with the lease held.
	 */
	private function retryOrphanCleanup( array $job ): void {
		if ( true !== ( $job['cleanupPending'] ?? false ) || absint( $job['nextCleanupAt'] ?? 0 ) > time() ) {
			return;
		}

		$offered = array();

		foreach ( (array) ( $job['rows'] ?? array() ) as $row ) {
			if ( is_array( $row ) && 'created' === ( $row['state'] ?? null ) && is_string( $row['selectedFileId'] ?? null ) ) {
				$offered[] = $row['selectedFileId'];
			}
		}

		$temporaries = $this->stringList( $job['googleTemporaries'] ?? array() );
		$orphans     = array_values( array_diff( $temporaries, $offered ) );
		$remaining   = $this->trashTemporaries( absint( $job['ownerUserId'] ), $orphans );

		$job['googleTemporaries'] = array_values( array_diff( $temporaries, array_diff( $orphans, $remaining ) ) );

		if ( array() === $remaining ) {
			$job['cleanupPending']  = false;
			$job['cleanupAttempts'] = 0;
			$job['nextCleanupAt']   = 0;
		} else {
			$job = $this->withCleanupBackoff( $job );
		}

		$this->jobs->save( $job );
	}

	/**
	 * Mark a job for a cleanup retry after one more failed attempt.
	 *
	 * The delay doubles from 1 hour per failed attempt, capped at 24 hours.
	 *
	 * @param array<string,mixed> $job Job.
	 * @return array<string,mixed>
	 */
	private function withCleanupBackoff( array $job ): array {
		$attempts = absint( $job['cleanupAttempts'] ?? 0 ) + 1;

		$job['cleanupPending']  = true;
		$job['cleanupAttempts'] = $attempts;
		$job['nextCleanupAt']   = time() + (int) min( self::CLEANUP_MAX_SECONDS, self::CLEANUP_BASE_SECONDS * ( 2 ** min( 10, $attempts - 1 ) ) );

		return $job;
	}

	/**
	 * A post may get a created Doc only when it is unlinked and has none yet.
	 *
	 * @param array<string,mixed> $job     Job.
	 * @param int                 $post_id Post ID.
	 * @return bool|WP_Error
	 */
	private function assertRowAcceptsCreatedDoc( array $job, int $post_id ): bool|WP_Error {
		$row = $this->findRow( $job, $post_id );

		if ( ! is_array( $row ) ) {
			return $this->postNotInJobError();
		}

		if ( 'alreadyLinked' === $row['state'] || null !== $this->source_repository->getSource( $post_id ) ) {
			return new WP_Error(
				'docsync_wp_post_already_linked',
				__( 'This post is already linked to a Google Doc.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		if ( 'created' === $row['state'] ) {
			return new WP_Error(
				'docsync_wp_matching_doc_already_created',
				__( 'A Google Doc was already created for this post in this linking job.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * Stored commit result for a replayed key, a conflict, or null to run.
	 *
	 * @param array<string,mixed> $job          Job.
	 * @param string              $key_hash     Owner-bound key hash.
	 * @param string              $request_hash Body hash.
	 * @return array<string,mixed>|WP_Error|null
	 */
	private function replayCommit( array $job, string $key_hash, string $request_hash ): array|WP_Error|null {
		$record = $job['commits'][ $key_hash ] ?? null;

		if ( ! is_array( $record ) ) {
			return null;
		}

		if ( ( $record['requestHash'] ?? '' ) !== $request_hash ) {
			return $this->idempotencyConflictError();
		}

		return 'complete' === ( $record['status'] ?? '' ) ? $this->formatCommit( $job, $record ) : null;
	}

	/**
	 * Stored create-doc result for a replayed key, a conflict, or null to run.
	 *
	 * @param array<string,mixed> $job          Job.
	 * @param string              $key_hash     Owner-bound key hash.
	 * @param string              $request_hash Body hash.
	 * @return array<string,mixed>|WP_Error|null
	 */
	private function replayCreateDoc( array $job, string $key_hash, string $request_hash ): array|WP_Error|null {
		$record = $job['createDocs'][ $key_hash ] ?? null;

		if ( ! is_array( $record ) ) {
			return null;
		}

		if ( ( $record['requestHash'] ?? '' ) !== $request_hash ) {
			return $this->idempotencyConflictError();
		}

		$row = $this->findRow( $job, absint( $record['postId'] ?? 0 ) );

		return array( 'row' => is_array( $row ) ? (array) $this->formatRow( $job, $row ) : array() );
	}

	/**
	 * Commit response from a stored record.
	 *
	 * @param array<string,mixed> $job    Job.
	 * @param array<string,mixed> $record Commit record.
	 * @return array{jobId:string,results:array<int,array<string,mixed>>}
	 */
	private function formatCommit( array $job, array $record ): array {
		$results = (array) ( $record['results'] ?? array() );

		ksort( $results, SORT_NUMERIC );

		return array(
			'jobId'   => (string) $job['jobId'],
			'results' => array_values( $results ),
		);
	}

	/**
	 * Per-pair result.
	 *
	 * @param int           $post_id Post ID.
	 * @param string        $file_id Doc ID.
	 * @param WP_Error|null $error   Failure, or null when linked.
	 * @return array<string,mixed>
	 */
	private function pairResult( int $post_id, string $file_id, ?WP_Error $error ): array {
		return array(
			'postId' => $post_id,
			'fileId' => $file_id,
			'status' => null === $error ? SyncService::STATUS_LINKED : 'failed',
			'error'  => null === $error ? null : array(
				'code'    => (string) $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
		);
	}

	/**
	 * Format one row for REST responses, or null when its post is gone.
	 *
	 * @param array<string,mixed> $job Job.
	 * @param array<string,mixed> $row Internal row.
	 * @return array<string,mixed>|null
	 */
	private function formatRow( array $job, array $row ): ?array {
		$post_id = absint( $row['postId'] ?? 0 );
		$post    = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$owner      = absint( $job['ownerUserId'] );
		$edit_link  = get_edit_post_link( $post_id, 'raw' );
		$candidates = array();

		foreach ( (array) ( $row['candidates'] ?? array() ) as $candidate ) {
			$doc = is_array( $candidate ) ? ( $job['docs'][ $candidate['fileId'] ] ?? null ) : null;

			if ( ! is_array( $doc ) ) {
				continue;
			}

			$linked_post_id = absint( $doc['linkedPostId'] ?? 0 );
			$candidates[]   = array(
				'fileId'       => (string) $doc['fileId'],
				'name'         => (string) $doc['name'],
				'webViewLink'  => (string) $doc['webViewLink'],
				'modifiedTime' => (string) $doc['modifiedTime'],
				'kind'         => (string) $candidate['kind'],
				'score'        => (float) $candidate['score'],
				'linkedPostId' => $linked_post_id > 0 && user_can( $owner, 'edit_post', $linked_post_id ) ? $linked_post_id : null,
			);
		}

		return array(
			'postId'           => $post_id,
			'postTitle'        => $this->postTitle( $post ),
			'postType'         => $post->post_type,
			'editUrl'          => is_string( $edit_link ) ? esc_url_raw( $edit_link ) : '',
			'state'            => (string) $row['state'],
			'selectedFileId'   => in_array( $row['state'], array( 'preselected', 'created' ), true ) ? $row['selectedFileId'] : null,
			'matchKind'        => $row['matchKind'],
			'preselectBlocked' => $row['preselectBlocked'],
			'candidates'       => $candidates,
		);
	}

	/**
	 * Validate and resolve the job's posts.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $posts   `{postType,postIds?}`.
	 * @return array{postType:string,postIds:array<int,int>}|WP_Error
	 */
	private function resolvePosts( int $user_id, array $posts ): array|WP_Error {
		if ( array() !== array_diff( array_keys( $posts ), array( 'postType', 'postIds' ) ) ) {
			return $this->invalidInputError();
		}

		$post_type = $posts['postType'] ?? null;

		if ( ! is_string( $post_type ) || '' === $post_type || sanitize_key( $post_type ) !== $post_type || ! $this->source_repository->isPostTypeEnabled( $post_type ) ) {
			return $this->invalidInputError();
		}

		if ( ! $this->source_repository->userCanEditPostType( $post_type, $user_id ) ) {
			return $this->postForbiddenError();
		}

		if ( ! array_key_exists( 'postIds', $posts ) ) {
			return array(
				'postType' => $post_type,
				'postIds'  => $this->recentUnlinkedPostIds( $user_id, $post_type ),
			);
		}

		$raw_ids = $posts['postIds'];

		if ( ! is_array( $raw_ids ) || ! array_is_list( $raw_ids ) || array() === $raw_ids || count( $raw_ids ) > self::MAX_POSTS ) {
			return $this->invalidInputError();
		}

		$post_ids = array();

		foreach ( $raw_ids as $raw_id ) {
			if ( ! is_int( $raw_id ) && ! ( is_string( $raw_id ) && ctype_digit( $raw_id ) ) ) {
				return $this->invalidInputError();
			}

			$post_id = absint( $raw_id );
			$post    = $post_id > 0 ? get_post( $post_id ) : null;

			if ( ! $post instanceof WP_Post || $post->post_type !== $post_type || in_array( $post->post_status, self::EXCLUDED_POST_STATUSES, true ) ) {
				return $this->invalidInputError();
			}

			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				return $this->postForbiddenError();
			}

			$post_ids[ $post_id ] = $post_id;
		}

		return array(
			'postType' => $post_type,
			'postIds'  => array_values( $post_ids ),
		);
	}

	/**
	 * The 100 most recently modified unlinked posts of a type that the user can edit.
	 *
	 * @param int    $user_id   User ID.
	 * @param string $post_type Post type.
	 * @return array<int,int>
	 */
	private function recentUnlinkedPostIds( int $user_id, string $post_type ): array {
		$found   = array();
		$scanned = 0;
		$page    = 0;

		while ( $scanned < self::POST_SCAN_LIMIT ) {
			++$page;
			$query = new WP_Query(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'any',
					'fields'                 => 'ids',
					'orderby'                => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'posts_per_page'         => 100,
					'paged'                  => $page,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						'relation' => 'OR',
						array(
							'key'     => SourceRepository::META_FILE_ID,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => SourceRepository::META_FILE_ID,
							'value'   => '',
							'compare' => '=',
						),
					),
				)
			);

			$ids = array_map( 'absint', (array) $query->posts );

			foreach ( $ids as $post_id ) {
				++$scanned;

				if ( $post_id > 0 && user_can( $user_id, 'edit_post', $post_id ) ) {
					$found[] = $post_id;

					if ( count( $found ) >= self::MAX_POSTS ) {
						return $found;
					}
				}
			}

			if ( count( $ids ) < 100 ) {
				break;
			}
		}

		return $found;
	}

	/**
	 * Validate the candidate scope.
	 *
	 * @param array<string,mixed> $scope Raw scope.
	 * @return array{location:string,folderId:string,driveId:string,fileIds:array<int,string>}|WP_Error
	 */
	private function normalizeScope( array $scope ): array|WP_Error {
		if ( array() !== array_diff( array_keys( $scope ), array( 'location', 'folderId', 'driveId', 'fileIds' ) ) ) {
			return $this->invalidInputError();
		}

		$location  = $scope['location'] ?? 'myDrive';
		$folder_id = $scope['folderId'] ?? '';
		$drive_id  = $scope['driveId'] ?? '';
		$file_ids  = $scope['fileIds'] ?? array();

		if (
			! is_string( $location ) || ! in_array( $location, self::LOCATIONS, true )
			|| ! is_string( $folder_id ) || ( '' !== $folder_id && 'root' !== $folder_id && 1 !== preg_match( self::FILE_ID_PATTERN, $folder_id ) )
			|| ! is_string( $drive_id ) || ( '' !== $drive_id && 1 !== preg_match( self::FILE_ID_PATTERN, $drive_id ) )
			|| ! is_array( $file_ids ) || ! array_is_list( $file_ids ) || count( $file_ids ) > self::MAX_FILE_IDS
		) {
			return $this->invalidInputError();
		}

		$unique = array();

		foreach ( $file_ids as $file_id ) {
			if ( ! is_string( $file_id ) || 1 !== preg_match( self::FILE_ID_PATTERN, $file_id ) ) {
				return $this->invalidInputError();
			}

			$unique[ $file_id ] = $file_id;
		}

		if ( array() === $unique && 'sharedDrive' === $location && '' === $drive_id ) {
			return $this->invalidInputError();
		}

		return array(
			'location' => $location,
			'folderId' => $folder_id,
			'driveId'  => $drive_id,
			'fileIds'  => array_values( $unique ),
		);
	}

	/**
	 * Display name of the scope root; a chosen folder must be readable.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $scope   Normalized scope.
	 * @return string|WP_Error
	 */
	private function resolveRootName( int $user_id, array $scope ): string|WP_Error {
		if ( array() !== $scope['fileIds'] ) {
			return '';
		}

		if ( '' !== $scope['folderId'] && 'root' !== $scope['folderId'] ) {
			$folder = $this->drive_client->getDriveItem( $user_id, $scope['folderId'] );

			if ( is_wp_error( $folder ) ) {
				return $folder;
			}

			if ( 'folder' !== ( $folder['itemType'] ?? '' ) ) {
				return $this->invalidInputError();
			}

			return (string) $folder['name'];
		}

		if ( 'sharedDrive' === $scope['location'] ) {
			$drive = $this->drive_client->getDriveItem( $user_id, $scope['driveId'] );

			return ! is_wp_error( $drive ) && '' !== (string) ( $drive['name'] ?? '' ) ? (string) $drive['name'] : __( 'Shared drive', 'brasth-document-sync-for-google-docs' );
		}

		if ( '' === $scope['folderId'] && 'sharedWithMe' === $scope['location'] ) {
			return __( 'Shared with me', 'brasth-document-sync-for-google-docs' );
		}

		if ( '' === $scope['folderId'] && 'recent' === $scope['location'] ) {
			return __( 'Recent', 'brasth-document-sync-for-google-docs' );
		}

		if ( '' === $scope['folderId'] && 'starred' === $scope['location'] ) {
			return __( 'Starred', 'brasth-document-sync-for-google-docs' );
		}

		return __( 'My Drive', 'brasth-document-sync-for-google-docs' );
	}

	/**
	 * Initial job state handed to the repository.
	 *
	 * @param array{postType:string,postIds:array<int,int>} $posts Resolved posts.
	 * @param array<string,mixed>                           $scope Normalized scope with root name.
	 * @return array<string,mixed>
	 */
	private function initialState( array $posts, array $scope ): array {
		$queue   = array();
		$visited = array();

		if ( array() === $scope['fileIds'] ) {
			$flat = '' === $scope['folderId'] && in_array( $scope['location'], self::FLAT_LOCATIONS, true );

			if ( $flat ) {
				$folder_id = '';
			} elseif ( '' !== $scope['folderId'] ) {
				$folder_id = $scope['folderId'];
			} else {
				$folder_id = 'sharedDrive' === $scope['location'] ? $scope['driveId'] : 'root';
			}

			$queue[] = array(
				'folderId'  => $folder_id,
				'driveId'   => 'sharedDrive' === $scope['location'] ? $scope['driveId'] : '',
				'pageToken' => '',
				'depth'     => 0,
				'attempts'  => 0,
				'path'      => (string) $scope['rootName'],
				'location'  => $flat ? $scope['location'] : '',
			);

			if ( '' !== $folder_id ) {
				$visited[] = $folder_id;
			}
		}

		return array(
			'postType'   => $posts['postType'],
			'posts'      => array_map(
				static function ( int $post_id ): array {
					return array(
						'postId' => $post_id,
						'status' => 'pending',
					);
				},
				$posts['postIds']
			),
			'scope'      => $scope,
			'inventory'  => array(
				'complete'         => false,
				'docsFound'        => 0,
				'foldersVisited'   => 0,
				'pagesFetched'     => 0,
				'warnings'         => array(),
				'queue'            => $queue,
				'visitedFolderIds' => $visited,
				'fileIds'          => $scope['fileIds'],
				'fileAttempts'     => array(),
			),
			'docs'       => array(),
			'progress'   => array(
				'processed' => 0,
				'total'     => 0,
			),
			'rows'       => array(),
			'error'      => null,
			'compares'   => array(),
			'commits'    => array(),
			'createDocs' => array(),
		);
	}

	/**
	 * `searchDriveItems` query for a queue entry.
	 *
	 * Folder pages list one folder's direct children; flat entries page through
	 * Shared with me, Recent, or Starred.
	 *
	 * @param array<string,mixed> $entry Queue entry.
	 * @return array<string,mixed>
	 */
	private function listQuery( array $entry ): array {
		$query = array(
			'pageToken' => (string) $entry['pageToken'],
			'pageSize'  => self::LIST_PAGE_SIZE,
		);

		if ( '' !== (string) $entry['location'] ) {
			$query['location'] = (string) $entry['location'];

			return $query;
		}

		$query['location'] = '' !== (string) $entry['driveId'] ? 'sharedDrive' : 'myDrive';
		$query['folderId'] = (string) $entry['folderId'];
		$query['driveId']  = (string) $entry['driveId'];

		return $query;
	}

	/**
	 * Normalize commit pairs and enforce one-to-one pairing.
	 *
	 * @param array<int|string,mixed> $pairs Raw pairs.
	 * @return array<int,array{postId:int,fileId:string,compareFingerprint:string}>|WP_Error
	 */
	private function normalizePairs( array $pairs ): array|WP_Error {
		if ( ! array_is_list( $pairs ) || array() === $pairs || count( $pairs ) > self::MAX_POSTS ) {
			return $this->invalidInputError();
		}

		$normalized = array();
		$posts      = array();
		$files      = array();

		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || array() !== array_diff( array_keys( $pair ), array( 'postId', 'fileId', 'compareFingerprint' ) ) ) {
				return $this->invalidInputError();
			}

			$post_id     = $pair['postId'] ?? null;
			$file_id     = $pair['fileId'] ?? null;
			$fingerprint = $pair['compareFingerprint'] ?? '';

			if (
				( ! is_int( $post_id ) && ! ( is_string( $post_id ) && ctype_digit( $post_id ) ) ) || absint( $post_id ) <= 0
				|| ! is_string( $file_id ) || 1 !== preg_match( self::FILE_ID_PATTERN, $file_id )
				|| ! is_string( $fingerprint ) || ( '' !== $fingerprint && 1 !== preg_match( self::FINGERPRINT_PATTERN, $fingerprint ) )
			) {
				return $this->invalidInputError();
			}

			$post_id = absint( $post_id );

			if ( isset( $posts[ $post_id ] ) || isset( $files[ $file_id ] ) ) {
				return new WP_Error(
					'docsync_wp_matching_not_one_to_one',
					__( 'Each post and each Google Doc can appear only once.', 'brasth-document-sync-for-google-docs' ),
					array( 'status' => 400 )
				);
			}

			$posts[ $post_id ] = true;
			$files[ $file_id ] = true;
			$normalized[]      = array(
				'postId'             => $post_id,
				'fileId'             => $file_id,
				'compareFingerprint' => $fingerprint,
			);
		}

		return $normalized;
	}

	/**
	 * Read a job that has finished matching.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $user_id User ID.
	 * @return array<string,mixed>|WP_Error
	 */
	private function reviewableJob( string $job_id, int $user_id ): array|WP_Error {
		$job = $this->jobs->get( $job_id, $user_id );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( ! in_array( $job['status'], self::REVIEW_STATUSES, true ) ) {
			return new WP_Error(
				'docsync_wp_matching_job_not_ready',
				__( 'This linking job is still looking for matching Google Docs.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 409 )
			);
		}

		return $job;
	}

	/**
	 * A post that belongs to the job and is still editable by the user.
	 *
	 * @param array<string,mixed> $job     Job.
	 * @param int                 $post_id Post ID.
	 * @param int                 $user_id User ID.
	 * @return WP_Post|WP_Error
	 */
	private function jobPost( array $job, int $post_id, int $user_id ): WP_Post|WP_Error {
		if ( null === $this->findPostFeatures( $job, $post_id ) ) {
			return $this->postNotInJobError();
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || in_array( $post->post_status, self::EXCLUDED_POST_STATUSES, true ) ) {
			return $this->postNotInJobError();
		}

		if ( ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) ) {
			return $this->postForbiddenError();
		}

		return $post;
	}

	/**
	 * Features of a job post that matching read.
	 *
	 * @param array<string,mixed> $job     Job.
	 * @param int                 $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	private function findPostFeatures( array $job, int $post_id ): ?array {
		foreach ( (array) ( $job['posts'] ?? array() ) as $entry ) {
			if ( is_array( $entry ) && absint( $entry['postId'] ?? 0 ) === $post_id && 'ready' === ( $entry['status'] ?? '' ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Internal row of a post.
	 *
	 * @param array<string,mixed> $job     Job.
	 * @param int                 $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	private function findRow( array $job, int $post_id ): ?array {
		foreach ( (array) ( $job['rows'] ?? array() ) as $row ) {
			if ( is_array( $row ) && absint( $row['postId'] ?? 0 ) === $post_id ) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * Whether any post still needs its features computed.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	private function hasPendingPosts( array $job ): bool {
		foreach ( $job['posts'] as $entry ) {
			if ( 'pending' === ( $entry['status'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any Doc still needs to be read.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	private function hasPendingDocs( array $job ): bool {
		foreach ( $job['docs'] as $doc ) {
			if ( 'pending' === $doc['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Trash app-created Docs; return the IDs whose trash must be retried.
	 *
	 * A Doc linked to any post is resolved without trashing. Missing,
	 * already-trashed, and not-app-created files are resolved by the client.
	 *
	 * @param int               $user_id  Owner whose Google connection trashes the files.
	 * @param array<int,string> $file_ids Google file IDs.
	 * @return array<int,string>
	 */
	private function trashTemporaries( int $user_id, array $file_ids ): array {
		$remaining = array();

		foreach ( $file_ids as $file_id ) {
			if ( null !== $this->source_repository->findPostIdByGoogleFileId( $file_id ) ) {
				continue;
			}

			$trashed = $this->drive_write->trashAppCreatedFile( $user_id, $file_id );

			if ( true === $trashed ) {
				continue;
			}

			$data = is_wp_error( $trashed ) ? $trashed->get_error_data() : null;

			if ( is_array( $data ) && ! empty( $data['resolved'] ) ) {
				continue;
			}

			$remaining[] = $file_id;
		}

		return $remaining;
	}

	/**
	 * Record an inventory warning once; every warning makes the inventory incomplete.
	 *
	 * @param array<string,mixed> $job       Job, updated in place.
	 * @param string              $code      Warning code.
	 * @param string              $message   Message.
	 * @param string|null         $folder_id Folder ID.
	 * @param string|null         $file_id   File ID.
	 */
	private function addWarning( array &$job, string $code, string $message, ?string $folder_id, ?string $file_id ): void {
		$job['inventory']['complete'] = false;

		foreach ( $job['inventory']['warnings'] as $warning ) {
			if ( $warning['code'] === $code && $warning['folderId'] === $folder_id && $warning['fileId'] === $file_id ) {
				return;
			}
		}

		if ( count( $job['inventory']['warnings'] ) >= self::MAX_WARNINGS ) {
			return;
		}

		$job['inventory']['warnings'][] = array(
			'code'     => $code,
			'message'  => $message,
			'folderId' => $folder_id,
			'fileId'   => $file_id,
		);
	}

	/**
	 * Record an unreadable candidate file.
	 *
	 * @param array<string,mixed> $job     Job, updated in place.
	 * @param string              $file_id File ID.
	 * @param string              $reason  Reason from Google.
	 */
	private function addFileUnavailable( array &$job, string $file_id, string $reason ): void {
		$this->addWarning(
			$job,
			'fileUnavailable',
			/* translators: %s: reason reported by Google. */
			sprintf( __( 'Brasth Document Sync could not read this Google Doc: %s', 'brasth-document-sync-for-google-docs' ), $reason ),
			null,
			$file_id
		);
	}

	/**
	 * Stop a job on an error every later tick would hit too.
	 *
	 * @param array<string,mixed> $job   Job, updated in place.
	 * @param WP_Error            $error Error.
	 */
	private function failJob( array &$job, WP_Error $error ): void {
		$job['status'] = 'failed';
		$job['error']  = array(
			'code'    => (string) $error->get_error_code(),
			'message' => $error->get_error_message(),
		);
	}

	/**
	 * Errors that fail the whole job: no connection, missing scope, or Docs API disabled.
	 *
	 * @param WP_Error $error Error.
	 */
	private function isFatalGoogleError( WP_Error $error ): bool {
		$data = $error->get_error_data();

		return in_array( $error->get_error_code(), self::FATAL_ERROR_CODES, true )
			|| ( is_array( $data ) && 401 === absint( $data['status'] ?? 0 ) );
	}

	/**
	 * Errors worth retrying on a later tick (rate limits and server errors).
	 *
	 * @param WP_Error $error Error.
	 */
	private function isTransientGoogleError( WP_Error $error ): bool {
		$data   = $error->get_error_data();
		$status = is_array( $data ) ? absint( $data['status'] ?? 0 ) : 0;

		return 429 === $status || $status >= 500;
	}

	/**
	 * Whether a job is past its expiry.
	 *
	 * @param array<string,mixed> $job Job.
	 */
	private function isExpired( array $job ): bool {
		return 'expired' === $job['status'] || absint( $job['expiresAt'] ?? 0 ) <= time();
	}

	/**
	 * Schedule the next tick unless an earlier one is already scheduled.
	 *
	 * @param string $job_id  Job ID.
	 * @param int    $delay   Seconds from now.
	 * @param bool   $replace Whether a later scheduled tick is moved earlier.
	 */
	private function scheduleRun( string $job_id, int $delay, bool $replace = true ): void {
		$args = array( $job_id );
		$at   = time() + max( 0, $delay );
		$next = wp_next_scheduled( self::RUN_HOOK, $args );

		if ( false !== $next ) {
			if ( $next <= $at || ! $replace ) {
				return;
			}

			wp_unschedule_event( $next, self::RUN_HOOK, $args );
		}

		wp_schedule_single_event( $at, self::RUN_HOOK, $args );
	}

	/**
	 * `sha256(postId|fileId|post_modified_gmt|doc version)`.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $file_id      Doc ID.
	 * @param string $modified_gmt Post modified time (GMT).
	 * @param string $version      Doc version.
	 */
	private function compareFingerprint( int $post_id, string $file_id, string $modified_gmt, string $version ): string {
		return hash( 'sha256', $post_id . '|' . $file_id . '|' . $modified_gmt . '|' . $version );
	}

	/**
	 * Plain-text excerpt of rendered HTML or escaped Doc text.
	 *
	 * @param string $html HTML.
	 */
	private function excerpt( string $html ): string {
		$text = html_entity_decode( wp_strip_all_tags( strip_shortcodes( $html ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );

		return sanitize_textarea_field( wp_trim_words( $text, self::EXCERPT_WORDS, "\u{2026}" ) );
	}

	/**
	 * Stored post title as plain text, without "Private:" or "Protected:" prefixes.
	 *
	 * @param WP_Post $post Post.
	 */
	private function postTitle( WP_Post $post ): string {
		return html_entity_decode( wp_strip_all_tags( (string) $post->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Post modified time as ISO-8601 UTC.
	 *
	 * @param WP_Post $post Post.
	 */
	private function postModifiedAt( WP_Post $post ): string {
		$timestamp = get_post_modified_time( 'U', true, $post );

		return is_numeric( $timestamp ) ? $this->isoTime( (int) $timestamp ) : '';
	}

	/**
	 * ISO-8601 UTC timestamp.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	private function isoTime( int $timestamp ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/**
	 * Join a folder path and a child name.
	 *
	 * @param string $path Parent path.
	 * @param string $name Child name.
	 */
	private function joinPath( string $path, string $name ): string {
		return '' === $path ? $name : $path . ' / ' . $name;
	}

	/**
	 * Keep the newest entries of an append-ordered map.
	 *
	 * @param array<string,mixed> $map   Map.
	 * @param int                 $limit Maximum entries.
	 * @return array<string,mixed>
	 */
	private function capMap( array $map, int $limit ): array {
		return count( $map ) > $limit ? array_slice( $map, -$limit, null, true ) : $map;
	}

	/**
	 * Stored list of strings.
	 *
	 * @param mixed $value Stored value.
	 * @return array<int,string>
	 */
	private function stringList( mixed $value ): array {
		return is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : array();
	}

	/**
	 * Malformed request.
	 */
	private function invalidInputError(): WP_Error {
		return new WP_Error(
			'docsync_wp_matching_invalid_input',
			__( 'Brasth Document Sync received an invalid linking request.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Post not editable by the caller.
	 */
	private function postForbiddenError(): WP_Error {
		return new WP_Error(
			'docsync_wp_matching_post_forbidden',
			__( 'You do not have permission to link one of these posts.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Post is not part of the job.
	 */
	private function postNotInJobError(): WP_Error {
		return new WP_Error(
			'docsync_wp_matching_post_not_in_job',
			__( 'This post is not part of this linking job.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Another request holds the job lease.
	 */
	private function jobBusyError(): WP_Error {
		return new WP_Error(
			'docsync_wp_matching_job_busy',
			__( 'This linking job is busy. Wait a moment, then try again.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Idempotency key reused with a different body.
	 */
	private function idempotencyConflictError(): WP_Error {
		return new WP_Error(
			'docsync_wp_idempotency_conflict',
			__( 'This request key was already used for a different request.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 409 )
		);
	}
}
