<?php
/**
 * Site-wide block and pattern inventory.
 *
 * Scans all published posts/pages to gather block usage counts, namespace
 * totals, pattern references, and non-preferred pattern detection. Future
 * home for storage-mode auto-discovery and other "what blocks live on this
 * site, and what do we know about them" intelligence.
 *
 * Renamed from Usage_Stats — the original name implied dashboard-style
 * numeric rollups, but this class actually owns broader inventory data.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Block_Inventory
 *
 * Cached site-wide block + pattern inventory.
 */
class Block_Inventory {

	/**
	 * Transient key for the cached inventory.
	 *
	 * @var string
	 */
	const CACHE_KEY = 'gk_block_inventory';

	/**
	 * Cache TTL in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Storage-mode constants. Use these instead of the bare strings so
	 * a typo doesn't silently mis-classify a block. Surface in API
	 * responses as the underlying string value.
	 */
	const STORAGE_MODE_STATIC  = 'static';
	const STORAGE_MODE_DYNAMIC = 'dynamic';
	const STORAGE_MODE_DUAL    = 'dual';

	/**
	 * Option key for the BLOCK-13 storage-mode scan results.
	 *
	 * Persists a `block_name => storage_mode` map produced by walking the
	 * site once and applying the dynamic+innerHTML heuristic. Read first
	 * by `is_block_dual_storage()` so the live classification beats the
	 * filter defaults.
	 */
	const STORAGE_MODES_OPTION = 'gk_block_api_storage_modes';

	/** Last-run timestamp for the storage-mode scan (used for rate limiting). */
	const STORAGE_SCAN_LAST_RUN_OPTION = 'gk_block_api_storage_modes_last_run';

	/**
	 * Hard chunk size for site-wide post scans. Picked to keep peak memory
	 * predictable on shared hosting (~256MB) regardless of avg post size.
	 */
	const SCAN_BATCH_SIZE = 200;

	/** Minimum interval between full storage-mode scans (seconds). */
	const STORAGE_SCAN_MIN_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Minimum interval between manual get_stats(refresh=true) calls.
	 * Matches CACHE_TTL so a refresh can never run more often than the
	 * cache would naturally expire — every refresh is bounded amplification.
	 */
	const REFRESH_MIN_INTERVAL = HOUR_IN_SECONDS;

	/** Option key tracking the last get_stats refresh timestamp. */
	const REFRESH_LAST_RUN_OPTION = 'gk_block_api_stats_refresh_last';

	// ──────────────────────────────────────────────────────────────────
	// Storage-mode classification.
	//
	// "What kind of block is this?" — static / dynamic / dual. Lives here
	// (not in Block_CRUD) because it's inventory metadata about block
	// types, not a write-time concern. Block_CRUD consults this layer
	// when annotating get_page_blocks responses and when enforcing the
	// "dual blocks need both fields" rule (BLOCK-14).
	//
	// BLOCK-13 will extend this with site-scan auto-discovery that
	// persists findings to `wp_options.gk_block_api_storage_modes`.
	// ──────────────────────────────────────────────────────────────────

	/**
	 * Resolve the storage mode for a block: "static" | "dynamic" | "dual".
	 *
	 * - "static": innerHTML is the source of truth (most core/* blocks).
	 * - "dynamic": attributes is the source of truth; innerHTML is
	 *   regenerated on render (e.g., core/post-title, query loops).
	 * - "dual": BOTH attributes and innerHTML carry the same data and
	 *   must be kept in sync (e.g., yoast/faq-block.questions[]).
	 *
	 * Site admins can extend the dual-storage list via the
	 * `gk_block_api_dual_storage_blocks` filter.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @param bool   $is_dynamic Whether the registered block is server-rendered.
	 * @return string
	 */
	public function resolve_storage_mode( $block_name, $is_dynamic ) {
		if ( $this->is_block_dual_storage( $block_name ) ) {
			return self::STORAGE_MODE_DUAL;
		}
		return $is_dynamic ? self::STORAGE_MODE_DYNAMIC : self::STORAGE_MODE_STATIC;
	}

