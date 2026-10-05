<?php
/**
 * Applies Doc metadata fields to a WordPress post using the sync owner's capabilities.
 *
 * @package DocSyncWP
 */

declare(strict_types=1);

namespace DocSyncWP\Sync\Metadata;

use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Writes title, slug, excerpt, featured image, terms, author, and SEO meta.
 */
final class PostMetadataApplier {
	/**
	 * Apply fields. Every write is checked against the sync owner, not the current request user.
	 *
	 * @param int                 $post_id Post ID.
	 * @param int                 $user_id Sync owner user ID.
	 * @param array<string,mixed> $fields  Extracted fields.
	 * @return array{applied:array<int,string>,warnings:array<int,string>,removed:array<int,string>}
	 */
	public function apply( int $post_id, int $user_id, array $fields ): array {
		$result = array(
			'applied'  => array(),
			'warnings' => array(),
			'removed'  => array(),
		);
		$post   = get_post( $post_id );

		if ( array() === $fields || null === $post ) {
			return $result;
		}

		$update  = array( 'ID' => $post_id );
		$pending = array();

		if ( isset( $fields['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $fields['title'] );
			$pending[]            = 'title';
		}

		if ( isset( $fields['slug'] ) ) {
			if ( in_array( $post->post_status, array( 'publish', 'future', 'private' ), true ) ) {
				$result['warnings'][] = __( 'slug (post is already published)', 'brasth-document-sync-for-google-docs' );
			} else {
				$update['post_name'] = sanitize_title( (string) $fields['slug'] );
				$pending[]           = 'slug';
			}
		}

		if ( isset( $fields['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $fields['excerpt'] );
			$pending[]              = 'excerpt';
		}

		if ( isset( $fields['author'] ) ) {
			$author_id = $this->resolveAuthor( $post->post_type, $user_id, (string) $fields['author'] );

			if ( $author_id > 0 ) {
				$update['post_author'] = $author_id;
				$pending[]             = 'author';
			} else {
				$result['warnings'][] = __( 'author (user not found or not permitted)', 'brasth-document-sync-for-google-docs' );
			}
		}

		if ( count( $update ) > 1 ) {
			$saved = wp_update_post( wp_slash( $update ), true );

			if ( is_wp_error( $saved ) || 0 === (int) $saved ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: comma-separated field names. */
					__( '%s (could not be saved)', 'brasth-document-sync-for-google-docs' ),
					implode( ', ', $pending )
				);
				$pending = array();
			}
		}

		$result['applied'] = array_merge( $result['applied'], $pending );

		if ( isset( $fields['featured_image'] ) ) {
			$attachment_id = attachment_url_to_postid( (string) $fields['featured_image'] );

			if ( $attachment_id > 0 && false !== set_post_thumbnail( $post_id, $attachment_id ) ) {
				$result['applied'][] = 'featured image';
			} else {
				$result['warnings'][] = __( 'featured image (not in the Media Library)', 'brasth-document-sync-for-google-docs' );
			}
		}

		$this->applyTerms( $post->post_type, $post_id, $user_id, $fields, $result );
		$this->applySeo( $post_id, $fields, $result );

		return $result;
	}

	/**
	 * Resolve an author by email or login when the sync owner may assign them.
	 *
	 * @param string $post_type Post type.
	 * @param int    $user_id   Sync owner user ID.
	 * @param string $author    Email or login.
	 */
	private function resolveAuthor( string $post_type, int $user_id, string $author ): int {
		$object = get_post_type_object( $post_type );

		if ( null === $object || ! user_can( $user_id, $object->cap->edit_others_posts ) ) {
			return 0;
		}

		$user = str_contains( $author, '@' ) ? get_user_by( 'email', $author ) : get_user_by( 'login', $author );

		if ( false === $user || ! user_can( $user->ID, $object->cap->edit_posts ) ) {
			return 0;
		}

		return (int) $user->ID;
	}

	/**
	 * Apply categories and tags on the post type's primary taxonomies.
	 *
	 * @param string              $post_type Post type.
	 * @param int                 $post_id   Post ID.
	 * @param int                 $user_id   Sync owner user ID.
	 * @param array<string,mixed> $fields    Fields.
	 * @param array<string,mixed> $result    Result accumulator.
	 */
	private function applyTerms( string $post_type, int $post_id, int $user_id, array $fields, array &$result ): void {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );

		foreach ( array(
			'categories' => true,
			'tags'       => false,
		) as $field => $hierarchical ) {
			if ( empty( $fields[ $field ] ) || ! is_array( $fields[ $field ] ) ) {
				continue;
			}

			$taxonomy = null;

			foreach ( $taxonomies as $candidate ) {
				if ( $candidate->show_ui && (bool) $candidate->hierarchical === $hierarchical ) {
					$taxonomy = $candidate;
					break;
				}
			}

			if ( null === $taxonomy || ! user_can( $user_id, $taxonomy->cap->assign_terms ) ) {
				$result['warnings'][] = 'categories' === $field ? __( 'categories (not permitted)', 'brasth-document-sync-for-google-docs' ) : __( 'tags (not permitted)', 'brasth-document-sync-for-google-docs' );
				continue;
			}

			$term_ids = array();

			foreach ( $fields[ $field ] as $name ) {
				$term_id = $this->resolveTerm( (string) $name, $taxonomy->name, user_can( $user_id, $taxonomy->cap->manage_terms ), $result );

				if ( $term_id > 0 ) {
					$term_ids[] = $term_id;
				}
			}

			if ( array() === $term_ids ) {
				// Nothing resolved: keep the existing terms instead of clearing the taxonomy.
				continue;
			}

			// A Categories/Tags row is authoritative for its taxonomy, so the post loses the terms the
			// Doc does not list. Report them rather than replacing WordPress curation silently.
			$removed = $this->removedTermNames( $post_id, $taxonomy->name, $term_ids );

			$assigned = wp_set_object_terms( $post_id, $term_ids, $taxonomy->name );

			if ( is_wp_error( $assigned ) ) {
				$result['warnings'][] = sprintf(
					/* translators: %s: field name (categories or tags). */
					__( '%s (could not be saved)', 'brasth-document-sync-for-google-docs' ),
					$field
				);
				continue;
			}

			$result['applied'][] = $field;

			if ( array() !== $removed ) {
				$shown = array_slice( $removed, 0, 3 );

				if ( count( $removed ) > count( $shown ) ) {
					$shown[] = '+' . ( count( $removed ) - count( $shown ) );
				}

				$result['removed'][] = sprintf(
					/* translators: 1: field name (categories or tags), 2: comma-separated term names removed from the post. */
					__( '%1$s (removed: %2$s)', 'brasth-document-sync-for-google-docs' ),
					$field,
					implode( ', ', $shown )
				);
			}
		}
	}

	/**
	 * Names of the terms this post loses when the Doc's list replaces them.
	 *
	 * @param int              $post_id  Post ID.
	 * @param string           $taxonomy Taxonomy name.
	 * @param array<int,mixed> $keep_ids Term IDs the Doc assigns.
	 * @return array<int,string>
	 */
	private function removedTermNames( int $post_id, string $taxonomy, array $keep_ids ): array {
		$existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );

		if ( is_wp_error( $existing ) ) {
			return array();
		}

		$names = array();

		foreach ( $existing as $term_id ) {
			if ( in_array( (int) $term_id, $keep_ids, true ) ) {
				continue;
			}

			$term = get_term( (int) $term_id, $taxonomy );

			if ( $term instanceof WP_Term ) {
				$names[] = $term->name;
			}
		}

		return $names;
	}

	/**
	 * Find or (when permitted) create a term.
	 *
	 * @param string              $name      Term name.
	 * @param string              $taxonomy  Taxonomy name.
	 * @param bool                $can_create Whether the owner may create terms.
	 * @param array<string,mixed> $result    Result accumulator.
	 */
	private function resolveTerm( string $name, string $taxonomy, bool $can_create, array &$result ): int {
		$name  = sanitize_text_field( $name );
		$found = term_exists( $name, $taxonomy );

		if ( is_array( $found ) ) {
			return (int) $found['term_id'];
		}

		if ( is_int( $found ) && $found > 0 ) {
			return $found;
		}

		if ( ! $can_create ) {
			/* translators: %s: term name. */
			$result['warnings'][] = sprintf( __( 'term "%s" does not exist', 'brasth-document-sync-for-google-docs' ), $name );
			return 0;
		}

		$created = wp_insert_term( $name, $taxonomy );

		if ( is_wp_error( $created ) ) {
			// A concurrent sync may have created the term between the lookup and the insert.
			$existing = term_exists( $name, $taxonomy );
			$term_id  = is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;

			if ( $term_id > 0 ) {
				return $term_id;
			}

			/* translators: %s: term name. */
			$result['warnings'][] = sprintf( __( 'term "%s" could not be created', 'brasth-document-sync-for-google-docs' ), $name );
			return 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Write SEO title and description for Yoast SEO or Rank Math when active.
	 *
	 * @param int                 $post_id Post ID.
	 * @param array<string,mixed> $fields  Fields.
	 * @param array<string,mixed> $result  Result accumulator.
	 */
	private function applySeo( int $post_id, array $fields, array &$result ): void {
		if ( ! isset( $fields['seo_title'] ) && ! isset( $fields['seo_description'] ) ) {
			return;
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			$keys = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' );
		} elseif ( class_exists( 'RankMath' ) ) {
			$keys = array( 'rank_math_title', 'rank_math_description' );
		} else {
			$result['warnings'][] = __( 'SEO fields (no supported SEO plugin active)', 'brasth-document-sync-for-google-docs' );
			return;
		}

		if ( isset( $fields['seo_title'] ) ) {
			update_post_meta( $post_id, $keys[0], sanitize_text_field( (string) $fields['seo_title'] ) );
			$result['applied'][] = 'SEO title';
		}

		if ( isset( $fields['seo_description'] ) ) {
			update_post_meta( $post_id, $keys[1], sanitize_text_field( (string) $fields['seo_description'] ) );
			$result['applied'][] = 'SEO description';
		}
	}
}
