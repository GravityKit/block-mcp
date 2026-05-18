<?php
/**
 * Block_Reader — read-path operations extracted from Block_CRUD.
 *
 * Handles get_blocks() and format_blocks() along with their private recursive
 * helpers. Delegates ref management and tree utilities back to Block_CRUD.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

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
	 * Site-wide block inventory.
	 *
	 * @var Block_Inventory
	 */
	private $inventory;

	/**
	 * Constructor.
	 *
	 * @param Block_CRUD       $crud        Owning CRUD instance for shared utilities.
	 * @param Preferences      $preferences Preferences instance.
	 * @param Block_Safety     $safety      Block safety checker.
	 * @param HTML_Transformer $transformer HTML transformer.
	 * @param Block_Inventory  $inventory   Block inventory.
	 */
	public function __construct( Block_CRUD $crud, Preferences $preferences, Block_Safety $safety, HTML_Transformer $transformer, Block_Inventory $inventory ) {
		$this->crud        = $crud;
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
					// may have raced in between our parse_blocks() above and
					// our lock acquisition. If they did, their refs are now on
					// disk; assign_missing_refs_recursive() will be a no-op and
					// we won't double-write.
					$fresh = parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
					if ( is_array( $fresh ) && ! empty( $fresh ) ) {
						$blocks = $fresh;
					}
					if ( $this->crud->assign_missing_refs_recursive( $blocks ) ) {
						$this->crud->persist_ref_assignments( $post_id, $blocks );
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
			$original_post   = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- intentional: sets post context for render_block() and do_shortcode(); restored after format.
			setup_postdata( $post );
		}

		$result = $this->crud->format_blocks( $blocks, $render );

		// Restore original post context.
		if ( $render ) {
			$GLOBALS['post'] = $original_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring original post context after render pass.
			if ( $original_post ) {
				setup_postdata( $original_post );
			} else {
				wp_reset_postdata();
			}
		}

		return $result;
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
				++$top_level_counter;
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
					$top_level_counter,
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
}
