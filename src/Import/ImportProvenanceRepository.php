<?php
/**
 * One-time and synced-Word import provenance.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Import;

use DocSyncWP\Sync\SourceRepository;
use WP_Error;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Stores import provenance separately from Google source metadata.
 *
 * Provenance lives in `_docsync_wp_import_provenance` with a flat, queryable
 * `_docsync_wp_import_kind` (`syncedWord` or `oneTime`) written in the same
 * save. One-time posts never get a Google file ID, a next-sync time, or a
 * scheduled event; this class never writes source metadata. Listing and
 * activation scans use the same per-post authority as source scans.
 */
final class ImportProvenanceRepository {
	public const META_KEY           = '_docsync_wp_import_provenance';
	public const KIND_META_KEY      = '_docsync_wp_import_kind';
	public const SCAN_BATCH_SIZE    = 100;
	public const CONTENT_SCAN_LIMIT = 2000;

	public const KIND_SYNCED_WORD = 'syncedWord';
	public const KIND_ONE_TIME    = 'oneTime';
	public const VERSION          = 1;

	private const ONE_TIME_CONVERTERS = array(
		'docx' => 'googleDocsOneTime',
		'pptx' => 'googleSlidesOneTime',
		'pdf'  => 'localPdf',
	);
	private const SYNCED_CONVERTER    = 'googleDocsSynced';
	private const SESSION_PATTERN     = '/^[a-f0-9-]{36}$/';
	private const UTC_PATTERN         = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';
	private const GOOGLE_ID_PATTERN   = '/^[A-Za-z0-9_-]{10,200}$/';

