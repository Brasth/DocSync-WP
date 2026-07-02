<?php
/**
 * Post edit screen sync controls.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Admin;

use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\Elementor\SyncDecider;
use DocSyncWP\Sync\SourceRepository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a lightweight React mount for post-level sync controls.
 */
final class PostSyncMetaBox {
	private const BOX_ID = 'docsync-wp-post-sync';

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Elementor sync decider.
	 *
	 * @var SyncDecider
	 */
	private SyncDecider $elementor_decider;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository   $source_repository Source repository.
	 * @param SettingsRepository $settings          Settings repository.
	 * @param SyncDecider        $elementor_decider Elementor sync decider.
	 */
	public function __construct( SourceRepository $source_repository, SettingsRepository $settings, SyncDecider $elementor_decider ) {
		$this->source_repository = $source_repository;
		$this->settings          = $settings;
		$this->elementor_decider = $elementor_decider;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'registerMetaBox' ), 10, 2 );
	}

	/**
	 * Register the sync meta box for enabled post types.
	 *
	 * @param string  $post_type Current post type.
	 * @param WP_Post $post      Current post.
	 */
	public function registerMetaBox( string $post_type, WP_Post $post ): void {
		if ( ! $this->source_repository->isPostTypeEnabled( $post_type ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			esc_html__( 'Brasth Document Sync', 'brasth-document-sync-for-google-docs' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'high'
		);
	}

	/**
	 * Render the React mount point.
	 *
	 * @param WP_Post $post Current post.
	 */
	public function render( WP_Post $post ): void {
		$source = $this->source_repository->formatSource( $post->ID );
		$json   = wp_json_encode( $source );

		if ( ! is_string( $json ) ) {
			$json = 'null';
		}

		$elementor_enabled = $this->settings->isElementorSyncEnabled();
		$elementor_active  = $this->elementor_decider->isElementorSyncAvailable();
		$default_elementor = $this->elementor_decider->getDefaultElementorSync( $post->ID );
		?>
		<div
			id="docsync-wp-post-sync-root"
			data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
			data-post-type="<?php echo esc_attr( $post->post_type ); ?>"
			data-source="<?php echo esc_attr( $json ); ?>"
			data-elementor-available="<?php echo $elementor_active ? 'true' : 'false'; ?>"
			data-elementor-enabled="<?php echo $elementor_enabled ? 'true' : 'false'; ?>"
			data-default-elementor-sync="<?php echo $default_elementor ? 'true' : 'false'; ?>"
		>
			<p><?php esc_html_e( 'Loading sync controls...', 'brasth-document-sync-for-google-docs' ); ?></p>
		</div>
		<?php
	}
}
