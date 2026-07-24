<?php
/**
 * Pattern listing, search, and preference scoring.
 *
 * Provides access to both synced patterns (wp_block CPT) and registered
 * patterns (WP_Block_Patterns_Registry) with scoring and legacy detection.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Pattern_Manager
 *
 * Manages pattern discovery, scoring, and block analysis.
 */
class Pattern_Manager {

	/**
	 * Transient key for the synced-pattern reference-count map.
	 *
	 * Keyed `wp_block` post ID → count of distinct published posts that contain
	 * a `<!-- wp:block {"ref":ID} /-->` reference. One transient, not one per
	 * pattern, so listing all patterns is a single cached lookup instead of N
	 * post_content LIKE scans.
	 */
	const REF_COUNT_CACHE_KEY = 'gk_block_api_pattern_ref_counts';

	/**
	 * TTL for the reference-count cache (1 hour).
	 *
	 * Matches `Block_Inventory`'s cache TTL. Counts are an informational scoring
	 * input, not a correctness invariant — a one-hour lag on reference totals
	 * is acceptable. Callers needing fresh data pass `refresh=true`.
	 */
	const REF_COUNT_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Rows pulled per chunk when scanning post_content for pattern references.
	 * Peak memory scales with batch_size × matching post_content size.
	 * Override via the `gk/block-mcp/pattern/ref-scan-batch-size` filter.
	 */
	const SCAN_BATCH_SIZE = 200;

	/**
	 * Core's own post meta key for a synced pattern's sync status.
	 *
	 * A `wp_block` post is synced by default (no meta row); the value
	 * `'unsynced'` opts it out. This is the same key WordPress core and the
	 * Site Editor read/write, so `create_pattern()` stays interoperable with
	 * patterns created any other way.
	 */
	const SYNC_STATUS_META_KEY = 'wp_pattern_sync_status';

	/**
	 * Preferences instance.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Per-request memo of the pattern reference-count map.
	 *
	 * `format_synced_pattern()` resolves each pattern's count through this
	 * class once per pattern in a list response; without an instance-level
	 * memo each call would re-enter `get_transient()`. Null until the first
	 * lookup; cleared naturally with the Pattern_Manager instance at end
	 * of request.
	 *
	 * @var array<int,int>|null
	 */
	private $ref_counts_memo;

	/**
	 * Block CRUD facade — used by create_pattern() for the same
	 * build/validation path (registry check, tier rejection, dual-storage)
	 * that Post_Manager::create_post() uses for structured `blocks` input.
	 *
	 * @var Block_CRUD
	 */
	private $block_crud;

	/**
	 * Constructor.
	 *
	 * @param Preferences $preferences Preferences instance.
	 * @param Block_CRUD  $block_crud  Block CRUD facade (build/validate block defs).
	 */
	public function __construct( Preferences $preferences, Block_CRUD $block_crud ) {
		$this->preferences = $preferences;
		$this->block_crud  = $block_crud;
	}

