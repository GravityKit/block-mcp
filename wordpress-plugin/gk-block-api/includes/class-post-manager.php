<?php
/**
 * Post-level CRUD (metadata + status). Block-content edits stay on
 * the existing per-block endpoints.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post_Manager
 *
 * Owns create_post and update_post. Reuses Block_CRUD's preference
 * pipeline when callers pass structured blocks at create time.
 */
class Post_Manager {

	const ALLOWED_STATUSES_CREATE = array( 'draft', 'pending', 'private', 'publish', 'future' );
	const ALLOWED_STATUSES_UPDATE = array( 'draft', 'pending', 'private', 'publish', 'future', 'trash' );

	/**
	 * @var Preferences
	 */
	private $preferences;

	public function __construct( Preferences $preferences ) {
		$this->preferences = $preferences;
	}

	/**
	 * Create a new post or page.
	 *
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.1.
	 * @return array|\WP_Error
	 */
	public function create_post( array $args ) {
		if ( empty( $args['title'] ) || ! is_string( $args['title'] ) ) {
			return new \WP_Error(
				'missing_title',
				__( 'A non-empty "title" is required.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		if ( ! in_array( $post_type, $this->default_allowed_post_types(), true ) ) {
			return new \WP_Error(
				'invalid_post_type',
				sprintf( 'Post type "%s" is not allowed.', $post_type ),
				array( 'status' => 400 )
			);
		}

		$pt_object = get_post_type_object( $post_type );
		$create_cap = ( $pt_object && isset( $pt_object->cap->create_posts ) )
			? $pt_object->cap->create_posts
			: 'edit_posts';
		if ( ! current_user_can( $create_cap ) ) {
			return new \WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to create posts of this type.', 'gk-block-api' ),
				array( 'status' => 403 )
			);
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft';
		if ( ! in_array( $status, self::ALLOWED_STATUSES_CREATE, true ) ) {
			return new \WP_Error(
				'invalid_status',
				sprintf( 'Status "%s" is not allowed on create. Use update_post for trash transitions.', $status ),
				array( 'status' => 400 )
			);
		}

		if ( 'publish' === $status ) {
			$publish_cap = ( $pt_object && isset( $pt_object->cap->publish_posts ) )
				? $pt_object->cap->publish_posts
				: 'publish_posts';
			if ( ! current_user_can( $publish_cap ) ) {
				return new \WP_Error(
					'rest_cannot_publish',
					__( 'You cannot publish posts of this type.', 'gk-block-api' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( isset( $args['content'] ) && isset( $args['blocks'] ) ) {
			return new \WP_Error(
				'mutually_exclusive',
				'"content" and "blocks" are mutually exclusive.',
				array( 'status' => 400 )
			);
		}

		$warnings = array();
		$content  = '';
		if ( ! empty( $args['blocks'] ) && is_array( $args['blocks'] ) ) {
			$validation = $this->validate_blocks_for_insert( $args['blocks'] );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}
			$warnings = $validation['warnings'];
			$content  = serialize_blocks( $validation['blocks'] );
		} elseif ( isset( $args['content'] ) && is_string( $args['content'] ) ) {
			$content = wp_kses_post( $args['content'] );
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_status'  => $status,
			'post_title'   => sanitize_text_field( $args['title'] ),
			'post_content' => $content,
		);

		if ( isset( $args['slug'] ) && is_string( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['excerpt'] ) && is_string( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( $args['excerpt'] );
		}
		if ( isset( $args['parent'] ) ) {
			$parent_check = $this->validate_parent( (int) $args['parent'], $post_type, 0 );
			if ( is_wp_error( $parent_check ) ) {
				return $parent_check;
			}
			$postarr['post_parent'] = (int) $args['parent'];
		}
		if ( isset( $args['date'] ) && is_string( $args['date'] ) ) {
			$postarr['post_date'] = sanitize_text_field( $args['date'] );
		}
		if ( isset( $args['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $args['menu_order'];
		}
		if ( isset( $args['comment_status'] ) ) {
			$postarr['comment_status'] = in_array( $args['comment_status'], array( 'open', 'closed' ), true )
				? $args['comment_status']
				: 'closed';
		}
		if ( isset( $args['ping_status'] ) ) {
			$postarr['ping_status'] = in_array( $args['ping_status'], array( 'open', 'closed' ), true )
				? $args['ping_status']
				: 'closed';
		}
		if ( isset( $args['author'] ) ) {
			$author_id = (int) $args['author'];
			if ( $author_id !== get_current_user_id() ) {
				$others_cap = ( $pt_object && isset( $pt_object->cap->edit_others_posts ) )
					? $pt_object->cap->edit_others_posts
					: 'edit_others_posts';
				if ( ! current_user_can( $others_cap ) ) {
					return new \WP_Error(
						'rest_cannot_assign_author',
						__( 'You cannot assign authorship to other users.', 'gk-block-api' ),
						array( 'status' => 403 )
					);
				}
			}
			$postarr['post_author'] = $author_id;
		}
		if ( isset( $args['featured_media'] ) ) {
			$fm = (int) $args['featured_media'];
			if ( $fm > 0 ) {
				$mime = get_post_mime_type( $fm );
				if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
					return new \WP_Error(
						'invalid_featured_media',
						'featured_media is not a valid image attachment.',
						array( 'status' => 400 )
					);
				}
			}
		}

		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'wp_insert_post_failed',
				'wp_insert_post returned a non-positive ID.',
				array( 'status' => 500 )
			);
		}

		if ( isset( $args['featured_media'] ) ) {
			$fm = (int) $args['featured_media'];
			if ( $fm > 0 ) {
				set_post_thumbnail( $post_id, $fm );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		$term_assignment = $this->assign_terms( $post_id, $post_type, $args );
		if ( is_wp_error( $term_assignment ) ) {
			wp_delete_post( $post_id, true );
			return $term_assignment;
		}

		$revision_id = $this->latest_revision_id( $post_id );
		$post        = get_post( $post_id );

		return array(
			'success'            => true,
			'id'                 => $post_id,
			'post_type'          => $post->post_type,
			'status'             => $post->post_status,
			'title'              => $post->post_title,
			'slug'               => $post->post_name,
			'permalink'          => get_permalink( $post ),
			'edit_link'          => get_edit_post_link( $post, 'raw' ),
			'before_revision_id' => null,
			'revision_id'        => $revision_id,
			'warnings'           => $warnings,
		);
	}

	/**
	 * Update post metadata, status, or terms.
	 *
	 * @param int   $post_id
	 * @param array $args See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.2.
	 * @return array|\WP_Error
	 */
	public function update_post( $post_id, array $args ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( 'Post %d does not exist.', $post_id ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_cannot_edit',
				__( 'You cannot edit this post.', 'gk-block-api' ),
				array( 'status' => 403 )
			);
		}

		$pt_object        = get_post_type_object( $post->post_type );
		$before_rev_id    = $this->latest_revision_id( $post_id );
		$transitioned_to_publish = false;
		$untrashed               = false;
		$status_to_set           = null;

		if ( array_key_exists( 'status', $args ) ) {
			$new_status = sanitize_key( $args['status'] );
			if ( ! in_array( $new_status, self::ALLOWED_STATUSES_UPDATE, true ) ) {
				return new \WP_Error(
					'invalid_status',
					sprintf( 'Status "%s" is not allowed.', $new_status ),
					array( 'status' => 400 )
				);
			}
			if ( 'publish' === $new_status ) {
				$publish_cap = ( $pt_object && isset( $pt_object->cap->publish_posts ) )
					? $pt_object->cap->publish_posts
					: 'publish_posts';
				if ( ! current_user_can( $publish_cap ) ) {
					return new \WP_Error(
						'rest_cannot_publish',
						__( 'You cannot publish posts of this type.', 'gk-block-api' ),
						array( 'status' => 403 )
					);
				}
			}
			if ( 'trash' === $new_status ) {
				if ( 'trash' !== $post->post_status ) {
					$trashed = wp_trash_post( $post_id );
					if ( ! $trashed ) {
						return new \WP_Error(
							'trash_failed',
							'wp_trash_post returned a falsey value.',
							array( 'status' => 500 )
						);
					}
					$post = get_post( $post_id );
				}
			} else {
				if ( 'trash' === $post->post_status ) {
					wp_untrash_post( $post_id );
					$untrashed = true;
					$post      = get_post( $post_id );
				}
				if (
					'publish' === $new_status
					&& in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future', 'private' ), true )
				) {
					$transitioned_to_publish = true;
				}
				$status_to_set = $new_status;
			}
		}

		$postarr = array( 'ID' => $post_id );
		if ( array_key_exists( 'title', $args ) ) {
			$postarr['post_title'] = sanitize_text_field( (string) $args['title'] );
		}
		if ( array_key_exists( 'slug', $args ) ) {
			$postarr['post_name'] = sanitize_title( (string) $args['slug'] );
		}
		if ( array_key_exists( 'excerpt', $args ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $args['excerpt'] );
		}
		if ( array_key_exists( 'date', $args ) ) {
			$postarr['post_date'] = sanitize_text_field( (string) $args['date'] );
		}
		if ( array_key_exists( 'menu_order', $args ) ) {
			$postarr['menu_order'] = (int) $args['menu_order'];
		}
		if ( array_key_exists( 'comment_status', $args ) ) {
			$postarr['comment_status'] = in_array( $args['comment_status'], array( 'open', 'closed' ), true )
				? $args['comment_status']
				: 'closed';
		}
		if ( array_key_exists( 'ping_status', $args ) ) {
			$postarr['ping_status'] = in_array( $args['ping_status'], array( 'open', 'closed' ), true )
				? $args['ping_status']
				: 'closed';
		}
		if ( array_key_exists( 'parent', $args ) ) {
			$parent_check = $this->validate_parent( (int) $args['parent'], $post->post_type, $post_id );
			if ( is_wp_error( $parent_check ) ) {
				return $parent_check;
			}
			$postarr['post_parent'] = (int) $args['parent'];
		}
		if ( array_key_exists( 'author', $args ) ) {
			$author_id = (int) $args['author'];
			if ( $author_id !== get_current_user_id() ) {
				$others_cap = ( $pt_object && isset( $pt_object->cap->edit_others_posts ) )
					? $pt_object->cap->edit_others_posts
					: 'edit_others_posts';
				if ( ! current_user_can( $others_cap ) ) {
					return new \WP_Error(
						'rest_cannot_assign_author',
						__( 'You cannot assign authorship to other users.', 'gk-block-api' ),
						array( 'status' => 403 )
					);
				}
			}
			$postarr['post_author'] = $author_id;
		}
		if ( null !== $status_to_set ) {
			$postarr['post_status'] = $status_to_set;
		}

		if ( count( $postarr ) > 1 ) {
			$updated = wp_update_post( $postarr, true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		if ( array_key_exists( 'featured_media', $args ) ) {
			$fm = (int) $args['featured_media'];
			if ( $fm > 0 ) {
				$mime = get_post_mime_type( $fm );
				if ( ! $mime || strpos( $mime, 'image/' ) !== 0 ) {
					return new \WP_Error(
						'invalid_featured_media',
						'featured_media is not a valid image attachment.',
						array( 'status' => 400 )
					);
				}
				set_post_thumbnail( $post_id, $fm );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		$term_assignment = $this->assign_terms( $post_id, $post->post_type, $args );
		if ( is_wp_error( $term_assignment ) ) {
			return $term_assignment;
		}

		$after_rev_id = $this->latest_revision_id( $post_id );
		if ( $after_rev_id === $before_rev_id ) {
			$after_rev_id = null;
		}

		$post = get_post( $post_id );

		return array(
			'success'                 => true,
			'id'                      => $post_id,
			'post_type'               => $post->post_type,
			'status'                  => $post->post_status,
			'title'                   => $post->post_title,
			'slug'                    => $post->post_name,
			'permalink'               => get_permalink( $post ),
			'edit_link'               => get_edit_post_link( $post, 'raw' ),
			'transitioned_to_publish' => $transitioned_to_publish,
			'untrashed'               => $untrashed,
			'before_revision_id'      => $before_rev_id,
			'revision_id'             => $after_rev_id,
			'warnings'                => array(),
		);
	}

	/**
	 * Validate blocks against the registry and preference tier.
	 *
	 * @param array $blocks
	 * @return array{blocks:array,warnings:array}|\WP_Error
	 */
	private function validate_blocks_for_insert( array $blocks ) {
		$warnings = array();
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( $blocks as $block ) {
			$name = isset( $block['name'] ) ? (string) $block['name'] : '';
			if ( '' === $name ) {
				return new \WP_Error( 'invalid_block', 'Each block requires a "name".', array( 'status' => 400 ) );
			}
			if ( ! $registry->is_registered( $name ) ) {
				return new \WP_Error( 'unregistered_block', sprintf( 'Block "%s" is not registered.', $name ), array( 'status' => 400 ) );
			}
			$score = $this->preferences->get_block_score( $name );
			$tier  = isset( $score['tier'] ) ? $score['tier'] : 'acceptable';
			if ( 'legacy' === $tier ) {
				return new \WP_Error( 'legacy_block', sprintf( 'Block "%s" is legacy and cannot be inserted.', $name ), array( 'status' => 400 ) );
			}
			if ( 'avoid' === $tier ) {
				$warnings[] = array(
					'block'                 => $name,
					'message'               => sprintf( 'Block "%s" is on the avoid list.', $name ),
					'suggested_replacement' => $this->preferences->get_replacement( $name ),
				);
			}
		}

		$normalized = array_map(
			function ( $block ) {
				return array(
					'blockName'    => $block['name'],
					'attrs'        => isset( $block['attributes'] ) && is_array( $block['attributes'] ) ? $block['attributes'] : array(),
					'innerBlocks'  => isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(),
					'innerHTML'    => isset( $block['innerHTML'] ) ? wp_kses_post( $block['innerHTML'] ) : '',
					'innerContent' => isset( $block['innerContent'] ) ? $block['innerContent'] : array(),
				);
			},
			$blocks
		);
		return array( 'blocks' => $normalized, 'warnings' => $warnings );
	}

	/**
	 * @param int    $parent_id
	 * @param string $post_type
	 * @param int    $self_id  Set to the post's own ID on update; 0 on create.
	 * @return true|\WP_Error
	 */
	private function validate_parent( $parent_id, $post_type, $self_id ) {
		if ( 0 === $parent_id ) {
			return true;
		}
		$pt_object = get_post_type_object( $post_type );
		if ( ! $pt_object || empty( $pt_object->hierarchical ) ) {
			return new \WP_Error(
				'invalid_parent',
				sprintf( '"%s" is not hierarchical; parent cannot be set.', $post_type ),
				array( 'status' => 400 )
			);
		}
		if ( $self_id && $parent_id === $self_id ) {
			return new \WP_Error( 'cycle_parent', 'A post cannot be its own parent.', array( 'status' => 400 ) );
		}
		$parent = get_post( $parent_id );
		if ( ! $parent || $parent->post_type !== $post_type ) {
			return new \WP_Error(
				'invalid_parent',
				sprintf( 'Parent post %d does not exist or is not of type "%s".', $parent_id, $post_type ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Assign terms (categories, tags, generic terms map). Validates each
	 * term exists in its taxonomy and the taxonomy is registered for the
	 * post type.
	 *
	 * @param int    $post_id
	 * @param string $post_type
	 * @param array  $args
	 * @return true|\WP_Error
	 */
	private function assign_terms( $post_id, $post_type, array $args ) {
		$assignments = array();
		if ( array_key_exists( 'categories', $args ) ) {
			$assignments['category'] = (array) $args['categories'];
		}
		if ( array_key_exists( 'tags', $args ) ) {
			$assignments['post_tag'] = (array) $args['tags'];
		}
		if ( ! empty( $args['terms'] ) && is_array( $args['terms'] ) ) {
			foreach ( $args['terms'] as $tax => $ids ) {
				$assignments[ sanitize_key( $tax ) ] = (array) $ids;
			}
		}

		foreach ( $assignments as $taxonomy => $ids ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				return new \WP_Error(
					'invalid_taxonomy',
					sprintf( 'Taxonomy "%s" does not exist.', $taxonomy ),
					array( 'status' => 400 )
				);
			}
			$registered_for_type = get_object_taxonomies( $post_type );
			if ( ! in_array( $taxonomy, (array) $registered_for_type, true ) ) {
				return new \WP_Error(
					'invalid_taxonomy',
					sprintf( 'Taxonomy "%s" is not registered for post type "%s".', $taxonomy, $post_type ),
					array( 'status' => 400 )
				);
			}
			$ids = array_map( 'absint', $ids );
			foreach ( $ids as $term_id ) {
				if ( $term_id <= 0 ) {
					continue;
				}
				$term = get_term( $term_id, $taxonomy );
				if ( ! $term || is_wp_error( $term ) ) {
					return new \WP_Error(
						'invalid_term',
						sprintf( 'Term %d does not exist in taxonomy "%s".', $term_id, $taxonomy ),
						array( 'status' => 400 )
					);
				}
			}
			$result = wp_set_post_terms( $post_id, $ids, $taxonomy, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * @param int $post_id
	 * @return int|null
	 */
	private function latest_revision_id( $post_id ) {
		$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
		if ( empty( $revisions ) ) {
			return null;
		}
		$first = array_values( $revisions )[0];
		return is_object( $first ) && isset( $first->ID ) ? (int) $first->ID : null;
	}

	/**
	 * Built-in allow-list when no override option is set.
	 *
	 * @return string[]
	 */
	private function default_allowed_post_types() {
		$built_in     = array( 'post', 'page' );
		$rest_enabled = function_exists( 'get_post_types' )
			? array_values( get_post_types( array( 'show_in_rest' => true ), 'names' ) )
			: array();
		return array_values( array_unique( array_merge( $built_in, $rest_enabled ) ) );
	}
}
