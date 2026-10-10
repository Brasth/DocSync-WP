<?php
/**
 * REST controller for administrator connection directory.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Auth\GoogleConnectionDirectory;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists operator Google connection states for administrators.
 */
final class SettingsConnectionsController {
	private const DEFAULT_PAGE     = 1;
	private const DEFAULT_PER_PAGE = 20;
	private const MAX_PER_PAGE     = 50;

	/**
	 * Connection directory.
	 *
	 * @var GoogleConnectionDirectory
	 */
	private GoogleConnectionDirectory $directory;

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	private string $rest_namespace;

	/**
	 * Constructor.
	 *
	 * @param GoogleConnectionDirectory $directory      Connection directory.
	 * @param string                    $rest_namespace REST namespace.
	 */
	public function __construct( GoogleConnectionDirectory $directory, string $rest_namespace ) {
		$this->directory      = $directory;
		$this->rest_namespace = $rest_namespace;
	}

	/**
	 * Register WordPress hooks.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	/**
	 * Register connection routes.
	 */
	public function registerRoutes(): void {
		register_rest_route(
			$this->rest_namespace,
			'/settings/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'getConnections' ),
				'permission_callback' => array( RestPermissions::class, 'canManageSettings' ),
				'args'                => array(
					'page'    => array(
						'type'              => 'integer',
						'default'           => self::DEFAULT_PAGE,
						'minimum'           => 1,
						'sanitize_callback' => array( $this, 'sanitizePage' ),
					),
					'perPage' => array(
						'type'              => 'integer',
						'default'           => self::DEFAULT_PER_PAGE,
						'minimum'           => 1,
						'maximum'           => self::MAX_PER_PAGE,
						'sanitize_callback' => array( $this, 'sanitizePerPage' ),
					),
				),
			)
		);
	}

	/**
	 * List connection states for the requested page.
	 *
	 * @param WP_REST_Request $request REST request.
	 */
	public function getConnections( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			$this->directory->listConnections(
				$this->sanitizePage( $request->get_param( 'page' ) ),
				$this->sanitizePerPage( $request->get_param( 'perPage' ) )
			)
		);
	}

	/**
	 * Normalize a 1-based page.
	 *
	 * @param mixed $value Requested page.
	 */
	public function sanitizePage( mixed $value ): int {
		$page = absint( $value );

		return $page < 1 ? self::DEFAULT_PAGE : $page;
	}

	/**
	 * Normalize a page size to the supported range.
	 *
	 * @param mixed $value Requested page size.
	 */
	public function sanitizePerPage( mixed $value ): int {
		$per_page = absint( $value );

		if ( $per_page < 1 ) {
			return self::DEFAULT_PER_PAGE;
		}

		return min( self::MAX_PER_PAGE, $per_page );
	}
}
