<?php
/**
 * REST controller for the combined Google and one-time content listing.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Rest;

use DocSyncWP\Import\ImportProvenanceRepository;
use DocSyncWP\Sync\SourceRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

/**
 * Lists posts the caller can sync that are either linked to a Google Doc or
 * were imported once from an upload. The legacy `/sources` routes are untouched.
 */
final class ContentController {
	private const ALLOWED_PARAMS    = array( 'page', 'perPage', 'kind', 'postType', 'search', 'orderBy', 'order', 'postId' );
	private const ALLOWED_META_ARGS = array( 'rest_route' );
	private const KINDS             = array( 'all', 'google', 'oneTime' );
	private const ORDER_BY          = array( 'modified', 'title', 'date' );
	private const ORDERS            = array( 'asc', 'desc' );
	private const MAX_PER_PAGE      = 100;
	private const DEFAULT_PER_PAGE  = 20;
	private const MAX_SEARCH_LENGTH = 200;

	/**
	 * Source repository.
	 *
	 * @var SourceRepository
	 */
	private SourceRepository $source_repository;

	/**
	 * Import provenance repository.
	 *
	 * @var ImportProvenanceRepository
	 */
	private ImportProvenanceRepository $provenance;

	/**
	 * Constructor.
	 *
	 * @param SourceRepository           $source_repository Source repository.
	 * @param ImportProvenanceRepository $provenance        Import provenance repository.
	 */
	public function __construct( SourceRepository $source_repository, ImportProvenanceRepository $provenance ) {
		$this->source_repository = $source_repository;
		$this->provenance        = $provenance;
	}

	/**
	 * Register controller routes.
	 *
	 * @param string $rest_namespace REST namespace.
	 */
	public function registerRoutes( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listContent' ),
				'permission_callback' => array( RestPermissions::class, 'canUseAuthenticatedRest' ),
			)
		);
	}

	/**
	 * List accessible Google and one-time content with paging, filters, and sort.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function listContent( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = get_current_user_id();
		$query   = $this->parseQuery( (array) $request->get_query_params(), $user_id );

		if ( is_wp_error( $query ) ) {
			return $query;
		}

		$listing = $this->provenance->listAccessibleContent( $user_id, $query );
		$items   = array();

		foreach ( $listing['postIds'] as $post_id ) {
			$item = $this->provenance->formatContentItem( absint( $post_id ) );

			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		return rest_ensure_response(
			array(
				'items'     => $items,
				'page'      => $listing['page'],
				'perPage'   => $listing['perPage'],
				'hasMore'   => $listing['hasMore'],
				'truncated' => $listing['truncated'],
			)
		);
	}

	/**
	 * Validate the camelCase query; unknown or out-of-range values are rejected.
	 *
	 * @param array<string,mixed> $params  Raw query parameters.
	 * @param int                 $user_id Caller.
	 * @return array<string,mixed>|WP_Error
	 */
	private function parseQuery( array $params, int $user_id ): array|WP_Error {
		foreach ( array_keys( $params ) as $name ) {
			$name = (string) $name;

			if ( ! in_array( $name, self::ALLOWED_PARAMS, true ) && ! in_array( $name, self::ALLOWED_META_ARGS, true ) && ! str_starts_with( $name, '_' ) ) {
				return $this->invalidQuery();
			}
		}

		$page     = $this->integerParam( $params, 'page', 1, 1, PHP_INT_MAX );
		$per_page = $this->integerParam( $params, 'perPage', self::DEFAULT_PER_PAGE, 1, self::MAX_PER_PAGE );
		$kind     = $this->enumParam( $params, 'kind', self::KINDS, 'all' );
		$order_by = $this->enumParam( $params, 'orderBy', self::ORDER_BY, 'modified' );
		$order    = $this->enumParam( $params, 'order', self::ORDERS, 'desc' );
		$search   = $this->stringParam( $params, 'search' );
		$type     = $this->stringParam( $params, 'postType' );

		if ( null === $page || null === $per_page || null === $kind || null === $order_by || null === $order || null === $search || null === $type ) {
			return $this->invalidQuery();
		}

		if ( mb_strlen( $search ) > self::MAX_SEARCH_LENGTH ) {
			return $this->invalidQuery();
		}

		if ( '' !== $type && ( ! in_array( $type, $this->source_repository->getEnabledPostTypes(), true ) || ! $this->source_repository->userCanEditPostType( $type, $user_id ) ) ) {
			return $this->invalidQuery();
		}

		$query = array(
			'page'     => $page,
			'perPage'  => $per_page,
			'kind'     => $kind,
			'postType' => $type,
			'search'   => sanitize_text_field( $search ),
			'orderBy'  => $order_by,
			'order'    => $order,
		);

		if ( array_key_exists( 'postId', $params ) ) {
			$post_id = $this->integerParam( $params, 'postId', 0, 1, PHP_INT_MAX );

			if ( null === $post_id ) {
				return $this->invalidQuery();
			}

			$query['postId'] = $post_id;
		}

		return $query;
	}

	/**
	 * Integer parameter within a range, its default when absent, or null when invalid.
	 *
	 * @param array<string,mixed> $params        Query parameters.
	 * @param string              $name          Parameter name.
	 * @param int                 $default_value Value when the parameter is absent.
	 * @param int                 $min           Minimum value.
	 * @param int                 $max           Maximum value.
	 */
	private function integerParam( array $params, string $name, int $default_value, int $min, int $max ): ?int {
		if ( ! array_key_exists( $name, $params ) ) {
			return $default_value;
		}

		$value = $params[ $name ];

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]{1,18}$/', $value ) ) {
			return null;
		}

		$number = (int) $value;

		return $number >= $min && $number <= $max ? $number : null;
	}

	/**
	 * Enumerated string parameter, its default when absent, or null when invalid.
	 *
	 * @param array<string,mixed> $params        Query parameters.
	 * @param string              $name          Parameter name.
	 * @param array<int,string>   $allowed       Allowed values.
	 * @param string              $default_value Value when the parameter is absent.
	 */
	private function enumParam( array $params, string $name, array $allowed, string $default_value ): ?string {
		if ( ! array_key_exists( $name, $params ) ) {
			return $default_value;
		}

		$value = $params[ $name ];

		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : null;
	}

	/**
	 * Free string parameter, '' when absent, or null when it is not a string.
	 *
	 * @param array<string,mixed> $params Query parameters.
	 * @param string              $name   Parameter name.
	 */
	private function stringParam( array $params, string $name ): ?string {
		if ( ! array_key_exists( $name, $params ) ) {
			return '';
		}

		return is_string( $params[ $name ] ) ? $params[ $name ] : null;
	}

	/**
	 * Error for an unknown or out-of-range query parameter.
	 */
	private function invalidQuery(): WP_Error {
		return new WP_Error(
			'docsync_wp_content_invalid_query',
			__( 'Brasth Document Sync received an unsupported content listing query.', 'brasth-document-sync-for-google-docs' ),
			array( 'status' => 400 )
		);
	}
}
