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
	 * Constructor.
	 *
	 * @param Preferences  $preferences Preferences instance.
	 * @param Block_Safety $safety      Block safety checker.
	 */
	public function __construct( Preferences $preferences, Block_Safety $safety ) {
		$this->preferences = $preferences;
		$this->safety      = $safety;
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
	public function get_blocks( $post_id, $render = false ) {
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

		// Merge attributes.
		if ( ! empty( $attributes ) ) {
			$block['attrs'] = array_merge(
				isset( $block['attrs'] ) ? $block['attrs'] : array(),
				$attributes
			);
		}

		// Replace innerHTML.
		if ( null !== $inner_html ) {
			$block['innerHTML'] = wp_kses_post( $inner_html );
			// Preserve innerBlock placeholders (null) in innerContent for container blocks.
			if ( ! empty( $block['innerBlocks'] ) && ! empty( $block['innerContent'] ) ) {
				$block['innerContent'] = $this->rebuild_inner_content( $block['innerContent'], $block['innerHTML'] );
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

		return array(
			'success'              => true,
			'block'                => array(
				'index'      => $index,
				'name'       => $block['blockName'],
				'attributes' => isset( $block['attrs'] ) ? $block['attrs'] : array(),
			),
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
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

		// Validate and build each block.
		foreach ( $blocks as $block_def ) {
			$name = isset( $block_def['name'] ) ? $block_def['name'] : '';

			// Validate block name exists in registry.
			$registry = \WP_Block_Type_Registry::get_instance();
			if ( ! empty( $name ) && ! $registry->is_registered( $name ) ) {
				return new \WP_Error(
					'invalid_block',
					/* translators: %s: block name */
					sprintf( __( 'Block type "%s" is not registered.', 'gk-block-api' ), $name ),
					array( 'status' => 400 )
				);
			}

			// Check preferences.
			$pref = $this->preferences->get_block_score( $name );

			if ( 'legacy' === $pref['tier'] ) {
				$replacement = $this->preferences->get_replacement( $name );
				return new \WP_Error(
					'legacy_block',
					sprintf(
						/* translators: 1: block name, 2: replacement block name */
						__( 'Block "%1$s" is legacy and cannot be inserted. Use "%2$s" instead.', 'gk-block-api' ),
						$name,
						$replacement ? $replacement : 'a preferred block'
					),
					array( 'status' => 400 )
				);
			}

			if ( 'avoid' === $pref['tier'] ) {
				$replacement = $this->preferences->get_replacement( $name );
				$warnings[]  = array(
					'block'                 => $name,
					'message'               => sprintf(
						/* translators: %s: block namespace */
						__( '%s blocks are deprecated on this site. Prefer filter/ or core/ blocks.', 'gk-block-api' ),
						$this->preferences->extract_namespace( $name ) . '/'
					),
					'suggested_replacement' => $replacement,
				);
			}

			// Build the block array.
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
		$inserted = array();
		foreach ( $new_blocks as $i => $block ) {
			$inserted[] = array(
				'index' => $visible_insert + $i,
				'name'  => $block['blockName'],
			);
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

		$registry   = \WP_Block_Type_Registry::get_instance();
		$warnings   = array();
		$new_blocks = array();

		foreach ( $blocks as $block_def ) {
			$name = isset( $block_def['name'] ) ? $block_def['name'] : '';

			// Validate block name.
			if ( ! empty( $name ) && ! $registry->is_registered( $name ) ) {
				return new \WP_Error(
					'invalid_block',
					sprintf( __( 'Block type "%s" is not registered.', 'gk-block-api' ), $name ),
					array( 'status' => 400 )
				);
			}

			// Check preferences.
			$pref = $this->preferences->get_block_score( $name );

			if ( 'legacy' === $pref['tier'] ) {
				$replacement = $this->preferences->get_replacement( $name );
				return new \WP_Error(
					'legacy_block',
					sprintf(
						__( 'Block "%1$s" is legacy and cannot be used. Use "%2$s" instead.', 'gk-block-api' ),
						$name,
						$replacement ? $replacement : 'a preferred block'
					),
					array( 'status' => 400 )
				);
			}

			if ( 'avoid' === $pref['tier'] ) {
				$replacement = $this->preferences->get_replacement( $name );
				$warnings[]  = array(
					'block'                 => $name,
					'message'               => sprintf(
						__( '%s blocks are deprecated on this site.', 'gk-block-api' ),
						$this->preferences->extract_namespace( $name ) . '/'
					),
					'suggested_replacement' => $replacement,
				);
			}

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
			$block_list[] = array(
				'index'      => $i,
				'name'       => $block['blockName'],
				'attributes' => $block['attrs'],
			);
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

		// Determine insertion index.
		$insert_at = count( $existing_blocks );

		if ( 'start' === $position ) {
			$insert_at = 0;
		} elseif ( is_numeric( $position ) ) {
			$pos = (int) $position;
			if ( -1 === $pos ) {
				$insert_at = count( $existing_blocks );
			} else {
				$insert_at = min( $pos + 1, count( $existing_blocks ) );
			}
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
	 * Perform a path-based mutation on the block tree.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $op      Operation name.
	 * @param array  $path    Integer array path to target block.
	 * @param array  $params  Operation-specific parameters.
	 *
	 * @return array|\WP_Error Mutation result with revision IDs, or WP_Error.
	 */
	public function mutate_block_tree( $post_id, $op, $path, $params = array() ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-api' ), array( 'status' => 404 ) );
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}

		// Validate path format.
		foreach ( $path as $segment ) {
			if ( ! is_int( $segment ) || $segment < 0 ) {
				return new \WP_Error( 'invalid_path', __( 'Path must be an array of non-negative integers.', 'gk-block-api' ), array( 'status' => 400 ) );
			}
		}

		if ( empty( $path ) ) {
			return new \WP_Error( 'invalid_path', __( 'Path cannot be empty.', 'gk-block-api' ), array( 'status' => 400 ) );
		}

		// Navigate to the parent array within $blocks by reference.
		$parent = &$blocks;
		for ( $i = 0; $i < count( $path ) - 1; $i++ ) {
			$segment = $path[ $i ];

			if ( ! isset( $parent[ $segment ] ) ) {
				return new \WP_Error(
					'invalid_path',
					sprintf( __( 'Path segment %1$d (index %2$d) is out of bounds.', 'gk-block-api' ), $i, $segment ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $parent[ $segment ]['innerBlocks'] ) ) {
				return new \WP_Error(
					'invalid_path',
					sprintf( __( 'Block at path segment %d has no inner blocks.', 'gk-block-api' ), $i ),
					array( 'status' => 400 )
				);
			}

			$parent = &$parent[ $segment ]['innerBlocks'];

			if ( ! is_array( $parent ) ) {
				return new \WP_Error( 'invalid_path', __( 'Path traversal encountered invalid block structure.', 'gk-block-api' ), array( 'status' => 400 ) );
			}
		}

		$target_index = end( $path );

		if ( ! isset( $parent[ $target_index ] ) ) {
			return new \WP_Error(
				'invalid_path',
				sprintf( __( 'Target block at index %d not found.', 'gk-block-api' ), $target_index ),
				array( 'status' => 400 )
			);
		}

		$warnings     = array();
		$result_block = null;

		switch ( $op ) {

			case 'update-attrs':
				$attributes = isset( $params['attributes'] ) ? $params['attributes'] : null;
				if ( empty( $attributes ) || ! is_array( $attributes ) ) {
					return new \WP_Error( 'missing_attributes', __( 'update-attrs requires an "attributes" object.', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				// Merge attributes.
				$parent[ $target_index ]['attrs'] = array_merge(
					isset( $parent[ $target_index ]['attrs'] ) ? $parent[ $target_index ]['attrs'] : array(),
					$attributes
				);

				// Auto-transform innerHTML for known attribute-to-HTML mappings.
				$auto_transformed = $this->auto_transform_html(
					$parent[ $target_index ]['blockName'],
					$attributes,
					isset( $parent[ $target_index ]['innerHTML'] ) ? $parent[ $target_index ]['innerHTML'] : ''
				);

				if ( null !== $auto_transformed ) {
					$block_type_name = $parent[ $target_index ]['blockName'];
					$parent[ $target_index ]['innerHTML'] = $auto_transformed;

					// Update innerContent: apply the same transform to each string
					// element while preserving null positions (innerBlock placeholders).
					// This is critical for container blocks where innerContent looks like:
					//   ['<div class="wp-block-group">', null, '</div>']
					// Replacing with array($html) would destroy child block positions.
					if ( ! empty( $parent[ $target_index ]['innerContent'] ) ) {
						$parent[ $target_index ]['innerContent'] = array_map(
							function ( $piece ) use ( $block_type_name, $attributes ) {
								if ( null === $piece ) {
									return null; // Preserve innerBlock placeholder.
								}
								// Apply the same transform to this content fragment.
								$result = $this->auto_transform_html( $block_type_name, $attributes, $piece );
								return null !== $result ? $result : $piece;
							},
							$parent[ $target_index ]['innerContent']
						);
					} else {
						$parent[ $target_index ]['innerContent'] = array( $auto_transformed );
					}
				} else {
					// No auto-transform available — check static block safety.
					$safety_warnings = $this->safety->check_mutation(
						$parent[ $target_index ]['blockName'],
						array_keys( $attributes ),
						false
					);
					$warnings = array_merge( $warnings, $safety_warnings );
				}

				$result_block = array(
					'name'       => $parent[ $target_index ]['blockName'],
					'attributes' => $parent[ $target_index ]['attrs'],
				);
				break;

			case 'update-html':
				$inner_html = isset( $params['innerHTML'] ) ? $params['innerHTML'] : null;
				if ( null === $inner_html ) {
					return new \WP_Error( 'missing_html', __( 'update-html requires an "innerHTML" string.', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				$parent[ $target_index ]['innerHTML'] = wp_kses_post( $inner_html );
				// Preserve innerBlock placeholders (null) in innerContent for container blocks.
				if ( ! empty( $parent[ $target_index ]['innerBlocks'] ) && ! empty( $parent[ $target_index ]['innerContent'] ) ) {
					$parent[ $target_index ]['innerContent'] = $this->rebuild_inner_content(
						$parent[ $target_index ]['innerContent'],
						$parent[ $target_index ]['innerHTML']
					);
				} else {
					$parent[ $target_index ]['innerContent'] = array( $parent[ $target_index ]['innerHTML'] );
				}

				$result_block = array(
					'name'       => $parent[ $target_index ]['blockName'],
					'attributes' => isset( $parent[ $target_index ]['attrs'] ) ? $parent[ $target_index ]['attrs'] : array(),
				);
				break;

			case 'replace-block':
				$new_block_def = isset( $params['block'] ) ? $params['block'] : null;
				if ( empty( $new_block_def ) || ! isset( $new_block_def['name'] ) ) {
					return new \WP_Error( 'missing_block', __( 'replace-block requires a "block" object with a "name" field.', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				// Validate block name.
				$name     = $new_block_def['name'];
				$registry = \WP_Block_Type_Registry::get_instance();
				if ( ! $registry->is_registered( $name ) ) {
					return new \WP_Error( 'invalid_block', sprintf( __( 'Block type "%s" is not registered.', 'gk-block-api' ), $name ), array( 'status' => 400 ) );
				}

				// Check preferences.
				$pref = $this->preferences->get_block_score( $name );
				if ( 'legacy' === $pref['tier'] ) {
					$replacement = $this->preferences->get_replacement( $name );
					return new \WP_Error( 'legacy_block', sprintf( __( 'Block "%1$s" is legacy. Use "%2$s" instead.', 'gk-block-api' ), $name, $replacement ? $replacement : 'a preferred block' ), array( 'status' => 400 ) );
				}
				if ( 'avoid' === $pref['tier'] ) {
					$replacement = $this->preferences->get_replacement( $name );
					$warnings[]  = array(
						'block'                 => $name,
						'message'               => sprintf( __( '%s blocks are deprecated on this site.', 'gk-block-api' ), $this->preferences->extract_namespace( $name ) . '/' ),
						'suggested_replacement' => $replacement,
					);
				}

				// Build replacement block.
				$attrs      = isset( $new_block_def['attributes'] ) ? $new_block_def['attributes'] : array();
				$inner_html = isset( $new_block_def['innerHTML'] ) ? wp_kses_post( $new_block_def['innerHTML'] ) : '';
				$inner_blocks = array();

				// Build innerBlocks recursively if provided.
				if ( ! empty( $new_block_def['innerBlocks'] ) ) {
					foreach ( $new_block_def['innerBlocks'] as $child_def ) {
						$child_name  = isset( $child_def['name'] ) ? $child_def['name'] : '';
						$child_attrs = isset( $child_def['attributes'] ) ? $child_def['attributes'] : array();
						$child_html  = isset( $child_def['innerHTML'] ) ? wp_kses_post( $child_def['innerHTML'] ) : '';
						$inner_blocks[] = array(
							'blockName'    => $child_name,
							'attrs'        => $child_attrs,
							'innerHTML'    => $child_html,
							'innerContent' => ! empty( $child_html ) ? array( $child_html ) : array(),
							'innerBlocks'  => array(),
						);
					}
				}

				$parent[ $target_index ] = array(
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerHTML'    => $inner_html,
					'innerContent' => ! empty( $inner_html ) ? array( $inner_html ) : array(),
					'innerBlocks'  => $inner_blocks,
				);

				$result_block = array(
					'name'       => $name,
					'attributes' => $attrs,
				);
				break;

			case 'remove-block':
				$removed_block = $parent[ $target_index ];

				// Warn if removing a synced pattern reference.
				if ( 'core/block' === $removed_block['blockName'] ) {
					$ref_id  = isset( $removed_block['attrs']['ref'] ) ? $removed_block['attrs']['ref'] : 0;
					$pattern = $ref_id ? get_post( $ref_id ) : null;
					$warnings[] = array(
						'message' => sprintf(
							__( 'Removing synced pattern reference "%s". The pattern itself is not deleted.', 'gk-block-api' ),
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
				break;

			case 'wrap-in-group':
				$wrapper_def   = isset( $params['wrapper'] ) ? $params['wrapper'] : array();
				$wrapper_name  = isset( $wrapper_def['name'] ) ? $wrapper_def['name'] : 'core/group';
				$wrapper_attrs = isset( $wrapper_def['attributes'] ) ? $wrapper_def['attributes'] : array();

				// Validate wrapper block name.
				$registry = \WP_Block_Type_Registry::get_instance();
				if ( ! $registry->is_registered( $wrapper_name ) ) {
					return new \WP_Error( 'invalid_block', sprintf( __( 'Wrapper block "%s" is not registered.', 'gk-block-api' ), $wrapper_name ), array( 'status' => 400 ) );
				}

				// Check wrapper preferences.
				$pref = $this->preferences->get_block_score( $wrapper_name );
				if ( 'legacy' === $pref['tier'] ) {
					return new \WP_Error( 'legacy_block', sprintf( __( 'Wrapper "%s" is legacy.', 'gk-block-api' ), $wrapper_name ), array( 'status' => 400 ) );
				}
				if ( 'avoid' === $pref['tier'] ) {
					$warnings[] = array(
						'block'                 => $wrapper_name,
						'message'               => sprintf( __( '%s blocks are deprecated.', 'gk-block-api' ), $this->preferences->extract_namespace( $wrapper_name ) . '/' ),
						'suggested_replacement' => $this->preferences->get_replacement( $wrapper_name ),
					);
				}

				// Take the target block, wrap it in a new container.
				$target_block = $parent[ $target_index ];

				// Build wrapper HTML tag. Default to <div> for core/group.
				$wrapper_tag = 'div';
				if ( isset( $wrapper_attrs['tagName'] ) ) {
					$wrapper_tag = sanitize_key( $wrapper_attrs['tagName'] );
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

				$result_block = array(
					'name'       => $wrapper_name,
					'attributes' => $wrapper_attrs,
				);
				break;

			case 'unwrap-group':
				$container = $parent[ $target_index ];

				if ( empty( $container['innerBlocks'] ) ) {
					return new \WP_Error( 'no_inner_blocks', __( 'Block has no inner blocks to unwrap.', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				$children     = $container['innerBlocks'];
				$child_count  = count( $children );

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
						$null_seen    = 0;
						$new_content  = array();
						foreach ( $gp[ $parent_index ]['innerContent'] as $piece ) {
							if ( null === $piece && $null_seen === $target_index ) {
								// Replace this null with N nulls.
								for ( $ci = 0; $ci < $child_count; $ci++ ) {
									$new_content[] = null;
								}
								$null_seen++;
							} else {
								$new_content[] = $piece;
								if ( null === $piece ) {
									$null_seen++;
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
					return new \WP_Error( 'missing_block', __( 'insert-child requires a "block" object with a "name".', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				$name = $new_block_def['name'];

				// Validate block name.
				$registry = \WP_Block_Type_Registry::get_instance();
				if ( ! $registry->is_registered( $name ) ) {
					return new \WP_Error( 'invalid_block', sprintf( __( 'Block type "%s" is not registered.', 'gk-block-api' ), $name ), array( 'status' => 400 ) );
				}

				// Check preferences.
				$pref = $this->preferences->get_block_score( $name );
				if ( 'legacy' === $pref['tier'] ) {
					$replacement = $this->preferences->get_replacement( $name );
					return new \WP_Error( 'legacy_block', sprintf( __( 'Block "%1$s" is legacy. Use "%2$s" instead.', 'gk-block-api' ), $name, $replacement ? $replacement : 'a preferred block' ), array( 'status' => 400 ) );
				}
				if ( 'avoid' === $pref['tier'] ) {
					$warnings[] = array(
						'block'                 => $name,
						'message'               => sprintf( __( '%s blocks are deprecated.', 'gk-block-api' ), $this->preferences->extract_namespace( $name ) . '/' ),
						'suggested_replacement' => $this->preferences->get_replacement( $name ),
					);
				}

				$attrs      = isset( $new_block_def['attributes'] ) ? $new_block_def['attributes'] : array();
				$inner_html = isset( $new_block_def['innerHTML'] ) ? wp_kses_post( $new_block_def['innerHTML'] ) : '';

				$child_block = array(
					'blockName'    => $name,
					'attrs'        => $attrs,
					'innerHTML'    => $inner_html,
					'innerContent' => ! empty( $inner_html ) ? array( $inner_html ) : array(),
					'innerBlocks'  => array(),
				);

				// Get the container block and its innerBlocks.
				if ( ! isset( $parent[ $target_index ]['innerBlocks'] ) ) {
					$parent[ $target_index ]['innerBlocks'] = array();
				}

				$position = isset( $params['position'] ) ? $params['position'] : 'end';

				if ( 'start' === $position ) {
					array_unshift( $parent[ $target_index ]['innerBlocks'], $child_block );
				} elseif ( 'end' === $position || null === $position ) {
					$parent[ $target_index ]['innerBlocks'][] = $child_block;
				} else {
					$pos = (int) $position;
					$pos = max( 0, min( $pos, count( $parent[ $target_index ]['innerBlocks'] ) ) );
					array_splice( $parent[ $target_index ]['innerBlocks'], $pos, 0, array( $child_block ) );
				}

				// Insert a null placeholder for the new child in innerContent.
				// innerContent for a container looks like: ['<div>', null, null, '</div>']
				// Nulls go BETWEEN string entries, not before the first or after the last.
				$ic = &$parent[ $target_index ]['innerContent'];

				if ( 'start' === $position ) {
					// Insert after the first string entry (opening tag).
					$insert_at = 0;
					foreach ( $ic as $ic_idx => $ic_val ) {
						if ( is_string( $ic_val ) ) {
							$insert_at = $ic_idx + 1;
							break;
						}
					}
					array_splice( $ic, $insert_at, 0, array( null ) );
				} elseif ( 'end' === $position || null === $position ) {
					// Insert before the last string entry (closing tag).
					$insert_at = count( $ic );
					for ( $ri = count( $ic ) - 1; $ri >= 0; $ri-- ) {
						if ( is_string( $ic[ $ri ] ) ) {
							$insert_at = $ri;
							break;
						}
					}
					array_splice( $ic, $insert_at, 0, array( null ) );
				} else {
					// Numeric position: find the Nth null and insert before it.
					$null_count    = 0;
					$insert_pos_ic = count( $ic );
					foreach ( $ic as $ic_idx => $ic_val ) {
						if ( null === $ic_val ) {
							if ( $null_count === $pos ) {
								$insert_pos_ic = $ic_idx;
								break;
							}
							$null_count++;
						}
					}
					array_splice( $ic, $insert_pos_ic, 0, array( null ) );
				}

				$result_block = array(
					'name'       => $name,
					'attributes' => $attrs,
				);
				break;

			case 'duplicate':
				$original = $parent[ $target_index ];

				// Deep clone via serialize/unserialize.
				$clone = unserialize( serialize( $original ) );

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
								$null_seen++;
							}
						}
						array_splice( $gp[ $parent_index ]['innerContent'], $insert_pos, 0, array( null ) );
					}
				}

				// Calculate the new path of the clone.
				$clone_path                              = $path;
				$clone_path[ count( $clone_path ) - 1 ]  = $target_index + 1;

				$result_block = array(
					'name'       => $clone['blockName'],
					'attributes' => isset( $clone['attrs'] ) ? $clone['attrs'] : array(),
					'new_path'   => $clone_path,
				);
				break;

			case 'move':
				$before_path = isset( $params['before'] ) ? $params['before'] : null;
				$destination  = isset( $params['destination'] ) ? $params['destination'] : $before_path;

				if ( empty( $destination ) || ! is_array( $destination ) ) {
					return new \WP_Error( 'missing_destination', __( 'move requires a "before" or "destination" path.', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				foreach ( $destination as $seg ) {
					if ( ! is_int( $seg ) || $seg < 0 ) {
						return new \WP_Error( 'invalid_path', __( 'Destination must be array of non-negative integers.', 'gk-block-api' ), array( 'status' => 400 ) );
					}
				}

				// Reject moving a block into itself or its own descendants.
				// If destination starts with the source path, it's a descendant.
				if ( count( $destination ) > count( $path ) ) {
					$is_descendant = true;
					for ( $ci = 0; $ci < count( $path ); $ci++ ) {
						if ( $path[ $ci ] !== $destination[ $ci ] ) {
							$is_descendant = false;
							break;
						}
					}
					if ( $is_descendant ) {
						return new \WP_Error(
							'invalid_destination',
							__( 'Cannot move a block into itself or its own descendants.', 'gk-block-api' ),
							array( 'status' => 400 )
						);
					}
				}

				$count = isset( $params['count'] ) ? max( 1, (int) $params['count'] ) : 1;

				// Validate count doesn't exceed available blocks.
				if ( $target_index + $count > count( $parent ) ) {
					return new \WP_Error( 'invalid_count', __( 'count exceeds available blocks at path.', 'gk-block-api' ), array( 'status' => 400 ) );
				}

				// Determine if source and destination share the same parent.
				$src_parent_path  = array_slice( $path, 0, -1 );
				$dest_parent_path = array_slice( $destination, 0, -1 );
				$dest_index       = end( $destination );

				$same_parent = ( $src_parent_path === $dest_parent_path );

				// Adjust destination for pre-move indexing.
				// After source removal, indices shift at the source's parent level.
				$adjusted_dest = $destination; // copy the full destination path.

				if ( $same_parent ) {
					// Same parent: just adjust the final segment.
					if ( $target_index < $adjusted_dest[ count( $adjusted_dest ) - 1 ] ) {
						$adjusted_dest[ count( $adjusted_dest ) - 1 ] -= $count;
					}
				} else {
					// Cross-level: check if destination passes through the source's parent.
					// The source parent path tells us which array the source was removed from.
					// If the destination path traverses that same array at the source's depth,
					// and the destination's index at that depth > source index, adjust.
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
					} elseif ( $src_depth === count( $adjusted_dest ) - 1 ) {
						// Destination is at the same depth as source but different parent.
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
				// This only matters for nested moves (source is inside a container).
				if ( count( $path ) > 1 ) {
					$src_gp_path  = array_slice( $path, 0, -2 );
					$src_pi       = $path[ count( $path ) - 2 ];
					$src_gp       = &$blocks;
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
								$null_seen++;
							}
						}
						// Remove in reverse order to preserve indices.
						foreach ( array_reverse( $to_remove ) as $rm_idx ) {
							array_splice( $src_gp[ $src_pi ]['innerContent'], $rm_idx, 1 );
						}
					}
				}

				// Navigate to destination parent.
				if ( empty( $dest_parent_path ) ) {
					// Top-level destination.
					$dest_index = max( 0, min( $dest_index, count( $blocks ) ) );
					array_splice( $blocks, $dest_index, 0, $moved_blocks );
				} else {
					$dest_parent = &$blocks;
					for ( $di = 0; $di < count( $dest_parent_path ); $di++ ) {
						$seg = $dest_parent_path[ $di ];
						if ( ! isset( $dest_parent[ $seg ] ) ) {
							return new \WP_Error( 'invalid_destination', __( 'Destination path is invalid.', 'gk-block-api' ), array( 'status' => 400 ) );
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
					for ( $di = 0; $di < count( $dest_parent_path ) - 1; $di++ ) {
						$dest_gp = &$dest_gp[ $dest_parent_path[ $di ] ]['innerBlocks'];
					}
					if ( isset( $dest_gp[ $dest_container_idx ]['innerContent'] ) ) {
						// Find the insertion point: after the Nth null corresponding to dest_index.
						$null_seen = 0;
						$ic_insert = count( $dest_gp[ $dest_container_idx ]['innerContent'] );
						foreach ( $dest_gp[ $dest_container_idx ]['innerContent'] as $ic_idx => $ic_val ) {
							if ( null === $ic_val ) {
								if ( $null_seen === $dest_index ) {
									$ic_insert = $ic_idx;
									break;
								}
								$null_seen++;
							}
						}
						// Insert $count nulls.
						$nulls = array_fill( 0, $count, null );
						array_splice( $dest_gp[ $dest_container_idx ]['innerContent'], $ic_insert, 0, $nulls );
					}
				}

				$first = $moved_blocks[0];
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
					sprintf( __( 'Unknown operation "%s".', 'gk-block-api' ), $op ),
					array( 'status' => 400 )
				);
		}

		// Serialize and save.
		$new_content = serialize_blocks( $blocks );
		$result      = $this->save_post_content( $post_id, $new_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		$response = array(
			'success'            => true,
			'op'                 => $op,
			'path'               => $path,
			'warnings'           => $warnings,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
		);

		if ( null !== $result_block ) {
			$response['block'] = $result_block;
		}

		return $response;
	}

	/**
	 * Auto-transform innerHTML when attribute changes imply HTML structure changes.
	 *
	 * Uses WP_HTML_Tag_Processor for safe attribute manipulation (no regex for
	 * attributes). Falls back to regex only for tag name swaps where the
	 * processor has no set_tag() support.
	 *
	 * Categories:
	 * 1. Tag name swaps (regex — processor can't change tag names)
	 * 2. HTML attribute transforms (WP_HTML_Tag_Processor)
	 * 3. CSS inline style transforms (WP_HTML_Tag_Processor)
	 * 4. Text content transforms (regex for inner text replacement)
	 *
	 * @param string $block_name    Block type name.
	 * @param array  $changed_attrs The attributes being set (key => value).
	 * @param string $current_html  Current innerHTML of the block.
	 *
	 * @return string|null Transformed HTML, or null if no transform applies.
	 */
	private function auto_transform_html( $block_name, $changed_attrs, $current_html ) {
		try {
		$html = $current_html;

		// ── 1. Tag name swaps (regex — WP_HTML_Tag_Processor has no set_tag) ──

		// core/list: `ordered` toggles <ul> ↔ <ol>.
		// Only swaps the FIRST opening and LAST closing tag to avoid corrupting nested lists.
		if ( 'core/list' === $block_name && array_key_exists( 'ordered', $changed_attrs ) ) {
			if ( $changed_attrs['ordered'] ) {
				$html = preg_replace( '/<ul(\s|>)/i', '<ol$1', $html, 1 ); // First only.
				$html = preg_replace( '/<\/ul>(?!.*<\/ul>)/is', '</ol>', $html );   // Last only.
			} else {
				$html = preg_replace( '/<ol(\s|>)/i', '<ul$1', $html, 1 ); // First only.
				$html = preg_replace( '/<\/ol>(?!.*<\/ol>)/is', '</ul>', $html );   // Last only.
			}
		}

		// core/heading, core/accordion-heading: `level` changes <hN> tag.
		if ( in_array( $block_name, array( 'core/heading', 'core/accordion-heading' ), true )
			&& array_key_exists( 'level', $changed_attrs )
		) {
			$new_level = (int) $changed_attrs['level'];
			if ( $new_level >= 1 && $new_level <= 6 ) {
				$html = preg_replace( '/<h[1-6](\s|>)/i', '<h' . $new_level . '$1', $html );
				$html = preg_replace( '/<\/h[1-6]>/i', '</h' . $new_level . '>', $html );
			}
		}

		// core/group, core/separator: `tagName` changes the wrapper element.
		if ( in_array( $block_name, array( 'core/group', 'core/separator' ), true )
			&& array_key_exists( 'tagName', $changed_attrs )
		) {
			$allowed_tags = array( 'div', 'section', 'aside', 'main', 'header', 'footer', 'article', 'hr' );
			$new_tag      = sanitize_key( $changed_attrs['tagName'] );
			if ( in_array( $new_tag, $allowed_tags, true ) ) {
				$html = preg_replace(
					'/^(\s*)<(div|section|aside|main|header|footer|article|hr)(\s|>)/i',
					'$1<' . $new_tag . '$3',
					$html
				);
				$html = preg_replace(
					'/<\/(div|section|aside|main|header|footer|article|hr)>(\s*)$/i',
					'</' . $new_tag . '>$2',
					$html
				);
			}
		}

		// ── 2. HTML attribute transforms (WP_HTML_Tag_Processor) ─────

		// Map block attrs → HTML attrs to set on the first matching tag.
		// Note: WP_HTML_Tag_Processor::set_attribute() handles escaping internally.
		// Do NOT pass values through esc_attr() — that would double-escape.
		$attr_map = array(
			'url' => array(
				'tags'  => array( 'a', 'img', 'audio', 'video', 'source', 'iframe', 'embed' ),
				'attrs' => array( 'href', 'src' ), // try href first, then src
			),
			'src' => array(
				'tags'  => array( 'img', 'audio', 'video', 'source', 'iframe' ),
				'attrs' => array( 'src' ),
			),
			'alt' => array(
				'tags'  => array( 'img' ),
				'attrs' => array( 'alt' ),
			),
			'preload' => array(
				'tags'  => array( 'audio', 'video' ),
				'attrs' => array( 'preload' ),
			),
		);

		foreach ( $attr_map as $block_attr => $config ) {
			if ( ! array_key_exists( $block_attr, $changed_attrs ) ) {
				continue;
			}

			$new_val    = $changed_attrs[ $block_attr ];
			$tags       = $config['tags'];
			$html_attrs = $config['attrs'];
			$found      = false;

			// Reset processor to scan from the start for each attribute.
			$processor = new \WP_HTML_Tag_Processor( $html );

			while ( $processor->next_tag() ) {
				// Filter by allowed tags if specified.
				if ( null !== $tags && ! in_array( strtolower( $processor->get_tag() ), $tags, true ) ) {
					continue;
				}

				// Try each candidate HTML attribute.
				foreach ( $html_attrs as $html_attr ) {
					if ( null !== $processor->get_attribute( $html_attr ) ) {
						$processor->set_attribute( $html_attr, $new_val );
						$found = true;
						break 2; // Set on first match only.
					}
				}
			}

			if ( $found ) {
				$html = $processor->get_updated_html();
			}
		}

		// Boolean HTML attributes (autoplay, loop) on audio/video.
		$bool_attrs = array( 'autoplay', 'loop' );

		foreach ( $bool_attrs as $attr ) {
			if ( ! array_key_exists( $attr, $changed_attrs ) ) {
				continue;
			}

			$processor = new \WP_HTML_Tag_Processor( $html );
			while ( $processor->next_tag() ) {
				$tag = strtolower( $processor->get_tag() );
				if ( ! in_array( $tag, array( 'audio', 'video' ), true ) ) {
					continue;
				}

				if ( $changed_attrs[ $attr ] ) {
					$processor->set_attribute( $attr, true );
				} else {
					$processor->remove_attribute( $attr );
				}
				break; // First match only.
			}
			$html = $processor->get_updated_html();
		}

		// core/details: `showContent` toggles the `open` attribute.
		if ( 'core/details' === $block_name && array_key_exists( 'showContent', $changed_attrs ) ) {
			$processor = new \WP_HTML_Tag_Processor( $html );
			if ( $processor->next_tag( 'details' ) ) {
				if ( $changed_attrs['showContent'] ) {
					$processor->set_attribute( 'open', true );
				} else {
					$processor->remove_attribute( 'open' );
				}
				$html = $processor->get_updated_html();
			}
		}

		// ── 3. CSS inline style transforms (WP_HTML_Tag_Processor) ───

		$css_prop_map = array(
			'height' => 'height',
			'width'  => 'width',
		);

		foreach ( $css_prop_map as $block_attr => $css_prop ) {
			if ( ! array_key_exists( $block_attr, $changed_attrs ) ) {
				continue;
			}

			$new_val   = sanitize_text_field( $changed_attrs[ $block_attr ] );
			$processor = new \WP_HTML_Tag_Processor( $html );

			if ( $processor->next_tag() ) {
				$style = $processor->get_attribute( 'style' );
				if ( null !== $style && false !== strpos( $style, $css_prop ) ) {
					$new_style = preg_replace(
						'/' . preg_quote( $css_prop, '/' ) . '\s*:\s*[^;"]+(;?)/',
						$css_prop . ':' . $new_val . '$1',
						$style
					);
					$processor->set_attribute( 'style', $new_style );
					$html = $processor->get_updated_html();
				}
			}
		}

		// ── 4. Text content transforms (regex — processor can't edit text) ──

		// core/heading, core/paragraph, core/button, core/code, core/preformatted,
		// core/verse: `content` attr replaces the element's inner text.
		// The content may contain inline HTML (links, bold, etc.) so we use wp_kses_post.
		$content_blocks = array( 'core/heading', 'core/paragraph', 'core/code', 'core/preformatted', 'core/verse' );
		if ( in_array( $block_name, $content_blocks, true )
			&& array_key_exists( 'content', $changed_attrs )
		) {
			$new_content = wp_kses_post( $changed_attrs['content'] );
			// Replace inner text between the first opening tag and last closing tag.
			$html = preg_replace_callback(
				'/^(\s*<[^>]+>)(.*?)(<\/[^>]+>\s*)$/is',
				function ( $matches ) use ( $new_content ) {
					return $matches[1] . $new_content . $matches[3];
				},
				$html
			);
		}

		// core/button: `text` attr replaces the <a> inner text.
		if ( 'core/button' === $block_name && array_key_exists( 'text', $changed_attrs ) ) {
			$new_text = wp_kses_post( $changed_attrs['text'] );
			$html     = preg_replace_callback(
				'/(<a[^>]*>)(.*?)(<\/a>)/is',
				function ( $matches ) use ( $new_text ) {
					return $matches[1] . $new_text . $matches[3];
				},
				$html
			);
		}

		// core/quote, core/pullquote: `citation` updates <cite> text.
		// Uses preg_replace_callback to avoid backreference injection if citation
		// contains $ characters (e.g., "Price is $100").
		if ( in_array( $block_name, array( 'core/quote', 'core/pullquote' ), true )
			&& array_key_exists( 'citation', $changed_attrs )
		) {
			$new_citation = wp_kses_post( $changed_attrs['citation'] );
			if ( preg_match( '/<cite[^>]*>.*?<\/cite>/is', $html ) ) {
				$html = preg_replace_callback(
					'/(<cite[^>]*>).*?(<\/cite>)/is',
					function ( $matches ) use ( $new_citation ) {
						return $matches[1] . $new_citation . $matches[2];
					},
					$html
				);
			} elseif ( ! empty( $new_citation ) ) {
				$html = preg_replace_callback(
					'/(<\/blockquote>\s*$)/i',
					function ( $matches ) use ( $new_citation ) {
						return '<cite>' . $new_citation . '</cite>' . $matches[1];
					},
					$html
				);
			}
		}

		// Return transformed HTML if anything changed.
		if ( $html !== $current_html ) {
			return $html;
		}

		return null;

		} catch ( \Throwable $e ) {
			// Transform failed — return null (no transform applied, safety warning will fire instead).
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'GK Block API auto_transform error: ' . $e->getMessage() );
			}
			return null;
		}
	}

	/**
	 * Rebuild innerContent when innerHTML is replaced on a container block.
	 *
	 * WordPress innerContent is an array like ['<div>', null, '</div>'] where
	 * null entries are placeholders for innerBlocks. When innerHTML is updated,
	 * we need to replace the string portions (wrapper HTML) while preserving
	 * the null placeholders so serialize_blocks() correctly outputs children.
	 *
	 * Strategy: the new innerHTML contains the wrapper markup. We split it
	 * into fragments around child positions by counting the existing nulls
	 * and distributing the new HTML across the same structure.
	 *
	 * For simple cases (1 null = 1 child), the result is:
	 *   [opening_html, null, closing_html]
	 *
	 * @param array  $old_inner_content The existing innerContent array.
	 * @param string $new_inner_html    The new innerHTML for the block.
	 *
	 * @return array Updated innerContent preserving null positions.
	 */
	private function rebuild_inner_content( $old_inner_content, $new_inner_html ) {
		try {
		// Count how many null placeholders exist (one per innerBlock).
		$null_count = 0;
		foreach ( $old_inner_content as $piece ) {
			if ( null === $piece ) {
				$null_count++;
			}
		}

		if ( 0 === $null_count ) {
			// No children — just a leaf block.
			return array( $new_inner_html );
		}

		// For container blocks, innerHTML typically looks like:
		//   "\n<div class=\"wp-block-group\">\n</div>\n"
		// We need to split this into opening wrapper + closing wrapper
		// and place nulls between them (one per child).
		//
		// Simple heuristic: find the split point where the opening wrapper ends.
		// The innerHTML has the inner content stripped, so it's effectively:
		//   opening_html + closing_html
		// We insert nulls between opening and closing.

		// Use WP_HTML_Tag_Processor to find the end of the first opening tag.
		$processor = new \WP_HTML_Tag_Processor( $new_inner_html );
		if ( $processor->next_tag() ) {
			// Get position after the first tag's > character.
			// The processor doesn't expose offset, so use a simpler approach:
			// find the first > in the HTML.
			$first_close = strpos( $new_inner_html, '>' );
			if ( false !== $first_close ) {
				$opening = substr( $new_inner_html, 0, $first_close + 1 );
				$closing = substr( $new_inner_html, $first_close + 1 );

				$result   = array( $opening );
				for ( $i = 0; $i < $null_count; $i++ ) {
					$result[] = null;
				}
				$result[] = $closing;

				return $result;
			}
		}

		// Fallback: preserve old structure, just update non-null entries
		// by splitting new HTML evenly across them.
		return array_map(
			function ( $piece ) use ( $new_inner_html ) {
				return null === $piece ? null : $new_inner_html;
			},
			$old_inner_content
		);

		} catch ( \Throwable $e ) {
			// Fallback: simple array with the new HTML.
			return array( $new_inner_html );
		}
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
	private function save_post_content( $post_id, $new_content ) {
		// Get the current latest revision as the "before" snapshot.
		$before_revisions = wp_get_post_revisions( $post_id, array(
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$before_revision_id = is_array( $before_revisions ) && ! empty( $before_revisions ) ? key( $before_revisions ) : 0;

		// Update the post (WordPress creates a revision automatically).
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Get the new latest revision as the "after" snapshot.
		$after_revisions = wp_get_post_revisions( $post_id, array(
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		$after_revision_id = is_array( $after_revisions ) && ! empty( $after_revisions ) ? key( $after_revisions ) : 0;

		return array(
			'before_revision_id' => (int) $before_revision_id,
			'revision_id'        => (int) $after_revision_id,
		);
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
		$counter = 0;
		return $this->format_blocks_recursive( $blocks, array(), $counter, $render );
	}

	/**
	 * Recursively format blocks with path tracking.
	 *
	 * @param array $blocks      Parsed blocks.
	 * @param array $parent_path Path to the parent container.
	 * @param int   &$counter    Flat sequential counter (by reference).
	 * @param bool  $render      Whether to include rendered output for dynamic blocks.
	 *
	 * @return array Formatted block data.
	 */
	private function format_blocks_recursive( $blocks, $parent_path, &$counter, $render = false ) {
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
							$pattern_counter      = 0;
							$pattern_data['blocks'] = $this->format_blocks_recursive(
								$pattern_blocks,
								array(),
								$pattern_counter,
								true
							);
						}
					}

					$data['pattern_ref'] = $pattern_data;
				}
			}

			// Mark blocks as dynamic or static.
			$registry   = \WP_Block_Type_Registry::get_instance();
			$block_type = $registry ? $registry->get_registered( $block['blockName'] ) : null;
			$is_dynamic = $block_type ? $block_type->is_dynamic() : false;
			$data['dynamic'] = $is_dynamic;

			$counter++;

			if ( ! empty( $block['innerHTML'] ) ) {
				$html = $block['innerHTML'];

				// Expand shortcodes in rendered mode.
				if ( $render && false !== strpos( $html, '[' ) && preg_match( '/\[[\w-]+/', $html ) ) {
					$data['innerHTML_rendered'] = do_shortcode( $html );
				}

				$data['innerHTML'] = $html;
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
						error_log( 'GK Block API render_block error: ' . $e->getMessage() );
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

			$formatted[] = $data;
		}

		return $formatted;
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
				$inner_path = array_merge( $current_path, array( 'innerBlocks' ) );
				$flat       = array_merge( $flat, $this->flatten_blocks( $block['innerBlocks'], $inner_path ) );
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
		$ref = &$blocks;

		foreach ( $path as $segment ) {
			if ( 'innerBlocks' === $segment ) {
				$ref = &$ref['innerBlocks'];
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
	private function check_rate_limit( $post_id, $type = 'write' ) {
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
	private function record_rate_limit( $post_id, $type = 'write' ) {
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
}