	/**
	 * Whether a block name is configured (or auto-discovered) as dual-storage.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public function is_block_dual_storage( $block_name ) {
		// 1. Authoritative: if a site-scan has run (BLOCK-13), trust it.
		$scanned = get_option( self::STORAGE_MODES_OPTION, array() );
		if ( is_array( $scanned ) && isset( $scanned[ $block_name ] ) ) {
			return self::STORAGE_MODE_DUAL === $scanned[ $block_name ];
		}

		// 2. Fallback: filterable static defaults. Cached per-request.
		static $cache = null;
		if ( null === $cache ) {
			/**
			 * Filter the list of block names treated as dual-storage.
			 *
			 * Dual-storage blocks store the same content in both
			 * `attributes` and `innerHTML` and require both to be kept
			 * in sync. Used when the BLOCK-13 site-scan has not run yet.
			 *
			 * @param string[] $dual_blocks Block names considered dual-storage.
			 */
			$dual_blocks = (array) apply_filters(
				'gk_block_api_dual_storage_blocks',
				array(
					'yoast/faq-block',
					'yoast/how-to-block',
				)
			);
			$cache       = array_flip( $dual_blocks );
		}
		return isset( $cache[ $block_name ] );
	}

	/**
	 * Scan all published content and classify every distinct block name
	 * as static, dynamic, or dual.
	 *
	 * Heuristic (no JS validateBlock available server-side):
	 *   - is_dynamic === true  AND innerHTML non-empty → DUAL (pre-rendered)
	 *   - is_dynamic === true  AND innerHTML empty     → DYNAMIC
	 *   - is_dynamic === false AND innerHTML present   → STATIC
	 *   - is_dynamic === false AND innerHTML empty     → STATIC (placeholder)
	 *
	 * Persists results to `wp_options.gk_block_api_storage_modes` so
	 * subsequent calls to `is_block_dual_storage()` use the live data
	 * instead of the filter defaults.
	 *
	 * @return array {
	 *     @type int   $scanned_posts Number of posts walked.
	 *     @type int   $unique_blocks Count of distinct block names found.
	 *     @type array $classification block_name => storage_mode.
	 *     @type int   $dual_count     How many blocks were classified as dual.
	 *     @type int   $dynamic_count  How many blocks were classified as dynamic.
	 *     @type int   $static_count   How many blocks were classified as static.
	 * }
	 *
	 * @param bool $force Skip the rate-limit check and run immediately.
	 */
	public function scan_storage_modes( $force = false ) {
		// Rate limit: the optional content sweep is unbounded across post count.
		// Even though most of the work now comes from `WP_Block_Type_Registry`,
		// we still want to cap how often this can be triggered.
		$last_run = (int) get_option( self::STORAGE_SCAN_LAST_RUN_OPTION, 0 );
		if ( ! $force && $last_run && ( time() - $last_run ) < self::STORAGE_SCAN_MIN_INTERVAL ) {
			$retry_after = self::STORAGE_SCAN_MIN_INTERVAL - ( time() - $last_run );
			return new \WP_Error(
				'scan_rate_limited',
				sprintf(
					/* translators: %d: seconds until the next scan is allowed */
					__( 'Storage-mode scan ran recently. Try again in %d seconds.', 'gk-block-api' ),
					$retry_after
				),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		// ── Pass 1: registry-based baseline.
		// `WP_Block_Type_Registry` knows every block's render_callback (→ dynamic)
		// and its `attributes` schema. Blocks with `attributes[*].source` set to
		// 'html' / 'attribute' / 'children' / 'node' / 'rich-text' / 'tag' read
		// from innerHTML at edit time — that's the structural dual-storage
		// signal. Combine with `is_dynamic()` and we cover the whole registered
		// surface without touching post_content.
		$classification     = array();
		$registry           = \WP_Block_Type_Registry::get_instance();
		$dynamic_candidates = array();
		if ( $registry ) {
			foreach ( $registry->get_all_registered() as $name => $block_type ) {
				$is_dynamic  = $block_type->is_dynamic();
				$reads_inner = $this->block_reads_innerhtml_via_attributes( $block_type );
				if ( $is_dynamic && $reads_inner ) {
					$classification[ $name ] = self::STORAGE_MODE_DUAL;
				} elseif ( $is_dynamic ) {
					// Dynamic blocks may still be dual via custom JS save() that
					// PHP can't see (e.g., yoast/faq-block). Mark as dynamic
					// here; pass 2 may upgrade to dual based on evidence.
					$classification[ $name ]     = self::STORAGE_MODE_DYNAMIC;
					$dynamic_candidates[ $name ] = true;
				} else {
					$classification[ $name ] = self::STORAGE_MODE_STATIC;
				}
			}
		}

		// ── Pass 2: evidence sweep — single content walk that does TWO jobs.
		//
		// (a) Upgrade dynamic candidates → dual when we see a stored
		// instance with non-empty innerHTML (custom JS save() pattern,
		// e.g., yoast/faq-block).
		//
		// (b) Discover orphan blocks — names present in post_content but
		// NOT in the live registry. These come from deactivated /
		// uninstalled plugins. Without a registration we can't run
		// `is_dynamic()`, so we classify by the only signal we have:
		// - any stored instance with non-empty innerHTML → static
		// (innerHTML is the only thing surviving; an AI can edit
		// it as text)
		// - all stored instances empty → dynamic (was server-
		// rendered, now renders nothing — broken)
		//
		// Skip the walk entirely if there are no dynamic candidates AND we
		// already classified every live block — we still walk in that case
		// because orphans are invisible to the registry but visible to AI
		// agents reading pages. The walk short-circuits via remaining-set
		// checks once everything is confirmed.
		$scanned_posts = 0;
		// Keyed by block name: mode (static|dynamic) and whether it had innerHTML.
		$orphan_state = array();
		$post_types   = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$paged        = 1;
		$remaining    = $dynamic_candidates;
		$batch_size   = 0;
		do {
			$batch      = get_posts(
				array(
					'post_type'           => $post_types,
					'post_status'         => 'publish',
					'posts_per_page'      => self::SCAN_BATCH_SIZE,
					'paged'               => $paged,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'ignore_sticky_posts' => true,
				)
			);
			$batch_size = count( $batch );
			foreach ( $batch as $post_id ) {
				$content = get_post_field( 'post_content', $post_id, 'raw' );
				if ( empty( $content ) ) {
					continue;
				}
				++$scanned_posts;
				$blocks = parse_blocks( $content );
				if ( is_array( $blocks ) ) {
					$this->scan_evidence_recursive( $blocks, $remaining, $classification, $orphan_state );
				}
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			++$paged;
		} while ( self::SCAN_BATCH_SIZE === $batch_size );

		// Promote orphan_state into classification with a final mode decision.
		foreach ( $orphan_state as $name => $state ) {
			$classification[ $name ] = $state['has_inner'] ? self::STORAGE_MODE_STATIC : self::STORAGE_MODE_DYNAMIC;
		}

		ksort( $classification );
		$counts = array(
			self::STORAGE_MODE_STATIC  => 0,
			self::STORAGE_MODE_DYNAMIC => 0,
			self::STORAGE_MODE_DUAL    => 0,
		);
		foreach ( $classification as $mode ) {
			if ( isset( $counts[ $mode ] ) ) {
				++$counts[ $mode ];
			}
		}

		update_option( self::STORAGE_MODES_OPTION, $classification, false );
		update_option( self::STORAGE_SCAN_LAST_RUN_OPTION, time(), false );

		return array(
			'scanned_posts'  => $scanned_posts,
			'unique_blocks'  => count( $classification ),
			'classification' => $classification,
			'dual_count'     => $counts[ self::STORAGE_MODE_DUAL ],
			'dynamic_count'  => $counts[ self::STORAGE_MODE_DYNAMIC ],
			'static_count'   => $counts[ self::STORAGE_MODE_STATIC ],
		);
	}

	/**
	 * Whether any of the block type's registered attributes declares a
	 * `source` that pulls from innerHTML — the structural signal of a
	 * block whose attributes mirror its rendered HTML.
	 *
	 * @param \WP_Block_Type $block_type Registered block type to inspect.
	 * @return bool
	 */
	private function block_reads_innerhtml_via_attributes( $block_type ) {
		if ( empty( $block_type->attributes ) || ! is_array( $block_type->attributes ) ) {
			return false;
		}
		// Sources that read from the saved markup (per Block API spec).
		static $inner_sources = array(
			'html'      => 1,
			'attribute' => 1,
			'children'  => 1,
			'node'      => 1,
			'rich-text' => 1,
			'tag'       => 1,
			'text'      => 1,
		);
		foreach ( $block_type->attributes as $attr ) {
			if ( is_array( $attr ) && isset( $attr['source'] ) && isset( $inner_sources[ $attr['source'] ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Combined evidence walker (pass 2 of scan_storage_modes).
	 *
	 * For each parsed block:
	 *   - If the block is in `$remaining` (a registered dynamic candidate),
	 *     non-empty innerHTML upgrades it to `dual` and removes it from the
	 *     remaining set.
	 *   - If the block is NOT in `$classification` (orphan from a
	 *     deactivated plugin), accumulate `has_inner` so the caller can
	 *     classify it static (any non-empty seen) or dynamic (all empty).
	 *
	 * @param array $blocks          parse_blocks() output.
	 * @param array &$remaining      block_name => true; unconfirmed dual candidates.
	 * @param array &$classification block_name => storage_mode; mutated on dual upgrade.
	 * @param array &$orphan_state   orphan_name => array('has_inner' => bool); mutated.
	 */
	private function scan_evidence_recursive( $blocks, array &$remaining, array &$classification, array &$orphan_state ) {
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$name      = $block['blockName'];
			$inner     = isset( $block['innerHTML'] ) ? $block['innerHTML'] : '';
			$stripped  = trim( preg_replace( '/<!--.*?-->/s', '', $inner ) );
			$has_inner = '' !== $stripped;

			if ( isset( $remaining[ $name ] ) ) {
				if ( $has_inner ) {
					$classification[ $name ] = self::STORAGE_MODE_DUAL;
					unset( $remaining[ $name ] );
				}
			} elseif ( ! isset( $classification[ $name ] ) ) {
				// Orphan: seen in content but absent from the live registry.
				// Track whether we've seen any non-empty stored instance.
				if ( ! isset( $orphan_state[ $name ] ) ) {
					$orphan_state[ $name ] = array( 'has_inner' => false );
				}
				if ( $has_inner ) {
					$orphan_state[ $name ]['has_inner'] = true;
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->scan_evidence_recursive( $block['innerBlocks'], $remaining, $classification, $orphan_state );
			}
		}
	}


	/**
	 * Whether a registered block type is server-rendered.
	 *
	 * Cached per block name. Wraps WP_Block_Type_Registry to give
	 * Block_CRUD a single discovery point that can be replaced by the
	 * scan-cached map once BLOCK-13 lands.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public function is_block_dynamic( $block_name ) {
		static $cache = array();
		if ( ! isset( $cache[ $block_name ] ) ) {
			$registry             = \WP_Block_Type_Registry::get_instance();
			$type                 = $registry ? $registry->get_registered( $block_name ) : null;
			$cache[ $block_name ] = $type ? $type->is_dynamic() : false;
		}
		return $cache[ $block_name ];
	}

	/**
	 * Get block and pattern usage statistics.
	 *
	 * Returns cached data unless $refresh is true, in which case the cache
	 * is regenerated by scanning all published content.
	 *
	 * @param bool $refresh Force cache regeneration.
	 *
	 * @return array {
	 *     @type array $block_usage       Block name => { count, post_count }.
	 *     @type array $namespace_totals  Namespace => total block count.
	 *     @type array $pattern_references Pattern ID => { name, refs }.
	 *     @type array $legacy_patterns   List of patterns containing legacy blocks.
	 * }
	 */
	public function get_stats( $refresh = false ) {
		if ( ! $refresh ) {
			$cached = get_transient( self::CACHE_KEY );

			if ( false !== $cached ) {
				return $cached;
			}
		}

		// Refresh rate-limit. build_stats() runs a chunked WP_Query across every
		// published post of every public post type, parse_blocks()-ing each one.
		// On a 10k-post site that's 10k+ DB fetches + parses — a cheap REST call
		// (/site-usage?refresh=true) amplifies into expensive backend work.
		// Cap manual refreshes at one per CACHE_TTL even when refresh=true; when
		// the budget is exhausted, fall back to the cached result instead of
		// erroring (the cache might be stale but is still useful).
		if ( $refresh ) {
			$last_refresh = (int) get_option( self::REFRESH_LAST_RUN_OPTION, 0 );
			if ( $last_refresh && ( time() - $last_refresh ) < self::REFRESH_MIN_INTERVAL ) {
				$cached = get_transient( self::CACHE_KEY );
				if ( false !== $cached ) {
					return $cached;
				}
				// No cache yet either — fall through and accept the cost.
			}
		}

		try {
			$stats = $this->build_stats();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG && WP_DEBUG_LOG ) {
				error_log( 'GK Block API stats build error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$stats = array(
				'block_usage'        => array(),
				'namespace_totals'   => array(),
				'pattern_references' => array(),
				'legacy_patterns'    => array(),
			);
		}

		set_transient( self::CACHE_KEY, $stats, self::CACHE_TTL );

		// Record the refresh stamp only on a successful build + cache write
		// so a failed rebuild doesn't consume the throttle budget.
		if ( $refresh ) {
			update_option( self::REFRESH_LAST_RUN_OPTION, time(), false );
		}

		return $stats;
	}

	/**
	 * Get usage data for a specific block type.
	 *
	 * @param string $block_name Full block name (e.g., "core/paragraph").
	 *
	 * @return array { count, post_count } or empty defaults.
	 */
	public function get_block_usage( $block_name ) {
		$stats = $this->get_stats();

		if ( isset( $stats['block_usage'][ $block_name ] ) ) {
			return $stats['block_usage'][ $block_name ];
		}

		return array(
			'count'      => 0,
			'post_count' => 0,
		);
	}

	/**
	 * Scan all published content and build usage statistics.
	 *
	 * @return array Structured usage stats.
	 */
	private function build_stats() {
		$block_usage      = array();
		$namespace_totals = array();
		$pattern_refs     = array();
		$posts_per_block  = array();

		// Chunked walk — see scan_storage_modes() for rationale. Same memory
		// ceiling, no full-table scan in a single shot.
		$post_types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
		$paged      = 1;
		$batch_size = 0;
		do {
			$batch      = get_posts(
				array(
					'post_type'           => $post_types,
					'post_status'         => 'publish',
					'posts_per_page'      => self::SCAN_BATCH_SIZE,
					'paged'               => $paged,
					'fields'              => 'ids',
					'no_found_rows'       => true,
					'orderby'             => 'ID',
					'order'               => 'ASC',
					'ignore_sticky_posts' => true,
				)
			);
			$batch_size = count( $batch );
			foreach ( $batch as $post_id ) {
				$content = get_post_field( 'post_content', $post_id, 'raw' );
				if ( empty( $content ) || ! has_blocks( $content ) ) {
					continue;
				}
				$blocks = parse_blocks( $content );
				if ( ! is_array( $blocks ) ) {
					continue;
				}
				$this->count_blocks( $blocks, $post_id, $block_usage, $posts_per_block, $namespace_totals, $pattern_refs );
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			++$paged;
		} while ( self::SCAN_BATCH_SIZE === $batch_size );

		// Merge post_count into block_usage.
		foreach ( $block_usage as $name => &$data ) {
			$data['post_count'] = isset( $posts_per_block[ $name ] )
				? count( $posts_per_block[ $name ] )
				: 0;
		}
		unset( $data );

		// Sort block_usage by count descending.
		uasort(
			$block_usage,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		// Sort namespace totals descending.
		arsort( $namespace_totals );

		// Resolve pattern names and detect legacy patterns.
		$pattern_references = $this->resolve_pattern_references( $pattern_refs );
		$legacy_patterns    = $this->detect_legacy_patterns( $pattern_references );

		return array(
			'block_usage'        => $block_usage,
			'namespace_totals'   => $namespace_totals,
			'pattern_references' => $pattern_references,
			'legacy_patterns'    => $legacy_patterns,
		);
	}

	/**
	 * Recursively count blocks within a block array.
	 *
	 * @param array $blocks          Parsed blocks array.
	 * @param int   $post_id         Current post ID.
	 * @param array &$block_usage    Running count per block name.
	 * @param array &$posts_per_block Post IDs per block name (for post_count).
	 * @param array &$namespace_totals Running count per namespace.
	 * @param array &$pattern_refs   Running count of core/block refs.
	 */
	private function count_blocks( $blocks, $post_id, &$block_usage, &$posts_per_block, &$namespace_totals, &$pattern_refs ) {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'];

			// Skip null/empty block names (freeform HTML, whitespace).
			if ( empty( $name ) ) {
				continue;
			}

			// Count the block.
			if ( ! isset( $block_usage[ $name ] ) ) {
				$block_usage[ $name ] = array( 'count' => 0 );
			}
			++$block_usage[ $name ]['count'];

			// Track which posts use this block.
			if ( ! isset( $posts_per_block[ $name ] ) ) {
				$posts_per_block[ $name ] = array();
			}
			$posts_per_block[ $name ][ $post_id ] = true;

			// Namespace total.
			$namespace = explode( '/', $name, 2 );
			$ns        = $namespace[0];
			if ( ! isset( $namespace_totals[ $ns ] ) ) {
				$namespace_totals[ $ns ] = 0;
			}
			++$namespace_totals[ $ns ];

			// Track synced pattern references (core/block with ref attribute).
			if ( 'core/block' === $name && ! empty( $block['attrs']['ref'] ) ) {
				$ref_id = (int) $block['attrs']['ref'];
				if ( ! isset( $pattern_refs[ $ref_id ] ) ) {
					$pattern_refs[ $ref_id ] = 0;
				}
				++$pattern_refs[ $ref_id ];
			}

			// Recurse into inner blocks.
			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->count_blocks( $block['innerBlocks'], $post_id, $block_usage, $posts_per_block, $namespace_totals, $pattern_refs );
			}
		}
	}

	/**
	 * Resolve pattern reference IDs to names and ref counts.
	 *
	 * @param array $pattern_refs Pattern ID => count.
	 *
	 * @return array Pattern ID => { name, refs }.
	 */
	private function resolve_pattern_references( $pattern_refs ) {
		$resolved = array();

		foreach ( $pattern_refs as $pattern_id => $count ) {
			$pattern = get_post( $pattern_id );

			if ( $pattern && 'wp_block' === $pattern->post_type ) {
				$resolved[ $pattern_id ] = array(
					'name' => $pattern->post_title,
					'refs' => $count,
				);
			} else {
				$resolved[ $pattern_id ] = array(
					'name' => __( '(deleted pattern)', 'gk-block-api' ),
					'refs' => $count,
				);
			}
		}

		// Sort by refs descending.
		uasort(
			$resolved,
			function ( $a, $b ) {
				return $b['refs'] - $a['refs'];
			}
		);

		return $resolved;
	}

	/**
	 * Detect synced patterns (wp_block posts) that contain legacy blocks.
	 *
	 * @param array $pattern_references Already-computed pattern references from build_stats().
	 *
	 * @return array List of legacy pattern data.
	 */
	private function detect_legacy_patterns( $pattern_references ) {
		$preferences = new Preferences();
		$legacy      = array();

		/**
		 * Filters the maximum number of synced patterns scanned for legacy
		 * block usage in one pass.
		 *
		 * Each fetched pattern is parsed and walked to detect legacy-tier
		 * blocks (`stackable/*`, `ugb/*`, `jetpack/*`, etc.), so peak memory
		 * scales with the number returned. The default cap covers the
		 * "typically dozens, occasionally hundreds" of user-created synced
		 * patterns on real sites; raise only when a known site has more.
		 *
		 * @param int $limit Maximum synced patterns to scan. Default 500.
		 */
		$legacy_scan_limit = (int) apply_filters( 'gk_block_api_legacy_patterns_scan_limit', 500 );

		$patterns = get_posts(
			array(
				'post_type'           => 'wp_block',
				'post_status'         => 'publish',
				'posts_per_page'      => $legacy_scan_limit,
				'no_found_rows'       => true,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
			)
		);

		foreach ( $patterns as $pattern ) {
			if ( empty( $pattern->post_content ) || ! has_blocks( $pattern->post_content ) ) {
				continue;
			}

			$blocks = parse_blocks( $pattern->post_content );
			if ( ! is_array( $blocks ) ) {
				continue;
			}
			$legacy_blocks = $this->find_legacy_blocks( $blocks, $preferences );

			if ( ! empty( $legacy_blocks ) ) {
				// Count references to this pattern using the already-computed data.
				$refs = isset( $pattern_references[ $pattern->ID ] )
					? $pattern_references[ $pattern->ID ]['refs']
					: 0;

				$legacy[] = array(
					'id'            => $pattern->ID,
					'name'          => $pattern->post_title,
					'refs'          => $refs,
					'legacy_blocks' => array_unique( $legacy_blocks ),
				);
			}
		}

		return $legacy;
	}

	/**
	 * Recursively find legacy block names in a block array.
	 *
	 * @param array       $blocks      Parsed blocks.
	 * @param Preferences $preferences Preferences instance.
	 *
	 * @return string[] Legacy block names found.
	 */
	private function find_legacy_blocks( $blocks, $preferences ) {
		$legacy = array();

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}

			if ( $preferences->is_legacy_block( $block['blockName'] ) ) {
				$legacy[] = $block['blockName'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$legacy = array_merge( $legacy, $this->find_legacy_blocks( $block['innerBlocks'], $preferences ) );
			}
		}

		return $legacy;
	}
}
