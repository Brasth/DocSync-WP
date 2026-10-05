<?php
/**
 * Post list table sync actions.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Admin;

use DocSyncWP\Cron\SyncCron;
use DocSyncWP\Rest\RestPermissions;
use DocSyncWP\Sync\Elementor\SyncDecider;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds list-table actions and source status columns.
 */
final class PostListActions {
	private const STATUS_COLUMN = 'docsync_wp_status';
	private const BULK_ACTION   = 'docsync_wp_sync';
	private const BULK_LIMIT    = 20;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Elementor sync decider.
	 *
	 * @var SyncDecider
	 */
	private SyncDecider $elementor_decider;

	/**
	 * Sync service.
	 *
	 * @var SyncService|null
	 */
	private ?SyncService $sync_service;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository $source_repository Source repository.
	 * @param SyncDecider      $elementor_decider Elementor sync decider.
	 * @param SyncService|null $sync_service      Sync service used by the bulk action.
	 */
	public function __construct( SourceRepository $source_repository, SyncDecider $elementor_decider, ?SyncService $sync_service = null ) {
		$this->source_repository = $source_repository;
		$this->elementor_decider = $elementor_decider;
		$this->sync_service      = $sync_service;
	}

	/**
	 * Register hooks after public custom post types are available.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerPostTypeHooks' ), 20 );
		add_action( 'restrict_manage_posts', array( $this, 'renderTopAction' ), 10, 2 );
		add_action( 'admin_notices', array( $this, 'renderBulkNotice' ) );
		add_filter( 'removable_query_args', array( $this, 'removableQueryArgs' ) );
	}

	/**
	 * Register filters for every enabled post type.
	 */
	public function registerPostTypeHooks(): void {
		add_filter( 'post_row_actions', array( $this, 'addRowAction' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'addRowAction' ), 10, 2 );

		foreach ( $this->source_repository->getEnabledPostTypes() as $post_type ) {
			add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'addBulkAction' ) );
			add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handleBulkAction' ), 10, 3 );

