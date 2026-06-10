<?php
/**
 * Block_Reader — read-path operations extracted from Block_CRUD.
 *
 * Handles get_blocks() and format_blocks() along with their private recursive
 * helpers. Delegates ref management and tree utilities back to Block_CRUD.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Reader
 *
 * Read-only block operations: parse, format, and render block content.
 */
class Block_Reader {

	/**
	 * Per-request parse_blocks() memo.
	 *
	 * Keyed on "{post_id}:{md5(post_content)}". Cleared by invalidate() after
	 * any write to that post so stale results never escape a single request.
	 *
	 * @var array<string, array>
	 */
	private $parse_cache = array();

	/**
	 * Per-instance cache of registered block type attribute schemas.
	 *
	 * Keyed by block name. Populated lazily on first call to
	 * extract_sourced_attributes() for a given block type. Using an instance
	 * property (not a static local) prevents stale entries from leaking across
	 * test cases that register/unregister block types in the same process.
	 *
	 * @var array<string, array>
	 */
	private $block_schema_cache = array();

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
	 * Site-wide block inventory.
	 *
	 * @var Block_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Block_CRUD      $crud        Owning CRUD instance for shared utilities.
	 * @param Preferences     $preferences Preferences instance.
	 * @param Block_Inventory $inventory   Block inventory.
	 */
	public function __construct( Block_CRUD $crud, Preferences $preferences, Block_Inventory $inventory ) {
		$this->crud        = $crud;
		$this->preferences = $preferences;
		$this->inventory   = $inventory;
	}

	/**
	 * Return memoized parse_blocks() output for a post.
	 *
	 * The cache key is "{post_id}:{md5(post_content)}" so a content change
	 * automatically busts the entry even without an explicit invalidate() call.
	 * Callers on the write path should still call invalidate() after a save so
	 * the next read re-fetches the freshly-saved content from the DB rather than
	 * a stale WP post object that may be cached in the object-cache.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return array Parsed blocks (same shape as parse_blocks() — may include
	 *               empty/whitespace entries). Returns empty array for missing
	 *               posts or non-string post_content.
	 */
	public function parse( $post_id ) {
		// Normalize once. The cache key MUST match what invalidate() builds
		// (always `(int)$post_id . ':'`) and what get_blocks() uses below —
		// otherwise a caller passing "42abc" would create a stale entry
		// keyed on "42abc:" that invalidate(42) never reaches, and the next
		// write would not bust it.
		$post_id = (int) $post_id;
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$content = $post->post_content;
		if ( ! is_string( $content ) ) {
			return array();
		}

		$key = $post_id . ':' . md5( $content );

		if ( ! isset( $this->parse_cache[ $key ] ) ) {
			$parsed                    = parse_blocks( $content );
			$this->parse_cache[ $key ] = is_array( $parsed ) ? $parsed : array();
		}

		return $this->parse_cache[ $key ];
	}

