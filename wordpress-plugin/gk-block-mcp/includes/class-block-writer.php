<?php
/**
 * Block_Writer — write-path operations extracted from Block_CRUD.
 *
 * Handles all mutations: update, insert, delete, replace, pattern insertion,
 * save, rate limiting, and optimistic-concurrency helpers. Delegates ref
 * management and tree utilities back to Block_CRUD.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Writer
 *
 * Write-path block operations: update, insert, delete, replace, save.
 */
class Block_Writer {

	/**
	 * Block attribute `source` values whose data lives inside the saved DOM.
	 *
	 * An attribute declared with one of these sources is parsed back out of
	 * `innerHTML` at edit time, so the saved markup must be present and must
	 * match what `save()` would emit for the stored attribute value.
	 * Inserts that omit `innerHTML` are rejected with `inner_html_required`
	 * (see `require_inner_html_for_source_bound_attrs`).
	 *
	 * The set is the canonical block.json meta-schema enum
	 * (https://schemas.wp.org/trunk/block.json, mirrored at
	 * tests/fixtures/core-blocks/block-schema.json) minus `meta` — the
	 * only source that doesn't read from the DOM. `children` is kept for
	 * backwards compatibility with third-party blocks that still register
	 * the legacy source value, even though it has been removed from the
	 * trunk schema.
	 *
	 * @var string[]
	 */
	const HTML_SOURCES = array( 'rich-text', 'html', 'children', 'text', 'raw', 'attribute', 'query' );

	/**
	 * Reference back to the owning Block_CRUD instance for shared utilities.
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
	 * Constructor.
	 *
	 * @param Block_CRUD       $crud        Owning CRUD instance for shared utilities.
	 * @param Preferences      $preferences Preferences instance.
	 * @param Block_Safety     $safety      Block safety checker.
	 * @param HTML_Transformer $transformer HTML transformer.
	 */
	public function __construct( Block_CRUD $crud, Preferences $preferences, Block_Safety $safety, HTML_Transformer $transformer ) {
		$this->crud        = $crud;
		$this->preferences = $preferences;
		$this->safety      = $safety;
		$this->transformer = $transformer;
	}

