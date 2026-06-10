<?php
/**
 * Post-level CRUD (metadata + status). Block-content edits stay on
 * the existing per-block endpoints.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

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

	/** Option name for the post-type allow-list (see spec §3.1). */
	const POST_TYPES_ALLOWLIST_OPTION = 'gk_block_api_post_types_allowlist';

	/** Option name for the "let the assistant move posts to trash" toggle. */
	const ALLOW_TRASH_OPTION = 'gk_block_api_allow_trash';

	/**
	 * Block CRUD service instance.
	 *
	 * @var Block_CRUD
	 */
	private $block_crud;

	/**
	 * Constructor.
	 *
	 * @param Block_CRUD $block_crud Block CRUD service.
	 */
	public function __construct( Block_CRUD $block_crud ) {
		$this->block_crud = $block_crud;
	}

	/**
	 * Whether the assistant is allowed to move posts to trash.
	 *
	 * Off by default: the dedicated agent has no `delete_*` capabilities, but
	 * trashing routes through `update_post( status: 'trash' )`, which only
	 * needs `edit_post` (the agent has it). This toggle is the application-level
	 * gate that keeps that path closed until a site owner opts in. Filterable
	 * via `gk/block-mcp/post/allow-trash` for programmatic control.
	 *
	 * @return bool
	 */
	public static function trashing_enabled() {
		$enabled = (bool) get_option( self::ALLOW_TRASH_OPTION, false );

		/**
		 * Control whether the AI assistant may move posts to the trash.
		 *
		 * Trashing is off by default and the agent has no delete capability,
		 * but moving a post to trash only needs edit access — so this is the
		 * real gate. There's a checkbox for it in Settings; use the filter when
		 * you'd rather decide in code, for example allowing the assistant to
		 * tidy up only its own draft posts while keeping everything else
		 * untouchable. Return true to permit trashing, false to forbid it.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Let the assistant trash posts only on the staging site.
		 * add_filter( 'gk/block-mcp/post/allow-trash', function ( $enabled ) {
		 *     return wp_get_environment_type() === 'staging' ? true : $enabled;
		 * } );
		 *
		 * @param bool $enabled Whether trashing is currently allowed by the stored option.
		 */
		return (bool) apply_filters( 'gk/block-mcp/post/allow-trash', $enabled );
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
				__( 'A non-empty "title" is required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		if ( ! in_array( $post_type, $this->default_allowed_post_types(), true ) ) {
			return new \WP_Error(
				'invalid_post_type',
				sprintf( /* translators: %s: post type slug */ __( 'Post type "%s" is not allowed.', 'gk-block-mcp' ), $post_type ),
				array( 'status' => 400 )
			);
		}

		$pt_object  = get_post_type_object( $post_type );
		$create_cap = ( $pt_object && isset( $pt_object->cap->create_posts ) )
			? $pt_object->cap->create_posts
			: 'edit_posts';
		if ( ! current_user_can( $create_cap ) ) {
			return new \WP_Error(
				'rest_cannot_create',
				__( 'Sorry, you are not allowed to create posts of this type.', 'gk-block-mcp' ),
				array( 'status' => 403 )
			);
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'draft';
		if ( ! in_array( $status, self::ALLOWED_STATUSES_CREATE, true ) ) {
			return new \WP_Error(
				'invalid_status',
				sprintf( /* translators: %s: status slug */ __( 'Status "%s" is not allowed on create. Use update_post for trash transitions.', 'gk-block-mcp' ), $status ),
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
					__( 'You cannot publish posts of this type.', 'gk-block-mcp' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( 'future' === $status ) {
			$future_check = $this->validate_future_date( isset( $args['date'] ) ? $args['date'] : null );
			if ( is_wp_error( $future_check ) ) {
				return $future_check;
			}
		}

		if ( isset( $args['content'] ) && isset( $args['blocks'] ) ) {
			return new \WP_Error(
				'mutually_exclusive',
				__( '"content" and "blocks" are mutually exclusive.', 'gk-block-mcp' ),
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
			// Same parse-and-normalize gate update_post enforces — without
			// this, sanitize_text_field accepts arbitrary strings and wp_insert_post
			// stores them verbatim, corrupting admin sort order and date queries.
			$date_raw = sanitize_text_field( $args['date'] );
			$ts       = strtotime( $date_raw );
			if ( false === $ts ) {
				return new \WP_Error(
					'invalid_date',
					__( 'date must be a parseable ISO 8601 or MySQL datetime.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			$gmt_datetime             = gmdate( 'Y-m-d H:i:s', $ts );
			$postarr['post_date_gmt'] = $gmt_datetime;
			// post_date stores the site-local clock; post_date_gmt the
			// timezone-invariant counterpart. WordPress reads post_date
			// directly for admin sort and date queries, so it MUST be
			// converted to the site's timezone — get_date_from_gmt does
			// the conversion in one call.
			$postarr['post_date'] = get_date_from_gmt( $gmt_datetime );
		}
		if ( isset( $args['menu_order'] ) ) {
			$postarr['menu_order'] = (int) $args['menu_order'];
		}
		if ( isset( $args['comment_status'] ) ) {
			if ( ! in_array( $args['comment_status'], array( 'open', 'closed' ), true ) ) {
				return new \WP_Error(
					'invalid_status',
					__( 'comment_status must be "open" or "closed".', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			$postarr['comment_status'] = $args['comment_status'];
		}
		if ( isset( $args['ping_status'] ) ) {
			if ( ! in_array( $args['ping_status'], array( 'open', 'closed' ), true ) ) {
				return new \WP_Error(
					'invalid_status',
					__( 'ping_status must be "open" or "closed".', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			$postarr['ping_status'] = $args['ping_status'];
		}
		if ( isset( $args['author'] ) ) {
			$author_id = (int) $args['author'];
			if ( $author_id < 1 || ! get_userdata( $author_id ) ) {
				return new \WP_Error(
					'invalid_author',
					__( 'The supplied author ID does not match an existing user.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			if ( get_current_user_id() !== $author_id ) {
				$others_cap = ( $pt_object && isset( $pt_object->cap->edit_others_posts ) )
					? $pt_object->cap->edit_others_posts
					: 'edit_others_posts';
				if ( ! current_user_can( $others_cap ) ) {
					return new \WP_Error(
						'rest_cannot_assign_author',
						__( 'You cannot assign authorship to other users.', 'gk-block-mcp' ),
						array( 'status' => 403 )
					);
				}
			}
			$postarr['post_author'] = $author_id;
		}
		if ( isset( $args['featured_media'] ) ) {
			$fm = (int) $args['featured_media'];
			if ( $fm > 0 && ! $this->is_valid_image_attachment( $fm ) ) {
				return new \WP_Error(
					'invalid_featured_media',
					__( 'featured_media is not a valid image attachment.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
		}

		// wp_insert_post() runs wp_unslash() on string fields. Our $postarr is
		// unslashed (serialize_blocks() output / decoded JSON args), so wp_slash
		// it first to keep escapes like \n, \" and -- intact.
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			return $this->ensure_status( $post_id, 400, 'wp_insert_post_failed' );
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'wp_insert_post_failed',
				__( 'wp_insert_post returned a non-positive ID.', 'gk-block-mcp' ),
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
			$deleted = wp_delete_post( $post_id, true );
			if ( false === $deleted || null === $deleted ) {
				if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
					error_log( sprintf( 'gk-block-mcp: orphaned post %d after term assignment failure', $post_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
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
	 * @param int   $post_id Post ID.
	 * @param array $args    See docs/specs/2026-04-27-docs-lifecycle-tools.md §3.2.
	 * @return array|\WP_Error
	 */
	public function update_post( $post_id, array $args ) {
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				sprintf( /* translators: %d: post ID */ __( 'Post %d does not exist.', 'gk-block-mcp' ), $post_id ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'rest_cannot_edit',
				__( 'You cannot edit this post.', 'gk-block-mcp' ),
				array( 'status' => 403 )
			);
		}

		// Per-post writes bucket (10/min). Shared with the existing block-level
		// write tools so updating a post and editing its blocks share the budget.
		$rate_check = $this->block_crud->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Validate featured_media BEFORE any writes so partial state can't leak
		// when the attachment is invalid.
		if ( array_key_exists( 'featured_media', $args ) ) {
			$fm = (int) $args['featured_media'];
			if ( $fm > 0 && ! $this->is_valid_image_attachment( $fm ) ) {
				return new \WP_Error(
					'invalid_featured_media',
					__( 'featured_media is not a valid image attachment.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
		}

		$pt_object               = get_post_type_object( $post->post_type );
		$before_rev_id           = $this->latest_revision_id( $post_id );
		$transitioned_to_publish = false;
		$untrashed               = false;
		$status_to_set           = null;

		if ( array_key_exists( 'status', $args ) ) {
			$new_status = sanitize_key( $args['status'] );
			if ( ! in_array( $new_status, self::ALLOWED_STATUSES_UPDATE, true ) ) {
				return new \WP_Error(
					'invalid_status',
					sprintf( /* translators: %s: status slug */ __( 'Status "%s" is not allowed.', 'gk-block-mcp' ), $new_status ),
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
						__( 'You cannot publish posts of this type.', 'gk-block-mcp' ),
						array( 'status' => 403 )
					);
				}
			}
			if ( 'future' === $new_status ) {
				$future_date  = array_key_exists( 'date', $args ) ? $args['date'] : $post->post_date;
				$future_check = $this->validate_future_date( $future_date );
				if ( is_wp_error( $future_check ) ) {
					return $future_check;
				}
			}
			if ( 'trash' === $new_status ) {
				if ( ! self::trashing_enabled() ) {
					return new \WP_Error(
						'trash_disabled',
						__( 'Moving posts to trash is turned off for the Block MCP. A site administrator can enable it under Block MCP → Settings.', 'gk-block-mcp' ),
						array( 'status' => 403 )
					);
				}
				// Reject trash-plus-other-fields: trashing is a status-only
				// operation. Mixed payloads were silently mutating a trashed
				// post's title/parent/etc. before this guard.
				$mutating = array_diff( array_keys( $args ), array( 'status' ) );
				if ( ! empty( $mutating ) ) {
					return new \WP_Error(
						'mixed_trash_payload',
						sprintf(
							'`status: "trash"` cannot be combined with other fields (got: %s). Trash first, then update.',
							implode( ', ', $mutating )
						),
						array( 'status' => 400 )
					);
				}
				if ( 'trash' !== $post->post_status ) {
					$trashed = wp_trash_post( $post_id );
					if ( false === $trashed || null === $trashed ) {
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
					$untrashed_post = wp_untrash_post( $post_id );
					if ( false === $untrashed_post || null === $untrashed_post ) {
						return new \WP_Error(
							'untrash_failed',
							'wp_untrash_post returned a falsey value.',
							array( 'status' => 500 )
						);
					}
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
			// Reject malformed dates BEFORE wp_update_post() — WP stores whatever
			// post_date string is given and a garbage value renders posts unsortable
			// in admin lists. Validate as a real ISO 8601 / MySQL datetime.
			$date_raw = sanitize_text_field( (string) $args['date'] );
			$ts       = strtotime( $date_raw );
			if ( false === $ts ) {
				return new \WP_Error(
					'invalid_date',
					__( 'date must be a parseable ISO 8601 or MySQL datetime.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			$gmt_datetime             = gmdate( 'Y-m-d H:i:s', $ts );
			$postarr['post_date_gmt'] = $gmt_datetime;
			$postarr['post_date']     = get_date_from_gmt( $gmt_datetime );
		}
		if ( array_key_exists( 'menu_order', $args ) ) {
			$postarr['menu_order'] = (int) $args['menu_order'];
		}
		if ( array_key_exists( 'comment_status', $args ) ) {
			// Reject unknown values explicitly instead of silently coercing to
			// 'closed' — a typo like 'opn' shouldn't quietly disable comments
			// while reporting success to the caller.
			if ( ! in_array( $args['comment_status'], array( 'open', 'closed' ), true ) ) {
				return new \WP_Error(
					'invalid_status',
					__( 'comment_status must be "open" or "closed".', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			$postarr['comment_status'] = $args['comment_status'];
		}
		if ( array_key_exists( 'ping_status', $args ) ) {
			if ( ! in_array( $args['ping_status'], array( 'open', 'closed' ), true ) ) {
				return new \WP_Error(
					'invalid_status',
					__( 'ping_status must be "open" or "closed".', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			$postarr['ping_status'] = $args['ping_status'];
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
			if ( $author_id < 1 || ! get_userdata( $author_id ) ) {
				return new \WP_Error(
					'invalid_author',
					__( 'The supplied author ID does not match an existing user.', 'gk-block-mcp' ),
					array( 'status' => 400 )
				);
			}
			if ( get_current_user_id() !== $author_id ) {
				$others_cap = ( $pt_object && isset( $pt_object->cap->edit_others_posts ) )
					? $pt_object->cap->edit_others_posts
					: 'edit_others_posts';
				if ( ! current_user_can( $others_cap ) ) {
					return new \WP_Error(
						'rest_cannot_assign_author',
						__( 'You cannot assign authorship to other users.', 'gk-block-mcp' ),
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
			// wp_update_post() runs wp_unslash() on string fields; slash to keep
			// values like titles or excerpts containing backslashes intact.
			$updated = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $updated ) ) {
				return $this->ensure_status( $updated, 400, 'wp_update_post_failed' );
			}
		}

		// featured_media was already validated above, before any writes.
		if ( array_key_exists( 'featured_media', $args ) ) {
			$fm = (int) $args['featured_media'];
			if ( $fm > 0 ) {
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

		// Record successful write into the per-post writes bucket.
		$this->block_crud->record_rate_limit( $post_id, 'write' );

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
	 * Walk a block tree applying validate_block_def at every depth.
	 *
	 * Returns a WP_Error on first hard rejection (legacy tier); accumulates
	 * non-fatal warnings into the passed-by-reference array.
	 *
	 * @param array $blocks   Block defs in API shape.
	 * @param array $warnings Warning accumulator.
	 *
	 * @return null|\WP_Error
	 */
	private function walk_blocks_for_validation( array $blocks, array &$warnings ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['name'] ) ? (string) $block['name'] : '';
			if ( '' === $name ) {
				return new \WP_Error( 'invalid_block', __( 'Each block requires a "name".', 'gk-block-mcp' ), array( 'status' => 400 ) );
			}
			$check = $this->block_crud->validate_block_def( $name );
			if ( $check['error'] instanceof \WP_Error ) {
				return $check['error'];
			}
			if ( ! empty( $check['warnings'] ) ) {
				$warnings = array_merge( $warnings, $check['warnings'] );
			}
			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
				$err = $this->walk_blocks_for_validation( $block['innerBlocks'], $warnings );
				if ( $err instanceof \WP_Error ) {
					return $err;
				}
			}
		}
		return null;
	}

	/**
	 * Validate and normalize a block array before insertion.
	 *
	 * @param array $blocks API-shaped block definitions.
	 * @return array|\WP_Error Normalized block array with warnings, or WP_Error on failure.
	 */
	private function validate_blocks_for_insert( array $blocks ) {
		$warnings = array();
		// Walk the full tree so nested blocks hit the same tier policy as top-level ones.
		$err = $this->walk_blocks_for_validation( $blocks, $warnings );
		if ( $err instanceof \WP_Error ) {
			return $err;
		}

		$normalized = array_map(
			array( $this, 'normalize_block_def_for_insert' ),
			$blocks
		);
		return array(
			'blocks'   => $normalized,
			'warnings' => $warnings,
		);
	}

	/**
	 * Recursively convert an API-shaped block definition (name / attributes /
	 * innerHTML / innerBlocks) into the WP internal shape (blockName / attrs /
	 * innerHTML / innerContent / innerBlocks) that serialize_blocks() expects.
	 *
	 * InnerContent is derived from innerHTML: for leaf blocks it becomes
	 * array( $innerHTML ); for container blocks the wrapper HTML is split into
	 * an opening fragment, one null placeholder per child, and a closing fragment.
	 *
	 * @param array $block API-shaped block definition.
	 * @return array WP internal block shape.
	 */
	private function normalize_block_def_for_insert( array $block ) {
		$inner_html = isset( $block['innerHTML'] ) ? wp_kses_post( $block['innerHTML'] ) : '';
		$attrs      = isset( $block['attributes'] ) && is_array( $block['attributes'] ) ? $block['attributes'] : array();

		// Recurse into children first so container blocks have a fully-formed
		// innerBlocks array before we compute innerContent.
		$children = array();
		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $child_def ) {
				$children[] = $this->normalize_block_def_for_insert( $child_def );
			}
		}

		// Build innerContent.
		if ( ! empty( $children ) ) {
			$n = count( $children );
			if ( ! empty( $inner_html ) ) {
				// Split the wrapper HTML into opening/closing halves and
				// interleave null placeholders for each child block.
				$first_close = strpos( $inner_html, '>' );
				if ( false !== $first_close ) {
					$inner_content = array( substr( $inner_html, 0, $first_close + 1 ) );
					for ( $i = 0; $i < $n; $i++ ) {
						$inner_content[] = null;
					}
					$inner_content[] = substr( $inner_html, $first_close + 1 );
				} else {
					$inner_content = array_fill( 0, $n, null );
				}
			} else {
				$inner_content = array_fill( 0, $n, null );
			}
		} elseif ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			// Caller supplied explicit innerContent — sanitize each string piece.
			$inner_content = array();
			foreach ( $block['innerContent'] as $piece ) {
				$inner_content[] = ( null === $piece ) ? null : wp_kses_post( (string) $piece );
			}
		} else {
			// Leaf block: innerContent is simply array( $innerHTML ) or empty.
			$inner_content = ! empty( $inner_html ) ? array( $inner_html ) : array();
		}

		return array(
			'blockName'    => $block['name'],
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => ! empty( $children ) ? '' : $inner_html,
			'innerContent' => $inner_content,
		);
	}

	/**
	 * Validate that `status: future` is paired with a future date.
	 *
	 * @param string|null $date ISO 8601 string.
	 * @return true|\WP_Error
	 */
	private function validate_future_date( $date ) {
		if ( empty( $date ) || ! is_string( $date ) ) {
			return new \WP_Error(
				'invalid_status',
				__( 'Status "future" requires a "date" set in the future.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		$timestamp = strtotime( $date );
		if ( false === $timestamp || $timestamp <= time() ) {
			return new \WP_Error(
				'invalid_status',
				__( 'Status "future" requires a "date" set in the future.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Verify an attachment ID points at an image.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	private function is_valid_image_attachment( $attachment_id ) {
		if ( function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $attachment_id ) ) {
			return true;
		}
		$mime = get_post_mime_type( $attachment_id );
		return is_string( $mime ) && 0 === strpos( $mime, 'image/' );
	}

	/**
	 * Ensure a WP_Error from core has a sensible HTTP status. Core returns
	 * `WP_Error`s with no `status` data field, which the REST infra defaults
	 * to 500 — even for validation errors. Wrap with the supplied status and
	 * preserve any existing data fields.
	 *
	 * @param \WP_Error $error    WP_Error from core to wrap.
	 * @param int       $status   HTTP status to apply.
	 * @param string    $fallback Code to use if $error has none.
	 * @return \WP_Error
	 */
	private function ensure_status( \WP_Error $error, $status, $fallback ) {
		$code = $error->get_error_code();
		if ( '' === $code ) {
			$code = $fallback;
		}
		$message = $error->get_error_message();
		$data    = (array) $error->get_error_data();
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = (int) $status;
		}
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Validate a parent post ID for a given post type.
	 *
	 * @param int    $parent_id Parent post ID to validate.
	 * @param string $post_type Post type slug.
	 * @param int    $self_id   Set to the post's own ID on update; 0 on create.
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
			return new \WP_Error( 'cycle_parent', __( 'A post cannot be its own parent.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}
		$parent = get_post( $parent_id );
		if ( ! $parent || $parent->post_type !== $post_type ) {
			return new \WP_Error(
				'invalid_parent',
				sprintf( /* translators: 1: parent ID, 2: post type slug */ __( 'Parent post %1$d does not exist or is not of type "%2$s".', 'gk-block-mcp' ), $parent_id, $post_type ),
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
	 * @param int    $post_id   Post ID to assign terms to.
	 * @param string $post_type Post type slug.
	 * @param array  $args      Request arguments containing categories, tags, and terms.
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
					sprintf( /* translators: %s: taxonomy slug */ __( 'Taxonomy "%s" does not exist.', 'gk-block-mcp' ), $taxonomy ),
					array( 'status' => 400 )
				);
			}
			$registered_for_type = get_object_taxonomies( $post_type );
			if ( ! in_array( $taxonomy, (array) $registered_for_type, true ) ) {
				return new \WP_Error(
					'invalid_taxonomy',
					sprintf( /* translators: 1: taxonomy slug, 2: post type slug */ __( 'Taxonomy "%1$s" is not registered for post type "%2$s".', 'gk-block-mcp' ), $taxonomy, $post_type ),
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
						sprintf( /* translators: 1: term ID, 2: taxonomy slug */ __( 'Term %1$d does not exist in taxonomy "%2$s".', 'gk-block-mcp' ), $term_id, $taxonomy ),
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
	 * Get the most recent revision ID for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return int|null
	 */
	private function latest_revision_id( $post_id ) {
		$revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
		if ( empty( $revisions ) ) {
			return null;
		}
		$first = array_values( $revisions )[0];
		return is_object( $first ) ? (int) $first->ID : null;
	}

	/**
	 * Resolve the post-type allow-list. Site admins can override the default
	 * via the `gk_block_api_post_types_allowlist` option (array of post-type
	 * slugs). When unset, defaults to `post`, `page`, plus any post type whose
	 * `show_in_rest` is true.
	 *
	 * @return string[]
	 */
	private function default_allowed_post_types() {
		$override = get_option( self::POST_TYPES_ALLOWLIST_OPTION, null );
		if ( is_array( $override ) && ! empty( $override ) ) {
			return array_values( array_unique( array_map( 'sanitize_key', $override ) ) );
		}
		$built_in     = array( 'post', 'page' );
		$rest_enabled = function_exists( 'get_post_types' )
			? array_values( get_post_types( array( 'show_in_rest' => true ), 'names' ) )
			: array();
		return array_values( array_unique( array_merge( $built_in, $rest_enabled ) ) );
	}
}