	/**
	 * Source repository (existing public methods only).
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
	 * Save provenance and the flat kind meta for a committed post.
	 *
	 * @param int                 $post_id    Post ID.
	 * @param array<string,mixed> $provenance Provenance fields.
	 * @return bool|WP_Error
	 */
	public function save( int $post_id, array $provenance ): bool|WP_Error {
		$record = $this->normalize( $provenance );

		if ( null === $record || ! get_post( $post_id ) instanceof WP_Post ) {
			return new WP_Error(
				'docsync_wp_import_provenance_invalid',
				__( 'Brasth Document Sync could not record where this post was imported from.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		update_post_meta( $post_id, self::META_KEY, wp_slash( $record ) );
		update_post_meta( $post_id, self::KIND_META_KEY, $record['kind'] );

		if ( $this->get( $post_id ) !== $record || get_post_meta( $post_id, self::KIND_META_KEY, true ) !== $record['kind'] ) {
			return new WP_Error(
				'docsync_wp_import_provenance_failed',
				__( 'Brasth Document Sync could not save where this post was imported from.', 'brasth-document-sync-for-google-docs' ),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Stored provenance, or null when missing or malformed.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function get( int $post_id ): ?array {
		$stored = get_post_meta( $post_id, self::META_KEY, true );

		return is_array( $stored ) ? $this->normalize( $stored ) : null;
	}

	/**
	 * Wire provenance for a one-time post (no Google file ID), else null.
	 *
	 * @param int $post_id Post ID.
	 * @return array{kind:string,format:string,originalName:string,converter:string,importedAt:string,importedByUserId:int}|null
	 */
	public function formatOneTime( int $post_id ): ?array {
		$record = $this->get( $post_id );

		if ( null === $record || self::KIND_ONE_TIME !== $record['kind'] || '' !== $this->googleFileId( $post_id ) ) {
			return null;
		}

		return array(
			'kind'             => self::KIND_ONE_TIME,
			'format'           => $record['format'],
			'originalName'     => $record['originalName'],
			'converter'        => $record['converter'],
			'importedAt'       => $record['importedAt'],
			'importedByUserId' => $record['importedByUserId'],
		);
	}

	/**
	 * Imported-from note for a synced Word post, else null.
	 *
	 * @param int $post_id Post ID.
	 * @return array{format:string,originalName:string,importedAt:string}|null
	 */
	public function formatImportedFrom( int $post_id ): ?array {
		$record = $this->get( $post_id );

		if ( null === $record || self::KIND_SYNCED_WORD !== $record['kind'] ) {
			return null;
		}

		return array(
			'format'       => $record['format'],
			'originalName' => $record['originalName'],
			'importedAt'   => $record['importedAt'],
		);
	}

	/**
	 * Whether the user can sync at least one successfully imported post.
	 *
	 * Mirrors SourceRepository::hasAccessibleSource: batches of 100 ordered
	 * by ID, stopping at the first post accepted by userCanSyncPost.
	 *
	 * @param int $user_id User ID.
	 */
	public function hasAccessibleSuccess( int $user_id ): bool {
		$post_types = $this->editablePostTypes( $user_id );

		if ( array() === $post_types ) {
			return false;
		}

		$query_args = array(
			'fields'                 => 'ids',
			'meta_query'             => array(
				array(
					'key'     => self::KIND_META_KEY,
					'value'   => array( self::KIND_SYNCED_WORD, self::KIND_ONE_TIME ),
					'compare' => 'IN',
				),
			),
			'no_found_rows'          => true,
			'order'                  => 'ASC',
			'orderby'                => 'ID',
			'post_status'            => 'any',
			'post_type'              => $post_types,
			'posts_per_page'         => self::SCAN_BATCH_SIZE,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		$scan_page  = 1;

		while ( true ) {
			$query_args['paged'] = $scan_page;
			$query               = new WP_Query( $query_args );

			foreach ( $query->posts as $post_id ) {
				if ( $this->source_repository->userCanSyncPost( absint( $post_id ), $user_id ) ) {
					return true;
				}
			}

			if ( count( $query->posts ) < self::SCAN_BATCH_SIZE ) {
				return false;
			}

			++$scan_page;
		}
	}

	/**
	 * Page through accessible Google and one-time content.
	 *
	 * @param int                 $user_id User ID.
	 * @param array<string,mixed> $query   Validated query: page, perPage, kind, postType, search, orderBy, order, postId.
	 * @return array{postIds:array<int,int>,page:int,perPage:int,hasMore:bool,truncated:bool}
	 */
	public function listAccessibleContent( int $user_id, array $query ): array {
		$page     = max( 1, absint( $query['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, absint( $query['perPage'] ?? 20 ) ) );
		$result   = array(
			'postIds'   => array(),
			'page'      => $page,
			'perPage'   => $per_page,
			'hasMore'   => false,
			'truncated' => false,
		);

		if ( isset( $query['postId'] ) && absint( $query['postId'] ) > 0 ) {
			$post_id = absint( $query['postId'] );

			if ( $this->source_repository->userCanSyncPost( $post_id, $user_id ) && null !== $this->formatContentItem( $post_id ) ) {
				$result['postIds'] = array( $post_id );
			}

			return $result;
		}

		$post_types = $this->editablePostTypes( $user_id );
		$post_type  = (string) ( $query['postType'] ?? '' );

		if ( '' !== $post_type ) {
			$post_types = in_array( $post_type, $post_types, true ) ? array( $post_type ) : array();
		}

		if ( array() === $post_types ) {
			return $result;
		}

		$direction  = 'asc' === ( $query['order'] ?? 'desc' ) ? 'ASC' : 'DESC';
		$order_by   = in_array( $query['orderBy'] ?? 'modified', array( 'modified', 'title', 'date' ), true ) ? (string) ( $query['orderBy'] ?? 'modified' ) : 'modified';
		$query_args = array(
			'fields'                 => 'ids',
			'meta_query'             => $this->kindMetaQuery( (string) ( $query['kind'] ?? 'all' ) ),
			'no_found_rows'          => true,
			'orderby'                => array(
				$order_by => $direction,
				'ID'      => $direction,
			),
			'post_status'            => 'any',
			'post_type'              => $post_types,
			'posts_per_page'         => self::SCAN_BATCH_SIZE,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$search = (string) ( $query['search'] ?? '' );

		if ( '' !== $search ) {
			$query_args['s']              = $search;
			$query_args['search_columns'] = array( 'post_title' );
		}

		$skip      = ( $page - 1 ) * $per_page;
		$seen      = 0;
		$scanned   = 0;
		$scan_page = 1;

		while ( $scanned < self::CONTENT_SCAN_LIMIT ) {
			$query_args['paged'] = $scan_page;
			$batch               = new WP_Query( $query_args );

			foreach ( $batch->posts as $post_id ) {
				++$scanned;
				$post_id = absint( $post_id );

				if ( ! $this->source_repository->userCanSyncPost( $post_id, $user_id ) ) {
					if ( $scanned >= self::CONTENT_SCAN_LIMIT ) {
						break;
					}

					continue;
				}

				++$seen;

				if ( $seen <= $skip ) {
					continue;
				}

				if ( count( $result['postIds'] ) < $per_page ) {
					$result['postIds'][] = $post_id;
				} else {
					$result['hasMore'] = true;

					return $result;
				}

				if ( $scanned >= self::CONTENT_SCAN_LIMIT ) {
					break;
				}
			}

			if ( count( $batch->posts ) < self::SCAN_BATCH_SIZE ) {
				return $result;
			}

			++$scan_page;
		}

		$result['truncated'] = true;

		return $result;
	}

	/**
	 * ContentItem for a Google-linked or one-time post, else null.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string,mixed>|null
	 */
	public function formatContentItem( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$google_file_id = $this->googleFileId( $post_id );

		if ( '' !== $google_file_id ) {
			$source = $this->source_repository->formatSource( $post_id );

			if ( null === $source ) {
				return null;
			}

			$doc_url    = isset( $source['googleDocUrl'] ) && '' !== (string) $source['googleDocUrl'] ? (string) $source['googleDocUrl'] : 'https://docs.google.com/document/d/' . rawurlencode( $google_file_id ) . '/edit';
			$provenance = array(
				'kind'         => 'google',
				'googleFileId' => $google_file_id,
				'googleDocUrl' => esc_url_raw( $doc_url ),
				'source'       => $source,
				'importedFrom' => $this->formatImportedFrom( $post_id ),
			);
		} else {
			$provenance = $this->formatOneTime( $post_id );

			if ( null === $provenance ) {
				return null;
			}
		}

		$edit_link = get_edit_post_link( $post_id, 'raw' );
		$view_url  = is_post_publicly_viewable( $post ) ? get_permalink( $post ) : false;

		return array(
			'postId'     => $post_id,
			'title'      => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
			'postType'   => $post->post_type,
			'postStatus' => $post->post_status,
			'editUrl'    => is_string( $edit_link ) ? esc_url_raw( $edit_link ) : '',
			'viewUrl'    => is_string( $view_url ) ? esc_url_raw( $view_url ) : null,
			'modifiedAt' => mysql2date( 'Y-m-d\TH:i:s\Z', $post->post_modified_gmt, false ),
			'provenance' => $provenance,
		);
	}

	/**
	 * Remove both provenance meta keys.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete( int $post_id ): bool {
		delete_post_meta( $post_id, self::META_KEY );
		delete_post_meta( $post_id, self::KIND_META_KEY );

		return '' === get_post_meta( $post_id, self::META_KEY, true ) && '' === get_post_meta( $post_id, self::KIND_META_KEY, true );
	}

	/**
	 * Validate and normalize a provenance record.
	 *
	 * @param array<string,mixed> $provenance Raw record.
	 * @return array<string,mixed>|null
	 */
	private function normalize( array $provenance ): ?array {
		$kind      = $provenance['kind'] ?? null;
		$format    = $provenance['format'] ?? null;
		$converter = $provenance['converter'] ?? null;
		$google_id = (string) ( $provenance['googleFileId'] ?? '' );

		if ( ! in_array( $format, array( 'docx', 'pptx', 'pdf' ), true ) ) {
			return null;
		}

		if ( self::KIND_SYNCED_WORD === $kind ) {
			if ( 'docx' !== $format || self::SYNCED_CONVERTER !== $converter || 1 !== preg_match( self::GOOGLE_ID_PATTERN, $google_id ) ) {
				return null;
			}
		} elseif ( self::KIND_ONE_TIME === $kind ) {
			if ( self::ONE_TIME_CONVERTERS[ $format ] !== $converter || '' !== $google_id ) {
				return null;
			}
		} else {
			return null;
		}

		$sha         = (string) ( $provenance['sha256'] ?? '' );
		$imported_at = (string) ( $provenance['importedAt'] ?? '' );
		$session_id  = (string) ( $provenance['sessionId'] ?? '' );
		$name        = sanitize_text_field( (string) ( $provenance['originalName'] ?? '' ) );

		if ( ( '' !== $sha && 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha ) ) || 1 !== preg_match( self::UTC_PATTERN, $imported_at ) || ( '' !== $session_id && 1 !== preg_match( self::SESSION_PATTERN, $session_id ) ) ) {
			return null;
		}

		return array(
			'version'          => self::VERSION,
			'kind'             => $kind,
			'format'           => $format,
			'originalName'     => $name,
			'sha256'           => $sha,
			'converter'        => $converter,
			'googleFileId'     => $google_id,
			'importedAt'       => $imported_at,
			'importedByUserId' => absint( $provenance['importedByUserId'] ?? 0 ),
			'sessionId'        => $session_id,
		);
	}

	/**
	 * Meta query for a content kind.
	 *
	 * @param string $kind all, google, or oneTime.
	 * @return array<int|string,mixed>
	 */
	private function kindMetaQuery( string $kind ): array {
		$google   = array(
			'key'     => SourceRepository::META_FILE_ID,
			'value'   => '',
			'compare' => '!=',
		);
		$one_time = array(
			'relation' => 'AND',
			array(
				'key'   => self::KIND_META_KEY,
				'value' => self::KIND_ONE_TIME,
			),
			array(
				'relation' => 'OR',
				array(
					'key'     => SourceRepository::META_FILE_ID,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => SourceRepository::META_FILE_ID,
					'value' => '',
				),
			),
		);

		if ( 'google' === $kind ) {
			return array( $google );
		}

		if ( 'oneTime' === $kind ) {
			return array( $one_time );
		}

		return array(
			'relation' => 'OR',
			$google,
			$one_time,
		);
	}

	/**
	 * Enabled post types the user can edit.
	 *
	 * @param int $user_id User ID.
	 * @return array<int,string>
	 */
	private function editablePostTypes( int $user_id ): array {
		return array_values(
			array_filter(
				$this->source_repository->getEnabledPostTypes(),
				function ( string $post_type ) use ( $user_id ): bool {
					return $this->source_repository->userCanEditPostType( $post_type, $user_id );
				}
			)
		);
	}

	/**
	 * Linked Google file ID of a post ('' when none).
	 *
	 * @param int $post_id Post ID.
	 */
	private function googleFileId( int $post_id ): string {
		$value = get_post_meta( $post_id, SourceRepository::META_FILE_ID, true );

		return is_string( $value ) ? trim( $value ) : '';
	}
}