			if ( 'page' === $post_type ) {
				add_filter( 'manage_pages_columns', array( $this, 'addStatusColumn' ) );
				add_action( 'manage_pages_custom_column', array( $this, 'renderStatusColumn' ), 10, 2 );
				continue;
			}

			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'addStatusColumn' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'renderStatusColumn' ), 10, 2 );
		}
	}

	/**
	 * Add the bulk sync action.
	 *
	 * @param array<string,string> $actions Bulk actions.
	 * @return array<string,string>
	 */
	public function addBulkAction( array $actions ): array {
		if ( null !== $this->sync_service ) {
			$actions[ self::BULK_ACTION ] = __( 'Sync from Google Docs', 'brasth-document-sync-for-google-docs' );
		}

		return $actions;
	}

	/**
	 * Queue background syncs for the selected linked posts.
	 *
	 * Posts with WordPress edits inside synced content are skipped; sync those from the editor to confirm.
	 *
	 * @param string           $redirect_url Redirect URL.
	 * @param string           $action       Bulk action name.
	 * @param array<int,mixed> $post_ids     Selected post IDs.
	 */
	public function handleBulkAction( string $redirect_url, string $action, array $post_ids ): string {
		if ( self::BULK_ACTION !== $action || null === $this->sync_service ) {
			return $redirect_url;
		}

		$user_id = get_current_user_id();
		$queued  = 0;
		$skipped = max( 0, count( $post_ids ) - self::BULK_LIMIT );

		foreach ( array_slice( array_map( 'absint', $post_ids ), 0, self::BULK_LIMIT ) as $post_id ) {
			$source = $this->source_repository->getSource( $post_id );

			if ( null === $source || ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) || $this->sync_service->hasLocalEdits( $post_id ) ) {
				++$skipped;
				continue;
			}

			$owner_id = absint( $source['sync_owner_user_id'] ?? 0 );
			$owner_id = $owner_id > 0 ? $owner_id : $user_id;
			$result   = $this->sync_service->markSyncQueued( $post_id, $owner_id, SyncCron::hasScheduledSourceSync( $post_id, $owner_id ) );

			if ( is_wp_error( $result ) ) {
				++$skipped;
				continue;
			}

			if ( empty( $result['alreadyQueued'] ) ) {
				$scheduled = SyncCron::scheduleSourceSync( $post_id, $owner_id, false );

				if ( is_wp_error( $scheduled ) ) {
					$this->sync_service->markSyncError( $post_id, $scheduled );
					++$skipped;
					continue;
				}
			}

			++$queued;
		}

		if ( $queued > 0 ) {
			SyncCron::spawnScheduledSyncs();
		}

		return add_query_arg(
			array(
				'docsync_queued'  => $queued,
				'docsync_skipped' => $skipped,
			),
			$redirect_url
		);
	}

	/**
	 * Let WordPress strip the bulk result arguments after the first load.
	 *
	 * @param array<int,string> $args Removable query args.
	 * @return array<int,string>
	 */
	public function removableQueryArgs( array $args ): array {
		return array_merge( $args, array( 'docsync_queued', 'docsync_skipped' ) );
	}

	/**
	 * Show the bulk sync result.
	 */
	public function renderBulkNotice(): void {
		$queued  = filter_input( INPUT_GET, 'docsync_queued', FILTER_VALIDATE_INT );
		$skipped = filter_input( INPUT_GET, 'docsync_skipped', FILTER_VALIDATE_INT );

		if ( null === $queued || false === $queued || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %d: number of posts queued for sync. */
			_n( 'Queued Google Docs sync for %d post.', 'Queued Google Docs sync for %d posts.', $queued, 'brasth-document-sync-for-google-docs' ),
			$queued
		);

		if ( is_int( $skipped ) && $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of posts skipped. */
				_n( '%d post was skipped (not linked, no permission, or has WordPress edits in synced content).', '%d posts were skipped (not linked, no permission, or have WordPress edits in synced content).', $skipped, 'brasth-document-sync-for-google-docs' ),
				$skipped
			);
		}

		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Add row action for linked/unlinked posts.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param WP_Post              $post    Current post.
	 * @return array<string,string>
	 */
	public function addRowAction( array $actions, WP_Post $post ): array {
		if ( ! $this->source_repository->userCanSyncPost( $post->ID, get_current_user_id() ) ) {
			return $actions;
		}

		$source = $this->source_repository->getSource( $post->ID );
		$mode   = null === $source ? 'link' : 'sync';
		$label  = null === $source ? __( 'Link Google Doc', 'brasth-document-sync-for-google-docs' ) : __( 'Sync Doc', 'brasth-document-sync-for-google-docs' );

		$actions['docsync_wp'] = sprintf(
			'<a href="#" class="docsync-wp-row-action" data-mode="%1$s" data-post-id="%2$d" data-post-type="%3$s" data-default-elementor-sync="%4$s">%5$s</a>',
			esc_attr( $mode ),
			absint( $post->ID ),
			esc_attr( $post->post_type ),
			$this->elementor_decider->getDefaultElementorSync( $post->ID ) ? 'true' : 'false',
			esc_html( $label )
		);

		return $actions;
	}

	/**
	 * Add source status column.
	 *
	 * @param array<string,string> $columns Existing columns.
	 * @return array<string,string>
	 */
	public function addStatusColumn( array $columns ): array {
		if ( ! RestPermissions::currentUserCanUseDocSync() ) {
			return $columns;
		}

		$columns[ self::STATUS_COLUMN ] = __( 'Sync', 'brasth-document-sync-for-google-docs' );

		return $columns;
	}

	/**
	 * Render source status column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function renderStatusColumn( string $column, int $post_id ): void {
		if ( self::STATUS_COLUMN !== $column ) {
			return;
		}

		if ( ! $this->source_repository->userCanSyncPost( $post_id, get_current_user_id() ) ) {
			return;
		}

		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			echo '<span class="docsync-wp-list-status is-empty">' . esc_html__( 'Not linked', 'brasth-document-sync-for-google-docs' ) . '</span>';
			return;
		}

		$title  = '' !== $source['google_title'] ? $source['google_title'] : $source['google_file_id'];
		$status = '' !== $source['sync_status'] ? $source['sync_status'] : 'linked';

		echo '<div class="docsync-wp-list-status is-linked">';
		echo '<strong>' . esc_html( (string) $title ) . '</strong><br />';
		echo '<span>' . esc_html( 'skipped' === $status ? __( 'Up to date', 'brasth-document-sync-for-google-docs' ) : ucfirst( str_replace( '_', ' ', (string) $status ) ) ) . '</span>';
		$this->renderProgressDetails( $source );

		if ( '' !== $source['last_synced_at'] ) {
			echo '<br /><small>' . esc_html( (string) $source['last_synced_at'] ) . '</small>';
		}

		if ( '' !== $source['sync_error'] ) {
			echo '<br /><small class="docsync-wp-list-error">' . esc_html( (string) $source['sync_error'] ) . '</small>';
		}

		echo '</div>';
	}

	/**
	 * Render source sync progress details.
	 *
	 * @param array<string,mixed> $source Source metadata.
	 */
	private function renderProgressDetails( array $source ): void {
		$progress = isset( $source['sync_progress'] ) ? max( 0, min( 100, (int) $source['sync_progress'] ) ) : 0;
		$message  = isset( $source['sync_message'] ) ? (string) $source['sync_message'] : '';
		$status   = isset( $source['sync_status'] ) ? (string) $source['sync_status'] : '';

		if ( 'syncing' !== $status ) {
			return;
		}

		$label = sprintf(
			/* translators: %d: Sync progress percent. */
			__( 'Sync progress: %d%%', 'brasth-document-sync-for-google-docs' ),
			$progress
		);

		echo '<div class="docsync-wp-sync-progress" role="progressbar" aria-label="' . esc_attr( $label ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . esc_attr( (string) $progress ) . '">';
		echo '<span style="width:' . esc_attr( (string) $progress ) . '%"></span>';
		echo '</div>';

		if ( '' !== $message ) {
			echo '<small>' . esc_html( sprintf( '%d%% - %s', $progress, $message ) ) . '</small>';
		}
	}

	/**
	 * Render list-table top action mount point.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Top or bottom tablenav location.
	 */
	public function renderTopAction( string $post_type, string $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! $this->source_repository->userCanCreateSyncedPost( $post_type, $user_id ) ) {
			return;
		}

		?>
		<span id="docsync-wp-list-sync-root" data-post-type="<?php echo esc_attr( $post_type ); ?>"></span>
		<?php
	}
}
