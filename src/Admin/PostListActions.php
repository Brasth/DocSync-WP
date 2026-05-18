<?php
/**
 * Post list table DocSync actions.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Admin;

use DocSyncWP\Sync\SourceRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds list-table actions and source status columns.
 */
final class PostListActions {
	private const STATUS_COLUMN = 'docsync_wp_status';

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository $source_repository Source repository.
	 */
	public function __construct( SourceRepository $source_repository ) {
		$this->source_repository = $source_repository;
	}

	/**
	 * Register hooks after public custom post types are available.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerPostTypeHooks' ), 20 );
		add_action( 'restrict_manage_posts', array( $this, 'renderTopAction' ), 10, 2 );
	}

	/**
	 * Register filters for every enabled post type.
	 */
	public function registerPostTypeHooks(): void {
		add_filter( 'post_row_actions', array( $this, 'addRowAction' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'addRowAction' ), 10, 2 );

		foreach ( $this->source_repository->getEnabledPostTypes() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'addStatusColumn' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'renderStatusColumn' ), 10, 2 );
		}
	}

	/**
	 * Add row action for linked/unlinked posts.
	 *
	 * @param array<string,string> $actions Row actions.
	 * @param WP_Post             $post    Current post.
	 * @return array<string,string>
	 */
	public function addRowAction( array $actions, WP_Post $post ): array {
		if ( ! $this->source_repository->userCanSyncPost( $post->ID, get_current_user_id() ) ) {
			return $actions;
		}

		$source = $this->source_repository->getSource( $post->ID );
		$mode   = null === $source ? 'link' : 'sync';
		$label  = null === $source ? __( 'Link Google Doc', 'docsync-wp' ) : __( 'Sync Doc', 'docsync-wp' );

		$actions['docsync_wp'] = sprintf(
			'<a href="#" class="docsync-wp-row-action" data-mode="%1$s" data-post-id="%2$d" data-post-type="%3$s">%4$s</a>',
			esc_attr( $mode ),
			absint( $post->ID ),
			esc_attr( $post->post_type ),
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
		$columns[ self::STATUS_COLUMN ] = __( 'DocSync', 'docsync-wp' );

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

		$source = $this->source_repository->getSource( $post_id );

		if ( null === $source ) {
			echo '<span class="docsync-wp-list-status is-empty">' . esc_html__( 'Not linked', 'docsync-wp' ) . '</span>';
			return;
		}

		$title  = '' !== $source['google_title'] ? $source['google_title'] : $source['google_file_id'];
		$status = '' !== $source['sync_status'] ? $source['sync_status'] : 'linked';

		echo '<div class="docsync-wp-list-status is-linked">';
		echo '<strong>' . esc_html( (string) $title ) . '</strong><br />';
		echo '<span>' . esc_html( ucfirst( (string) $status ) ) . '</span>';

		if ( '' !== $source['last_synced_at'] ) {
			echo '<br /><small>' . esc_html( (string) $source['last_synced_at'] ) . '</small>';
		}

		if ( '' !== $source['sync_error'] ) {
			echo '<br /><small class="docsync-wp-list-error">' . esc_html( (string) $source['sync_error'] ) . '</small>';
		}

		echo '</div>';
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