	// -------------------------------------------------------------------------
	// Rate limiting
	// -------------------------------------------------------------------------

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
			$data['writes'] = array_filter(
				$data['writes'],
				function ( $ts ) use ( $window_start ) {
					return $ts >= $window_start;
				}
			);
		}

		if ( isset( $data['puts'] ) ) {
			$data['puts'] = array_filter(
				$data['puts'],
				function ( $ts ) use ( $window_start ) {
					return $ts >= $window_start;
				}
			);
		}

		if ( 'put' === $type ) {
			$put_count = isset( $data['puts'] ) ? count( $data['puts'] ) : 0;
			if ( $put_count >= Block_CRUD::RATE_LIMIT_PUT ) {
				return new \WP_Error(
					'rate_limit_exceeded',
					sprintf(
						/* translators: %d: maximum number of full page rewrites per minute */
						__( 'Full page rewrite rate limit exceeded. Max %d per minute per post.', 'gk-block-mcp' ),
						Block_CRUD::RATE_LIMIT_PUT
					),
					array( 'status' => 429 )
				);
			}
		}

		$write_count = isset( $data['writes'] ) ? count( $data['writes'] ) : 0;
		if ( $write_count >= Block_CRUD::RATE_LIMIT_WRITES ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				sprintf(
					/* translators: %d: maximum number of writes per minute */
					__( 'Write rate limit exceeded. Max %d per minute per post.', 'gk-block-mcp' ),
					Block_CRUD::RATE_LIMIT_WRITES
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

	// -------------------------------------------------------------------------
	// Optimistic concurrency
	// -------------------------------------------------------------------------

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
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
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
				__( 'If-Match must be a revision ID, optionally wrapped in W/"...".', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$expected = (int) $normalized;
		$current  = $this->get_latest_revision_id( $post_id );

		if ( $expected !== $current ) {
			return new \WP_Error(
				'stale_revision',
				__( 'The post has changed since you fetched it. Re-fetch with get_page_blocks and retry.', 'gk-block-mcp' ),
				array(
					'status'            => 412,
					'expected_revision' => $expected,
					'current_revision'  => $current,
				)
			);
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Save helpers
	// -------------------------------------------------------------------------

	/**
	 * Validate, serialize, and save a block tree as the new post_content.
	 *
	 * Single chokepoint for every write that comes from a structured block
	 * array (insert / update / replace / mutate / pattern insert). Calling
	 * `save_post_content()` directly with pre-serialized content bypasses
	 * the depth guard, so always prefer this entry point for any block-
	 * shape input.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $blocks  Block tree in WP-internal shape.
	 *
	 * @return array|\WP_Error
	 */
	public function save_blocks( $post_id, array $blocks ) {
		$depth_check = Block_CRUD::validate_tree_depth( $blocks );
		if ( is_wp_error( $depth_check ) ) {
			return $depth_check;
		}
		return $this->save_post_content( $post_id, serialize_blocks( $blocks ) );
	}

	/**
	 * Save serialized block content to a post, tracking before/after revision IDs.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $new_content Serialized block markup to save.
	 * @return array|\WP_Error
	 */
	public function save_post_content( $post_id, $new_content ) {
		// Block content is encoded by serialize_blocks() / wp_json_encode(), which
		// correctly escapes newlines as \n in block comment JSON. Some
		// content_save_pre filters (notably WPCom_Markdown::preserve_code_blocks)
		// strip those backslashes, corrupting \n → n. Stash and remove all
		// content_save_pre callbacks for the duration of the save; block content
		// needs no further content processing after serialize_blocks().
		global $wp_filter; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: stashing and removing content_save_pre to prevent filter corruption of serialized blocks; restored after save.
		$saved_content_save_pre = isset( $wp_filter['content_save_pre'] ) ? $wp_filter['content_save_pre'] : null;
		if ( $saved_content_save_pre ) {
			remove_all_filters( 'content_save_pre' );
		}

		// Get the current latest revision as the "before" snapshot.
		$before_revision_id = $this->get_latest_revision_id( $post_id );

		try {
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
		} finally {
			// Restore content_save_pre via finally so a downstream throw inside
			// wp_update_post (e.g. a save_post hook handler that errors) doesn't
			// leave the filter stripped for every subsequent save in this
			// request — that would silently corrupt content from other plugins.
			if ( $saved_content_save_pre ) {
				$wp_filter['content_save_pre'] = $saved_content_save_pre; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring previously stashed filter callbacks after save.
			}
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

	// -------------------------------------------------------------------------
	// Block building / validation helpers
	// -------------------------------------------------------------------------

	/**
	 * Build the canonical post-save block snapshot returned to write callers.
	 *
	 * Single source of truth for what the agent sees in the response: the
	 * exact innerHTML and attributes that just landed in post_content (so
	 * after-write reads via this MCP are unnecessary — the response IS the
	 * verification). Includes `is_dynamic` so callers know whether the
	 * stored innerHTML represents the rendered output or just the template
	 * that runs at render time.
	 *
	 * @param array $block      Parsed block array, post-mutation.
	 * @param int   $flat_index Flat index of this block in the post.
	 * @return array Saved snapshot for response payload.
	 */
	public function format_saved_block( $block, $flat_index ) {
		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		$saved = array(
			'flat_index' => (int) $flat_index,
			'block_name' => $block_name,
			'attributes' => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
			'inner_html' => isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '',
			'is_dynamic' => $block_name ? $this->safety->is_dynamic_block( $block_name ) : false,
		);

		if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
			$saved['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
		}

		return $saved;
	}

	/**
	 * Apply attribute merge and/or innerHTML replacement to a single block in
	 * place. Pure mutation — no persistence, no rate limiting, no validation.
	 *
	 * Encapsulates the merge → auto-transform → innerContent reconciliation
	 * pipeline shared by `update_block` and `update_blocks_batch`. Callers are
	 * responsible for dual-storage rejection, rate limiting, and saving.
	 *
	 * @param array       &$block      Block array to mutate in place.
	 * @param array       $attributes  Partial attributes to merge (may be empty).
	 * @param string|null $inner_html  Replacement innerHTML, or null to skip.
	 * @return void
	 */
	public function apply_block_update_in_place( &$block, $attributes, $inner_html ) {
		$block_name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		if ( ! empty( $attributes ) ) {
			$existing_attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();

			// Deep-merge the 'metadata' sub-key so that a partial metadata update
			// (e.g. setting metadata.name) does not clobber existing sub-keys such
			// as metadata.bindings or metadata.gk_ref. All other top-level attrs use
			// a shallow merge (array_merge semantics), which matches prior behaviour.
			if ( isset( $attributes['metadata'] ) && is_array( $attributes['metadata'] ) ) {
				$existing_meta          = isset( $existing_attrs['metadata'] ) && is_array( $existing_attrs['metadata'] )
					? $existing_attrs['metadata']
					: array();
				$attributes['metadata'] = array_merge( $existing_meta, $attributes['metadata'] );
			}

			$block['attrs'] = array_merge( $existing_attrs, $attributes );

			$auto_transformed = $this->transformer->auto_transform_html(
				$block_name,
				$attributes,
				isset( $block['innerHTML'] ) ? $block['innerHTML'] : ''
			);

			if ( null !== $auto_transformed ) {
				$block['innerHTML'] = $auto_transformed;
				if ( ! empty( $block['innerContent'] ) ) {
					$transformer           = $this->transformer;
					$block['innerContent'] = array_map(
						function ( $piece ) use ( $transformer, $block_name, $attributes ) {
							if ( null === $piece ) {
								return null;
							}
							$result = $transformer->auto_transform_html( $block_name, $attributes, $piece );
							return null !== $result ? $result : $piece;
						},
						$block['innerContent']
					);
				} else {
					$block['innerContent'] = array( $auto_transformed );
				}
			} else {
				// No auto-transform — surface the safety check for completeness.
				// Result is currently silent (matches single-update behavior); a
				// future revision can plumb safety_warnings into the response.
				$this->safety->check_mutation( $block_name, array_keys( $attributes ), false );
			}
		}

		if ( null !== $inner_html ) {
			$block['innerHTML'] = $this->transformer->strip_empty_class_attributes( wp_kses_post( $inner_html ) );
			if ( ! empty( $block['innerBlocks'] ) && ! empty( $block['innerContent'] ) ) {
				$block['innerContent'] = $this->transformer->rebuild_inner_content(
					$block['innerContent'],
					$block['innerHTML']
				);
			} else {
				$block['innerContent'] = array( $block['innerHTML'] );
			}
		}
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
		$inner_html = $this->transformer->strip_empty_class_attributes( $inner_html );
		$children   = array();

		$inner_html_required = $this->require_inner_html_for_source_bound_attrs(
			$name,
			is_array( $attrs ) ? $attrs : array(),
			'' !== $inner_html,
			! empty( $block_def['innerBlocks'] )
		);
		if ( is_wp_error( $inner_html_required ) ) {
			return $inner_html_required;
		}

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
	 * Reject inserts that omit innerHTML for blocks whose attribute schema is HTML-sourced.
	 *
	 * WordPress core blocks like core/paragraph and core/heading declare
	 * attributes with `source: rich-text|html|children` and a DOM `selector`.
	 * The save() output is the HTML those attributes are parsed back out of —
	 * the comment payload is a hint, not a substitute. When serialize_blocks()
	 * receives an empty innerHTML and an empty innerContent it emits the
	 * self-closing form (`<!-- wp:paragraph {"content":"…"} /-->`), and on
	 * reload Gutenberg's parser runs the selector against an empty DOM, gets
	 * back "", and disagrees with the persisted attribute payload — hence
	 * "Block contains unexpected or invalid content".
	 *
	 * Rather than scaffolding the missing markup (which would re-implement
	 * each block's JS save() in PHP and rot whenever core changes), this
	 * helper detects the precondition and refuses the insert with a message
	 * that names the offending attribute(s). Callers who legitimately want a
	 * self-closing block (dynamic blocks, or static blocks with no
	 * source-bound attrs in the payload) pass through unchanged.
	 *
	 * @param string $block_name        Block type name.
	 * @param array  $attrs             Attributes from the caller's block def.
	 * @param bool   $has_inner_html    Whether the caller provided a non-empty innerHTML.
	 * @param bool   $has_inner_blocks  Whether the caller provided innerBlocks.
	 *
	 * @return \WP_Error|null WP_Error describing the missing innerHTML, or null to pass.
	 */
	public function require_inner_html_for_source_bound_attrs( $block_name, array $attrs, $has_inner_html, $has_inner_blocks ) {
		if ( $has_inner_html || $has_inner_blocks ) {
			return null;
		}
		if ( empty( $attrs ) ) {
			return null;
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry ) {
			return null;
		}
		$block_type = $registry->get_registered( $block_name );
		if ( ! $block_type ) {
			return null;
		}

		// Note: a render_callback presence does NOT exempt the block. Since
		// WP 6.5 the block bindings system attaches render callbacks to
		// otherwise-static blocks (core/paragraph, core/heading, …) while the
		// editor still uses the save() output for round-trip validation. The
		// only reliable signal is the attribute schema: if any source-bound
		// attribute is set, the editor will compare the parsed DOM against
		// the stored attribute on next load.

		$type_attrs = isset( $block_type->attributes ) && is_array( $block_type->attributes ) ? $block_type->attributes : array();
		if ( empty( $type_attrs ) ) {
			return null;
		}

		$missing = array();
		foreach ( $type_attrs as $attr_name => $attr_def ) {
			if ( ! is_array( $attr_def ) || empty( $attr_def['source'] ) ) {
				continue;
			}
			if ( ! in_array( $attr_def['source'], self::HTML_SOURCES, true ) ) {
				continue;
			}
			if ( ! array_key_exists( $attr_name, $attrs ) ) {
				continue;
			}
			$missing[] = $attr_name;
		}

		if ( empty( $missing ) ) {
			return null;
		}

		$message = sprintf(
			/* translators: 1: block type name (e.g., core/paragraph), 2: comma-separated attribute names */
			__( 'Block "%1$s" stores attribute(s) [%2$s] in HTML markup. Without innerHTML the saved block becomes self-closing and Gutenberg reports "Block contains unexpected or invalid content" on next edit. Include innerHTML that contains the same content (for example, set innerHTML to "<p>…</p>" when sending content on core/paragraph).', 'gk-block-mcp' ),
			$block_name,
			implode( ', ', $missing )
		);

		return new \WP_Error(
			'inner_html_required',
			$message,
			array(
				'status'                  => 400,
				'block'                   => $block_name,
				'source_bound_attributes' => $missing,
			)
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
		$result = array(
			'error'    => null,
			'warnings' => array(),
		);

		// Empty / non-string name used to silently pass through; serialize_blocks
		// then dropped the block entirely (blockName='' produces no output) so the
		// agent's insert appeared to succeed but nothing landed on disk. Reject
		// early with a clear error.
		if ( ! is_string( $block_name ) || '' === $block_name ) {
			$result['error'] = new \WP_Error(
				'invalid_block',
				__( 'Block "name" is required and must be a non-empty string.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
			return $result;
		}

		// Preference tier is namespace-based — it resolves regardless of
		// whether the block is registered on this install. Check it BEFORE
		// the registry lookup so a block whose namespace is configured as
		// legacy surfaces the actionable `legacy_block` error even on sites
		// that never had the source plugin installed. The previous order
		// returned the less informative `invalid_block` in that case.
		$pref = $this->preferences->get_block_score( $block_name );

		if ( 'legacy' === $pref['tier'] ) {
			$replacement     = $this->preferences->get_replacement( $block_name );
			$namespace       = $this->preferences->extract_namespace( $block_name );
			$message_parts   = array(
				sprintf(
					/* translators: 1: rejected namespace, 2: suggested replacement block name */
					__( 'The %1$s/ namespace is configured as legacy on this site; use "%2$s" instead.', 'gk-block-mcp' ),
					$namespace,
					$replacement ? $replacement : __( 'a preferred block', 'gk-block-mcp' )
				),
				__( 'See the block-mcp://agent-guide resource for the full allow/deny list.', 'gk-block-mcp' ),
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

		$registry = \WP_Block_Type_Registry::get_instance();
		if ( $registry && ! $registry->is_registered( $block_name ) ) {
			$result['error'] = new \WP_Error(
				'invalid_block',
				/* translators: %s: block type name (e.g., core/paragraph) */
				sprintf( __( 'Block type "%s" is not registered.', 'gk-block-mcp' ), $block_name ),
				array( 'status' => 400 )
			);
			return $result;
		}

		if ( 'avoid' === $pref['tier'] ) {
			$replacement          = $this->preferences->get_replacement( $block_name );
			$result['warnings'][] = array(
				'block'                 => $block_name,
				'message'               => sprintf(
					/* translators: %s: block namespace prefix (e.g., stackable/) */
					__( '%s blocks are deprecated on this site.', 'gk-block-mcp' ),
					$this->preferences->extract_namespace( $block_name ) . '/'
				),
				'suggested_replacement' => $replacement,
				'policy_resource'       => 'block-mcp://agent-guide',
			);
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

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
	 * @param array $options    Optional flags. Recognised keys:
	 *                          - allow_bound_writes (bool): when true, skip the
	 *                            bound-attribute guard and allow overwrites even for
	 *                            attributes listed in attrs.metadata.bindings.
	 *
	 * @return array|\WP_Error Updated block data with revision_id, or WP_Error.
	 */
	public function update_block( $post_id, $index, $attributes = array(), $inner_html = null, $options = array() ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}
		$flat = $this->crud->flatten_blocks( $blocks );

		if ( $index < 0 || $index >= count( $flat ) ) {
			return new \WP_Error(
				'invalid_index',
				__( 'Block index out of range.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Get reference to the actual block in the nested structure.
		$path  = $flat[ $index ]['path'];
		$block = &$this->crud->get_block_by_path( $blocks, $path );

		// BLOCK-14: refuse innerHTML-only updates on dual-storage blocks.
		// Sending innerHTML alone on yoast/faq-block et al. silently desyncs
		// the structured attributes (questions[], etc.) — see BLOCK-3.
		if (
			null !== $inner_html
			&& empty( $attributes )
			&& isset( $block['blockName'] )
			&& $this->crud->is_block_dual_storage( $block['blockName'] )
		) {
			return $this->crud->dual_storage_error( $block['blockName'] );
		}

		// WP 6.5+ Block Bindings guard: reject writes that attempt to overwrite
		// a dynamically-bound attribute unless the caller explicitly opts in with
		// allow_bound_writes:true. This prevents agents from silently clobbering
		// a value that is resolved at render time from post-meta or another source.
		$allow_bound_writes = ! empty( $options['allow_bound_writes'] );
		if ( ! $allow_bound_writes && ! empty( $attributes ) ) {
			$bindings = isset( $block['attrs']['metadata']['bindings'] ) && is_array( $block['attrs']['metadata']['bindings'] )
				? $block['attrs']['metadata']['bindings']
				: array();

			if ( ! empty( $bindings ) ) {
				$blocked = array();
				foreach ( array_keys( (array) $attributes ) as $attr_key ) {
					// 'metadata' writes are structural; only individual attribute
					// keys within bindings are protected. A write to 'metadata'
					// itself (e.g. updating metadata.name) is always allowed.
					if ( 'metadata' !== $attr_key && isset( $bindings[ $attr_key ] ) ) {
						$blocked[] = $attr_key;
					}
				}
				if ( ! empty( $blocked ) ) {
					return new \WP_Error(
						'bound_attribute',
						sprintf(
							/* translators: 1: comma-separated list of bound attribute names */
							__( 'Cannot overwrite bound attribute(s): %s. These are resolved dynamically from a binding source. Pass allow_bound_writes:true to force the update.', 'gk-block-mcp' ),
							implode( ', ', $blocked )
						),
						array(
							'status'           => 400,
							'bound_attributes' => $blocked,
						)
					);
				}
			}
		}

		// Apply attribute merge + auto-transform + innerHTML replacement.
		$this->apply_block_update_in_place( $block, (array) $attributes, $inner_html );

		// Serialize and save (depth-checked).
		$result = $this->save_blocks( $post_id, $blocks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		// Applies the gk/block-mcp/block/format filter (documented in class-block-reader.php).
		$block_data = apply_filters(
			'gk/block-mcp/block/format',
			array(
				'index'      => $index,
				'name'       => $block['blockName'],
				'attributes' => isset( $block['attrs'] ) ? $block['attrs'] : array(),
			),
			$block['blockName']
		);

		// Surface the stable ref so callers can chain mutations against the
		// same block without re-reading. The TS outputSchema declares this
		// field; without it agents that try to capture ref from update_block
		// for follow-up edits would see undefined.
		if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
			$block_data['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
		}

		return array(
			'success'            => true,
			'block'              => $block_data,
			'saved'              => $this->format_saved_block( $block, $index ),
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
		);
	}

	/**
	 * Apply N independent block updates atomically in a single revision.
	 *
	 * Each item targets ONE block by `ref` (recommended) or `flat_index`, with
	 * `attributes` and/or `innerHTML` to apply. The whole batch validates
	 * up-front: any item-level failure (stale ref, out-of-range index,
	 * dual-storage rejection, duplicate target) aborts the batch with an
	 * itemized `errors` payload — no partial writes ever hit disk.
	 *
	 * On success a single `wp_update_post` call produces ONE WordPress
	 * revision regardless of N, so revision history stays clean. Counts as
	 * one write against `RATE_LIMIT_WRITES`; size is capped at MAX_BATCH_SIZE
	 * to keep the rate-limit exemption from being abused.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $updates List of update items. Each: { ref XOR flat_index,
	 *                       attributes?, innerHTML? }.
	 * @param bool  $verbose When true, each result includes a `saved` snapshot
	 *                       (post-save innerHTML + attributes). Default false to
	 *                       keep batch responses compact — opt in when you want
	 *                       per-item verification without a re-read.
	 * @return array|\WP_Error On success: { success, count, results[],
	 *                       before_revision_id, revision_id }. On validation
	 *                       failure: WP_Error 'batch_validation_failed' (400)
	 *                       with `errors` data array.
	 */
	public function update_blocks_batch( $post_id, $updates, $verbose = false ) {
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		if ( ! is_array( $updates ) || empty( $updates ) ) {
			return new \WP_Error(
				'empty_batch',
				__( 'updates must be a non-empty array.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}
		if ( count( $updates ) > Block_CRUD::MAX_BATCH_SIZE ) {
			return new \WP_Error(
				'batch_too_large',
				sprintf(
					/* translators: 1: actual batch size, 2: maximum batch size */
					__( 'Batch contains %1$d items; maximum is %2$d.', 'gk-block-mcp' ),
					count( $updates ),
					Block_CRUD::MAX_BATCH_SIZE
				),
				array(
					'status'         => 400,
					'max_batch_size' => Block_CRUD::MAX_BATCH_SIZE,
				)
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! is_array( $blocks ) ) {
			$blocks = array();
		}
		$flat       = $this->crud->flatten_blocks( $blocks );
		$flat_count = count( $flat );

		// Phase 1: validate every item; collect resolved targets keyed by path
		// so a `ref` item and a `flat_index` item that point to the SAME block
		// are caught as duplicates (final state would be order-dependent).
		$errors     = array();
		$resolved   = array();
		$seen_paths = array();
		foreach ( $updates as $i => $item ) {
			if ( ! is_array( $item ) ) {
				$errors[] = array(
					'index'   => $i,
					'code'    => 'invalid_item',
					'message' => __( 'Each update must be an object.', 'gk-block-mcp' ),
				);
				continue;
			}

			$attributes = isset( $item['attributes'] ) && is_array( $item['attributes'] )
				? $item['attributes']
				: array();
			$inner_html = array_key_exists( 'innerHTML', $item ) && null !== $item['innerHTML']
				? (string) $item['innerHTML']
				: null;

			if ( empty( $attributes ) && null === $inner_html ) {
				$errors[] = array(
					'index'   => $i,
					'code'    => 'missing_payload',
					'message' => __( 'At least one of attributes or innerHTML is required.', 'gk-block-mcp' ),
				);
				continue;
			}

			$has_ref = isset( $item['ref'] ) && is_string( $item['ref'] ) && '' !== $item['ref'];
			$has_idx = isset( $item['flat_index'] ) && is_numeric( $item['flat_index'] );
			if ( $has_ref === $has_idx ) {
				$errors[] = array(
					'index'   => $i,
					'code'    => 'invalid_target',
					'message' => __( 'Provide exactly one of ref or flat_index.', 'gk-block-mcp' ),
				);
				continue;
			}

			if ( $has_ref ) {
				$resolved_index = $this->crud->resolve_ref_to_index( $post_id, $item['ref'] );
				if ( is_wp_error( $resolved_index ) ) {
					$errors[] = array(
						'index'   => $i,
						'code'    => $resolved_index->get_error_code(),
						'message' => $resolved_index->get_error_message(),
						'ref'     => (string) $item['ref'],
					);
					continue;
				}
				$flat_idx = (int) $resolved_index;
			} else {
				$flat_idx = (int) $item['flat_index'];
				if ( $flat_idx < 0 || $flat_idx >= $flat_count ) {
					$errors[] = array(
						'index'      => $i,
						'code'       => 'invalid_index',
						'message'    => __( 'flat_index out of range.', 'gk-block-mcp' ),
						'flat_index' => $flat_idx,
					);
					continue;
				}
			}

			$path     = $flat[ $flat_idx ]['path'];
			$path_key = implode( '.', array_map( 'intval', $path ) );
			if ( isset( $seen_paths[ $path_key ] ) ) {
				$errors[] = array(
					'index'         => $i,
					'code'          => 'duplicate_target',
					'message'       => sprintf(
						/* translators: %d: index of the earlier item targeting the same block */
						__( 'Duplicate target — same block already updated by item at index %d.', 'gk-block-mcp' ),
						$seen_paths[ $path_key ]
					),
					'first_seen_at' => $seen_paths[ $path_key ],
				);
				continue;
			}
			$seen_paths[ $path_key ] = $i;

			// Dual-storage check: innerHTML-only on dual-storage blocks is
			// rejected, matching single update_block semantics.
			$target_block = $this->crud->get_block_by_path( $blocks, $path );
			if (
				null === $target_block
				|| ! is_array( $target_block )
			) {
				$errors[] = array(
					'index'   => $i,
					'code'    => 'block_not_found',
					'message' => __( 'Could not resolve block at the computed path.', 'gk-block-mcp' ),
				);
				continue;
			}
			if (
				null !== $inner_html
				&& empty( $attributes )
				&& isset( $target_block['blockName'] )
				&& $this->crud->is_block_dual_storage( $target_block['blockName'] )
			) {
				$errors[] = array(
					'index'   => $i,
					'code'    => 'dual_storage_requires_both',
					'message' => sprintf(
						/* translators: %s: block name (e.g., yoast/faq-block) */
						__( 'Block "%s" is dual-storage and requires both attributes and innerHTML.', 'gk-block-mcp' ),
						$target_block['blockName']
					),
					'block'   => (string) $target_block['blockName'],
				);
				continue;
			}

			$resolved[] = array(
				'batch_index' => $i,
				'flat_index'  => $flat_idx,
				'path'        => $path,
				'attributes'  => $attributes,
				'innerHTML'   => $inner_html,
			);
		}

		if ( ! empty( $errors ) ) {
			return new \WP_Error(
				'batch_validation_failed',
				__( 'One or more items failed validation; no changes applied.', 'gk-block-mcp' ),
				array(
					'status' => 400,
					'errors' => $errors,
				)
			);
		}

		// Phase 2: apply every update in memory. Paths stay valid because
		// we don't re-flatten — attribute / innerHTML edits don't change the
		// nested topology.
		$results = array();
		foreach ( $resolved as $r ) {
			$block_ref = &$this->crud->get_block_by_path( $blocks, $r['path'] );
			$this->apply_block_update_in_place( $block_ref, $r['attributes'], $r['innerHTML'] );

			// Applies the gk/block-mcp/block/format filter (documented in class-block-reader.php).
			$block_data = apply_filters(
				'gk/block-mcp/block/format',
				array(
					'index'      => $r['flat_index'],
					'name'       => isset( $block_ref['blockName'] ) ? $block_ref['blockName'] : '',
					'attributes' => isset( $block_ref['attrs'] ) ? $block_ref['attrs'] : array(),
				),
				isset( $block_ref['blockName'] ) ? $block_ref['blockName'] : ''
			);
			if ( isset( $block_ref['attrs']['metadata']['gk_ref'] ) ) {
				$block_data['ref'] = (string) $block_ref['attrs']['metadata']['gk_ref'];
			}
			$result_item = array(
				'batch_index' => $r['batch_index'],
				'block'       => $block_data,
			);
			if ( $verbose ) {
				$result_item['saved'] = $this->format_saved_block( $block_ref, $r['flat_index'] );
			}
			$results[] = $result_item;

			// Break the reference so the next loop iteration doesn't alias
			// the previous block when it rebinds.
			unset( $block_ref );
		}

		// Phase 3: serialize and save. ONE wp_update_post call → ONE revision.
		$save_result = $this->save_blocks( $post_id, $blocks );
		if ( is_wp_error( $save_result ) ) {
			return $save_result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		return array(
			'success'            => true,
			'count'              => count( $results ),
			'results'            => $results,
			'before_revision_id' => $save_result['before_revision_id'],
			'revision_id'        => $save_result['revision_id'],
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

		// Fail fast on over-deep input so the recursive builders below
		// don't walk an adversarial tree before save_blocks() catches it.
		$depth_check = Block_CRUD::validate_tree_depth( is_array( $blocks ) ? $blocks : array() );
		if ( is_wp_error( $depth_check ) ) {
			return $depth_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-mcp' ),
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
		$this->crud->assign_missing_refs_recursive( $new_blocks );

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

		// Serialize and save (depth-checked).
		$result = $this->save_blocks( $post_id, $all_existing_blocks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		// Build inserted response.
		// Re-parse the saved post_content so the path values reflect the post-mutation
		// raw indices (parse_blocks() may collapse adjacent whitespace differently than
		// the in-memory $all_existing_blocks array). This guarantees the returned `path`
		// is immediately usable by mutate_block_tree without an extra get_page_blocks call.
		$inserted   = array();
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
				$entry         = array(
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
			'success'            => true,
			'inserted'           => $inserted,
			'warnings'           => $warnings,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
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
				__( 'Post not found.', 'gk-block-mcp' ),
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
				__( 'Block index out of range.', 'gk-block-mcp' ),
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
						__( 'Removing synced pattern reference "%s" from this page. The pattern itself is not deleted.', 'gk-block-mcp' ),
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

		// Serialize and save (depth-checked).
		$result = $this->save_blocks( $post_id, $all_blocks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		return array(
			'success'            => true,
			'deleted_index'      => $index,
			'deleted_count'      => $count,
			'warnings'           => $warnings,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
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
				__( 'Post not found.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$start = (int) $start;
		$count = max( 0, (int) $count );

		if ( $start < 0 ) {
			return new \WP_Error(
				'invalid_range',
				__( 'range.start must be >= 0.', 'gk-block-mcp' ),
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
					__( 'range.start (%1$d) is past the end of the page (max %2$d).', 'gk-block-mcp' ),
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
		$this->crud->assign_missing_refs_recursive( $new_blocks );

		// Resolve the raw splice range. We splice at the raw index of the
		// first removed block (or end-of-array if start === visible_count),
		// removing `count` raw entries by walking visible_to_raw.
		if ( $start >= $visible_count ) {
			$raw_splice_start = count( $all_existing_blocks );
			$raw_splice_count = 0;
		} else {
			$raw_splice_start = $visible_to_raw[ $start ];
			if ( 0 === $count ) {
				$raw_splice_count = 0;
			} else {
				$last_raw_idx     = ( $start + $count - 1 < $visible_count )
					? $visible_to_raw[ $start + $count - 1 ]
					: $visible_to_raw[ $visible_count - 1 ];
				$raw_splice_count = ( $last_raw_idx - $raw_splice_start ) + 1;
			}
		}

		// Detect synced pattern references being removed — same warning as delete_blocks.
		for ( $i = 0; $i < $raw_splice_count; $i++ ) {
			$raw_idx = $raw_splice_start + $i;
			if ( isset( $all_existing_blocks[ $raw_idx ] ) && 'core/block' === $all_existing_blocks[ $raw_idx ]['blockName'] ) {
				$ref_id     = isset( $all_existing_blocks[ $raw_idx ]['attrs']['ref'] ) ? $all_existing_blocks[ $raw_idx ]['attrs']['ref'] : 0;
				$pattern    = $ref_id ? get_post( $ref_id ) : null;
				$warnings[] = array(
					'message' => sprintf(
						/* translators: %s: pattern name */
						__( 'Removing synced pattern reference "%s" from this page. The pattern itself is not deleted.', 'gk-block-mcp' ),
						$pattern ? $pattern->post_title : '#' . $ref_id
					),
				);
			}
		}

		// Atomic splice — one operation, one save, one revision.
		array_splice( $all_existing_blocks, $raw_splice_start, $raw_splice_count, $new_blocks );

		$result = $this->save_blocks( $post_id, $all_existing_blocks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		// Build inserted refs with the same shape insert_blocks returns
		// (so callers can chain mutate_block_tree without an extra fetch).
		$inserted   = array();
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
				$entry         = array(
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
			'success'            => true,
			'removed'            => $count,
			'inserted'           => $inserted,
			'warnings'           => $warnings,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
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

		// Fail fast on over-deep input so the recursive builders below
		// don't walk an adversarial tree before save_blocks() catches it.
		$depth_check = Block_CRUD::validate_tree_depth( is_array( $blocks ) ? $blocks : array() );
		if ( is_wp_error( $depth_check ) ) {
			return $depth_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$warnings   = array();
		$new_blocks = array();

		foreach ( $blocks as $block_def ) {
			$built = $this->build_block_from_def( $block_def, $warnings );
			if ( is_wp_error( $built ) ) {
				return $built;
			}
			$new_blocks[] = $built;
		}

		// Stable refs for the new block tree (all depths).
		$this->crud->assign_missing_refs_recursive( $new_blocks );

		// Serialize and save (depth-checked).
		$result = $this->save_blocks( $post_id, $new_blocks );

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
			'success'            => true,
			'blocks'             => $block_list,
			'warnings'           => $warnings,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
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
				__( 'Post not found.', 'gk-block-mcp' ),
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
					__( 'Synced pattern not found.', 'gk-block-mcp' ),
					array( 'status' => 404 )
				);
			}

			// Visibility gate. Inserting a draft / private / password-protected
			// pattern would (a) copy its content verbatim under inline mode or
			// (b) embed a core/block ref that anonymous front-end visitors then
			// see when the host post renders. Either way it leaks content the
			// caller might not have rights to see. Shared with the read-side
			// enricher — see Block_CRUD::is_post_readable() for the contract.
			if ( ! Block_CRUD::is_post_readable( $pattern_post ) ) {
				return new \WP_Error(
					'pattern_not_found',
					__( 'Synced pattern not found.', 'gk-block-mcp' ),
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
					__( 'Pattern registry not available.', 'gk-block-mcp' ),
					array( 'status' => 404 )
				);
			}

			$registry = \WP_Block_Patterns_Registry::get_instance();

			if ( ! $registry->is_registered( $pattern_id ) ) {
				return new \WP_Error(
					'pattern_not_found',
					/* translators: %s: pattern name or slug */
					sprintf( __( 'Pattern "%s" not found in registry.', 'gk-block-mcp' ), $pattern_id ),
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

			$result = $this->save_blocks( $post_id, $existing_blocks );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->record_rate_limit( $post_id, 'write' );

			// Return the VISIBLE index (the position among non-whitespace blocks)
			// so the caller can address the new block via the same flat-index
			// vocabulary insert_blocks() and update_block() use. $insert_at is
			// a raw-array index that counts whitespace blocks and would be
			// off-by-N when whitespace surrounds the insertion site.
			return array(
				'success'            => true,
				'inserted'           => array(
					'index'        => $visible_insert,
					'name'         => 'core/block',
					'attributes'   => array( 'ref' => (int) $pattern_id ),
					'pattern_name' => $pattern_name,
					'synced'       => true,
				),
				'before_revision_id' => $result['before_revision_id'],
				'revision_id'        => $result['revision_id'],
			);
		}

		// Inline the pattern's blocks.
		if ( empty( $pattern_content ) ) {
			return new \WP_Error(
				'empty_pattern',
				__( 'Pattern has no block content.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$pattern_blocks = parse_blocks( $pattern_content );
		if ( ! is_array( $pattern_blocks ) ) {
			$pattern_blocks = array();
		}

		// Filter out empty/whitespace-only blocks.
		$pattern_blocks = array_values(
			array_filter(
				$pattern_blocks,
				function ( $block ) {
					return ! empty( $block['blockName'] );
				}
			)
		);

		if ( empty( $pattern_blocks ) ) {
			return new \WP_Error(
				'empty_pattern',
				__( 'Pattern contains no valid blocks.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		// Mint fresh metadata.gk_ref values for every block in the inlined
		// tree. Pattern source blocks may carry the gk_ref values they were
		// last read under; inlining them as-is would create duplicates with
		// any pre-existing block of the same ref on this (or any other) post,
		// causing write-by-ref calls to land on the wrong block.
		$this->crud->assign_fresh_refs_recursive( $pattern_blocks );

		array_splice( $existing_blocks, $insert_at, 0, $pattern_blocks );

		$result = $this->save_blocks( $post_id, $existing_blocks );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		// Visible index mapping mirrors the synced branch above — return
		// indices in the same flat-index vocabulary the rest of the write
		// surface uses, plus the freshly-minted refs so the caller can write
		// back to specific inlined blocks without a round-trip read.
		$inserted = array();
		foreach ( $pattern_blocks as $i => $block ) {
			$entry = array(
				'index' => $visible_insert + $i,
				'name'  => $block['blockName'],
			);
			if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
				$entry['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
			}
			$inserted[] = $entry;
		}

		return array(
			'success'            => true,
			'inserted'           => $inserted,
			'pattern_name'       => $pattern_name,
			'synced'             => false,
			'before_revision_id' => $result['before_revision_id'],
			'revision_id'        => $result['revision_id'],
		);
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
		// Counts as a write — without the rate-limit gate, a caller could
		// bypass the 10-writes-per-minute budget by routing every mutation
		// through revert (rebuild → write → revert → write → revert …)
		// and effectively unrate-limit the post.
		$rate_check = $this->check_rate_limit( $post_id, 'write' );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'post_not_found', __( 'Post not found.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}

		$revision = get_post( $revision_id );
		if ( ! $revision || 'revision' !== $revision->post_type ) {
			return new \WP_Error( 'revision_not_found', __( 'Revision not found.', 'gk-block-mcp' ), array( 'status' => 404 ) );
		}

		// Verify the revision belongs to this post.
		if ( (int) $revision->post_parent !== (int) $post_id ) {
			return new \WP_Error( 'revision_mismatch', __( 'Revision does not belong to this post.', 'gk-block-mcp' ), array( 'status' => 400 ) );
		}

		$result = $this->save_post_content( $post_id, $revision->post_content );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->record_rate_limit( $post_id, 'write' );

		return array(
			'success'              => true,
			'restored_revision_id' => $revision_id,
			'before_revision_id'   => $result['before_revision_id'],
			'revision_id'          => $result['revision_id'],
		);
	}
}
