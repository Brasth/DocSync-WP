<?php
/**
 * Admin page registration and rendering.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Admin;

use DocSyncWP\Rest\RestPermissions;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\SourceRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Brasth Document Sync admin screen.
 */
final class AdminPage {
	public const MENU_SLUG = 'brasth-document-sync-for-google-docs';

	public const SOURCES_MENU_SLUG = 'brasth-document-sync-for-google-docs-sources';

	public const FOLDERS_MENU_SLUG = 'brasth-document-sync-for-google-docs-folders';

	public const LOGS_MENU_SLUG = 'brasth-document-sync-for-google-docs-logs';

	public const HOOK_SUFFIX = 'toplevel_page_brasth-document-sync-for-google-docs';

	/**
	 * Hook suffix for the Sources submenu page.
	 *
	 * WordPress builds this from sanitize_title() of the translated top-level menu
	 * title, not from the parent menu slug. The prefix is therefore
	 * 'brasth-document-sync' in English, not 'brasth-document-sync-for-google-docs'.
	 *
	 * @internal Prefer matching against the stable SOURCES_MENU_SLUG when possible.
	 */
	public const SOURCES_HOOK_SUFFIX = 'brasth-document-sync_page_brasth-document-sync-for-google-docs-sources';

	/**
	 * Hook suffix for the Logs submenu page.
	 *
	 * See SOURCES_HOOK_SUFFIX: the prefix depends on the translated top-level menu title.
	 *
	 * @internal Prefer matching against the stable LOGS_MENU_SLUG when possible.
	 */
	public const LOGS_HOOK_SUFFIX = 'brasth-document-sync_page_brasth-document-sync-for-google-docs-logs';

	/**
	 * Site settings repository.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $sources;

	/**
	 * Constructor.
	 *
	 * @param SettingsRepository $settings Site settings repository.
	 * @param SourceRepository   $sources  Source repository.
	 */
	public function __construct( SettingsRepository $settings, SourceRepository $sources ) {
		$this->settings = $settings;
		$this->sources  = $sources;
	}

	/**
	 * Register the admin menu page.
	 */
	public function register(): void {
		if ( ! RestPermissions::currentUserCanUseDocSync() ) {
			return;
		}

		$top_level_slug = $this->topLevelSlug();
		$top_level_view = self::SOURCES_MENU_SLUG === $top_level_slug ? 'renderSources' : 'render';
		$parent_slug    = $top_level_slug;
		$use_capability = 'read';

		add_menu_page(
			esc_html__( 'Brasth Document Sync', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Brasth Document Sync', 'brasth-document-sync-for-google-docs' ),
			$use_capability,
			$top_level_slug,
			array( $this, $top_level_view ),
			'dashicons-media-document',
			58
		);

		if ( current_user_can( 'manage_options' ) ) {
			add_submenu_page(
				$parent_slug,
				esc_html__( 'Brasth Document Sync Setup', 'brasth-document-sync-for-google-docs' ),
				esc_html__( 'Setup', 'brasth-document-sync-for-google-docs' ),
				'manage_options',
				self::MENU_SLUG,
				array( $this, 'render' )
			);
		}

		if ( self::SOURCES_MENU_SLUG !== $parent_slug ) {
			add_submenu_page(
				$parent_slug,
				esc_html__( 'Brasth Document Sync Sources', 'brasth-document-sync-for-google-docs' ),
				esc_html__( 'Sources', 'brasth-document-sync-for-google-docs' ),
				$use_capability,
				self::SOURCES_MENU_SLUG,
				array( $this, 'renderSources' )
			);
		}

		add_submenu_page(
			$parent_slug,
			esc_html__( 'Brasth Document Sync Drive Folders', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Drive Folders', 'brasth-document-sync-for-google-docs' ),
			$use_capability,
			self::FOLDERS_MENU_SLUG,
			array( $this, 'renderFolders' )
		);

		add_submenu_page(
			$parent_slug,
			esc_html__( 'Brasth Document Sync Logs', 'brasth-document-sync-for-google-docs' ),
			esc_html__( 'Logs', 'brasth-document-sync-for-google-docs' ),
			$use_capability,
			self::LOGS_MENU_SLUG,
			array( $this, 'renderLogs' )
		);
	}

	/**
	 * Render the React mount point.
	 */
	public function render(): void {
		$this->renderMount( 'setup' );
	}

	/**
	 * Render the Sources React mount point.
	 */
	public function renderSources(): void {
		$this->renderMount( 'sources' );
	}

	/**
	 * Render the Drive Folders React mount point.
	 */
	public function renderFolders(): void {
		$this->renderMount( 'folders' );
	}

	/**
	 * Render the Logs React mount point.
	 */
	public function renderLogs(): void {
		$this->renderMount( 'logs' );
	}

	/**
	 * Render the React mount point.
	 *
	 * @param string $view Admin app view.
	 */
	private function renderMount( string $view ): void {
		$allowed = 'setup' === $view
			? current_user_can( 'manage_options' )
			: RestPermissions::currentUserCanUseDocSync();

		if ( ! in_array( $view, array( 'setup', 'sources', 'folders', 'logs' ), true ) ) {
			$view = 'sources';
		}

		if ( ! $allowed ) {
			wp_die(
				esc_html__( 'You do not have permission to access Brasth Document Sync.', 'brasth-document-sync-for-google-docs' ),
				esc_html__( 'Permission denied', 'brasth-document-sync-for-google-docs' ),
				array( 'response' => 403 )
			);
		}

		?>
		<div class="wrap docsync-wp-admin-page">
			<div id="docsync-wp-admin-root" data-view="<?php echo esc_attr( $view ); ?>"></div>
			<noscript>
				<?php esc_html_e( 'Brasth Document Sync requires JavaScript in the WordPress admin.', 'brasth-document-sync-for-google-docs' ); ?>
			</noscript>
		</div>
		<?php
	}

	/**
	 * Resolve only the top-level destination; direct submenu URLs stay stable.
	 */
	private function topLevelSlug(): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return self::SOURCES_MENU_SLUG;
		}

		return $this->settings->hasRequiredOAuthConfiguration()
			&& $this->sources->hasAccessibleSource( get_current_user_id() )
			? self::SOURCES_MENU_SLUG
			: self::MENU_SLUG;
	}
}