	/**
	 * Invalidate the parse cache for a specific post.
	 *
	 * Must be called by the write path (Block_Writer::save_blocks /
	 * save_post_content) after a successful save so that the next read in the
	 * same request sees the newly-persisted content.
	 *
	 * @param int $post_id Post ID whose cache entries to remove.
	 *
	 * @return void
	 */
	public function invalidate( $post_id ) {
		// Normalize to int to match the cache key built by parse() and
		// get_blocks() — passing a non-int $post_id here would otherwise
		// build a prefix that doesn't match the actual entries.
		$prefix = (int) $post_id . ':';
		foreach ( array_keys( $this->parse_cache ) as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				unset( $this->parse_cache[ $key ] );
			}
		}
	}

	/**
	 * Get all blocks for a post.
	 *
	 * Always uses parse_blocks() to ensure index consistency with write operations.
	 *
	 * @param int  $post_id      Post ID.
	 * @param bool $render       Whether to render dynamic blocks and expand shortcodes.
	 * @param bool $persist_refs Whether to persist gk_ref assignments to post_content.
	 *
	 * @return array|\WP_Error Array of block data or WP_Error.
	 */
	public function get_blocks( $post_id, $render = false, $persist_refs = true ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_Error(
				'post_not_found',
				__( 'Post not found.', 'gk-block-mcp' ),
				array( 'status' => 404 )
			);
		}

		$content = $post->post_content;

		if ( empty( $content ) || ! is_string( $content ) ) {
			return array();
		}

		// Track render-mode post-context so we can restore it in the finally block
		// even if a downstream collaborator (filter callback, ref-assigner, formatter)
		// throws. Without try/finally a thrown read would leave $GLOBALS['post']
		// pointing at the wrong post for the rest of the request.
		$original_post = null;
		$context_set   = false;

		try {
			// Work on the cached entry directly via reference so that
			// assign_missing_refs_recursive() writes its in-memory ref assignments
			// back into the cache. Any subsequent parse() call within the same request
			// will then return the already-assigned tree instead of a fresh copy
			// with no refs, ensuring ref stability across multiple reads without
			// requiring a DB write.
			$cache_key = (int) $post_id . ':' . md5( $content );
			if ( ! isset( $this->parse_cache[ $cache_key ] ) ) {
				$parsed                          = parse_blocks( $content );
				$this->parse_cache[ $cache_key ] = is_array( $parsed ) ? $parsed : array();
			}
			$blocks = &$this->parse_cache[ $cache_key ];

			if ( ! is_array( $blocks ) ) {
				$blocks = array();
			}

			// Lazy-assign block refs. When $persist_refs is true (default), any blocks
			// missing attrs.metadata.gk_ref get a fresh one and the post is updated
			// silently (no revision) so the refs survive across reads/writes.
			// When false, refs are still surfaced in-memory via the cache reference above
			// so a second read in the same request sees the same ephemeral refs.
			$dirty = $this->crud->assign_missing_refs_recursive( $blocks );
			if ( $dirty && $persist_refs ) {
				// Concurrency guard. Two readers landing on a fresh post could both
				// generate random refs and both call persist_ref_assignments(); the
				// second writer would silently win, leaving the first reader's
				// response with refs that no longer match disk. wp_cache_add() is
				// atomic on persistent object caches (Memcached/Redis), so use it
				// as a short-lived per-post lock around the assign-and-persist.
				$lock_key = 'gk_block_api_ref_lock_' . (int) $post_id;
				$got_lock = wp_cache_add( $lock_key, 1, 'gk_block_api', 5 );

				if ( $got_lock ) {
					try {
						// Re-parse current content under the lock — another writer
						// may have raced in between our parse() call above and our
						// lock acquisition. If they did, their refs are now on disk;
						// assign_missing_refs_recursive() will be a no-op and we
						// won't double-write. Bypass the memo here so we read the
						// authoritative post_content directly from the DB.
						$fresh_content = (string) get_post_field( 'post_content', $post_id );
						$fresh         = parse_blocks( $fresh_content );
						if ( is_array( $fresh ) && ! empty( $fresh ) ) {
							$blocks = $fresh;
							// Update the cache with the direct-from-DB content.
							$this->invalidate( $post_id );
							$this->parse_cache[ $post_id . ':' . md5( $fresh_content ) ] = $blocks;
						}
						if ( $this->crud->assign_missing_refs_recursive( $blocks ) ) {
							$persisted = $this->crud->persist_ref_assignments( $post_id, $blocks );
							if ( $persisted ) {
								// Persist succeeded — re-warm cache from authoritative DB content.
								$this->invalidate( $post_id );
								$new_content = (string) get_post_field( 'post_content', $post_id );
								$this->parse_cache[ $post_id . ':' . md5( $new_content ) ] = $blocks;
							} else {
								// Persist failed (read-only DB, replica lag, broken column). Refs
								// exist only in $blocks, not on disk. Caching them would surface
								// stable-looking refs in the response that the next write-by-ref
								// would reject as ref_stale. Drop the cache for this post so the
								// next read re-parses disk truth and tries again.
								$this->invalidate( $post_id );
							}
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
					$fresh_content = (string) get_post_field( 'post_content', $post_id );
					$fresh         = parse_blocks( $fresh_content );
					if ( is_array( $fresh ) && ! empty( $fresh ) ) {
						$blocks = $fresh;
						// Update the cache with the direct-from-DB content.
						$this->invalidate( $post_id );
						$this->parse_cache[ $post_id . ':' . md5( $fresh_content ) ] = $blocks;
					}
				}
			}

			// Set up post context so shortcodes and render_block() can
			// access the current post (needed for product-specific shortcodes
			// like [filter_edd_version_number], [filter_product_star_rating], etc.).
			if ( $render ) {
				$original_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
				$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: sets post context for render_block() and do_shortcode(); restored in the finally block below.
				setup_postdata( $post );
				$context_set = true;
			}

			return $this->crud->format_blocks( $blocks, $render );
		} catch ( \Throwable $e ) {
			$data = array( 'status' => 500 );

			// Production responses carry only "Error parsing post ID N." so
			// internals (class names, file paths, type errors) don't reach an
			// unauthenticated REST caller. The full exception trace is written
			// to error_log; WP_DEBUG also attaches the trace and the original
			// message to the WP_Error data for local debugging.
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Block MCP parse error for post ' . (int) $post_id . ': ' . $e->__toString() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$data['details'] = $e->__toString();
				$message         = sprintf(
					/* translators: %1$d: post ID, %2$s: exception message. */
					__( 'Error parsing post ID %1$d: %2$s', 'gk-block-mcp' ),
					(int) $post_id,
					$e->getMessage()
				);
			} else {
				$message = sprintf(
					/* translators: %d: post ID. */
					__( 'Error parsing post ID %d.', 'gk-block-mcp' ),
					(int) $post_id
				);
			}

			return new \WP_Error(
				'parse_error',
				$message,
				$data
			);
		} finally {
			// Restore render-mode post context whether the body returned or threw.
			if ( $context_set ) {
				$GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring original post context after render pass.
				if ( $original_post ) {
					setup_postdata( $original_post );
				} else {
					wp_reset_postdata();
				}
			}
		}
	}

	/**
	 * Format parsed blocks into a structured response array.
	 *
	 * Includes both `index` (flat sequential counter for backwards compatibility)
	 * and `path` (array of raw indices for the mutation tool).
	 *
	 * @param array $blocks Parsed blocks from parse_blocks().
	 * @param bool  $render Whether to render dynamic blocks and expand shortcodes.
	 *
	 * @return array Formatted block data.
	 */
	public function format_blocks( $blocks, $render = false ) {
		$counter           = 0;
		$top_level_counter = 0;
		return $this->format_blocks_recursive( $blocks, array(), $counter, $top_level_counter, $render );
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

			$parsed_attrs = isset( $block['attrs'] ) ? $block['attrs'] : array();
			$inner_html   = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';

			// Merge schema-sourced attributes extracted from innerHTML into the
			// attribute map. Delimiter-defined attrs take precedence (round-trip
			// stability). The underlying parse_blocks() output is NOT mutated —
			// this is a read-only enrichment for the formatted response only.
			$merged_attrs = $this->extract_sourced_attributes( $block['blockName'], $parsed_attrs, $inner_html );

			$data = array(
				'index'      => $counter,
				'path'       => $current_path,
				'name'       => $block['blockName'],
				'attributes' => $merged_attrs,
			);

			// Surface the stable ref (from attrs.metadata.gk_ref). Refs let agents
			// re-address the same block after sibling shifts without re-fetching.
			if ( isset( $block['attrs']['metadata']['gk_ref'] ) ) {
				$data['ref'] = (string) $block['attrs']['metadata']['gk_ref'];
			}

			// WP 6.5+ Block Bindings: surface bindings and bound_attributes so agents
			// know which attributes are dynamically resolved at render time. We do NOT
			// resolve the bound values here (that's render mode's job). Agents must not
			// overwrite bound attributes without explicit allow_bound_writes:true.
			if ( isset( $block['attrs']['metadata']['bindings'] ) && is_array( $block['attrs']['metadata']['bindings'] ) ) {
				$bindings = $block['attrs']['metadata']['bindings'];
				if ( ! empty( $bindings ) ) {
					$data['bindings']         = $bindings;
					$data['bound_attributes'] = array_keys( $bindings );
				}
			}

			// Top-level counter: sequential position among non-empty top-level blocks only.
			// This is the value consumed by `delete_block.block_index`,
			// `insert_blocks.before`/`after`, and the new atomic `replace_blocks` op.
			// Only set on depth-0 blocks; inner blocks intentionally omit it.
			if ( empty( $parent_path ) ) {
				$data['top_level_counter'] = $top_level_counter;
				++$top_level_counter;
			}

			// Surface section name from block metadata for easy scanning.
			if ( isset( $block['attrs']['metadata']['name'] ) && ! empty( $block['attrs']['metadata']['name'] ) ) {
				$data['section'] = $block['attrs']['metadata']['name'];
			}

			// Synced-pattern (core/block) `pattern_ref` expansion lives in the
			// Core_Block_Enricher at includes/block-enrichers/class-core-block-enricher.php
			// and fires via the gk/block-mcp/block/format filter below. The enricher
			// receives the parsed block + this Reader instance through filter context
			// so it can recursively format the pattern's own block tree under render
			// mode without touching this loop.

			// Mark blocks as dynamic or static (cached per block name).
			static $dynamic_cache = array();

			if ( ! isset( $dynamic_cache[ $block['blockName'] ] ) ) {
				$registry                             = \WP_Block_Type_Registry::get_instance();
				$block_type                           = $registry ? $registry->get_registered( $block['blockName'] ) : null;
				$dynamic_cache[ $block['blockName'] ] = $block_type ? $block_type->is_dynamic() : false;
			}
			$is_dynamic      = $dynamic_cache[ $block['blockName'] ];
			$data['dynamic'] = $is_dynamic;

			// storage_mode disambiguates the existing `dynamic` flag for AI consumers:
			// - "static": innerHTML is the source of truth (most core/* blocks).
			// - "dynamic": attributes is the source of truth; innerHTML is regenerated on render.
			// - "dual": both attributes AND innerHTML carry the same data and must be kept in sync.
			// (e.g., yoast/faq-block — sending innerHTML alone corrupts attributes.questions).
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
				$replacement        = $this->preferences->get_replacement( $block['blockName'] );
				if ( $replacement ) {
					$data['preference']['suggested_replacement'] = $replacement;
				}
			}

			++$counter;

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
						$text                  = wp_strip_all_tags( $rendered );
						$text                  = preg_replace( '/\s+/', ' ', trim( $text ) );
						if ( ! empty( $text ) ) {
							$data['rendered_text'] = mb_substr( $text, 0, 500 );
						}
					}
				} catch ( \Throwable $e ) {
					// Render failed — skip silently, leaving a breadcrumb when debugging.
					if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
						error_log( 'Block MCP render_block error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && count( $current_path ) < Block_CRUD::MAX_BLOCK_DEPTH ) {
				// Depth guard. Block_CRUD::MAX_BLOCK_DEPTH caps writes, but
				// pre-cap content (or anything bypassing the write path —
				// direct DB inserts, imports) could in theory be deeper. Recursing
				// past MAX_BLOCK_DEPTH would stack-overflow the read path before
				// the formatter could return. Truncate innerBlocks at the cap;
				// the tree above remains visible, the agent sees a flag-free
				// drop at the boundary. Same constant the write side enforces.
				$data['innerBlocks'] = $this->format_blocks_recursive(
					$block['innerBlocks'],
					$current_path,
					$counter,
					$top_level_counter,
					$render
				);
			}

			/**
			 * Shape what the AI sees for any block before it's returned.
			 *
			 * This is the single most powerful extension point for tailoring how
			 * the assistant perceives your content. Strip noisy computed fields so
			 * the agent isn't drowned in markup, or enrich a block with the
			 * context it actually needs to edit well — an image's dimensions, a
			 * custom block's friendly label, a product block's price. It runs for
			 * every block on read, so a tiny enricher can make a whole block type
			 * far easier for the AI to reason about and safely modify.
			 *
			 * The same filter also fires on the write path (in Block_Writer) so a
			 * single enricher keeps reads and write-responses consistent; the
			 * write path passes the block name but not the read-time context array.
			 *
			 * @since 2.0.0
			 *
			 * @example
			 * // Drop heavy rendered HTML from a custom block so the AI sees only its attributes.
			 * add_filter( 'gk/block-mcp/block/format', function ( $data, $block_name ) {
			 *     if ( 'acme/chart' === $block_name ) {
			 *         unset( $data['innerHTML'] );
			 *     }
			 *     return $data;
			 * }, 10, 2 );
			 *
			 * @param array  $data       Formatted block data about to be returned.
			 * @param string $block_name Fully-qualified block type name.
			 * @param array  $context    {
			 *     Additional context for enrichers that need it (read path only).
			 *
			 *     @type array  $parsed_block Raw parse_blocks() entry for this block.
			 *     @type bool   $render       Whether render-mode is active.
			 *     @type object $reader       This Block_Reader instance (enables
			 *                                recursive formatting of nested trees,
			 *                                e.g. synced pattern contents).
			 * }
			 */
			$data = apply_filters(
				'gk/block-mcp/block/format',
				$data,
				$block['blockName'],
				array(
					'parsed_block' => $block,
					'render'       => $render,
					'reader'       => $this,
				)
			);

			$formatted[] = $data;
		}

		return $formatted;
	}

	/**
	 * Merge schema-sourced attributes from innerHTML into the parsed attrs map.
	 *
	 * WordPress stores some block attributes inline in HTML rather than in the
	 * JSON comment delimiter. parse_blocks() only surfaces delimiter attrs.
	 * This method reads the registered block type's attribute schema and
	 * extracts HTML-sourced values so agents see the full attribute set.
	 *
	 * Supported sources:
	 *   - 'attribute' + selector + attribute  → DOM attribute value
	 *   - 'html'      + selector              → inner HTML of matched element
	 *   - 'rich-text' + selector              → same as 'html'
	 *   - 'text'      + selector              → text content (tags stripped)
	 *   - 'query'     → skipped (TODO: array-of-objects — complex, deferred)
	 *   - 'meta'      → skipped (deprecated, returns nothing)
	 *
	 * Delimiter-defined values always win — HTML extraction only fills in attrs
	 * that are absent from the JSON comment, preserving round-trip stability.
	 *
	 * @param string $block_name   Fully-qualified block type name.
	 * @param array  $parsed_attrs Attributes already decoded from the comment delimiter.
	 * @param string $inner_html   The block's innerHTML from parse_blocks().
	 *
	 * @return array Merged attribute map (parsed_attrs + any extracted sourced values).
	 */
	private function extract_sourced_attributes( $block_name, array $parsed_attrs, $inner_html ) {
		if ( '' === $block_name || empty( $inner_html ) ) {
			return $parsed_attrs;
		}

		// Per-instance cache for block type attribute schemas — registry lookup
		// can be non-trivial in large environments; no need to repeat it per block.
		// Instance property (not static local) so test cases that register/
		// unregister block types don't see stale cache entries from prior tests.
		if ( ! isset( $this->block_schema_cache[ $block_name ] ) ) {
			$registry = \WP_Block_Type_Registry::get_instance();
			if ( ! $registry ) {
				return $parsed_attrs;
			}
			$block_type                              = $registry->get_registered( $block_name );
			$this->block_schema_cache[ $block_name ] = ( $block_type && ! empty( $block_type->attributes ) )
				? $block_type->attributes
				: array();
		}

		$schema = $this->block_schema_cache[ $block_name ];
		if ( empty( $schema ) ) {
			return $parsed_attrs;
		}

		$extracted = array();

		foreach ( $schema as $attr_name => $attr_def ) {
			// Delimiter value already present — delimiter wins, skip DOM extraction.
			if ( array_key_exists( $attr_name, $parsed_attrs ) ) {
				continue;
			}

			$value = $this->source_attribute_value( $attr_def, $inner_html );
			if ( null !== $value ) {
				$extracted[ $attr_name ] = $value;
			}
		}

		if ( empty( $extracted ) ) {
			return $parsed_attrs;
		}

		// Delimiter-parsed attrs win — merge extracted underneath.
		return array_merge( $extracted, $parsed_attrs );
	}

	/**
	 * Dispatch a single attribute definition to the matching per-source resolver.
	 *
	 * One method per source.json source type, mirroring Automattic's
	 * vip-block-data-api content-parser layout. The dispatcher is the
	 * single open-closed seam — adding a new source means adding one method
	 * here and one case below.
	 *
	 * Supported sources: `attribute`, `html`, `rich-text`, `text`. Unsupported
	 * (`query`, `meta`, `node`, `children`, `raw`, `tag`) return null so the
	 * caller falls back to delimiter-only attrs. See README.md → Limitations
	 * for what's intentionally unsupported in v1.
	 *
	 * @param array  $attr_def   Block-attribute definition from block.json.
	 * @param string $inner_html Block's innerHTML for DOM extraction.
	 *
	 * @return string|null Extracted value, or null if the source / selector
	 *                     doesn't match or isn't supported.
	 */
	private function source_attribute_value( array $attr_def, $inner_html ) {
		$source = isset( $attr_def['source'] ) ? $attr_def['source'] : '';

		switch ( $source ) {
			case 'attribute':
				return $this->source_attribute( $attr_def, $inner_html );
			case 'html':
				return $this->source_html( $attr_def, $inner_html );
			case 'rich-text':
				return $this->source_rich_text( $attr_def, $inner_html );
			case 'text':
				return $this->source_text( $attr_def, $inner_html );
			default:
				// 'query', 'meta', 'node', 'children', 'raw', 'tag' — not yet
				// supported; the WP_HTML_Tag_Processor backend can't do real
				// CSS selectors or array-of-objects sourcing. See README.md
				// → Limitations.
				return null;
		}
	}

	/**
	 * Resolve a `source: attribute` definition — reads a DOM attribute value.
	 *
	 * @param array  $attr_def   Block-attribute definition. Requires `selector`
	 *                           and `attribute` keys to be useful.
	 * @param string $inner_html Block's innerHTML.
	 *
	 * @return string|null Attribute value, or null if missing.
	 */
	private function source_attribute( array $attr_def, $inner_html ) {
		$selector = isset( $attr_def['selector'] ) ? $attr_def['selector'] : '';
		if ( '' === $selector || ! isset( $attr_def['attribute'] ) ) {
			return null;
		}
		return $this->extract_dom_attribute( $inner_html, $selector, $attr_def['attribute'] );
	}

	/**
	 * Resolve a `source: html` definition — returns the inner HTML of the
	 * first matching element.
	 *
	 * @param array  $attr_def   Block-attribute definition. Requires `selector`.
	 * @param string $inner_html Block's innerHTML.
	 *
	 * @return string|null Inner HTML, or null if the selector doesn't match.
	 */
	private function source_html( array $attr_def, $inner_html ) {
		$selector = isset( $attr_def['selector'] ) ? $attr_def['selector'] : '';
		if ( '' === $selector ) {
			return null;
		}
		return $this->extract_inner_html( $inner_html, $selector );
	}

	/**
	 * Resolve a `source: rich-text` definition — returns the inner HTML of the
	 * first matching element. Kept distinct from source_html so future
	 * divergence (e.g. RichText-format normalization) lands in one place.
	 *
	 * @param array  $attr_def   Block-attribute definition. Requires `selector`.
	 * @param string $inner_html Block's innerHTML.
	 *
	 * @return string|null Inner HTML, or null if the selector doesn't match.
	 */
	private function source_rich_text( array $attr_def, $inner_html ) {
		$selector = isset( $attr_def['selector'] ) ? $attr_def['selector'] : '';
		if ( '' === $selector ) {
			return null;
		}
		return $this->extract_inner_html( $inner_html, $selector );
	}

	/**
	 * Resolve a `source: text` definition — returns the matched element's
	 * inner content stripped of all HTML tags.
	 *
	 * @param array  $attr_def   Block-attribute definition. Requires `selector`.
	 * @param string $inner_html Block's innerHTML.
	 *
	 * @return string|null Plain text, or null if the selector doesn't match.
	 */
	private function source_text( array $attr_def, $inner_html ) {
		$selector = isset( $attr_def['selector'] ) ? $attr_def['selector'] : '';
		if ( '' === $selector ) {
			return null;
		}
		$html = $this->extract_inner_html( $inner_html, $selector );
		if ( null === $html ) {
			return null;
		}
		return wp_strip_all_tags( $html );
	}

	/**
	 * Extract a DOM attribute value from the first element matching a CSS
	 * tag-name selector using WP_HTML_Tag_Processor.
	 *
	 * Only simple tag-name selectors (e.g. 'a', 'img') and comma-separated
	 * tag-name lists are supported. Class/ID/attribute selectors are skipped.
	 *
	 * @param string $html      The HTML to search.
	 * @param string $selector  CSS selector string (tag name(s), comma-separated).
	 * @param string $attribute DOM attribute name to read (e.g. 'href', 'alt').
	 *
	 * @return string|null Attribute value, or null if not found.
	 */
	private function extract_dom_attribute( $html, $selector, $attribute ) {
		$tags = $this->selector_to_tag_names( $selector );
		if ( empty( $tags ) ) {
			return null;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag_name = strtolower( $processor->get_tag() );
			if ( in_array( $tag_name, $tags, true ) ) {
				$value = $processor->get_attribute( $attribute );
				if ( null !== $value ) {
					// get_attribute() returns the unescaped value — correct for JSON consumers.
					return (string) $value;
				}
			}
		}
		return null;
	}

	/**
	 * Extract the inner HTML of the first element matching a tag-name selector.
	 *
	 * WP_HTML_Tag_Processor cannot extract innerHTML directly, so this uses a
	 * targeted regex to pull out the content between the matched opening and
	 * closing tags. Only simple single or comma-separated tag names are supported.
	 *
	 * @param string $html     The HTML to search.
	 * @param string $selector CSS selector string (tag name(s), comma-separated).
	 *
	 * @return string|null Inner HTML of matched element, or null if not found.
	 */
	private function extract_inner_html( $html, $selector ) {
		$tags = $this->selector_to_tag_names( $selector );
		if ( empty( $tags ) ) {
			return null;
		}

		foreach ( $tags as $tag ) {
			// Regex: match opening tag (with optional attributes) then capture
			// everything until the corresponding closing tag. Non-greedy.
			// Flags: s (DOTALL) so . matches newlines.
			$pattern = '/<' . preg_quote( $tag, '/' ) . '(?:\s[^>]*)?>(.+?)<\/' . preg_quote( $tag, '/' ) . '>/is';
			if ( preg_match( $pattern, $html, $matches ) ) {
				return $matches[1];
			}
		}
		return null;
	}

	/**
	 * Convert a CSS selector string to an array of lowercase tag names.
	 *
	 * Only plain tag names and comma-separated lists thereof are handled.
	 * Selectors with class (.), ID (#), attribute ([]), pseudo (:), or
	 * combinators ( >) are skipped entirely — return empty array.
	 *
	 * @param string $selector CSS selector string, e.g. 'h1,h2,h3' or 'a'.
	 *
	 * @return string[] Lowercase tag names, or empty array if selector is too complex.
	 */
	private function selector_to_tag_names( $selector ) {
		if ( '' === $selector ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', $selector ) );
		$tags  = array();

		foreach ( $parts as $part ) {
			// Accept only pure tag names: letters, digits, hyphens (custom elements).
			if ( ! preg_match( '/^[a-zA-Z][a-zA-Z0-9-]*$/', $part ) ) {
				// Complex selector — skip entire list to stay safe.
				return array();
			}
			$tags[] = strtolower( $part );
		}

		return $tags;
	}
}