	/**
	 * Get patterns with optional filtering and scoring.
	 *
	 * @param array $args {
	 *     Optional query arguments.
	 *
	 *     @type string $q         Search term for pattern name.
	 *     @type bool   $synced    True for synced only, false for registered only, null for all.
	 *     @type int    $min_score Minimum preference score.
	 *     @type string $category  Filter by pattern category.
	 *     @type int    $limit     Max results (default 20).
	 *     @type string $order_by  Sort field: score (default), usage, date, name.
	 * }
	 *
	 * @return array Array of enriched pattern data.
	 */
	public function get_patterns( $args = array() ) {
		$defaults = array(
			'q'         => '',
			'synced'    => null,
			'min_score' => null,
			'category'  => '',
			'limit'     => 20,
			'order_by'  => 'score',
			'refresh'   => false,
		);

		$args = wp_parse_args( $args, $defaults );

		// Bust the reference-count cache before per-pattern enrichment runs so
		// every formatted pattern in this response reads from the rebuilt map.
		// The instance memo must drop too — otherwise a refresh call inside
		// the same request would still see the pre-bust map.
		if ( ! empty( $args['refresh'] ) ) {
			delete_transient( self::REF_COUNT_CACHE_KEY );
			$this->ref_counts_memo = null;
		}

		$results = array();

		// Collect synced patterns (wp_block CPT).
		if ( null === $args['synced'] || true === $args['synced'] ) {
			$synced  = $this->get_synced_patterns( $args );
			$results = array_merge( $results, $synced );
		}

		// Collect registered patterns (WP_Block_Patterns_Registry).
		if ( null === $args['synced'] || false === $args['synced'] ) {
			$registered = $this->get_registered_patterns( $args );
			$results    = array_merge( $results, $registered );
		}

		// Filter by minimum score.
		if ( null !== $args['min_score'] ) {
			$min     = (int) $args['min_score'];
			$results = array_filter(
				$results,
				function ( $pattern ) use ( $min ) {
					return $pattern['preference']['score'] >= $min;
				}
			);
			$results = array_values( $results );
		}

		// Sort results.
		$results = $this->sort_patterns( $results, $args['order_by'] );

		// Limit results.
		$limit = max( 1, (int) $args['limit'] );
		if ( count( $results ) > $limit ) {
			$results = array_slice( $results, 0, $limit );
		}

		return $results;
	}

	/**
	 * Get a single pattern by ID or registered name.
	 *
	 * @param int|string $id Pattern post ID (synced) or registered pattern name.
	 *
	 * @return array|null Pattern data or null if not found.
	 */
	public function get_pattern( $id ) {
		// Try as synced pattern (numeric ID).
		if ( is_numeric( $id ) ) {
			$post = get_post( (int) $id );

			if ( $post && 'wp_block' === $post->post_type ) {
				// Visibility gate. The list endpoint (get_synced_patterns)
				// filters to post_status='publish'. The single-pattern lookup
				// did NOT, so a draft / private / password-protected wp_block
				// could be fetched by ID by any edit_posts caller. Shared
				// readability contract — see Block_CRUD::is_post_readable().
				if ( ! Block_CRUD::is_post_readable( $post ) ) {
					return null;
				}
				return $this->format_synced_pattern( $post );
			}
		}

		// Try as registered pattern name.
		if ( class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$registry = \WP_Block_Patterns_Registry::get_instance();

			if ( $registry->is_registered( $id ) ) {
				$pattern = $registry->get_registered( $id );

				return $this->format_registered_pattern( $pattern );
			}
		}

		return null;
	}

	/**
	 * Get the parsed blocks contained in a pattern.
	 *
	 * @param array $pattern Pattern data (must include 'content' or be fetched by ID).
	 *
	 * @return array Parsed blocks array.
	 */
	public function get_pattern_blocks( $pattern ) {
		$content = '';

		if ( isset( $pattern['content'] ) ) {
			$content = $pattern['content'];
		} elseif ( isset( $pattern['id'] ) && is_numeric( $pattern['id'] ) ) {
			$post = get_post( (int) $pattern['id'] );
			if ( $post ) {
				$content = $post->post_content;
			}
		}

		if ( empty( $content ) ) {
			return array();
		}

		$blocks = parse_blocks( $content );

		return is_array( $blocks ) ? $blocks : array();
	}

