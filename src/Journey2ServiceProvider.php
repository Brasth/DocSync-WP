<?php
/**
 * Journey 2 (add content) service provider.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP;

use DocSyncWP\Auth\GoogleOAuthService;
use DocSyncWP\Auth\OAuthContinuationStore;
use DocSyncWP\Auth\TokenStore;
use DocSyncWP\Google\DocsClient;
use DocSyncWP\Google\DocumentIdParser;
use DocSyncWP\Google\DriveClient;
use DocSyncWP\Google\DriveWriteClient;
use DocSyncWP\Google\SlidesClient;
use DocSyncWP\Import\CanonicalRenderer;
use DocSyncWP\Import\DeckConverter;
use DocSyncWP\Import\DocxConverter;
use DocSyncWP\Import\ImportCleanup;
use DocSyncWP\Import\ImportCommitter;
use DocSyncWP\Import\ImportProvenanceRepository;
use DocSyncWP\Import\ImportService;
use DocSyncWP\Import\ImportSessionRepository;
use DocSyncWP\Import\PdfConverter;
use DocSyncWP\Import\PrivateAssetStore;
use DocSyncWP\Import\UploadValidator;
use DocSyncWP\Matching\MatchingService;
use DocSyncWP\Matching\MatchNormalizer;
use DocSyncWP\Matching\MatchSessionRepository;
use DocSyncWP\Rest\ContentController;
use DocSyncWP\Rest\ImportController;
use DocSyncWP\Rest\MatchingController;
use DocSyncWP\Rest\RestServiceProvider;
use DocSyncWP\Security\EncryptionService;
use DocSyncWP\Settings\SettingsRepository;
use DocSyncWP\Sync\Elementor\Preset\ElementorPresetRegistry;
use DocSyncWP\Sync\HtmlToBlockContentConverter;
use DocSyncWP\Sync\Layout\LayoutConversionService;
use DocSyncWP\Sync\Layout\LayoutPresetRegistry;
use DocSyncWP\Sync\SourceBatchService;
use DocSyncWP\Sync\SourceRepository;
use DocSyncWP\Sync\SyncService;

defined( 'ABSPATH' ) || exit;

/**
 * Builds every Journey 2 service once from the shared instances that
 * `Plugin::boot()` already handed to the REST controllers.
 *
 * The dependency map comes from `RestServiceProvider::getDependencies()`.
 * Services that are not exposed there are stateless wrappers over options,
 * constants, and the injected Google OAuth client, so constructing them here
 * with the same arguments behaves exactly like the instances in `Plugin::boot()`.
 * When a dependency or a Journey 2 class is missing, the provider stays inert
 * and every legacy route keeps working.
 */
final class Journey2ServiceProvider {
	/**
	 * Required dependency keys and the class each instance must have.
	 */
	private const REQUIRED_DEPENDENCIES = array(
		'sourceRepository' => SourceRepository::class,
		'syncService'      => SyncService::class,
		'documentIdParser' => DocumentIdParser::class,
		'layoutPresets'    => LayoutPresetRegistry::class,
		'elementorPresets' => ElementorPresetRegistry::class,
		'driveClient'      => DriveClient::class,
		'googleOAuth'      => GoogleOAuthService::class,
		'tokenStore'       => TokenStore::class,
	);

	/**
	 * Journey 2 classes that must be loadable before anything is built.
	 */
	private const REQUIRED_CLASSES = array(
		OAuthContinuationStore::class,
		DriveWriteClient::class,
		SlidesClient::class,
		CanonicalRenderer::class,
		DeckConverter::class,
		DocxConverter::class,
		ImportCleanup::class,
		ImportCommitter::class,
		ImportProvenanceRepository::class,
		ImportService::class,
		ImportSessionRepository::class,
		PdfConverter::class,
		PrivateAssetStore::class,
		UploadValidator::class,
		MatchingService::class,
		MatchNormalizer::class,
		MatchSessionRepository::class,
		ContentController::class,
		ImportController::class,
		MatchingController::class,
		SourceBatchService::class,
		'Smalot\PdfParser\Parser',
	);

	/**
	 * Whether every dependency and class was present and the services were built.
	 *
	 * @var bool
	 */
	private bool $ready = false;

	/**
	 * Multi-Doc batch and attach-only service.
	 *
	 * @var SourceBatchService|null
	 */
	private ?SourceBatchService $source_batch = null;

	/**
	 * Import provenance repository.
	 *
	 * @var ImportProvenanceRepository|null
	 */
	private ?ImportProvenanceRepository $import_provenance = null;

	/**
	 * Upload import orchestration service.
	 *
	 * @var ImportService|null
	 */
	private ?ImportService $imports = null;

	/**
	 * Asynchronous import commit worker.
	 *
	 * @var ImportCommitter|null
	 */
	private ?ImportCommitter $committer = null;

	/**
	 * Bulk-linking matching service.
	 *
	 * @var MatchingService|null
	 */
	private ?MatchingService $matching = null;

	/**
	 * Expiry and retry cleanup for sessions, jobs, continuations, and batch keys.
	 *
	 * @var ImportCleanup|null
	 */
	private ?ImportCleanup $cleanup = null;

	/**
	 * Import REST controller.
	 *
	 * @var ImportController|null
	 */
	private ?ImportController $import_controller = null;

