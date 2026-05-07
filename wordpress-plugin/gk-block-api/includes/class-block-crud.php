<?php
/**
 * Block CRUD operations: parse, serialize, insert, update, delete, replace.
 *
 * All write operations create WordPress revisions. Rate limiting prevents
 * runaway automated edits.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_CRUD
 *
 * Provides block-level create, read, update, and delete operations on post content.
 */
class Block_CRUD {

	/**
	 * Maximum writes per post per minute.
	 *
	 * @var int
	 */
	const RATE_LIMIT_WRITES = 10;

	/**
	 * Maximum full-page rewrites (PUT) per post per minute.
	 *
	 * @var int
	 */
	const RATE_LIMIT_PUT = 2;

	/**
	 * Preferences instance.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Block safety checker.
	 *
	 * @var Block_Safety
	 */
	private $safety;

	/**
	 * HTML transformer.
	 *
	 * @var HTML_Transformer
	 */
	private $transformer;

	/**
	 * Site-wide block inventory (storage_mode classification + dual-storage list).
	 *
	 * @var Block_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Preferences      $preferences Preferences instance.
	 * @param Block_Safety     $safety      Block safety checker.
	 * @param HTML_Transformer $transformer HTML transformer.
	 * @param Block_Inventory  $inventory   Block inventory.
	 */
	public function __construct( Preferences $preferences, Block_Safety $safety, HTML_Transformer $transformer, Block_Inventory $inventory ) {
		$this->preferences = $preferences;
		$this->safety      = $safety;
		$this->transformer = $transformer;
		$this->inventory   = $inventory;
	}

	/**
	 * Get all blocks for a post.
	 *
	 * Always uses parse_blocks() to ensure index consistency with write operations.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array|\WP_Error Array of block data or WP_Error.
	 */
	public function get_blocks( $post_id, $render = false, $persist_refs = true ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$content = $post->post_content;

		if ( empty( $content ) || ! is_string( $content ) ) {
			return array();
		}

		$blocks = parse_blocks( $content );

		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		// Lazy-assign block refs. When $persist_refs is true (default), any blocks
		// missing attrs.metadata.gk_ref get a fresh one and the post is updated
		// silently (no revision) so the refs survive across reads/writes.
		// When false, refs are still surfaced in the response but won't resolve later.
		$dirty = $this->assign_missing_refs_recursive( $blocks );
		if ( $dirty && $persist_refs ) {
			// Concurrency guard. Two readers landing on a fresh post could both
			// generate random refs and both call persist_ref_assignments(); the
			// second writer would silently win, leaving the first reader's
			// response with refs that no longer match disk. wp_cache_add() is
			// atomic on persistent object caches (Memcached/Redis), so use it
			// as a short-lived per-post lock around the assign-and-persist.
			$lock_key  = 'gk_block_api_ref_lock_' . (int) $post_id;
			$got_lock  = wp_cache_add( $lock_key, 1, 'gk_block_api', 5 );

			if ( $got_lock ) {
				try {
					// Re-parse current content under the lock — another writer
					// may have raced in between our parse_blocks() above and
					// our lock acquisition. If they did, their refs are now on
					// disk; assign_missing_refs_recursive() will be a no-op and
					// we won't double-write.
					$fresh = parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
					if ( is_array( $fresh ) && ! empty( $fresh ) ) {
						$blocks = $fresh;
					}
					if ( $this->assign_missing_refs_recursive( $blocks ) ) {
						$this->persist_ref_assignments( $post_id, $blocks );
					}
				} finally {
					wp_cache_delete( $lock_key, 'gk_block_api' );
				}
			} else {
				// Another worker is mid-assignment. Briefly wait for them to
				// finish, then re-parse so we surface whatever they persist
				// instead of our locally-generated random refs (which would
				// be stale by the time the response leaves this server).
				usleep( 50000 ); // 50 ms.
				$fresh = parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
				if ( is_array( $fresh ) && ! empty( $fresh ) ) {
					$blocks = $fresh;
				}
			}
		}

		// Set up post context so shortcodes and render_block() can
		// access the current post (needed for product-specific shortcodes
		// like [filter_edd_version_number], [filter_product_star_rating], etc.).
		if ( $render ) {
			$original_post    = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$GLOBALS['post']  = $post;
			setup_postdata( $post );
		}

		$result = $this->format_blocks( $blocks, $render );

		// Restore original post context.
		if ( $render ) {
			$GLOBALS['post'] = $original_post;
			if ( $original_post ) {
				setup_postdata( $original_post );
			} else {
				wp_reset_postdata();
			}
		}

		return $result;
	}

	/**
	 * Update a single block by index.
	 *
	 * Merges provided attributes and/or replaces innerHTML at the specified
	 * flat index in the parsed block array.
	 *
	 * @param int   $post_id    Post ID.
	 * @param int   $index      Block index (0-based).
	 * @param array $attributes Partial attributes to merge (optional).
	 * @param mixed $inner_html New innerHTML content (optional, pass null to skip).
	 *
	 * @return array|\WP_Error Updated block data with revision_id, or WP_Error.
	 */
	public function update_block( $post_id, $index, $attributes = array(), $inner_html = null ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}
		$flat   = $this->flatten_blocks( $blocks );