	/**
	 * Get synced patterns (wp_block CPT posts).
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array Formatted pattern data.
	 */
	private function get_synced_patterns( $args ) {
		$query_args = array(
			'post_type'           => 'wp_block',
			'post_status'         => 'publish',
			'posts_per_page'      => $this->synced_patterns_query_limit(),
			'no_found_rows'       => true,
			'orderby'             => 'modified',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		);

		// Search by name.
		if ( ! empty( $args['q'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['q'] );
		}

		$posts   = get_posts( $query_args );
		$results = array();

		foreach ( $posts as $post ) {
			$formatted = $this->format_synced_pattern( $post );

			// Filter by category if specified.
			if ( ! empty( $args['category'] ) ) {
				$block_categories = $this->get_block_categories_in_content( $post->post_content );
				if ( ! in_array( $args['category'], $block_categories, true ) ) {
					continue;
				}
			}

			$results[] = $formatted;
		}

		return $results;
	}

	/**
	 * Get registered patterns from the WP_Block_Patterns_Registry.
	 *
	 * @param array $args Query arguments.
	 *
	 * @return array Formatted pattern data.
	 */
	private function get_registered_patterns( $args ) {
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return array();
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();
		$all      = $registry->get_all_registered();
		$results  = array();
		$search   = ! empty( $args['q'] ) ? strtolower( sanitize_text_field( (string) $args['q'] ) ) : '';

		foreach ( $all as $pattern ) {
			// Search filter.
			if ( ! empty( $search ) ) {
				$title = isset( $pattern['title'] ) ? strtolower( $pattern['title'] ) : '';
				$name  = isset( $pattern['name'] ) ? strtolower( $pattern['name'] ) : '';
				if ( false === strpos( $title, $search ) && false === strpos( $name, $search ) ) {
					continue;
				}
			}

			// Category filter.
			if ( ! empty( $args['category'] ) ) {
				$categories = isset( $pattern['categories'] ) ? $pattern['categories'] : array();
				if ( ! in_array( $args['category'], $categories, true ) ) {
					continue;
				}
			}

			$results[] = $this->format_registered_pattern( $pattern );
		}

		return $results;
	}

	/**
	 * Format a synced pattern (wp_block post) into a standardized array.
	 *
	 * @param \WP_Post $post The wp_block post.
	 *
	 * @return array Formatted pattern data.
	 */
	private function format_synced_pattern( $post ) {
		$content         = $post->post_content;
		$blocks          = ! empty( $content ) ? parse_blocks( $content ) : array();
		$block_names     = $this->extract_block_names( $blocks );
		$legacy_blocks   = $this->find_legacy_blocks_in_list( $block_names );
		$has_legacy      = ! empty( $legacy_blocks );
		$ref_counts      = $this->get_all_pattern_reference_counts();
		$reference_count = isset( $ref_counts[ $post->ID ] ) ? (int) $ref_counts[ $post->ID ] : 0;

		// Build scoring input.
		$scoring_input = array(
			'reference_count' => $reference_count,
			'created'         => $post->post_date,
			'has_legacy'      => $has_legacy,
		);

		$preference = $this->preferences->get_pattern_score( $scoring_input );

		// Add contextual reasons.
		if ( ! empty( $block_names ) ) {
			$namespaces = array_unique( array_map( array( $this->preferences, 'extract_namespace' ), $block_names ) );
			if ( in_array( 'filter', $namespaces, true ) ) {
				$preference['reasons'][] = 'filter_theme_blocks';
			}
		}

		$data = array(
			'id'                => $post->ID,
			'name'              => $post->post_title,
			'type'              => 'synced',
			'sync_status'       => $this->get_sync_status( $post->ID ),
			'created'           => gmdate( 'Y-m-d', strtotime( $post->post_date ) ),
			'modified'          => gmdate( 'Y-m-d', strtotime( $post->post_modified ) ),
			'reference_count'   => $reference_count,
			'preference'        => $preference,
			'contains_blocks'   => $block_names,
			'has_legacy_blocks' => $has_legacy,
		);

		if ( $has_legacy ) {
			$data['legacy_blocks'] = $legacy_blocks;
		}

		// Include preview HTML (first 500 chars of content).
		if ( ! empty( $content ) ) {
			$data['preview_html'] = mb_substr( $content, 0, 500 );
		}

		return $data;
	}

	/**
	 * Format a registered pattern into a standardized array.
	 *
	 * @param array $pattern Registered pattern data from WP_Block_Patterns_Registry.
	 *
	 * @return array Formatted pattern data.
	 */
	private function format_registered_pattern( $pattern ) {
		$content       = isset( $pattern['content'] ) ? $pattern['content'] : '';
		$blocks        = ! empty( $content ) ? parse_blocks( $content ) : array();
		$block_names   = $this->extract_block_names( $blocks );
		$legacy_blocks = $this->find_legacy_blocks_in_list( $block_names );
		$has_legacy    = ! empty( $legacy_blocks );

		// Registered patterns have no reference count or date; use defaults.
		$scoring_input = array(
			'reference_count' => 0,
			'created'         => gmdate( 'Y-m-d' ), // No creation date available; treat as current.
			'has_legacy'      => $has_legacy,
		);

		$preference = $this->preferences->get_pattern_score( $scoring_input );

		$data = array(
			'id'                => $pattern['name'],
			'name'              => isset( $pattern['title'] ) ? $pattern['title'] : $pattern['name'],
			'type'              => 'registered',
			'reference_count'   => 0,
			'preference'        => $preference,
			'contains_blocks'   => $block_names,
			'has_legacy_blocks' => $has_legacy,
		);

		if ( $has_legacy ) {
			$data['legacy_blocks'] = $legacy_blocks;
		}

		if ( ! empty( $content ) ) {
			$data['preview_html'] = mb_substr( $content, 0, 500 );
		}

		if ( ! empty( $pattern['categories'] ) ) {
			$data['categories'] = $pattern['categories'];
		}

		return $data;
	}

	/**
	 * Recursively extract all block names from a parsed block array.
	 *
	 * @param array $blocks Parsed blocks.
	 *
	 * @return string[] Unique block names.
	 */
	private function extract_block_names( $blocks ) {
		$names = array();

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = $block['blockName'];
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->extract_block_names( $block['innerBlocks'] ) );
			}
		}

		return array_unique( $names );
	}

	/**
	 * Find legacy block names from a list of block names.
	 *
	 * @param string[] $block_names Block names to check.
	 *
	 * @return string[] Legacy block names.
	 */
	private function find_legacy_blocks_in_list( $block_names ) {
		$legacy = array();

		foreach ( $block_names as $name ) {
			if ( $this->preferences->is_legacy_block( $name ) ) {
				$legacy[] = $name;
			}
		}

		return array_unique( $legacy );
	}

	/**
	 * Maximum number of synced patterns acknowledged per query.
	 *
	 * Shared by `get_synced_patterns()` (the listing query) and
	 * `get_all_pattern_reference_counts()` (the orphan-filter allow-list),
	 * so both call sites agree on which patterns exist. Without a shared
	 * cap, the allow-list could outgrow the list it gates.
	 *
	 * @return int
	 */
	private function synced_patterns_query_limit() {
		/**
		 * Set how many synced patterns the API recognizes per query.
		 *
		 * This cap governs both the synced-pattern listing and the reference
		 * counts that decide which patterns are "in use," so the two always
		 * agree on the same set. If your site runs a large, actively used
		 * pattern library and some patterns aren't showing up, raise this so the
		 * full set is acknowledged.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Recognize up to 1,500 synced patterns on a pattern-heavy site.
		 * add_filter( 'gk/block-mcp/pattern/synced-query-limit', function () {
		 *     return 1500;
		 * } );
		 *
		 * @param int $limit Maximum number of synced patterns acknowledged per query. Default 500.
		 */
		return (int) apply_filters( 'gk/block-mcp/pattern/synced-query-limit', 500 );
	}

	/**
	 * Build (or read from cache) a map of `pattern_id => reference_count`
	 * spanning every published post on the site.
	 *
	 * Uses a single LIKE query to collect post_content rows that contain
	 * `"ref":` substrings, then regex-extracts the IDs in PHP and de-duplicates
	 * per post. The old per-pattern implementation ran two LIKE scans of
	 * `wp_posts` per pattern, so a /patterns response of N synced patterns
	 * cost 2N full-table scans (e.g. 60 on gravitykit.com). This collapses
	 * the work into one scan plus an in-memory tally and caches the result.
	 *
	 * @return array<int,int> Map of pattern ID → count.
	 */
	public function get_all_pattern_reference_counts() {
		if ( null !== $this->ref_counts_memo ) {
			return $this->ref_counts_memo;
		}

		$cached = get_transient( self::REF_COUNT_CACHE_KEY );
		if ( is_array( $cached ) ) {
			$this->ref_counts_memo = $cached;
			return $this->ref_counts_memo;
		}

		// Allow-list of real published synced patterns. Extracted refs that
		// don't appear here (orphaned IDs, leftover copy-pastes from other
		// installs) are dropped so the cache stays bounded to actual patterns.
		// Shares the same cap as the listing query so both call sites agree
		// on which patterns exist.
		$valid_ids = get_posts(
			array(
				'post_type'           => 'wp_block',
				'post_status'         => 'publish',
				'posts_per_page'      => $this->synced_patterns_query_limit(),
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'orderby'             => 'ID',
				'order'               => 'ASC',
				'ignore_sticky_posts' => true,
			)
		);

		// Empty allow-list → no patterns to count. Persist the empty map so
		// repeated cold reads on a site with zero patterns don't keep scanning.
		if ( empty( $valid_ids ) ) {
			set_transient( self::REF_COUNT_CACHE_KEY, array(), self::REF_COUNT_CACHE_TTL );
			$this->ref_counts_memo = array();
			return $this->ref_counts_memo;
		}

		$valid_lookup = array_flip( array_map( 'intval', $valid_ids ) );

		global $wpdb;
		$like_pattern = '%' . $wpdb->esc_like( '"ref":' ) . '%';

		/**
		 * Tune the memory/speed trade-off when counting pattern usage.
		 *
		 * To work out how often each synced pattern is used, the plugin pages
		 * through post content in chunks rather than loading every row at once.
		 * Lower the batch size to shrink peak memory on a constrained host;
		 * raise it to finish the tally in fewer round-trips on a fast database.
		 * Values below 1 are clamped to 1.
		 *
		 * @since 2.0.0
		 *
		 * @example
		 * // Use smaller batches on a memory-limited host.
		 * add_filter( 'gk/block-mcp/pattern/ref-scan-batch-size', function () {
		 *     return 50;
		 * } );
		 *
		 * @param int $batch_size Number of post rows pulled per chunk. Default 200.
		 */
		$batch_size = (int) apply_filters( 'gk/block-mcp/pattern/ref-scan-batch-size', self::SCAN_BATCH_SIZE );
		$batch_size = max( 1, $batch_size );

		$offset = 0;
		$counts = array();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_content FROM {$wpdb->posts}
						WHERE post_status = 'publish'
						AND post_content LIKE %s
						ORDER BY ID
						LIMIT %d OFFSET %d",
					$like_pattern,
					$batch_size,
					$offset
				)
			);

			foreach ( $rows as $content ) {
				if ( ! is_string( $content ) || '' === $content ) {
					continue;
				}

				// `"ref":<digits>` followed by `,` or `}` — trailing-boundary
				// guard so `"ref":12` and `"ref":123` never collide.
				if ( ! preg_match_all( '/"ref":(\d+)\s*[,}]/', $content, $matches ) ) {
					continue;
				}

				// De-duplicate per post so a post that references the same
				// pattern twice counts once — matches COUNT(DISTINCT ID).
				$unique_ids = array_unique( array_map( 'intval', $matches[1] ) );
				foreach ( $unique_ids as $id ) {
					// Skip non-positive IDs and orphaned/foreign refs that
					// don't resolve to a real published wp_block on this site.
					if ( $id <= 0 || ! isset( $valid_lookup[ $id ] ) ) {
						continue;
					}
					$counts[ $id ] = isset( $counts[ $id ] ) ? $counts[ $id ] + 1 : 1;
				}
			}

			$rows_returned = count( $rows );
			$offset       += $rows_returned;
		} while ( $rows_returned === $batch_size );

		set_transient( self::REF_COUNT_CACHE_KEY, $counts, self::REF_COUNT_CACHE_TTL );

		$this->ref_counts_memo = $counts;
		return $this->ref_counts_memo;
	}

	/**
	 * Get block categories used in content.
	 *
	 * @param string $content Block content string.
	 *
	 * @return string[] Category slugs.
	 */
	private function get_block_categories_in_content( $content ) {
		if ( empty( $content ) ) {
			return array();
		}

		$blocks     = parse_blocks( $content );
		$categories = array();
		$registry   = \WP_Block_Type_Registry::get_instance();

		foreach ( $this->extract_block_names( $blocks ) as $name ) {
			$block_type = $registry->get_registered( $name );
			if ( $block_type && ! empty( $block_type->category ) ) {
				$categories[] = $block_type->category;
			}
		}

		return array_unique( $categories );
	}

	/**
	 * Sort patterns by the specified field.
	 *
	 * @param array  $patterns Patterns to sort.
	 * @param string $order_by Sort field: score, usage, date, name.
	 *
	 * @return array Sorted patterns.
	 */
	private function sort_patterns( $patterns, $order_by ) {
		usort(
			$patterns,
			function ( $a, $b ) use ( $order_by ) {
				switch ( $order_by ) {
					case 'usage':
						$a_refs = isset( $a['reference_count'] ) ? $a['reference_count'] : 0;
						$b_refs = isset( $b['reference_count'] ) ? $b['reference_count'] : 0;
						return $b_refs - $a_refs;

					case 'date':
						$a_date = isset( $a['modified'] ) ? strtotime( $a['modified'] ) : 0;
						$b_date = isset( $b['modified'] ) ? strtotime( $b['modified'] ) : 0;
						return $b_date - $a_date;

					case 'name':
						$a_name = isset( $a['name'] ) ? $a['name'] : '';
						$b_name = isset( $b['name'] ) ? $b['name'] : '';
						return strcasecmp( $a_name, $b_name );

					case 'score':
					default:
						$a_score = isset( $a['preference']['score'] ) ? $a['preference']['score'] : 0;
						$b_score = isset( $b['preference']['score'] ) ? $b['preference']['score'] : 0;
						return $b_score - $a_score;
				}
			}
		);

		return $patterns;
	}

	/**
	 * A synced pattern's sync status: 'synced' (default — the meta row is
	 * absent) or 'unsynced' (the meta row is present and set).
	 *
	 * @since 2.2.0
	 *
	 * @param int $post_id `wp_block` post ID.
	 *
	 * @return string 'synced'|'unsynced'.
	 */
	private function get_sync_status( $post_id ) {
		$meta = get_post_meta( $post_id, self::SYNC_STATUS_META_KEY, true );
		return 'unsynced' === $meta ? 'unsynced' : 'synced';
	}

	/**
	 * Create a synced pattern (a `wp_block` post).
	 *
	 * Structured `blocks` go through the same registry/tier/dual-storage
	 * validation as `Post_Manager::create_post()`'s structured path (via
	 * `Block_CRUD::build_block_from_def()`); raw `content` is sanitized the
	 * same way. Sync status is core's own `wp_pattern_sync_status` meta —
	 * present and `'unsynced'` opts a pattern out of sync; absent (the
	 * default) means synced, matching how the Site Editor and WP-CLI's
	 * `block synced-pattern create` represent the same state.
	 *
	 * @since 2.2.0
	 *
	 * @param array $args {
	 *     Pattern arguments.
	 *
	 *     @type string $title       Required, non-empty.
	 *     @type array  $blocks      Structured block definitions. Exactly one of blocks/content.
	 *     @type string $content     Raw post_content. Exactly one of blocks/content.
	 *     @type string $sync_status 'synced' (default) or 'unsynced'.
	 *     @type string $slug        Optional post_name.
	 *     @type string $status      'publish' (default) or 'draft'.
	 * }
	 *
	 * @return array|\WP_Error `{pattern_id, title, slug, sync_status, edit_url, warnings}` plus, for a
	 *                         synced pattern, `reference` (a ready-to-insert core/block snippet), or
	 *                         for an unsynced pattern, `insert_hint` (an insert_pattern call shape) —
	 *                         or a `WP_Error`.
	 */
	public function create_pattern( array $args ) {
		// Sanitize before checking emptiness, not after: a whitespace- or
		// markup-only title passes a raw non-empty-string check but
		// collapses to '' once sanitize_text_field() strips tags/trims it,
		// which would otherwise create a nameless pattern. Explicit
		// empty-string test, not empty(): title "0" is valid.
		$raw_title = isset( $args['title'] ) ? $args['title'] : null;
		$title     = is_string( $raw_title ) ? sanitize_text_field( $raw_title ) : '';
		if ( '' === $title ) {
			return new \WP_Error(
				'missing_title',
				__( 'A non-empty "title" is required.', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$has_blocks  = ! empty( $args['blocks'] ) && is_array( $args['blocks'] );
		$has_content = isset( $args['content'] ) && is_string( $args['content'] ) && '' !== $args['content'];

		if ( $has_blocks === $has_content ) {
			return new \WP_Error(
				'mutually_exclusive',
				__( 'Provide exactly one of "content" or "blocks".', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$sync_status = isset( $args['sync_status'] ) ? sanitize_key( $args['sync_status'] ) : 'synced';
		if ( ! in_array( $sync_status, array( 'synced', 'unsynced' ), true ) ) {
			return new \WP_Error(
				'invalid_sync_status',
				__( '"sync_status" must be "synced" or "unsynced".', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$status = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'publish';
		if ( ! in_array( $status, array( 'publish', 'draft' ), true ) ) {
			return new \WP_Error(
				'invalid_status',
				__( '"status" must be "publish" or "draft".', 'gk-block-mcp' ),
				array( 'status' => 400 )
			);
		}

		$warnings = array();
		$content  = '';

		if ( $has_blocks ) {
			$depth_check = Block_CRUD::validate_tree_depth( $args['blocks'] );
			if ( is_wp_error( $depth_check ) ) {
				return $depth_check;
			}

			$built = array();
			foreach ( $args['blocks'] as $block_def ) {
				if ( ! is_array( $block_def ) ) {
					return new \WP_Error(
						'invalid_block',
						__( 'Each block must be an object.', 'gk-block-mcp' ),
						array( 'status' => 400 )
					);
				}
				$block = $this->block_crud->build_block_from_def( $block_def, $warnings );
				if ( is_wp_error( $block ) ) {
					return $block;
				}
				$built[] = $block;
			}
			$content = serialize_blocks( $built );
		} else {
			$content = wp_kses_post( $args['content'] );
		}

		$postarr = array(
			'post_type'    => 'wp_block',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_content' => $content,
		);
		if ( isset( $args['slug'] ) && is_string( $args['slug'] ) && '' !== $args['slug'] ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}

		// wp_insert_post() runs wp_unslash() on string fields; our content is
		// already unslashed (serialize_blocks() output / decoded JSON args),
		// so wp_slash it first to keep escapes like \n, \" and -- intact.
		$post_id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $post_id ) ) {
			$code    = $post_id->get_error_code();
			$message = $post_id->get_error_message();
			$data    = (array) $post_id->get_error_data();
			if ( ! isset( $data['status'] ) ) {
				$data['status'] = 400;
			}
			return new \WP_Error( '' !== $code ? $code : 'wp_insert_post_failed', $message, $data );
		}
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error(
				'wp_insert_post_failed',
				__( 'wp_insert_post returned a non-positive ID.', 'gk-block-mcp' ),
				array( 'status' => 500 )
			);
		}

		if ( 'unsynced' === $sync_status ) {
			update_post_meta( $post_id, self::SYNC_STATUS_META_KEY, 'unsynced' );
		}

		$post = get_post( $post_id );

		$response = array(
			'pattern_id'  => $post_id,
			'title'       => $post->post_title,
			'slug'        => $post->post_name,
			'sync_status' => $sync_status,
			'edit_url'    => get_edit_post_link( $post_id, 'raw' ),
			'warnings'    => $warnings,
		);

		if ( 'synced' === $sync_status ) {
			// core/block is WordPress's live, propagating synced-block
			// reference. Only safe for a synced pattern: handing it to a
			// caller who explicitly asked for a non-propagating one-off
			// would silently re-link content they asked to keep independent.
			$response['reference'] = array(
				'blockName' => 'core/block',
				'attrs'     => array( 'ref' => $post_id ),
			);
		} else {
			// insert_pattern() supports an inline (non-synced) copy of any
			// pattern via synced:false, independent of the pattern's own
			// sync_status meta — point unsynced callers there instead.
			$response['insert_hint'] = array(
				'tool'   => 'insert_pattern',
				'params' => array(
					'pattern_id' => $post_id,
					'synced'     => false,
				),
			);
		}

		return $response;
	}
}
