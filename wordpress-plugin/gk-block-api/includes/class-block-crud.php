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
 * Facade that delegates to Block_Reader (read path) and Block_Writer (write
 * path). Retains ownership of shared utilities: ref management, tree depth,
 * path resolution, and dual-storage helpers. Public API is unchanged.
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
	 * Maximum number of items in a single `update_blocks_batch()` call.
	 *
	 * One batch counts as one write against `RATE_LIMIT_WRITES`, so without
	 * a cap a single call could update unbounded blocks per minute.
	 *
	 * @var int
	 */
	const MAX_BATCH_SIZE = 50;

	/**
	 * Maximum nesting depth for any block tree the plugin will write.
	 *
	 * Real-world Gutenberg layouts rarely exceed 5–8 levels. 32 is generous
	 * for valid use and prevents both accidental and adversarial deep trees
	 * that would risk:
	 *
	 *   - stack overflow in WP's `parse_blocks()` / `serialize_blocks()`,
	 *   - quadratic walks in `format_blocks_recursive()` /
	 *     `assign_missing_refs_recursive()` / Block_Mutator path traversal,
	 *   - unboundedly large response payloads on read.
	 *
	 * Enforced in every write path (`insert_blocks`, `replace_all_blocks`,
	 * `update_block`, `update_blocks_batch`, `mutate_block_tree`) by
	 * `tree_depth()` before the write commits. Rejection error code is
	 * `block_depth_exceeded` (HTTP 400).
	 *
	 * @var int
	 */
	const MAX_BLOCK_DEPTH = 32;

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
	 * Read-path collaborator.
	 *
	 * @var Block_Reader
	 */
	private $reader;

	/**
	 * Write-path collaborator.
	 *
	 * @var Block_Writer
	 */
	private $writer;

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
		$this->reader      = new Block_Reader( $this, $preferences, $safety, $transformer, $inventory );
		$this->writer      = new Block_Writer( $this, $preferences, $safety, $transformer, $inventory );
	}

	// =========================================================================
	// READ FACADE — delegates to Block_Reader
	// =========================================================================

	/**
	 * Get all blocks for a post.
	 *
	 * Delegates to Block_Reader. Preserved as a facade so existing callers
	 * (tests, REST_Controller, Block_Mutator) need no changes.
	 *
	 * @param int  $post_id      Post ID.
	 * @param bool $render       Whether to render dynamic blocks and expand shortcodes.
	 * @param bool $persist_refs Whether to persist gk_ref assignments to post_content.
	 *
	 * @return array|\WP_Error Array of block data or WP_Error.
	 */
	public function get_blocks( $post_id, $render = false, $persist_refs = true ) {
		return $this->reader->get_blocks( $post_id, $render, $persist_refs );
	}

	/**
	 * Format parsed blocks into a structured response array.
	 *
	 * Delegates to Block_Reader. Preserved as a facade so existing callers
	 * (tests, REST_Controller, Block_Mutator) need no changes.
	 *
	 * @param array $blocks Parsed blocks from parse_blocks().
	 * @param bool  $render Whether to render dynamic blocks and expand shortcodes.
	 *
	 * @return array Formatted block data.
	 */
	public function format_blocks( $blocks, $render = false ) {
		return $this->reader->format_blocks( $blocks, $render );
	}

	// =========================================================================
	// WRITE FACADE — delegates to Block_Writer
	// =========================================================================

	/**
	 * Update a single block by index.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int   $post_id    Post ID.
	 * @param int   $index      Block index (0-based).
	 * @param array $attributes Partial attributes to merge (optional).
	 * @param mixed $inner_html New innerHTML content (optional, pass null to skip).
	 * @param array $options    Optional flags (e.g. allow_bound_writes).
	 *
	 * @return array|\WP_Error Updated block data with revision_id, or WP_Error.
	 */
	public function update_block( $post_id, $index, $attributes = array(), $inner_html = null, $options = array() ) {
		return $this->writer->update_block( $post_id, $index, $attributes, $inner_html, $options );
	}

	/**
	 * Apply N independent block updates atomically in a single revision.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $updates List of update items. Each: { ref XOR flat_index, attributes?, innerHTML? }.
	 * @param bool  $verbose When true, each result includes a `saved` snapshot.
	 *
	 * @return array|\WP_Error On success: { success, count, results[], before_revision_id, revision_id }.
	 */
	public function update_blocks_batch( $post_id, $updates, $verbose = false ) {
		return $this->writer->update_blocks_batch( $post_id, $updates, $verbose );
	}

	/**
	 * Insert blocks at a position.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int   $post_id  Post ID.
	 * @param mixed $position Insert position: numeric index for "after", "start" for prepend, null for append.
	 * @param array $blocks   Array of block definitions: { name, attributes, innerHTML }.
	 *
	 * @return array|\WP_Error Insert result with warnings and revision_id, or WP_Error.
	 */
	public function insert_blocks( $post_id, $position, $blocks ) {
		return $this->writer->insert_blocks( $post_id, $position, $blocks );
	}

	/**
	 * Delete block(s) at a position.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int $post_id Post ID.
	 * @param int $index   Start index to delete (0-based).
	 * @param int $count   Number of consecutive blocks to remove (default 1).
	 *
	 * @return array|\WP_Error Result with revision_id, or WP_Error.
	 */
	public function delete_blocks( $post_id, $index, $count = 1 ) {
		return $this->writer->delete_blocks( $post_id, $index, $count );
	}

	/**
	 * Atomically replace a range of top-level blocks with a different shape.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int   $post_id Post ID.
	 * @param int   $start   Top-level counter of the first block to replace (0-based).
	 * @param int   $count   Number of consecutive top-level blocks to replace.
	 * @param array $blocks  New block definitions to splice in.
	 *
	 * @return array|\WP_Error Result with revision_id, or WP_Error.
	 */
	public function replace_blocks_range( $post_id, $start, $count, $blocks ) {
		return $this->writer->replace_blocks_range( $post_id, $start, $count, $blocks );
	}

	/**
	 * Replace all blocks on a post (full page rewrite).
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $blocks  Array of block definitions.
	 *
	 * @return array|\WP_Error Result with revision_id, or WP_Error.
	 */
	public function replace_all_blocks( $post_id, $blocks ) {
		return $this->writer->replace_all_blocks( $post_id, $blocks );
	}

	/**
	 * Insert a pattern at a position on a post.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int        $post_id    Post ID.
	 * @param int|string $pattern_id Pattern post ID (synced) or registered pattern name.
	 * @param mixed      $position   Insert position.
	 * @param bool       $synced     If true, insert as core/block ref. If false, inline blocks.
	 *
	 * @return array|\WP_Error Insert result with revision_id, or WP_Error.
	 */
	public function insert_pattern( $post_id, $pattern_id, $position, $synced = true ) {
		return $this->writer->insert_pattern( $post_id, $pattern_id, $position, $synced );
	}

	/**
	 * Get the most recent revision ID for a post, or 0 if there are none yet.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return int Revision post ID (0 = no revisions yet).
	 */
	public function get_latest_revision_id( $post_id ) {
		return $this->writer->get_latest_revision_id( $post_id );
	}

	/**
	 * Optimistic-concurrency check for write endpoints.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int    $post_id           Post being written.
	 * @param string $expected_revision Raw header value, e.g. `W/"123"` or `123`.
	 *
	 * @return null|\WP_Error null = proceed; WP_Error = 412 with current_revision.
	 */
	public function check_if_match( $post_id, $expected_revision ) {
		return $this->writer->check_if_match( $post_id, $expected_revision );
	}

	/**
	 * Validate, serialize, and save a block tree as the new post_content.
	 *
	 * Delegates to Block_Writer, then invalidates the reader parse-cache so the
	 * next read in the same request sees the freshly-saved content.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $blocks  Block tree in WP-internal shape.
	 *
	 * @return array|\WP_Error
	 */
	public function save_blocks( $post_id, array $blocks ) {
		$result = $this->writer->save_blocks( $post_id, $blocks );
		if ( ! is_wp_error( $result ) ) {
			$this->reader->invalidate( (int) $post_id );
		}
		return $result;
	}

	/**
	 * Save serialized block content to a post, tracking before/after revision IDs.
	 *
	 * Delegates to Block_Writer, then invalidates the reader parse-cache so the
	 * next read in the same request sees the freshly-saved content.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $new_content Serialized block markup to save.
	 *
	 * @return array|\WP_Error
	 */
	public function save_post_content( $post_id, $new_content ) {
		$result = $this->writer->save_post_content( $post_id, $new_content );
		if ( ! is_wp_error( $result ) ) {
			$this->reader->invalidate( (int) $post_id );
		}
		return $result;
	}

	/**
	 * Check rate limits for a post.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so Post_Manager and
	 * other callers need no changes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Rate type: 'write' or 'put'.
	 *
	 * @return true|\WP_Error True if within limits, WP_Error if exceeded.
	 */
	public function check_rate_limit( $post_id, $type = 'write' ) {
		return $this->writer->check_rate_limit( $post_id, $type );
	}

	/**
	 * Record a write operation for rate limiting.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so Post_Manager and
	 * other callers need no changes.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $type    Rate type: 'write' or 'put'.
	 */
	public function record_rate_limit( $post_id, $type = 'write' ) {
		$this->writer->record_rate_limit( $post_id, $type );
	}

	/**
	 * Revert a post to a specific revision.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so existing callers need no changes.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $revision_id Revision ID to restore.
	 *
	 * @return array|\WP_Error Result with new revision ID.
	 */
	public function revert_to_revision( $post_id, $revision_id ) {
		return $this->writer->revert_to_revision( $post_id, $revision_id );
	}

	/**
	 * Build the canonical post-save block snapshot returned to write callers.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so Block_Mutator and
	 * other callers need no changes.
	 *
	 * @param array $block      Parsed block array, post-mutation.
	 * @param int   $flat_index Flat index of this block in the post.
	 *
	 * @return array Saved snapshot for response payload.
	 */
	public function format_saved_block( $block, $flat_index ) {
		return $this->writer->format_saved_block( $block, $flat_index );
	}

	/**
	 * Apply attribute merge and/or innerHTML replacement to a single block in place.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so Block_Mutator and
	 * other callers need no changes.
	 *
	 * @param array       &$block      Block array to mutate in place.
	 * @param array       $attributes  Partial attributes to merge (may be empty).
	 * @param string|null $inner_html  Replacement innerHTML, or null to skip.
	 *
	 * @return void
	 */
	public function apply_block_update_in_place( &$block, $attributes, $inner_html ) {
		$this->writer->apply_block_update_in_place( $block, $attributes, $inner_html );
	}

	/**
	 * Recursively builds a WP block array from an API block definition.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so Post_Manager and
	 * other callers need no changes.
	 *
	 * @param array $block_def  Input definition (name, attributes, innerHTML, innerBlocks).
	 * @param array &$warnings  Accumulated warnings (modified in place).
	 *
	 * @return array|\WP_Error Built block array ready for serialize_blocks(), or WP_Error.
	 */
	public function build_block_from_def( array $block_def, array &$warnings ) {
		return $this->writer->build_block_from_def( $block_def, $warnings );
	}

	/**
	 * Validate a block name against the registry and preference tiers.
	 *
	 * Delegates to Block_Writer. Preserved as a facade so Post_Manager and
	 * other callers need no changes.
	 *
	 * @param string $block_name Block type name.
	 *
	 * @return array { error: \WP_Error|null, warnings: array }
	 */
	public function validate_block_def( $block_name ) {
		return $this->writer->validate_block_def( $block_name );
	}

	// =========================================================================
	// SHARED UTILITIES — owned by Block_CRUD, used by Reader, Writer, Mutator
	// =========================================================================

	/**
	 * Visibility gate shared by the read enricher and the insert-pattern
	 * write path. Returns true if a wp_block CPT post is safe to surface /
	 * inline / reference for the current caller.
	 *
	 * WordPress doesn't expose a single helper that matches this contract:
	 *   - is_post_publicly_viewable() factors in the post type's `public`
	 *     flag, which wp_block lacks, so it returns false even for a
	 *     plainly-published synced pattern.
	 *   - post_password_required() covers only the password case.
	 *   - current_user_can('read_post') alone over-blocks: published patterns
	 *     should expand for unauthenticated test contexts too.
	 *
	 * Treat publish-without-password as universally readable. For any other
	 * status (or a password-protected publish), fall back to the standard
	 * read_post cap-check, which WP cap-maps against ownership + status +
	 * read_private_<post_type> + the password cookie.
	 *
	 * @param \WP_Post|null $post Post object, typically from get_post().
	 *
	 * @return bool True if the caller may see this post's title/content.
	 */
	public static function is_post_readable( $post ): bool {
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}
		// Publish + no password = universally readable.
		// Strict empty-string check (not empty()) because empty('0') is true
		// in PHP — a literal "0" is a valid post_password and we must NOT
		// treat it as "no password set".
		$has_password = '' !== (string) $post->post_password;
		$is_public    = 'publish' === $post->post_status && ! $has_password;
		if ( $is_public ) {
			return true;
		}
		// Non-public-status path: WP's cap mapping for `read_post` handles
		// draft / private / pending / trash, factoring in ownership and the
		// read_private_<post_type> cap. This DOES NOT consider post_password —
		// that's a runtime cookie check, not a cap.
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return false;
		}
		// Password-protected posts: the password is enforced at output time
		// via post_password_required(), which depends on a cookie we don't
		// have in a REST / WP-CLI context. Cap-elevate password-protected
		// access to `edit_post` so subscribers (who have the `read` cap, and
		// therefore satisfy `read_post`) can't bypass the password by going
		// through our API. Editors / authors of the post still pass.
		if ( $has_password ) {
			return current_user_can( 'edit_post', $post->ID );
		}
		return true;
	}

	/**
	 * Compute the maximum nesting depth of a block tree. A flat array
	 * (no innerBlocks anywhere) is depth 1. Empty input is depth 0.
	 *
	 * @param array $blocks       Block tree in either WP-internal shape
	 *                            (`innerBlocks`) or API shape (`innerBlocks`).
	 * @param int   $depth_so_far Internal recursion accumulator. Callers
	 *                            should leave this at the default 0; the
	 *                            recursive walker uses it to implement the
	 *                            early-exit at MAX_BLOCK_DEPTH + 1.
	 *
	 * @return int
	 */
	public static function tree_depth( array $blocks, int $depth_so_far = 0 ): int {
		// Hard early-exit. Without this, an adversarial 100k-deep block tree
		// would recurse 100k times before validate_tree_depth could reject it,
		// blowing the PHP stack (segfault) before we got a chance to return a
		// proper 400 WP_Error. Return MAX_BLOCK_DEPTH + 1 as soon as we know
		// the bound is exceeded; the depth value past the limit is meaningless
		// to the caller (validate_tree_depth only compares > MAX_BLOCK_DEPTH).
		if ( $depth_so_far > self::MAX_BLOCK_DEPTH ) {
			return $depth_so_far;
		}
		if ( empty( $blocks ) ) {
			return $depth_so_far;
		}
		$max = $depth_so_far;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$child_depth = $depth_so_far + 1;
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$child_depth = self::tree_depth( $block['innerBlocks'], $depth_so_far + 1 );
			}
			$max = max( $max, $child_depth );
		}
		return $max;
	}

	/**
	 * Validate that a block tree does not exceed `MAX_BLOCK_DEPTH`.
	 *
	 * @param array $blocks Block tree to validate.
	 *
	 * @return true|\WP_Error true if within bound, WP_Error otherwise.
	 */
	public static function validate_tree_depth( array $blocks ) {
		$depth = self::tree_depth( $blocks );
		if ( $depth > self::MAX_BLOCK_DEPTH ) {
			return new \WP_Error(
				'block_depth_exceeded',
				sprintf(
					/* translators: 1: max depth; 2: actual depth */
					__( 'Block tree exceeds the maximum nesting depth (%1$d levels, got %2$d).', 'gk-block-api' ),
					self::MAX_BLOCK_DEPTH,
					$depth
				),
				array(
					'status'       => 400,
					'max_depth'    => self::MAX_BLOCK_DEPTH,
					'actual_depth' => $depth,
				)
			);
		}
		return true;
	}

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
	 * Truncated to 9 hex chars (36 bits). Birthday-paradox collisions appear
	 * around sqrt(2^36 / 2) ≈ 185k generated refs — comfortably past any
	 * realistic per-post scale. The recursive ref assigners
	 * (`assign_missing_refs_recursive` / `assign_fresh_refs_recursive`) track
	 * already-emitted refs in the current tree and re-roll on duplicate via
	 * `generate_unique_ref()` so the per-post uniqueness invariant is
	 * deterministic, not probabilistic.
	 *
	 * @return string A new ref like "blk_a3f2c1q9d".
	 */
	public static function generate_ref() {
		$seed = uniqid( 'gk_block_ref_', true ) . '|' . wp_rand() . '|' . microtime( true );
		// Use the 'auth' scheme (stable, long-lived). The 'nonce' scheme is
		// intended for time-bounded values that rotate; refs persist with the post.
		return self::REF_PREFIX . substr( wp_hash( $seed, 'auth' ), 0, 9 );
	}

	/**
	 * Generate a fresh ref that's guaranteed unique against a set of refs
	 * already in use. Used by the recursive ref assigners so within a
	 * single post / single assignment pass, the ref set has no duplicates
	 * regardless of how the 32-bit hash space happens to land.
	 *
	 * Re-rolls up to 8 times. Birthday math says the chance of needing
	 * even one re-roll at 1000 in-use refs is ~1 in 4M; the 8-iteration
	 * cap is paranoia, not need.
	 *
	 * @param array<string, true> $in_use Set of refs already assigned.
	 *
	 * @return string
	 */
	public static function generate_unique_ref( array $in_use ) {
		for ( $i = 0; $i < 8; $i++ ) {
			$ref = self::generate_ref();
			if ( ! isset( $in_use[ $ref ] ) ) {
				return $ref;
			}
		}
		// Pathological — every re-roll collided. Fall back to a
		// uniqid-suffixed variant so we always return something unique.
		return self::REF_PREFIX . substr( wp_hash( uniqid( 'gk_ref_fallback_', true ) . wp_rand(), 'auth' ), 0, 12 );
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
				array(
					'status' => 404,
					'ref'    => $ref,
				)
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
			array(
				'status' => 404,
				'ref'    => $ref,
			)
		);
	}

	/**
	 * Fetch a single block by ref or flat index. Returns the same `saved`
	 * snapshot shape that write endpoints echo, so verification reads use the
	 * identical contract as the writes that produced them.
	 *
	 * Lighter than get_blocks() when you only need one block — useful for
	 * after-the-fact re-checks when the original write response was lost or
	 * the agent wants to confirm the current state of a known ref before
	 * chaining another edit.
	 *
	 * @param int             $post_id    Post ID.
	 * @param string|int|null $ref        Stable gk_ref. Provide this OR flat_index.
	 * @param int|null        $flat_index Flat index. Provide this OR ref.
	 *
	 * @return array|\WP_Error { saved: {...} } on success, WP_Error on failure.
	 */
	public function get_block( $post_id, $ref = null, $flat_index = null ) {
		$has_ref = is_string( $ref ) && '' !== $ref;
		$has_idx = null !== $flat_index && is_numeric( $flat_index );

		if ( $has_ref === $has_idx ) {
			return new \WP_Error(
				'invalid_target',
				__( 'Provide exactly one of ref or flat_index.', 'gk-block-api' ),
				array( 'status' => 400 )
			);
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
		$flat = $this->flatten_blocks( $blocks );

		if ( $has_ref ) {
			$resolved = $this->resolve_ref_to_index( $post_id, $ref );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$flat_idx = (int) $resolved;
		} else {
			$flat_idx = (int) $flat_index;
			if ( $flat_idx < 0 || $flat_idx >= count( $flat ) ) {
				return new \WP_Error(
					'invalid_index',
					__( 'flat_index out of range.', 'gk-block-api' ),
					array( 'status' => 400 )
				);
			}
		}

		$path  = $flat[ $flat_idx ]['path'];
		$block = $this->get_block_by_path( $blocks, $path );
		if ( null === $block || ! is_array( $block ) ) {
			return new \WP_Error(
				'block_not_found',
				__( 'Could not resolve block at the computed path.', 'gk-block-api' ),
				array( 'status' => 404 )
			);
		}

		return array(
			'success' => true,
			'saved'   => $this->format_saved_block( $block, $flat_idx ),
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
				array(
					'status' => 400,
					'ref'    => $ref,
					'path'   => $path,
				)
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
			++$counter;
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
		// Collect every ref already present in the tree first so any
		// freshly-generated ref is guaranteed unique against them. Without
		// this pre-pass the recursive walker could mint a hash that
		// happens to collide with a deeper block's existing ref.
		$in_use = $this->collect_refs( $blocks );
		return $this->assign_missing_refs_walk( $blocks, $in_use );
	}

	/**
	 * Walk a block tree assigning gk_ref to any block that lacks one.
	 *
	 * @param array               $blocks Blocks (passed by reference).
	 * @param array<string, true> $in_use Refs already in use within this tree;
	 *                                    written to as new refs are assigned.
	 *
	 * @return bool True if any block got a new ref.
	 */
	private function assign_missing_refs_walk( &$blocks, array &$in_use ) {
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
				$ref = self::generate_unique_ref( $in_use );
				$blocks[ $i ]['attrs']['metadata']['gk_ref'] = $ref;
				$in_use[ $ref ]                              = true;
				$dirty                                       = true;
			}
			if ( ! empty( $blocks[ $i ]['innerBlocks'] ) && is_array( $blocks[ $i ]['innerBlocks'] ) ) {
				if ( $this->assign_missing_refs_walk( $blocks[ $i ]['innerBlocks'], $in_use ) ) {
					$dirty = true;
				}
			}
		}
		return $dirty;
	}

	/**
	 * Walk a block tree and return the set of gk_refs already assigned.
	 *
	 * @param array $blocks Block tree to scan.
	 *
	 * @return array<string, true>
	 */
	private function collect_refs( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['blockName'] ) ) {
				continue;
			}
			$ref = $block['attrs']['metadata']['gk_ref'] ?? null;
			if ( is_string( $ref ) && '' !== $ref ) {
				$out[ $ref ] = true;
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$out += $this->collect_refs( $block['innerBlocks'] );
			}
		}
		return $out;
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
		$in_use = array();
		$this->assign_fresh_refs_walk( $blocks, $in_use );
	}

	/**
	 * Walk a block tree and overwrite gk_ref on every block with a fresh value.
	 *
	 * @param array               $blocks Block tree (passed by reference).
	 * @param array<string, true> $in_use Refs already minted in this pass.
	 *
	 * @return void
	 */
	private function assign_fresh_refs_walk( &$blocks, array &$in_use ) {
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
			$ref = self::generate_unique_ref( $in_use );
			$blocks[ $i ]['attrs']['metadata']['gk_ref'] = $ref;
			$in_use[ $ref ]                              = true;
			if ( ! empty( $blocks[ $i ]['innerBlocks'] ) && is_array( $blocks[ $i ]['innerBlocks'] ) ) {
				$this->assign_fresh_refs_walk( $blocks[ $i ]['innerBlocks'], $in_use );
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
	 * Whether a block name is dual-storage. Thin delegate to Block_Inventory
	 * so callers (Block_Mutator etc.) have one entry point on the CRUD layer.
	 *
	 * @param string $block_name Fully-qualified block name.
	 *
	 * @return bool
	 */
	public function is_block_dual_storage( $block_name ) {
		return $this->inventory->is_block_dual_storage( $block_name );
	}

	/**
	 * Build the BLOCK-14 dual-storage rejection error.
	 *
	 * @param string $block_name The dual-storage block being mutated.
	 *
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
	 * Flatten a nested block structure into a flat array with path references.
	 *
	 * Each entry contains the block data and a 'path' array indicating how to
	 * traverse the nested structure to reach it.
	 *
	 * Promoted to public so Block_Writer can access it via $this->crud->flatten_blocks().
	 *
	 * @param array $blocks Parsed blocks.
	 * @param array $path   Current path (for recursion).
	 *
	 * @return array Flat list of { block, path }.
	 */
	public function flatten_blocks( $blocks, $path = array() ) {
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
	 * Promoted to public so Block_Writer can access it via $this->crud->get_block_by_path().
	 *
	 * @param array &$blocks Parsed blocks (passed by reference).
	 * @param array $path   Path array from flatten_blocks().
	 *
	 * @return array Reference to the block array.
	 */
	public function &get_block_by_path( &$blocks, $path ) {
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
}