		if ( $index < 0 || $index >= count( $flat ) ) {
			return new \WP_Error(
				'invalid_index',
				__( 'Block index out of range.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		// Get reference to the actual block in the nested structure.
		$path  = $flat[ $index ]['path'];
		$block = &$this->get_block_by_path( $blocks, $path );

		// BLOCK-14: refuse innerHTML-only updates on dual-storage blocks.
		// Sending innerHTML alone on yoast/faq-block et al. silently desyncs
		// the structured attributes (questions[], etc.) — see BLOCK-3.
		if (
			null !== $inner_html
			&& empty( $attributes )
			&& isset( $block['blockName'] )
			&& $this->is_block_dual_storage( $block['blockName'] )
		) {
			return $this->dual_storage_error( $block['blockName'] );
		}

		// Merge attributes.
		if ( ! empty( $attributes ) ) {
			$block['attrs'] = array_merge(
				isset( $block['attrs'] ) ? $block['attrs'] : array(),
				$attributes
			);

			// Auto-transform innerHTML for known attribute-to-HTML mappings.
			$auto_transformed = $this->transformer->auto_transform_html(
				$block['blockName'],
				$attributes,
				isset( $block['innerHTML'] ) ? $block['innerHTML'] : ''
			);

			if ( null !== $auto_transformed ) {
				$block['innerHTML'] = $auto_transformed;
				// Update innerContent preserving null placeholders.
				if ( ! empty( $block['innerContent'] ) ) {
					$block_type_name = $block['blockName'];
					$transformer = $this->transformer;
					$block['innerContent'] = array_map(
						function ( $piece ) use ( $transformer, $block_type_name, $attributes ) {
							if ( null === $piece ) {
								return null;
							}
							$result = $transformer->auto_transform_html( $block_type_name, $attributes, $piece );
							return null !== $result ? $result : $piece;
						},
						$block['innerContent']
					);
				} else {
					$block['innerContent'] = array( $auto_transformed );
				}
			} else {
				// No auto-transform available — check static block safety.
				$safety_warnings = $this->safety->check_mutation(
					$block['blockName'],
					array_keys( $attributes ),
					false
				);
				if ( ! empty( $safety_warnings ) ) {
					// update_block doesn't currently return warnings, so these are
					// silently noted. Safety warnings can be added later if
					// update_block gets a warnings field in the response.
				}
			}
		}

		// Replace innerHTML.
		if ( null !== $inner_html ) {
			$block['innerHTML'] = wp_kses_post( $inner_html );
			// Preserve innerBlock placeholders (null) in innerContent for container blocks.
			if ( ! empty( $block['innerBlocks'] ) && ! empty( $block['innerContent'] ) ) {
				$block['innerContent'] = $this->transformer->rebuild_inner_content( $block['innerContent'], $block['innerHTML'] );
			} else {
				$block['innerContent'] = array( $block['innerHTML'] );
			}
		}

		// Serialize and save.
		$new_content = serialize_blocks( $blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		$block_data = apply_filters(
			'gk_block_api_format_block',
			array(
				'index'      => $index,
				'name'       => $block['blockName'],
				'attributes' => isset( $block['attrs'] ) ? $block['attrs'] : array(),
			),
			$block['blockName']
		);

		return array(
			'success'            => true,
			'block'              => $block_data,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
		);
	}

	/**
	 * Insert blocks at a position.
	 *
	 * @param int   $post_id  Post ID.
	 * @param mixed $position Insert position: numeric index for "after", "start" for prepend, null for append.
	 * @param array $blocks   Array of block definitions: { name, attributes, innerHTML }.
	 *
	 * @return array|\WP_Error Insert result with warnings and revision_id, or WP_Error.
	 */
	public function insert_blocks( $post_id, $position, $blocks ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$all_existing_blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $all_existing_blocks ) ) {
			$all_existing_blocks = array();
		}

		// Build a map from filtered (visible) index to raw index in the full array.
		// This preserves whitespace blocks during serialization while letting
		// the API consumer use the same indices as format_blocks().
		$visible_to_raw = array();
		foreach ( $all_existing_blocks as $raw_idx => $blk ) {
			if ( ! empty( $blk['blockName'] ) ) {
				$visible_to_raw[] = $raw_idx;
			}
		}
		$visible_count = count( $visible_to_raw );

		$warnings   = array();
		$new_blocks = array();

		foreach ( $blocks as $block_def ) {
			$built = $this->build_block_from_def( $block_def, $warnings );
			if ( is_wp_error( $built ) ) {
				return $built;
			}
			$new_blocks[] = $built;
		}

		// Assign fresh refs to newly inserted blocks (and any innerBlocks).
		// Agents need these refs to address the blocks they just created
		// without re-fetching the page.
		$this->assign_missing_refs_recursive( $new_blocks );

		// Determine insertion index (visible index), then map to raw position.
		$visible_insert = $visible_count; // Default: append.

		if ( 'start' === $position ) {
			$visible_insert = 0;
		} elseif ( is_numeric( $position ) ) {
			$pos = (int) $position;
			if ( -1 === $pos ) {
				$visible_insert = $visible_count;
			} else {
				$visible_insert = min( $pos + 1, $visible_count );
			}
		}

		// Map visible index to raw array position (preserving whitespace blocks).
		if ( $visible_insert >= $visible_count ) {
			$raw_insert = count( $all_existing_blocks );
		} elseif ( $visible_insert <= 0 ) {
			$raw_insert = 0;
		} else {
			$raw_insert = $visible_to_raw[ $visible_insert ];
		}

		// Splice into the FULL array (preserving whitespace blocks).
		array_splice( $all_existing_blocks, $raw_insert, 0, $new_blocks );

		// Serialize and save.
		$new_content = serialize_blocks( $all_existing_blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		// Build inserted response.
		// Re-parse the saved post_content so the path values reflect the post-mutation
		// raw indices (parse_blocks() may collapse adjacent whitespace differently than
		// the in-memory $all_existing_blocks array). This guarantees the returned `path`
		// is immediately usable by mutate_block_tree without an extra get_page_blocks call.
		$inserted = array();
		$saved_post = get_post( $post_id );
		if ( $saved_post ) {
			$parsed_after = parse_blocks( $saved_post->post_content );
			if ( ! is_array( $parsed_after ) ) {
				$parsed_after = array();
			}
			// Map from visible index → raw index in the post-mutation array.
			$post_visible_to_raw = array();
			foreach ( $parsed_after as $raw_idx => $blk ) {
				if ( ! empty( $blk['blockName'] ) ) {
					$post_visible_to_raw[] = $raw_idx;
				}
			}
			foreach ( $new_blocks as $i => $block ) {
				$visible_index = $visible_insert + $i;
				$raw_idx       = isset( $post_visible_to_raw[ $visible_index ] )
					? $post_visible_to_raw[ $visible_index ]
					: null;
				$entry = array(
					'index'             => $visible_index,
					'top_level_counter' => $visible_index,
					'path'              => null === $raw_idx ? array( $visible_index ) : array( $raw_idx ),
					'name'              => $block['blockName'],
				);
				if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
					$entry['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
				}
				$inserted[] = $entry;
			}
		} else {
			foreach ( $new_blocks as $i => $block ) {
				$inserted[] = array(
					'index'             => $visible_insert + $i,
					'top_level_counter' => $visible_insert + $i,
					'path'              => array( $visible_insert + $i ),
					'name'              => $block['blockName'],
				);
			}
		}

		return array(
			'success'              => true,
			'inserted'             => $inserted,
			'warnings'             => $warnings,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}

	/**
	 * Delete block(s) at a position.
	 *
	 * @param int $post_id Post ID.
	 * @param int $index   Start index to delete (0-based).
	 * @param int $count   Number of consecutive blocks to remove (default 1).
	 *
	 * @return array|\WP_Error Result with revision_id, or WP_Error.
	 */
	public function delete_blocks( $post_id, $index, $count = 1 ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$all_blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $all_blocks ) ) {
			$all_blocks = array();
		}

		// Build visible-to-raw index map to preserve whitespace blocks.
		$vis_to_raw = array();
		foreach ( $all_blocks as $raw_idx => $blk ) {
			if ( ! empty( $blk['blockName'] ) ) {
				$vis_to_raw[] = $raw_idx;
			}
		}

		if ( $index < 0 || $index >= count( $vis_to_raw ) ) {
			return new \WP_Error(
				'invalid_index',
				__( 'Block index out of range.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		$count = max( 1, (int) $count );

		if ( ( $index + $count ) > count( $vis_to_raw ) ) {
			$count = count( $vis_to_raw ) - $index;
		}

		// Check for synced pattern references being deleted.
		$warnings = array();
		for ( $i = $index; $i < $index + $count; $i++ ) {
			$raw_idx = $vis_to_raw[ $i ];
			if ( isset( $all_blocks[ $raw_idx ] ) && 'core/block' === $all_blocks[ $raw_idx ]['blockName'] ) {
				$ref_id  = isset( $all_blocks[ $raw_idx ]['attrs']['ref'] ) ? $all_blocks[ $raw_idx ]['attrs']['ref'] : 0;
				$pattern = $ref_id ? get_post( $ref_id ) : null;

				$warnings[] = array(
					'message' => sprintf(
						/* translators: %s: pattern name */
						__( 'Removing synced pattern reference "%s" from this page. The pattern itself is not deleted.', 'gk-block-api' ),
						$pattern ? $pattern->post_title : '#' . $ref_id
					),
				);
			}
		}

		// Remove blocks from the FULL array (preserving whitespace blocks).
		// Map the visible index range to raw indices and remove them.
		$raw_indices_to_remove = array();
		for ( $i = $index; $i < $index + $count; $i++ ) {
			$raw_indices_to_remove[] = $vis_to_raw[ $i ];
		}
		// Remove in reverse order to preserve indices.
		foreach ( array_reverse( $raw_indices_to_remove ) as $rm_idx ) {
			array_splice( $all_blocks, $rm_idx, 1 );
		}

		// Serialize and save.
		$new_content = serialize_blocks( $all_blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		return array(
			'success'              => true,
			'deleted_index'        => $index,
			'deleted_count'        => $count,
			'warnings'             => $warnings,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}

	/**
	 * Atomically replace a range of top-level blocks with a different shape.
	 *
	 * Single-revision swap: one save_post_content call regardless of whether
	 * the new shape contains 0, 1, or N blocks. Use this when you want to
	 * swap a section's worth of blocks (e.g., 12 FAQ paragraph blocks → 1
	 * yoast/faq-block) without a delete + insert race that leaves the page
	 * half-written if the second op fails.
	 *
	 * Distinct from `replace_all_blocks`: scoped to a range of top-level
	 * blocks, not the entire post.
	 *
	 * @param int   $post_id Post ID.
	 * @param int   $start   Top-level counter of the first block to replace (0-based).
	 * @param int   $count   Number of consecutive top-level blocks to replace.
	 *                       Pass 0 to insert without removing (equivalent to insert_blocks).
	 * @param array $blocks  New block definitions to splice in. May be empty
	 *                       to delete the range without inserting anything.
	 *
	 * @return array|\WP_Error Result with revision_id, or WP_Error.
	 */
	public function replace_blocks_range( $post_id, $start, $count, $blocks ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$start = (int) $start;
		$count = max( 0, (int) $count );

		if ( $start < 0 ) {
			return new \WP_Error(
				'invalid_range',
				__( 'range.start must be >= 0.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		$all_existing_blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $all_existing_blocks ) ) {
			$all_existing_blocks = array();
		}

		// Map visible (top-level counter) → raw index, mirroring insert_blocks
		// and delete_blocks. Whitespace blocks are preserved at their raw indices.
		$visible_to_raw = array();
		foreach ( $all_existing_blocks as $raw_idx => $blk ) {
			if ( ! empty( $blk['blockName'] ) ) {
				$visible_to_raw[] = $raw_idx;
			}
		}
		$visible_count = count( $visible_to_raw );

		if ( $start > $visible_count ) {
			return new \WP_Error(
				'invalid_range',
				sprintf(
					/* translators: 1: start value, 2: maximum visible index */
					__( 'range.start (%1$d) is past the end of the page (max %2$d).', 'gk-block-api' ),
					$start,
					$visible_count
				),
				array( 'status' => 400 )
			);
		}

		// Clamp count to what's actually available.
		if ( ( $start + $count ) > $visible_count ) {
			$count = $visible_count - $start;
		}

		// Validate every replacement block BEFORE touching post_content. A
		// legacy block in the new shape must abort the whole op so the post
		// is never partially mutated.
		$warnings   = array();
		$new_blocks = array();
		foreach ( $blocks as $block_def ) {
			$built = $this->build_block_from_def( $block_def, $warnings );
			if ( is_wp_error( $built ) ) {
				return $built;
			}
			$new_blocks[] = $built;
		}

		// Stable refs for the new blocks (and any nested innerBlocks).
		$this->assign_missing_refs_recursive( $new_blocks );

		// Resolve the raw splice range. We splice at the raw index of the
		// first removed block (or end-of-array if start === visible_count),
		// removing `count` raw entries by walking visible_to_raw.
		if ( $start >= $visible_count ) {
			$raw_splice_start = count( $all_existing_blocks );
			$raw_splice_count = 0;
		} else {
			$raw_splice_start = $visible_to_raw[ $start ];
			if ( $count === 0 ) {
				$raw_splice_count = 0;
			} else {
				$last_raw_idx = ( $start + $count - 1 < $visible_count )
					? $visible_to_raw[ $start + $count - 1 ]
					: $visible_to_raw[ $visible_count - 1 ];
				$raw_splice_count = ( $last_raw_idx - $raw_splice_start ) + 1;
			}
		}

		// Detect synced pattern references being removed — same warning as delete_blocks.
		for ( $i = 0; $i < $raw_splice_count; $i++ ) {
			$raw_idx = $raw_splice_start + $i;
			if ( isset( $all_existing_blocks[ $raw_idx ] ) && 'core/block' === $all_existing_blocks[ $raw_idx ]['blockName'] ) {
				$ref_id  = isset( $all_existing_blocks[ $raw_idx ]['attrs']['ref'] ) ? $all_existing_blocks[ $raw_idx ]['attrs']['ref'] : 0;
				$pattern = $ref_id ? get_post( $ref_id ) : null;
				$warnings[] = array(
					'message' => sprintf(
						/* translators: %s: pattern name */
						__( 'Removing synced pattern reference "%s" from this page. The pattern itself is not deleted.', 'gk-block-api' ),
						$pattern ? $pattern->post_title : '#' . $ref_id
					),
				);
			}
		}

		// Atomic splice — one operation, one save, one revision.
		array_splice( $all_existing_blocks, $raw_splice_start, $raw_splice_count, $new_blocks );

		$new_content = serialize_blocks( $all_existing_blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		// Build inserted refs with the same shape insert_blocks returns
		// (so callers can chain mutate_block_tree without an extra fetch).
		$inserted = array();
		$saved_post = get_post( $post_id );
		if ( $saved_post ) {
			$parsed_after = parse_blocks( $saved_post->post_content );
			if ( ! is_array( $parsed_after ) ) {
				$parsed_after = array();
			}
			$post_visible_to_raw = array();
			foreach ( $parsed_after as $raw_idx => $blk ) {
				if ( ! empty( $blk['blockName'] ) ) {
					$post_visible_to_raw[] = $raw_idx;
				}
			}
			foreach ( $new_blocks as $i => $block ) {
				$visible_index = $start + $i;
				$raw_idx       = isset( $post_visible_to_raw[ $visible_index ] )
					? $post_visible_to_raw[ $visible_index ]
					: null;
				$entry = array(
					'index'             => $visible_index,
					'top_level_counter' => $visible_index,
					'path'              => null === $raw_idx ? array( $visible_index ) : array( $raw_idx ),
					'name'              => $block['blockName'],
				);
				if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
					$entry['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
				}
				$inserted[] = $entry;
			}
		}

		return array(
			'success'              => true,
			'removed'              => $count,
			'inserted'             => $inserted,
			'warnings'             => $warnings,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}

	/**
	 * Replace all blocks on a post (full page rewrite).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $blocks  Array of block definitions.
	 *
	 * @return array|\WP_Error Result with revision_id, or WP_Error.
	 */
	public function replace_all_blocks( $post_id, $blocks ) {
		$rate_check = $this->check_rate_limit( $post_id, 'put' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$warnings   = array();
		$new_blocks = array();

		foreach ( $blocks as $block_def ) {
			$name = isset( $block_def['name'] ) ? $block_def['name'] : '';

			// Validate block name and preference tier.
			$validation = $this->validate_block_def( $name );
			if ( $validation['error'] ) {
				return $validation['error'];
			}
			$warnings = array_merge( $warnings, $validation['warnings'] );

			$attrs      = isset( $block_def['attributes'] ) ? $block_def['attributes'] : array();
			$inner_html = isset( $block_def['innerHTML'] ) ? wp_kses_post( $block_def['innerHTML'] ) : '';

			$new_blocks[] = array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerHTML'    => $inner_html,
				'innerContent' => ! empty( $inner_html ) ? array( $inner_html ) : array(),
				'innerBlocks'  => array(),
			);
		}

		// Stable refs for the new block tree.
		$this->assign_missing_refs_recursive( $new_blocks );

		// Serialize and save.
		$new_content = serialize_blocks( $new_blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'put' );

		// Build response block list.
		$block_list = array();
		foreach ( $new_blocks as $i => $block ) {
			$entry = array(
				'index'      => $i,
				'name'       => $block['blockName'],
				'attributes' => $block['attrs'],
			);
			if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
				$entry['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
			}
			$block_list[] = $entry;
		}

		return array(
			'success'              => true,
			'blocks'               => $block_list,
			'warnings'             => $warnings,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}

	/**
	 * Insert a pattern at a position on a post.
	 *
	 * @param int        $post_id    Post ID.
	 * @param int|string $pattern_id Pattern post ID (synced) or registered pattern name.
	 * @param mixed      $position   Insert position.
	 * @param bool       $synced     If true, insert as core/block ref. If false, inline blocks.
	 *
	 * @return array|\WP_Error Insert result with revision_id, or WP_Error.
	 */
	public function insert_pattern( $post_id, $pattern_id, $position, $synced = true ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		$existing_blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $existing_blocks ) ) {
			$existing_blocks = array();
		}
		$pattern_name    = '';
		$pattern_content = '';

		// Resolve the pattern.
		if ( is_numeric( $pattern_id ) ) {
			$pattern_post = get_post( (int) $pattern_id );

			if ( ! $pattern_post || 'wp_block' !== $pattern_post->post_type ) {
				return new \WP_Error(
					'pattern_not_found',
					__( 'Synced pattern not found.', 'gk-block-api' ),
					array( 'status' => 404 )
				);
			}

			$pattern_name    = $pattern_post->post_title;
			$pattern_content = $pattern_post->post_content;
		} else {
			// Try as a registered pattern name.
			if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
				return new \WP_Error(
					'pattern_not_found',
					__( 'Pattern registry not available.', 'gk-block-api' ),
					array( 'status' => 404 )
				);
			}

			$registry = \WP_Block_Patterns_Registry::get_instance();

			if ( ! $registry->is_registered( $pattern_id ) ) {
				return new \WP_Error(
					'pattern_not_found',
					sprintf( __( 'Pattern "%s" not found in registry.', 'gk-block-api' ), $pattern_id ),
					array( 'status' => 404 )
				);
			}

			$pattern         = $registry->get_registered( $pattern_id );
			$pattern_name    = isset( $pattern['title'] ) ? $pattern['title'] : $pattern_id;
			$pattern_content = isset( $pattern['content'] ) ? $pattern['content'] : '';

			// Registered patterns cannot be synced (no post ID to reference).
			$synced = false;
		}

		// Build a map from filtered (visible) index to raw index in the full array.
		// This preserves whitespace blocks during serialization while letting
		// the API consumer use the same indices as format_blocks().
		$visible_to_raw = array();
		foreach ( $existing_blocks as $raw_idx => $blk ) {
			if ( ! empty( $blk['blockName'] ) ) {
				$visible_to_raw[] = $raw_idx;
			}
		}
		$visible_count = count( $visible_to_raw );

		// Determine insertion index (visible index).
		$visible_insert = $visible_count; // Default: append.

		if ( 'start' === $position ) {
			$visible_insert = 0;
		} elseif ( is_numeric( $position ) ) {
			$pos = (int) $position;
			if ( -1 === $pos ) {
				$visible_insert = $visible_count;
			} else {
				$visible_insert = min( $pos + 1, $visible_count );
			}
		}

		// Map visible index to raw array position (preserving whitespace blocks).
		if ( $visible_insert >= $visible_count ) {
			$insert_at = count( $existing_blocks );
		} elseif ( $visible_insert <= 0 ) {
			$insert_at = 0;
		} else {
			$insert_at = $visible_to_raw[ $visible_insert ];
		}

		if ( $synced && is_numeric( $pattern_id ) ) {
			// Insert as a synced reference (core/block).
			$ref_block = array(
				'blockName'    => 'core/block',
				'attrs'        => array( 'ref' => (int) $pattern_id ),
				'innerHTML'    => '',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			);

			array_splice( $existing_blocks, $insert_at, 0, array( $ref_block ) );

			$new_content = serialize_blocks( $existing_blocks );
			$result      = $this->save_post_content( $post_id, $new_content );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->record_rate_limit( $post_id, 'write' );

			return array(
				'success'              => true,
				'inserted'             => array(
					'index'        => $insert_at,
					'name'         => 'core/block',
					'attributes'   => array( 'ref' => (int) $pattern_id ),
					'pattern_name' => $pattern_name,
					'synced'       => true,
				),
				'before_revision_id'   => $result['before_revision_id'],
				'revision_id'          => $result['revision_id'],
			);
		}

		// Inline the pattern's blocks.
		if ( empty( $pattern_content ) ) {
			return new \WP_Error(
				'empty_pattern',
				__( 'Pattern has no block content.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		$pattern_blocks = parse_blocks( $pattern_content );
		if ( ! is_array( $pattern_blocks ) ) {
			$pattern_blocks = array();
		}

		// Filter out empty/whitespace-only blocks.
		$pattern_blocks = array_values( array_filter( $pattern_blocks, function ( $block ) {
			return ! empty( $block['blockName'] );
		} ) );

		if ( empty( $pattern_blocks ) ) {
			return new \WP_Error(
				'empty_pattern',
				__( 'Pattern contains no valid blocks.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		array_splice( $existing_blocks, $insert_at, 0, $pattern_blocks );

		$new_content = serialize_blocks( $existing_blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		$inserted = array();
		foreach ( $pattern_blocks as $i => $block ) {
			$inserted[] = array(
				'index' => $insert_at + $i,
				'name'  => $block['blockName'],
			);
		}

		return array(
			'success'              => true,
			'inserted'             => $inserted,
			'pattern_name'         => $pattern_name,
			'synced'               => false,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}

	/**
	 * Get the most recent revision ID for a post, or 0 if there are none yet.
	 *
	 * Used as the optimistic-concurrency token: GETs surface it as an ETag,
	 * writes can require it via `If-Match` to detect concurrent edits.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Revision post ID (0 = no revisions yet).
	 */
	public function get_latest_revision_id( $post_id ) {
		$revisions = wp_get_post_revisions( $post_id, array(
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		return is_array( $revisions ) && ! empty( $revisions ) ? (int) key( $revisions ) : 0;
	}

	/**
	 * Optimistic-concurrency check for write endpoints.
	 *
	 * If the caller supplied an `If-Match` header (or an explicit
	 * `expected_revision` body field), this verifies the post's current
	 * revision still matches. A mismatch means another writer raced ahead;
	 * we return a 412 Precondition Failed with the current revision so
	 * the caller can refresh and retry.
	 *
	 * Absent header → skip check (preserves current behavior; opt-in).
	 *
	 * @param int    $post_id           Post being written.
	 * @param string $expected_revision Raw header value, e.g. `W/"123"` or `123`.
	 *                                  Empty string skips the check.
	 *
	 * @return null|\WP_Error null = proceed; WP_Error = 412 with current_revision.
	 */
	public function check_if_match( $post_id, $expected_revision ) {
		if ( ! is_string( $expected_revision ) || '' === $expected_revision ) {
			return null;
		}

		// Accept "123", "W/\"123\"", or "\"123\"" — strip RFC 7232 ETag wrappers.
		$normalized = trim( $expected_revision );
		if ( 0 === strpos( $normalized, 'W/' ) ) {
			$normalized = trim( substr( $normalized, 2 ) );
		}
		$normalized = trim( $normalized, '"' );

		if ( ! preg_match( '/^[0-9]+$/', $normalized ) ) {
			return new \WP_Error(
				'invalid_if_match',
				__( 'If-Match must be a revision ID, optionally wrapped in W/"...".', 'gk-block-api' ),
				array( 'status' => 400 )
			);
		}

		$expected = (int) $normalized;
		$current  = $this->get_latest_revision_id( $post_id );

		if ( $expected !== $current ) {
			return new \WP_Error(
				'stale_revision',
				__( 'The post has changed since you fetched it. Re-fetch with get_page_blocks and retry.', 'gk-block-api' ),
				array(
					'status'           => 412,
					'expected_revision' => $expected,
					'current_revision'  => $current,
				)
			);
		}

		return null;
	}

	/**
	 * Save new post content with before/after revision tracking.
	 *
	 * Relies on wp_update_post() to create revisions automatically, avoiding
	 * duplicate revisions from explicit wp_save_post_revision() calls.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $new_content New post_content.
	 *
	 * @return array|\WP_Error Array with before_revision_id and revision_id on success, or WP_Error.
	 */
	public function save_post_content( $post_id, $new_content ) {
		// Block content is encoded by serialize_blocks() / wp_json_encode(), which
		// correctly escapes newlines as \n in block comment JSON. Some
		// content_save_pre filters (notably WPCom_Markdown::preserve_code_blocks)
		// strip those backslashes, corrupting \n → n. Stash and remove all
		// content_save_pre callbacks for the duration of the save; block content
		// needs no further content processing after serialize_blocks().
		global $wp_filter;
		$saved_content_save_pre = isset( $wp_filter['content_save_pre'] ) ? $wp_filter['content_save_pre'] : null;
		if ( $saved_content_save_pre ) {
			remove_all_filters( 'content_save_pre' );
		}

		// Get the current latest revision as the "before" snapshot.
		$before_revision_id = $this->get_latest_revision_id( $post_id );

		// wp_update_post() runs wp_unslash() on post_content (it expects $_POST-shaped
		// input). serialize_blocks() output is unslashed JSON, so without wp_slash()
		// every \n, ", and the -- that serialize_block_attributes()
		// uses to escape `--` would be stripped of their leading backslash.
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $new_content ),
			),
			true
		);

		// Restore content_save_pre so subsequent saves in the same request are unaffected.
		if ( $saved_content_save_pre ) {
			$wp_filter['content_save_pre'] = $saved_content_save_pre;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Get the new latest revision as the "after" snapshot.
		$after_revision_id = $this->get_latest_revision_id( $post_id );

		return array(
			'before_revision_id' => (int) $before_revision_id,
			'revision_id'        => (int) $after_revision_id,
		);
	}

	/**
	 * Stable identity prefix for block refs stored in attrs.metadata.gk_ref.
	 *
	 * Refs let agents address a block without keeping path/index fresh between
	 * mutations — sibling shifts don't invalidate them.
	 *
	 * @var string
	 */
	const REF_PREFIX = 'blk_';

	/**
	 * Generate a fresh block ref.
	 *
	 * Uses wp_hash (HMAC keyed on the site secret) over a unique seed instead
	 * of the WP password helper, because that helper runs through the
	 * `random_password` filter which third-party plugins can override and
	 * could yield non-unique or filtered output. wp_hash is unfiltered and
	 * deterministic given an input, so feeding it a guaranteed-unique input
	 * (uniqid + wp_rand + microtime) produces a stable, collision-resistant ref.
	 *
	 * @return string A new ref like "blk_a3f2c1q9".
	 */
	public static function generate_ref() {
		$seed = uniqid( 'gk_block_ref_', true ) . '|' . wp_rand() . '|' . microtime( true );
		// Use the 'auth' scheme (stable, long-lived). The 'nonce' scheme is
		// intended for time-bounded values that rotate; refs persist with the post.
		return self::REF_PREFIX . substr( wp_hash( $seed, 'auth' ), 0, 8 );
	}

	/**
	 * Resolve a block ref to its current path within a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ref     Ref string (e.g., "blk_a3f2c1q9").
	 *
	 * @return int[]|\WP_Error Path array or WP_Error('ref_stale').
	 */
	public function resolve_ref( $post_id, $ref ) {
		if ( ! is_string( $ref ) || '' === $ref ) {
			return new \WP_Error( 'invalid_ref', __( 'Ref must be a non-empty string.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-api' ), array( 'status' => 404 ) );
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		$path = $this->find_ref_in_blocks( $blocks, $ref, array() );
		if ( null === $path ) {
			return new \WP_Error(
				'ref_stale',
				sprintf(
					/* translators: %s: ref string */
					__( 'Ref "%s" not found. The block may have been deleted, or the ref is from an older snapshot. Re-fetch the page to get current refs.', 'gk-block-api' ),
					$ref
				),
				array( 'status' => 404, 'ref' => $ref )
			);
		}

		return $path;
	}

	/**
	 * Resolve a block ref to its flat index (the addressing scheme used by
	 * update_block / delete_blocks). Skips empty/whitespace-only blocks the
	 * same way flatten_blocks does.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ref     Ref string.
	 *
	 * @return int|\WP_Error Flat index or WP_Error('ref_stale').
	 */
	public function resolve_ref_to_index( $post_id, $ref ) {
		if ( ! is_string( $ref ) || '' === $ref ) {
			return new \WP_Error( 'invalid_ref', __( 'Ref must be a non-empty string.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-api' ), array( 'status' => 404 ) );
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		$flat  = $this->flatten_blocks( $blocks );
		$count = count( $flat );
		for ( $i = 0; $i < $count; $i++ ) {
			$entry = $flat[ $i ];
			if ( isset( $entry['block']['attrs']['metadata']['gk_ref'] ) && $entry['block']['attrs']['metadata']['gk_ref'] === $ref ) {
				return $i;
			}
		}

		return new \WP_Error(
			'ref_stale',
			sprintf(
				/* translators: %s: ref string */
				__( 'Ref "%s" not found.', 'gk-block-api' ),
				$ref
			),
			array( 'status' => 404, 'ref' => $ref )
		);
	}

	/**
	 * Resolve a top-level ref to its top-level counter (for insert_blocks
	 * before/after positioning, which uses the top-level counter scheme).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ref     Ref string.
	 *
	 * @return int|\WP_Error Top-level position or WP_Error.
	 */
	public function resolve_ref_to_top_level( $post_id, $ref ) {
		$path = $this->resolve_ref( $post_id, $ref );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( count( $path ) !== 1 ) {
			return new \WP_Error(
				'ref_not_top_level',
				__( 'Ref refers to a nested block; insert_blocks before/after_ref requires a top-level block.', 'gk-block-api' ),
				array( 'status' => 400, 'ref' => $ref, 'path' => $path )
			);
		}
		// Top-level counter equals raw index for non-empty top-level blocks; need to map.
		// resolve_ref already verified the post exists, but a concurrent delete
		// between the two fetches would otherwise fatal here. Guard explicitly.
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-api' ), array( 'status' => 404 ) );
		}
		$blocks  = parse_blocks( $post->post_content );
		$count   = count( $blocks );
		$counter = 0;
		for ( $i = 0; $i < $count; $i++ ) {
			$block = $blocks[ $i ];
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			if ( $i === $path[0] ) {
				return $counter;
			}
			$counter++;
		}
		return new \WP_Error( 'ref_stale', __( 'Ref position could not be resolved.', 'gk-block-api' ), array( 'status' => 404 ) );
	}

	/**
	 * Recursive walker — returns the path of the first block whose
	 * attrs.metadata.gk_ref matches $ref, or null if not found.
	 *
	 * @param array  $blocks       Parsed blocks (may be nested).
	 * @param string $ref          Ref to find.
	 * @param int[]  $current_path Path accumulated so far.
	 *
	 * @return int[]|null Path or null if not found.
	 */
	private function find_ref_in_blocks( $blocks, $ref, $current_path ) {
		$count = count( $blocks );
		for ( $i = 0; $i < $count; $i++ ) {
			$block = $blocks[ $i ];
			if ( ! is_array( $block ) ) {
				continue;
			}
			$path = array_merge( $current_path, array( (int) $i ) );
			if ( isset( $block['attrs']['metadata']['gk_ref'] ) && $block['attrs']['metadata']['gk_ref'] === $ref ) {
				return $path;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = $this->find_ref_in_blocks( $block['innerBlocks'], $ref, $path );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * Walk a block tree and assign a fresh gk_ref to any block that doesn't
	 * have one. Returns true if any assignments were made.
	 *
	 * @param array $blocks Blocks (passed by reference).
	 *
	 * @return bool True if any block got a new ref.
	 */
	public function assign_missing_refs_recursive( &$blocks ) {
		$dirty = false;
		$count = count( $blocks );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $blocks[ $i ] ) || empty( $blocks[ $i ]['blockName'] ) ) {
				continue;
			}
			if ( ! isset( $blocks[ $i ]['attrs'] ) || ! is_array( $blocks[ $i ]['attrs'] ) ) {
				$blocks[ $i ]['attrs'] = array();
			}
			if ( ! isset( $blocks[ $i ]['attrs']['metadata'] ) || ! is_array( $blocks[ $i ]['attrs']['metadata'] ) ) {
				$blocks[ $i ]['attrs']['metadata'] = array();
			}
			if ( empty( $blocks[ $i ]['attrs']['metadata']['gk_ref'] ) ) {
				$blocks[ $i ]['attrs']['metadata']['gk_ref'] = self::generate_ref();
				$dirty = true;
			}
			if ( ! empty( $blocks[ $i ]['innerBlocks'] ) && is_array( $blocks[ $i ]['innerBlocks'] ) ) {
				if ( $this->assign_missing_refs_recursive( $blocks[ $i ]['innerBlocks'] ) ) {
					$dirty = true;
				}
			}
		}
		return $dirty;
	}

	/**
	 * Walk a block tree and overwrite gk_ref on every block. Used by `duplicate`
	 * so the clone doesn't share an identity with the source.
	 *
	 * @param array $blocks Blocks (passed by reference).
	 *
	 * @return void
	 */
	public function assign_fresh_refs_recursive( &$blocks ) {
		$count = count( $blocks );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! is_array( $blocks[ $i ] ) || empty( $blocks[ $i ]['blockName'] ) ) {
				continue;
			}
			if ( ! isset( $blocks[ $i ]['attrs'] ) || ! is_array( $blocks[ $i ]['attrs'] ) ) {
				$blocks[ $i ]['attrs'] = array();
			}
			if ( ! isset( $blocks[ $i ]['attrs']['metadata'] ) || ! is_array( $blocks[ $i ]['attrs']['metadata'] ) ) {
				$blocks[ $i ]['attrs']['metadata'] = array();
			}
			$blocks[ $i ]['attrs']['metadata']['gk_ref'] = self::generate_ref();
			if ( ! empty( $blocks[ $i ]['innerBlocks'] ) && is_array( $blocks[ $i ]['innerBlocks'] ) ) {
				$this->assign_fresh_refs_recursive( $blocks[ $i ]['innerBlocks'] );
			}
		}
	}

	/**
	 * Persist ref assignments to post_content directly via $wpdb. Bypasses
	 * wp_update_post to avoid creating revisions for what is effectively
	 * metadata bookkeeping (gk_ref is editor-only — see Block_Safety).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $blocks  Block tree with refs assigned.
	 *
	 * @return bool True on success.
	 */
	public function persist_ref_assignments( $post_id, $blocks ) {
		global $wpdb;
		$content = serialize_blocks( $blocks );
		$result  = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->posts,
			array( 'post_content' => $content ),
			array( 'ID' => (int) $post_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( (int) $post_id );

		/**
		 * Fires after gk_ref UUIDs have been persisted to a post via direct DB
		 * write (no revision created, no save_post hook). Use this to invalidate
		 * any secondary caches keyed on post_content (search indexes, CDN edge
		 * caches, page-builder CSS). Skipped when the underlying $wpdb->update
		 * fails.
		 *
		 * @param int $post_id Post that received fresh refs.
		 */
		if ( false !== $result ) {
			do_action( 'gk_block_api_refs_persisted', (int) $post_id );
		}

		return false !== $result;
	}

	/**
	 * Format parsed blocks into a structured response array.
	 *
	 * Includes both `index` (flat sequential counter for backwards compatibility)
	 * and `path` (array of raw indices for the mutation tool).
	 *
	 * @param array $blocks Parsed blocks from parse_blocks().
	 *
	 * @return array Formatted block data.
	 */
	public function format_blocks( $blocks, $render = false ) {
		$counter           = 0;
		$top_level_counter = 0;
		return $this->format_blocks_recursive( $blocks, array(), $counter, $top_level_counter, $render );
	}

	/**
	 * Whether a block name is dual-storage. Thin delegate to Block_Inventory
	 * so callers (Block_Mutator etc.) have one entry point on the CRUD layer.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public function is_block_dual_storage( $block_name ) {
		return $this->inventory->is_block_dual_storage( $block_name );
	}

	/**
	 * Build the BLOCK-14 dual-storage rejection error.
	 *
	 * @param string $block_name The dual-storage block being mutated.
	 * @return \WP_Error
	 */
	public function dual_storage_error( $block_name ) {
		return new \WP_Error(
			'dual_storage_requires_both',
			sprintf(
				/* translators: %s: block name (e.g., yoast/faq-block) */
				__( 'Block "%s" is dual-storage: both `attributes` and `innerHTML` carry the same data and must be kept in sync. Sending only `innerHTML` will silently desync the structured attributes (the canonical case is yoast/faq-block losing its questions[] array). Pass both fields together. See block-mcp://agent-guide for the dual-storage list.', 'gk-block-api' ),
				$block_name
			),
			array(
				'status'          => 400,
				'block'           => $block_name,
				'storage_mode'    => Block_Inventory::STORAGE_MODE_DUAL,
				'policy_resource' => 'block-mcp://agent-guide',
			)
		);
	}


	/**
	 * Recursively format blocks with path tracking.
	 *
	 * @param array $blocks             Parsed blocks.
	 * @param array $parent_path        Path to the parent container.
	 * @param int   &$counter           Flat sequential counter (by reference).
	 * @param int   &$top_level_counter Sequential counter among top-level blocks only (by reference).
	 *                                  Only incremented when $parent_path is empty.
	 * @param bool  $render             Whether to include rendered output for dynamic blocks.
	 *
	 * @return array Formatted block data.
	 */
	private function format_blocks_recursive( $blocks, $parent_path, &$counter, &$top_level_counter, $render = false ) {
		$formatted = array();

		foreach ( $blocks as $raw_index => $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = array_merge( $parent_path, array( (int) $raw_index ) );

			$data = array(
				'index'      => $counter,
				'path'       => $current_path,
				'name'       => $block['blockName'],
				'attributes' => isset( $block['attrs'] ) ? $block['attrs'] : array(),
			);

			// Surface the stable ref (from attrs.metadata.gk_ref). Refs let agents
			// re-address the same block after sibling shifts without re-fetching.
			if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
				$data['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
			}

			// Top-level counter: sequential position among non-empty top-level blocks only.
			// This is the value consumed by `delete_block.block_index`,
			// `insert_blocks.before`/`after`, and the new atomic `replace_blocks` op.
			// Only set on depth-0 blocks; inner blocks intentionally omit it.
			if ( empty( $parent_path ) ) {
				$data['top_level_counter'] = $top_level_counter;
				$top_level_counter++;
			}

			// Surface section name from block metadata for easy scanning.
			if ( isset( $block['attrs']['metadata']['name'] ) && ! empty( $block['attrs']['metadata']['name'] ) ) {
				$data['section'] = $block['attrs']['metadata']['name'];
			}

			// Resolve synced pattern reference names and optionally their content.
			if ( 'core/block' === $block['blockName'] && isset( $block['attrs']['ref'] ) ) {
				$ref_post = get_post( (int) $block['attrs']['ref'] );
				if ( $ref_post ) {
					$pattern_data = array(
						'id'   => (int) $block['attrs']['ref'],
						'name' => $ref_post->post_title,
					);

					// Optionally include the pattern's parsed block tree.
					if ( $render && ! empty( $ref_post->post_content ) ) {
						$pattern_blocks = parse_blocks( $ref_post->post_content );
						if ( is_array( $pattern_blocks ) ) {
							$pattern_counter           = 0;
							$pattern_top_level_counter = 0;
							$pattern_data['blocks']    = $this->format_blocks_recursive(
								$pattern_blocks,
								array(),
								$pattern_counter,
								$pattern_top_level_counter,
								true
							);
						}
					}

					$data['pattern_ref'] = $pattern_data;
				}
			}

			// Mark blocks as dynamic or static (cached per block name).
			static $dynamic_cache = array();

			if ( ! isset( $dynamic_cache[ $block['blockName'] ] ) ) {
				$registry   = \WP_Block_Type_Registry::get_instance();
				$block_type = $registry ? $registry->get_registered( $block['blockName'] ) : null;
				$dynamic_cache[ $block['blockName'] ] = $block_type ? $block_type->is_dynamic() : false;
			}
			$is_dynamic = $dynamic_cache[ $block['blockName'] ];
			$data['dynamic'] = $is_dynamic;

			// storage_mode disambiguates the existing `dynamic` flag for AI consumers:
			//   - "static": innerHTML is the source of truth (most core/* blocks)
			//   - "dynamic": attributes is the source of truth; innerHTML is regenerated on render
			//   - "dual": both attributes AND innerHTML carry the same data and must be kept in sync
			//             (e.g., yoast/faq-block — sending innerHTML alone corrupts attributes.questions)
			$data['storage_mode'] = $this->inventory->resolve_storage_mode( $block['blockName'], $is_dynamic );

			// Preference tier from the (admin-editable, filter-extensible) Preferences
			// config. Replaces hardcoded namespace lists in client-side enrichment.
			// Only attach for non-preferred tiers — preferred is the default and adding
			// the field on every block bloats the response.
			$pref = $this->preferences->get_block_score( $block['blockName'] );
			if ( isset( $pref['tier'] ) && 'preferred' !== $pref['tier'] ) {
				$data['preference'] = array(
					'tier' => $pref['tier'],
				);
				$replacement = $this->preferences->get_replacement( $block['blockName'] );
				if ( $replacement ) {
					$data['preference']['suggested_replacement'] = $replacement;
				}
			}

			$counter++;

			if ( ! empty( $block['innerHTML'] ) ) {
				$html = $block['innerHTML'];

				// Expand shortcodes in rendered mode.
				if ( $render && false !== strpos( $html, '[' ) && preg_match( '/\[[\w-]+/', $html ) ) {
					$data['innerHTML_rendered'] = do_shortcode( $html );
				}

				$data['innerHTML'] = $html;

				// Add text_preview: stripped, decoded, truncated content for quick scanning.
				// Lets AI agents identify blocks without regex parsing innerHTML.
				$preview = wp_strip_all_tags( $html );
				$preview = html_entity_decode( $preview, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$preview = preg_replace( '/\s+/', ' ', trim( $preview ) );
				if ( ! empty( $preview ) ) {
					$data['text_preview'] = mb_substr( $preview, 0, 100 );
				}
			}

			// For dynamic blocks in render mode, include the server-rendered output.
			if ( $render && $is_dynamic ) {
				try {
					$rendered = render_block( $block );
					if ( ! empty( $rendered ) ) {
						// Strip to plain text for a concise summary, keep HTML in rendered_html.
						$data['rendered_html'] = $rendered;
						$text = wp_strip_all_tags( $rendered );
						$text = preg_replace( '/\s+/', ' ', trim( $text ) );
						if ( ! empty( $text ) ) {
							$data['rendered_text'] = mb_substr( $text, 0, 500 );
						}
					}
				} catch ( \Throwable $e ) {
					// Render failed — skip silently.
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
						error_log( 'GK Block API render_block error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$data['innerBlocks'] = $this->format_blocks_recursive(
					$block['innerBlocks'],
					$current_path,
					$counter,
					$render
				);
			}

			/**
			 * Filter a formatted block before it is included in the response.
			 *
			 * Use this to strip computed/derived fields (e.g. codeHTML, innerHTML)
			 * from specific block types so agents never see large noise payloads.
			 *
			 * @param array  $data       Formatted block data.
			 * @param string $block_name Fully-qualified block type name.
			 */
			$data = apply_filters( 'gk_block_api_format_block', $data, $block['blockName'] );

			$formatted[] = $data;
		}

		return $formatted;
	}

	/**
	 * Recursively builds a WP block array from an API block definition.
	 * Validates block names and collects preference warnings at every depth.
	 *
	 * @param array $block_def  Input definition (name, attributes, innerHTML, innerBlocks).
	 * @param array &$warnings  Accumulated warnings (modified in place).
	 * @return array|\WP_Error  Built block array ready for serialize_blocks(), or WP_Error.
	 */
	public function build_block_from_def( array $block_def, array &$warnings ) {
		$name = isset( $block_def['name'] ) ? $block_def['name'] : '';

		$validation = $this->validate_block_def( $name );
		if ( $validation['error'] ) {
			return $validation['error'];
		}
		$warnings = array_merge( $warnings, $validation['warnings'] );

		$attrs      = isset( $block_def['attributes'] ) ? $block_def['attributes'] : array();
		$inner_html = isset( $block_def['innerHTML'] ) ? wp_kses_post( $block_def['innerHTML'] ) : '';
		$children   = array();

		if ( ! empty( $block_def['innerBlocks'] ) && is_array( $block_def['innerBlocks'] ) ) {
			foreach ( $block_def['innerBlocks'] as $child_def ) {
				$child = $this->build_block_from_def( $child_def, $warnings );
				if ( is_wp_error( $child ) ) {
					return $child;
				}
				$children[] = $child;
			}
		}

		if ( ! empty( $children ) ) {
			$n = count( $children );
			if ( ! empty( $inner_html ) ) {
				// Split wrapper HTML into opening/closing halves and interleave nulls.
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
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerHTML'    => '',
				'innerContent' => $inner_content,
				'innerBlocks'  => $children,
			);
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => $inner_html,
			'innerContent' => ! empty( $inner_html ) ? array( $inner_html ) : array(),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Validate a block name against the registry and preference tiers.
	 *
	 * Returns an array with 'error' (WP_Error or null) and 'warnings' (array).
	 * Legacy blocks produce a hard error; avoid blocks produce a warning.
	 *
	 * @param string $block_name Block type name.
	 *
	 * @return array { error: \WP_Error|null, warnings: array }
	 */
	public function validate_block_def( $block_name ) {
		$result = array( 'error' => null, 'warnings' => array() );

		if ( empty( $block_name ) ) {
			return $result;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( $registry && ! $registry->is_registered( $block_name ) ) {
			$result['error'] = new \WP_Error(
				'invalid_block',
				sprintf( __( 'Block type "%s" is not registered.', 'gk-block-api' ), $block_name ),
				array( 'status' => 400 )
			);
			return $result;
		}

		$pref = $this->preferences->get_block_score( $block_name );

		if ( 'legacy' === $pref['tier'] ) {
			$replacement   = $this->preferences->get_replacement( $block_name );
			$namespace     = $this->preferences->extract_namespace( $block_name );
			$message_parts = array(
				sprintf(
					/* translators: 1: legacy block name, 2: suggested replacement block name */
					__( 'Block "%1$s" is legacy. Use "%2$s" instead.', 'gk-block-api' ),
					$block_name,
					$replacement ? $replacement : __( 'a preferred block', 'gk-block-api' )
				),
				sprintf(
					/* translators: %s: rejected namespace */
					__( 'The %s/ namespace is blocked by site policy.', 'gk-block-api' ),
					$namespace
				),
				__( 'See the block-mcp://agent-guide resource for the full allow/deny list.', 'gk-block-api' ),
			);
			$result['error'] = new \WP_Error(
				'legacy_block',
				implode( ' ', $message_parts ),
				array(
					'status'                => 400,
					'block'                 => $block_name,
					'namespace'             => $namespace,
					'suggested_replacement' => $replacement,
					'policy_resource'       => 'block-mcp://agent-guide',
				)
			);
			return $result;
		}

		if ( 'avoid' === $pref['tier'] ) {
			$replacement = $this->preferences->get_replacement( $block_name );
			$result['warnings'][] = array(
				'block'                 => $block_name,
				'message'               => sprintf(
					__( '%s blocks are deprecated on this site.', 'gk-block-api' ),
					$this->preferences->extract_namespace( $block_name ) . '/'
				),
				'suggested_replacement' => $replacement,
				'policy_resource'       => 'block-mcp://agent-guide',
			);
		}

		return $result;
	}

	/**
	 * Flatten a nested block structure into a flat array with path references.
	 *
	 * Each entry contains the block data and a 'path' array indicating how to
	 * traverse the nested structure to reach it.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param array  $path   Current path (for recursion).
	 *
	 * @return array Flat list of { block, path }.
	 */
	private function flatten_blocks( $blocks, $path = array() ) {
		$flat = array();

		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			$current_path = array_merge( $path, array( $i ) );
			$flat[]       = array(
				'block' => $block,
				'path'  => $current_path,
			);

			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, $this->flatten_blocks( $block['innerBlocks'], $current_path ) );
			}
		}

		return $flat;
	}

	/**
	 * Get a reference to a block within a nested structure by path.
	 *
	 * @param array &$blocks Parsed blocks (passed by reference).
	 * @param array  $path   Path array from flatten_blocks().
	 *
	 * @return array Reference to the block array.
	 */
	private function &get_block_by_path( &$blocks, $path ) {
		$ref  = &$blocks;
		$last = count( $path ) - 1;
		foreach ( $path as $depth => $segment ) {
			if ( $depth < $last ) {
				$ref = &$ref[ $segment ]['innerBlocks'];
			} else {
				$ref = &$ref[ $segment ];
			}
		}
		return $ref;
	}

	/**
	 * Check rate limits for a post.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Rate type: 'write' or 'put'.
	 *
	 * @return true|\WP_Error True if within limits, WP_Error if exceeded.
	 */
	public function check_rate_limit( $post_id, $type = 'write' ) {
		$transient_key = 'gk_block_api_rate_' . $post_id;
		$data          = get_transient( $transient_key );

		if ( false === $data ) {
			return true;
		}

		$now          = time();
		$window_start = $now - 60;

		// Clean old entries.
		if ( isset( $data['writes'] ) ) {
			$data['writes'] = array_filter( $data['writes'], function ( $ts ) use ( $window_start ) {
				return $ts >= $window_start;
			} );
		}

		if ( isset( $data['puts'] ) ) {
			$data['puts'] = array_filter( $data['puts'], function ( $ts ) use ( $window_start ) {
				return $ts >= $window_start;
			} );
		}

		if ( 'put' === $type ) {
			$put_count = isset( $data['puts'] ) ? count( $data['puts'] ) : 0;
			if ( $put_count >= self::RATE_LIMIT_PUT ) {
				return new \WP_Error(
					'rate_limit_exceeded',
					sprintf(
						/* translators: %d: maximum number of full page rewrites per minute */
						__( 'Full page rewrite rate limit exceeded. Max %d per minute per post.', 'gk-block-api' ),
						self::RATE_LIMIT_PUT
					),
					array( 'status' => 429 )
				);
			}
		}

		$write_count = isset( $data['writes'] ) ? count( $data['writes'] ) : 0;
		if ( $write_count >= self::RATE_LIMIT_WRITES ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum number of writes per minute */
					__( 'Write rate limit exceeded. Max %d per minute per post.', 'gk-block-api' ),
					self::RATE_LIMIT_WRITES
				),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Record a write operation for rate limiting.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Rate type: 'write' or 'put'.
	 */
	public function record_rate_limit( $post_id, $type = 'write' ) {
		$transient_key = 'gk_block_api_rate_' . $post_id;
		$data          = get_transient( $transient_key );

		if ( false === $data ) {
			$data = array(
				'writes' => array(),
				'puts'   => array(),
			);
		}

		$now = time();

		$data['writes'][] = $now;

		if ( 'put' === $type ) {
			$data['puts'][] = $now;
		}

		// Store with 2-minute TTL (covers the 1-minute rolling window).
		set_transient( $transient_key, $data, 120 );
	}

	/**
	 * Revert a post to a specific revision.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $revision_id Revision ID to restore.
	 *
	 * @return array|\WP_Error Result with new revision ID.
	 */
	public function revert_to_revision( $post_id, $revision_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-api' ), array( 'status' => 404 ) );
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new \WP_Error( 'revision_not_found', __( 'Revision not found.', 'gk-block-api' ), array( 'status' => 404 ) );
		}

		// Verify the revision belongs to this post.
		if ( (int) $revision->post_parent !== (int) $post_id ) {
			return new \WP_Error( 'revision_mismatch', __( 'Revision does not belong to this post.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		$result = $this->save_post_content( $post_id, $revision->post_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success'              => true,
			'restored_revision_id' => $revision_id,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}
}
