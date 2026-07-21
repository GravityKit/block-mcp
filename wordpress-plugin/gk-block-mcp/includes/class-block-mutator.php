<?php
/**
 * Path-based block tree mutation engine.
 *
 * Handles all 9 mutation operations: update-attrs, update-html, replace-block,
 * remove-block, wrap-in-group, unwrap-group, insert-child, duplicate, move.
 *
 * Supports dry_run mode for validation without saving.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Mutator
 *
 * Extracted from Block_CRUD to isolate the path-based mutation logic.
 */
class Block_Mutator {

	/**
	 * Block CRUD instance (for save, rate limit, validation).
	 *
	 * @var Block_CRUD
	 */
	private $crud;

	/**
	 * Preferences instance.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Constructor.
	 *
	 * @param Block_CRUD  $crud        Block CRUD instance.
	 * @param Preferences $preferences Preferences instance.
	 */
	public function __construct( Block_CRUD $crud, Preferences $preferences ) {
		$this->crud        = $crud;
		$this->preferences = $preferences;
	}

	/**
	 * Perform a path-based mutation on the block tree.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $op      Operation name.
	 * @param array  $path    Integer array path to target block.
	 * @param array  $params  Operation-specific parameters.
	 * @param bool   $dry_run If true, validate and simulate without saving.
	 *
	 * @return array|\WP_Error Mutation result with revision IDs, or WP_Error.
	 */
	public function mutate( $post_id, $op, $path, $params = array(), $dry_run = false ) {
		if ( ! $dry_run ) {
			$rate_check = $this->crud->check_rate_limit( $post_id, 'write' );
			if ( is_wp_error( $rate_check ) ) {
				return $rate_check;
			}
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		// Validate path format.
		foreach ( $path as $segment ) {
			if ( ! is_int( $segment ) || $segment < 0 ) {
				return new \WP_Error( 'invalid_path', __( 'Path must be an array of non-negative integers.', 'gk-block-mcp' ), array( 'status' => 400 ) );
			}
		}

		if ( empty( $path ) ) {
			return new \WP_Error( 'invalid_path', __( 'Path cannot be empty.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		// Navigate to the parent array within $blocks by reference.
		$parent   = &$blocks;
		$path_len = count( $path );
		for ( $i = 0; $i < $path_len - 1; $i++ ) {
			$segment = $path[ $i ];

			if ( ! isset( $parent[ $segment ] ) ) {
				$valid_range  = count( $parent ) > 0 ? '[0..' . ( count( $parent ) - 1 ) . ']' : '(empty)';
				$partial_path = array_slice( $path, 0, $i );
				return new \WP_Error(
					'invalid_path',
					sprintf(
						/* translators: 1: segment index in path, 2: requested index, 3: valid range, 4: partial path */
						__( 'Path segment %1$d (index %2$d) is out of bounds. Valid indices at this level: %3$s. Partial path up to failure: [%4$s]. Run GET /posts/{id}/blocks?outline=true to see the page structure.', 'gk-block-mcp' ),
						$i,
						$segment,
						$valid_range,
						implode( ', ', $partial_path )
					),
					array(
						'status'       => 400,
						'valid_range'  => $valid_range,
						'partial_path' => $partial_path,
					)
				);
			}

			if ( empty( $parent[ $segment ]['innerBlocks'] ) ) {
				$block_name   = isset( $parent[ $segment ]['blockName'] ) ? $parent[ $segment ]['blockName'] : 'unknown';
				$partial_path = array_slice( $path, 0, $i + 1 );
				return new \WP_Error(
					'invalid_path',
					sprintf(
						/* translators: 1: block name, 2: partial path */
						__( 'Block "%1$s" at [%2$s] has no inner blocks. Cannot traverse into it. Use update-attrs/update-html on this block directly, or wrap-in-group to add children.', 'gk-block-mcp' ),
						$block_name,
						implode( ', ', $partial_path )
					),
					array(
						'status'       => 400,
						'block_name'   => $block_name,
						'partial_path' => $partial_path,
					)
				);
			}

			$parent = &$parent[ $segment ]['innerBlocks'];

			if ( ! is_array( $parent ) ) {
				return new \WP_Error( 'invalid_path', __( 'Path traversal encountered invalid block structure.', 'gk-block-mcp' ), array( 'status' => 400 ) );
			}
		}

		$target_index = end( $path );

		if ( ! isset( $parent[ $target_index ] ) ) {
			$valid_range = count( $parent ) > 0 ? '[0..' . ( count( $parent ) - 1 ) . ']' : '(empty)';
			return new \WP_Error(
				'invalid_path',
				sprintf(
					/* translators: 1: requested target index, 2: valid range */
					__( 'Target block at index %1$d not found. Valid indices at this level: %2$s. Run GET /posts/{id}/blocks?outline=true to see the page structure.', 'gk-block-mcp' ),
					$target_index,
					$valid_range
				),
				array(
					'status'      => 400,
					'valid_range' => $valid_range,
				)
			);
		}

		$warnings     = array();
		$result_block = null;

		switch ( $op ) {

			case 'update-attrs':
				$attributes = isset( $params['attributes'] ) ? $params['attributes'] : null;
				if ( empty( $attributes ) || ! is_array( $attributes ) ) {
					return new \WP_Error( 'missing_attributes', __( 'update-attrs requires an "attributes" object.', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				// Block Bindings write-guard, uniform with the per-block update_block
				// path so an agent can't bypass it via edit_block_tree. Opt out with
				// allow_bound_writes:true.
				$existing_attrs = isset( $parent[ $target_index ]['attrs'] ) && is_array( $parent[ $target_index ]['attrs'] )
					? $parent[ $target_index ]['attrs']
					: array();
				$bound_error = $this->crud->reject_bound_write(
					$attributes,
					$existing_attrs,
					! empty( $params['allow_bound_writes'] )
				);
				if ( null !== $bound_error ) {
					return $bound_error;
				}

				// Merge → auto-transform → innerContent reconciliation via the shared
				// pipeline (metadata deep-merged, other attrs shallow-merged, null
				// placeholders preserved). Passing $warnings surfaces the static-block
				// safety check the mutate response reports.
				$this->crud->apply_block_update_in_place( $parent[ $target_index ], $attributes, null, $warnings );

				$result_block = array(
					'name'       => $parent[ $target_index ]['blockName'],
					'attributes' => $parent[ $target_index ]['attrs'],
				);
				break;

			case 'update-html':
				$inner_html = isset( $params['innerHTML'] ) ? $params['innerHTML'] : null;
				if ( null === $inner_html ) {
					return new \WP_Error( 'missing_html', __( 'update-html requires an "innerHTML" string.', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				// update-html carries no `attributes` companion, so a dual-storage
				// block can only stay in sync if its content is re-derivable from
				// the new markup — derive and merge those attributes when it is,
				// otherwise reject (use update-attrs or replace-block with both).
				if (
					isset( $parent[ $target_index ]['blockName'] )
					&& $this->crud->is_block_dual_storage( $parent[ $target_index ]['blockName'] )
				) {
					$existing_attrs = isset( $parent[ $target_index ]['attrs'] ) ? $parent[ $target_index ]['attrs'] : array();
					$derived        = $this->crud->auto_derive_dual_attributes(
						$parent[ $target_index ]['blockName'],
						$existing_attrs,
						$inner_html
					);
					if ( null === $derived ) {
						return $this->crud->dual_storage_error( $parent[ $target_index ]['blockName'] );
					}
					// Same bound-write guard update_block applies to derived attrs.
					$bound_error = $this->crud->reject_bound_write(
						$derived,
						$existing_attrs,
						! empty( $params['allow_bound_writes'] )
					);
					if ( null !== $bound_error ) {
						return $bound_error;
					}
					$parent[ $target_index ]['attrs'] = array_merge( $existing_attrs, $derived );
				}

				// innerHTML replacement via the shared pipeline, so /mutate applies
				// the same sanitize + strip_empty_class_attributes + innerContent
				// reconciliation as the per-block update_block path, and both persist
				// identical markup for the same edit. Dual-storage attrs merged above.
				$this->crud->apply_block_update_in_place( $parent[ $target_index ], array(), $inner_html );

				$result_block = array(
					'name'       => $parent[ $target_index ]['blockName'],
					'attributes' => isset( $parent[ $target_index ]['attrs'] ) ? $parent[ $target_index ]['attrs'] : array(),
				);
				break;

			case 'replace-block':
				$new_block_def = isset( $params['block'] ) ? $params['block'] : null;
				if ( empty( $new_block_def ) || ! isset( $new_block_def['name'] ) ) {
					return new \WP_Error( 'missing_block', __( 'replace-block requires a "block" object with a "name" field.', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				// Build the replacement (validating + building innerBlocks
				// recursively to any depth) via Block_CRUD's shared builder —
				// the same one insert_blocks uses, so deeply-nested structures
				// like core/columns → core/column → core/heading round-trip
				// correctly through this op too.
				$built = $this->crud->build_block_from_def( $new_block_def, $warnings );
				if ( is_wp_error( $built ) ) {
					return $built;
				}

				$parent[ $target_index ] = $built;

				// Stable ref for the replacement (and any nested children).
				$single = array( &$parent[ $target_index ] );
				$this->crud->assign_missing_refs_recursive( $single );

				$result_block = array(
					'name'       => $built['blockName'],
					'attributes' => isset( $parent[ $target_index ]['attrs'] ) ? $parent[ $target_index ]['attrs'] : array(),
				);
				if ( isset( $parent[ $target_index ]['attrs']['metadata']['gk_ref'] ) ) {
					$result_block['ref'] = (string) $parent[ $target_index ]['attrs']['metadata']['gk_ref'];
				}
				break;

			case 'remove-block':
				$removed_block = $parent[ $target_index ];

				// Warn if removing a synced pattern reference.
				if ( 'core/block' === $removed_block['blockName'] ) {
					$ref_id     = isset( $removed_block['attrs']['ref'] ) ? $removed_block['attrs']['ref'] : 0;
					$pattern    = $ref_id ? get_post( $ref_id ) : null;
					$warnings[] = array(
						'message' => sprintf(
							/* translators: %s: pattern title or reference ID */
							__( 'Removing synced pattern reference "%s". The pattern itself is not deleted.', 'gk-block-mcp' ),
							$pattern ? $pattern->post_title : '#' . $ref_id
						),
					);
				}

				$result_block = array(
					'name'       => $removed_block['blockName'],
					'attributes' => isset( $removed_block['attrs'] ) ? $removed_block['attrs'] : array(),
				);

				// Remove from parent array and re-index.
				array_splice( $parent, $target_index, 1 );

				// If nested, also remove the matching null placeholder from the
				// grandparent's innerContent so the null count stays aligned with
				// innerBlocks. Otherwise serialize_block() pops a non-existent
				// innerBlock index and produces corrupt post_content.
				if ( count( $path ) > 1 ) {
					$gp_path = array_slice( $path, 0, -2 );
					$pi      = $path[ count( $path ) - 2 ];
					$gp      = &$blocks;
					foreach ( $gp_path as $seg ) {
						$gp = &$gp[ $seg ]['innerBlocks'];
					}
					if ( isset( $gp[ $pi ]['innerContent'] ) ) {
						$null_seen = 0;
						foreach ( $gp[ $pi ]['innerContent'] as $ic_idx => $ic_val ) {
							if ( null === $ic_val ) {
								if ( $null_seen === $target_index ) {
									array_splice( $gp[ $pi ]['innerContent'], $ic_idx, 1 );
									break;
								}
								++$null_seen;
							}
						}
					}
					unset( $gp );
				}
				break;

			case 'wrap-in-group':
				$wrapper_def   = isset( $params['wrapper'] ) ? $params['wrapper'] : array();
				$wrapper_name  = isset( $wrapper_def['name'] ) ? $wrapper_def['name'] : 'core/group';
				$wrapper_attrs = isset( $wrapper_def['attributes'] ) ? $wrapper_def['attributes'] : array();

				// Validate wrapper block name.
				$registry = \WP_Block_Type_Registry::get_instance();
				if ( ! $registry->is_registered( $wrapper_name ) ) {
					return new \WP_Error(
						'invalid_block',
						/* translators: %s: block type name */
						sprintf( __( 'Wrapper block "%s" is not registered.', 'gk-block-mcp' ), $wrapper_name ),
						array( 'status' => 400 )
					);
				}

				// Check wrapper preferences.
				$pref = $this->preferences->get_block_score( $wrapper_name );
				if ( 'legacy' === $pref['tier'] ) {
					return new \WP_Error(
						'legacy_block',
						/* translators: %s: block type name */
						sprintf( __( 'Wrapper "%s" is legacy.', 'gk-block-mcp' ), $wrapper_name ),
						array( 'status' => 400 )
					);
				}
				if ( 'avoid' === $pref['tier'] ) {
					$warnings[] = array(
						'block'                 => $wrapper_name,
						/* translators: %s: block namespace prefix (e.g. "stackable/") */
						'message'               => sprintf( __( '%s blocks are deprecated.', 'gk-block-mcp' ), $this->preferences->extract_namespace( $wrapper_name ) . '/' ),
						'suggested_replacement' => $this->preferences->get_replacement( $wrapper_name ),
					);
				}

				// Take the target block, wrap it in a new container.
				$target_block = $parent[ $target_index ];

				// Build wrapper HTML tag. Default to <div> for core/group.
				// Tag is constrained to a small allowlist that matches what
				// `core/group` officially supports — without this guard
				// `wrapper.attributes.tagName` could inject arbitrary tags
				// like <script> or <iframe> into the wrapper's raw innerHTML
				// (the wrapper HTML is built by string concatenation, not
				// wp_kses_post, since it's never user-facing markup until
				// serialize_blocks() round-trips it).
				$allowed_wrapper_tags = HTML_Transformer::CONTAINER_TAGS;
				$wrapper_tag          = 'div';
				if ( isset( $wrapper_attrs['tagName'] ) ) {
					$candidate = sanitize_key( $wrapper_attrs['tagName'] );
					if ( in_array( $candidate, $allowed_wrapper_tags, true ) ) {
						$wrapper_tag = $candidate;
					}
				}

				// Build class attribute from wrapper name.
				$wrapper_class = 'wp-block-' . str_replace( '/', '-', $wrapper_name );
				$opening_tag   = '<' . $wrapper_tag . ' class="' . esc_attr( $wrapper_class ) . '">';
				$closing_tag   = '</' . $wrapper_tag . '>';

				$wrapper_block = array(
					'blockName'    => $wrapper_name,
					'attrs'        => $wrapper_attrs,
					'innerHTML'    => $opening_tag . $closing_tag,
					'innerContent' => array( $opening_tag, null, $closing_tag ),
					'innerBlocks'  => array( $target_block ),
				);

				$parent[ $target_index ] = $wrapper_block;

				// Stable ref for the new wrapper. The wrapped target keeps its
				// existing ref because assign_missing_refs_recursive only fills
				// in missing slots — wrapper gets a fresh one, target untouched.
				$single = array( &$parent[ $target_index ] );
				$this->crud->assign_missing_refs_recursive( $single );

				$result_block = array(
					'name'       => $wrapper_name,
					'attributes' => $parent[ $target_index ]['attrs'],
				);
				if ( isset( $parent[ $target_index ]['attrs']['metadata']['gk_ref'] ) ) {
					$result_block['ref'] = (string) $parent[ $target_index ]['attrs']['metadata']['gk_ref'];
				}
				break;

			case 'unwrap-group':
				$container = $parent[ $target_index ];

				if ( empty( $container['innerBlocks'] ) ) {
					return new \WP_Error( 'no_inner_blocks', __( 'Block has no inner blocks to unwrap.', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				$children    = $container['innerBlocks'];
				$child_count = count( $children );

				$result_block = array(
					'name'           => $container['blockName'],
					'children_count' => $child_count,
				);

				// Replace the container with its children at the same position.
				array_splice( $parent, $target_index, 1, $children );

				// If nested (path > 1), update grandparent's innerContent:
				// the single null for the removed container must become N nulls
				// for the promoted children.
				if ( count( $path ) > 1 ) {
					$grandparent_path = array_slice( $path, 0, -2 );
					$parent_index     = $path[ count( $path ) - 2 ];

					$gp = &$blocks;
					foreach ( $grandparent_path as $seg ) {
						$gp = &$gp[ $seg ]['innerBlocks'];
					}

					if ( isset( $gp[ $parent_index ]['innerContent'] ) ) {
						// Find the null that corresponds to the unwrapped container
						// and replace it with $child_count nulls.
						$null_seen   = 0;
						$new_content = array();
						foreach ( $gp[ $parent_index ]['innerContent'] as $piece ) {
							if ( null === $piece && $null_seen === $target_index ) {
								// Replace this null with N nulls.
								for ( $ci = 0; $ci < $child_count; $ci++ ) {
									$new_content[] = null;
								}
								++$null_seen;
							} else {
								$new_content[] = $piece;
								if ( null === $piece ) {
									++$null_seen;
								}
							}
						}
						$gp[ $parent_index ]['innerContent'] = $new_content;
					}
				}
				break;

			case 'insert-child':
				$new_block_def = isset( $params['block'] ) ? $params['block'] : null;
				if ( empty( $new_block_def ) || ! isset( $new_block_def['name'] ) ) {
					return new \WP_Error( 'missing_block', __( 'insert-child requires a "block" object with a "name".', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				// Build the child (validating + building innerBlocks recursively
				// to any depth) via Block_CRUD's shared builder. Replaces an
				// earlier inline construction that silently dropped nested
				// innerBlocks past one level deep.
				$child_block = $this->crud->build_block_from_def( $new_block_def, $warnings );
				if ( is_wp_error( $child_block ) ) {
					return $child_block;
				}
				$name  = $child_block['blockName'];
				$attrs = $child_block['attrs'];

				// Stable ref for the new child.
				$single = array( &$child_block );
				$this->crud->assign_missing_refs_recursive( $single );

				// Get the container block and its innerBlocks.
				if ( ! isset( $parent[ $target_index ]['innerBlocks'] ) ) {
					$parent[ $target_index ]['innerBlocks'] = array();
				}

				$position = isset( $params['position'] ) ? $params['position'] : 'end';

				if ( 'start' === $position ) {
					array_unshift( $parent[ $target_index ]['innerBlocks'], $child_block );
				} elseif ( 'end' === $position ) {
					$parent[ $target_index ]['innerBlocks'][] = $child_block;
				} else {
					$pos = (int) $position;
					$pos = max( 0, min( $pos, count( $parent[ $target_index ]['innerBlocks'] ) ) );
					array_splice( $parent[ $target_index ]['innerBlocks'], $pos, 0, array( $child_block ) );
				}

				// Insert a null placeholder for the new child in innerContent.
				if ( 'start' === $position ) {
					$child_position = 0;
				} elseif ( 'end' === $position ) {
					$child_position = count( $parent[ $target_index ]['innerBlocks'] ) - 1;
				} else {
					$child_position = $pos;
				}

				$existing_ic = isset( $parent[ $target_index ]['innerContent'] ) && is_array( $parent[ $target_index ]['innerContent'] )
					? $parent[ $target_index ]['innerContent']
					: array();

				$parent[ $target_index ]['innerContent'] = $this->insert_child_placeholders( $existing_ic, $child_position );

				$result_block = array(
					'name'       => $name,
					'attributes' => isset( $child_block['attrs'] ) ? $child_block['attrs'] : $attrs,
				);
				if ( isset( $child_block['attrs']['metadata']['gk_ref'] ) ) {
					$result_block['ref'] = (string) $child_block['attrs']['metadata']['gk_ref'];
				}
				break;

			case 'duplicate':
				$original = $parent[ $target_index ];

				// Deep clone via JSON round-trip. Block trees are JSON-shaped
				// (associative arrays, scalars, null) so this preserves the
				// innerContent null placeholders that serialize_blocks() depends
				// on, without invoking the discouraged serialize()/unserialize().
				$encoded = wp_json_encode( $original );
				$clone   = ( false === $encoded ) ? null : json_decode( $encoded, true );

				// Abort if the round-trip didn't yield an array. parse_blocks() output
				// is JSON-shaped so this only fires on truly malformed input (resources,
				// invalid UTF-8); bailing prevents inserting null into the sibling array.
				if ( ! is_array( $clone ) ) {
					return new \WP_Error(
						'duplicate_failed',
						__( 'Failed to clone block for duplication.', 'gk-block-mcp' ),
						array( 'status' => 500 )
					);
				}

				// Strip & replace refs on the clone — every block in the clone tree
				// must have a fresh ref so the duplicate doesn't share identity with
				// the source. assign_fresh_refs_recursive overwrites unconditionally.
				$clone_arr = array( &$clone );
				$this->crud->assign_fresh_refs_recursive( $clone_arr );

				// Insert clone immediately after original in the sibling array.
				array_splice( $parent, $target_index + 1, 0, array( $clone ) );

				// If this block is nested (path length > 1), update the grandparent's
				// innerContent to include a null placeholder for the new sibling.
				if ( count( $path ) > 1 ) {
					$grandparent_path = array_slice( $path, 0, -2 );
					$parent_index     = $path[ count( $path ) - 2 ];

					// Navigate to the grandparent to find the parent block.
					$gp = &$blocks;
					foreach ( $grandparent_path as $seg ) {
						$gp = &$gp[ $seg ]['innerBlocks'];
					}

					// Insert a null in the parent block's innerContent after the
					// position of the original block's placeholder.
					if ( isset( $gp[ $parent_index ]['innerContent'] ) ) {
						$null_seen  = 0;
						$insert_pos = count( $gp[ $parent_index ]['innerContent'] );
						foreach ( $gp[ $parent_index ]['innerContent'] as $ic_idx => $ic_val ) {
							if ( null === $ic_val ) {
								if ( $null_seen === $target_index ) {
									$insert_pos = $ic_idx + 1;
									break;
								}
								++$null_seen;
							}
						}
						array_splice( $gp[ $parent_index ]['innerContent'], $insert_pos, 0, array( null ) );
					}
				}

				// Calculate the new path of the clone.
				$clone_path                             = $path;
				$clone_path[ count( $clone_path ) - 1 ] = $target_index + 1;

				$result_block = array(
					'name'       => $clone['blockName'],
					'attributes' => isset( $clone['attrs'] ) ? $clone['attrs'] : array(),
					'new_path'   => $clone_path,
				);
				if ( isset( $clone['attrs']['metadata']['gk_ref'] ) ) {
					$result_block['ref'] = (string) $clone['attrs']['metadata']['gk_ref'];
				}
				break;

			case 'move':
				$destination = isset( $params['destination'] ) ? $params['destination'] : null;

				if ( empty( $destination ) || ! is_array( $destination ) ) {
					return new \WP_Error( 'missing_destination', __( 'move requires a "destination" path.', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				foreach ( $destination as $seg ) {
					if ( ! is_int( $seg ) || $seg < 0 ) {
						return new \WP_Error( 'invalid_path', __( 'Destination must be array of non-negative integers.', 'gk-block-mcp' ), array( 'status' => 400 ) );
					}
				}

				$count = isset( $params['count'] ) ? max( 1, (int) $params['count'] ) : 1;

				// Reject moving a block into itself or its own descendants. For a
				// range move (count > 1) the moved blocks occupy
				// [target_index, target_index + count - 1] under the source parent,
				// so a destination descending into ANY of them is invalid, not just
				// the first block at $path. Guard the whole range up front rather
				// than relying on downstream path resolution to reject it.
				$dest_len = count( $destination );
				$path_len = count( $path );
				if ( $dest_len > $path_len ) {
					$src_parent_segs  = array_slice( $path, 0, $path_len - 1 );
					$dest_prefix_segs = array_slice( $destination, 0, $path_len - 1 );
					$dest_seg_at_src  = (int) $destination[ $path_len - 1 ];
					$into_moved_range = $src_parent_segs === $dest_prefix_segs
						&& $dest_seg_at_src >= $target_index
						&& $dest_seg_at_src <= $target_index + $count - 1;
					if ( $into_moved_range ) {
						return new \WP_Error(
							'invalid_destination',
							__( 'Cannot move a block into itself or its own descendants.', 'gk-block-mcp' ),
							array( 'status' => 400 )
						);
					}
				}

				// Validate count doesn't exceed available blocks.
				if ( $target_index + $count > count( $parent ) ) {
					return new \WP_Error( 'invalid_count', __( 'count exceeds available blocks at path.', 'gk-block-mcp' ), array( 'status' => 400 ) );
				}

				// Determine if source and destination share the same parent.
				$src_parent_path  = array_slice( $path, 0, -1 );
				$dest_parent_path = array_slice( $destination, 0, -1 );
				$dest_index       = end( $destination );

				$same_parent = ( $src_parent_path === $dest_parent_path );

				// Adjust destination for pre-move indexing.
				$adjusted_dest = $destination;

				if ( $same_parent ) {
					if ( $target_index < $adjusted_dest[ count( $adjusted_dest ) - 1 ] ) {
						$adjusted_dest[ count( $adjusted_dest ) - 1 ] -= $count;
					}
				} else {
					$src_depth = count( $src_parent_path );
					if ( $src_depth < count( $adjusted_dest ) ) {
						$shared = true;
						for ( $sp = 0; $sp < $src_depth; $sp++ ) {
							if ( $src_parent_path[ $sp ] !== $adjusted_dest[ $sp ] ) {
								$shared = false;
								break;
							}
						}
						if ( $shared && $adjusted_dest[ $src_depth ] > $target_index ) {
							$adjusted_dest[ $src_depth ] -= $count;
						}
					} elseif ( count( $adjusted_dest ) - 1 === $src_depth ) {
						$shared = true;
						for ( $sp = 0; $sp < $src_depth; $sp++ ) {
							if ( $sp < count( $adjusted_dest ) - 1 && $src_parent_path[ $sp ] !== $adjusted_dest[ $sp ] ) {
								$shared = false;
								break;
							}
						}
						if ( $shared && $adjusted_dest[ $src_depth ] > $target_index ) {
							$adjusted_dest[ $src_depth ] -= $count;
						}
					}
				}

				$dest_parent_path = array_slice( $adjusted_dest, 0, -1 );
				$dest_index       = end( $adjusted_dest );

				// Extract source blocks.
				$moved_blocks = array_splice( $parent, $target_index, $count );

				// Update source parent's innerContent: remove $count nulls at the source position.
				if ( count( $path ) > 1 ) {
					$src_gp_path = array_slice( $path, 0, -2 );
					$src_pi      = $path[ count( $path ) - 2 ];
					$src_gp      = &$blocks;
					foreach ( $src_gp_path as $seg ) {
						$src_gp = &$src_gp[ $seg ]['innerBlocks'];
					}
					if ( isset( $src_gp[ $src_pi ]['innerContent'] ) ) {
						$null_seen = 0;
						$to_remove = array();
						foreach ( $src_gp[ $src_pi ]['innerContent'] as $ic_idx => $ic_val ) {
							if ( null === $ic_val ) {
								if ( $null_seen >= $target_index && $null_seen < $target_index + $count ) {
									$to_remove[] = $ic_idx;
								}
								++$null_seen;
							}
						}
						foreach ( array_reverse( $to_remove ) as $rm_idx ) {
							array_splice( $src_gp[ $src_pi ]['innerContent'], $rm_idx, 1 );
						}
					}
				}

				// Navigate to destination parent.
				if ( empty( $dest_parent_path ) ) {
					$dest_index = max( 0, min( $dest_index, count( $blocks ) ) );
					array_splice( $blocks, $dest_index, 0, $moved_blocks );
				} else {
					$dest_parent     = &$blocks;
					$dest_parent_len = count( $dest_parent_path );
					for ( $di = 0; $di < $dest_parent_len; $di++ ) {
						$seg = $dest_parent_path[ $di ];
						if ( ! isset( $dest_parent[ $seg ] ) ) {
							return new \WP_Error( 'invalid_destination', __( 'Destination path is invalid.', 'gk-block-mcp' ), array( 'status' => 400 ) );
						}
						if ( ! isset( $dest_parent[ $seg ]['innerBlocks'] ) ) {
							$dest_parent[ $seg ]['innerBlocks'] = array();
						}
						$dest_parent = &$dest_parent[ $seg ]['innerBlocks'];
					}
					$dest_index = max( 0, min( $dest_index, count( $dest_parent ) ) );
					array_splice( $dest_parent, $dest_index, 0, $moved_blocks );

					// Update destination parent's innerContent: insert $count nulls.
					$dest_container_idx = end( $dest_parent_path );
					$dest_gp            = &$blocks;
					for ( $di = 0; $di < $dest_parent_len - 1; $di++ ) {
						$dest_gp = &$dest_gp[ $dest_parent_path[ $di ] ]['innerBlocks'];
					}
					if ( isset( $dest_gp[ $dest_container_idx ]['innerContent'] ) && is_array( $dest_gp[ $dest_container_idx ]['innerContent'] ) ) {
						$dest_gp[ $dest_container_idx ]['innerContent'] = $this->insert_child_placeholders(
							$dest_gp[ $dest_container_idx ]['innerContent'],
							$dest_index,
							$count
						);
					}
				}

				$first        = $moved_blocks[0];
				$result_block = array(
					'name'        => $first['blockName'],
					'attributes'  => isset( $first['attrs'] ) ? $first['attrs'] : array(),
					'moved_count' => $count,
					'new_path'    => array_merge( $dest_parent_path, array( $dest_index ) ),
				);
				break;

			default:
				return new \WP_Error(
					'invalid_op',
					/* translators: %s: operation name */
					sprintf( __( 'Unknown operation "%s".', 'gk-block-mcp' ), $op ),
					array( 'status' => 400 )
				);
		}

		// In dry_run mode, skip saving and rate limit recording.
		if ( $dry_run ) {
			$response = array(
				'success'            => true,
				'dry_run'            => true,
				'op'                 => $op,
				'path'               => $path,
				'warnings'           => $warnings,
				'before_revision_id' => null,
				'revision_id'        => null,
			);

			$response['block'] = $result_block;

			return $response;
		}

		// Serialize and save (depth-checked) with the pre-mutation snapshot for
		// the optimistic-concurrency guard.
		$result = $this->crud->save_blocks( $post_id, $blocks, $post->post_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->crud->record_rate_limit( $post_id, 'write' );

		$response = array(
			'success'            => true,
			'op'                 => $op,
			'path'               => $path,
			'warnings'           => $warnings,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
		);

		$response['block'] = $result_block;

		return $response;
	}

	/**
	 * Insert null placeholder(s) into a container's innerContent at a child position.
	 *
	 * Serialization interleaves innerContent — strings are emitted verbatim
	 * and each null is replaced by the next innerBlock — so a placeholder must
	 * land between the wrapper's opening and closing strings or the child is
	 * serialized OUTSIDE the wrapper markup. Two shapes defeat a naive
	 * Nth-null scan: a childless container parses back with a single unsplit
	 * wrapper string (['<div></div>']), and an insertion at the end position
	 * (child position == existing child count) has no Nth null to find. This
	 * helper first splits an unsplit wrapper at the first '>' into
	 * [opening, closing], then scans for the Nth null, and falls back to
	 * inserting before the last string piece (the closing tag).
	 *
	 * @param array $inner_content  The container's innerContent array.
	 * @param int   $child_position Index in innerBlocks the placeholder(s) represent.
	 * @param int   $count          Number of placeholders to insert.
	 *
	 * @return array The updated innerContent.
	 */
	private function insert_child_placeholders( array $inner_content, $child_position, $count = 1 ) {
		$has_null = in_array( null, $inner_content, true );
		if ( ! $has_null ) {
			foreach ( $inner_content as $piece_idx => $piece ) {
				if ( ! is_string( $piece ) ) {
					continue;
				}
				$open_end = strpos( $piece, '>' );
				if ( false === $open_end || ( strlen( $piece ) - 1 ) === $open_end ) {
					continue;
				}
				$opening = substr( $piece, 0, $open_end + 1 );
				$closing = substr( $piece, $open_end + 1 );
				if ( '' === $closing ) {
					continue;
				}
				array_splice( $inner_content, $piece_idx, 1, array( $opening, $closing ) );
				break;
			}
		}

		$null_seen = 0;
		$insert_at = null;
		foreach ( $inner_content as $ic_idx => $ic_val ) {
			if ( null === $ic_val ) {
				if ( $null_seen === $child_position ) {
					$insert_at = $ic_idx;
					break;
				}
				++$null_seen;
			}
		}

		if ( null === $insert_at ) {
			$insert_at = count( $inner_content );
			for ( $ri = count( $inner_content ) - 1; $ri >= 0; $ri-- ) {
				if ( is_string( $inner_content[ $ri ] ) ) {
					$insert_at = $ri;
					break;
				}
			}
		}

		array_splice( $inner_content, $insert_at, 0, array_fill( 0, $count, null ) );

		return $inner_content;
	}
}