	/**
	 * Matching REST controller.
	 *
	 * @var MatchingController|null
	 */
	private ?MatchingController $matching_controller = null;

	/**
	 * Combined content listing REST controller.
	 *
	 * @var ContentController|null
	 */
	private ?ContentController $content_controller = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $dependencies Merged map from RestServiceProvider::getDependencies().
	 */
	public function __construct( array $dependencies ) {
		if ( ! $this->dependenciesAreValid( $dependencies ) ) {
			return;
		}

		$this->build( $dependencies );
		$this->ready = true;
	}

	/**
	 * Whether Journey 2 is available.
	 *
	 * `ZipArchive` is deliberately not checked: without it only DOCX and PPTX
	 * uploads are rejected, per file, by the upload validator.
	 */
	public function isReady(): bool {
		return $this->ready;
	}

	/**
	 * Register routes, workers, and the hourly cleanup. No-op unless ready.
	 */
	public function register(): void {
		if ( ! $this->ready || null === $this->imports || null === $this->committer || null === $this->matching || null === $this->cleanup ) {
			return;
		}

		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );

		$this->imports->register();
		$this->committer->register();
		$this->matching->register();
		$this->cleanup->register();
	}

	/**
	 * Register the import, matching, and content routes.
	 */
	public function registerRoutes(): void {
		if ( ! $this->ready || null === $this->import_controller || null === $this->matching_controller || null === $this->content_controller ) {
			return;
		}

		$this->import_controller->registerRoutes( RestServiceProvider::NAMESPACE );
		$this->matching_controller->registerRoutes( RestServiceProvider::NAMESPACE );
		$this->content_controller->registerRoutes( RestServiceProvider::NAMESPACE );
	}

	/**
	 * Shared batch service injected into `SourceController`.
	 */
	public function getSourceBatch(): ?SourceBatchService {
		return $this->ready ? $this->source_batch : null;
	}

	/**
	 * Shared provenance repository injected into `WorkspaceController`.
	 */
	public function getImportProvenance(): ?ImportProvenanceRepository {
		return $this->ready ? $this->import_provenance : null;
	}

	/**
	 * Whether every required dependency has the right class and every Journey 2 class loads.
	 *
	 * @param array<string,mixed> $dependencies Dependency map.
	 */
	private function dependenciesAreValid( array $dependencies ): bool {
		foreach ( self::REQUIRED_DEPENDENCIES as $key => $class_name ) {
			if ( ! isset( $dependencies[ $key ] ) || ! $dependencies[ $key ] instanceof $class_name ) {
				return false;
			}
		}

		foreach ( self::REQUIRED_CLASSES as $class_name ) {
			if ( ! class_exists( $class_name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Build every Journey 2 object exactly once.
	 *
	 * @param array<string,mixed> $dependencies Validated dependency map.
	 */
	private function build( array $dependencies ): void {
		$source_repository  = $dependencies['sourceRepository'];
		$sync_service       = $dependencies['syncService'];
		$document_id_parser = $dependencies['documentIdParser'];
		$layout_presets     = $dependencies['layoutPresets'];
		$elementor_presets  = $dependencies['elementorPresets'];
		$drive_client       = $dependencies['driveClient'];
		$google_oauth       = $dependencies['googleOAuth'];

		// Same construction and registries as Plugin::boot(); the repository reads options on every call.
		$encryption    = new EncryptionService();
		$settings      = new SettingsRepository( $encryption, $layout_presets, $elementor_presets );
		$docs_client   = new DocsClient( $google_oauth );
		$slides_client = new SlidesClient( $google_oauth );
		$drive_write   = new DriveWriteClient( $google_oauth );
		$layout        = new LayoutConversionService( $settings, new HtmlToBlockContentConverter(), $layout_presets );
		$continuations = new OAuthContinuationStore( $settings );

		$this->source_batch      = new SourceBatchService(
			$source_repository,
			$sync_service,
			$document_id_parser,
			$layout_presets,
			$elementor_presets
		);
		$this->import_provenance = new ImportProvenanceRepository( $source_repository );

		$sessions  = new ImportSessionRepository();
		$assets    = new PrivateAssetStore( $encryption );
		$renderer  = new CanonicalRenderer( $layout );
		$validator = new UploadValidator();

		$this->imports   = new ImportService(
			$sessions,
			$assets,
			$validator,
			new DocxConverter( $drive_write, $docs_client, $assets ),
			new DeckConverter( $drive_write, $slides_client, $assets ),
			new PdfConverter( $assets ),
			$renderer,
			$source_repository,
			$drive_write
		);
		$this->committer = new ImportCommitter(
			$sessions,
			$assets,
			$renderer,
			$this->import_provenance,
			$source_repository,
			$sync_service,
			$this->imports
		);

		$match_jobs     = new MatchSessionRepository();
		$this->matching = new MatchingService(
			$match_jobs,
			new MatchNormalizer(),
			$drive_client,
			$docs_client,
			$drive_write,
			$source_repository,
			$this->source_batch
		);
		$this->cleanup  = new ImportCleanup(
			$sessions,
			$assets,
			$this->imports,
			$match_jobs,
			$this->matching,
			$continuations,
			$this->source_batch
		);

		$this->import_controller   = new ImportController( $this->imports, $this->committer );
		$this->matching_controller = new MatchingController( $this->matching );
		$this->content_controller  = new ContentController( $source_repository, $this->import_provenance );
	}
}
