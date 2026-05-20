<?php
/**
 * Pattern listing, search, and preference scoring.
 *
 * Provides access to both synced patterns (wp_block CPT) and registered
 * patterns (WP_Block_Patterns_Registry) with scoring and legacy detection.
 *
 * @package GravityKit\BlockAPI
 */

namespace GravityKit\BlockAPI;

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
	 *
	 * The aggregate loads `post_content` into PHP for every published post that
	 * matches `LIKE '%"ref":%'`. Without chunking, a site with thousands of
	 * matching rows (or a handful of multi-megabyte pillar pages) can exhaust
	 * `memory_limit` mid-rebuild. Streaming in batches keeps peak resident
	 * memory bounded to one batch worth of content regardless of corpus size.
	 *
	 * Tests override this via the `gk_block_api_pattern_ref_scan_batch_size`
	 * filter so the chunked path can be exercised with small fixtures.
	 */
	const SCAN_BATCH_SIZE = 200;

	/**
	 * Preferences instance.
	 *
	 * @var Preferences
	 */
	private $preferences;

	/**
	 * Constructor.
	 *
	 * @param Preferences $preferences Preferences instance.
	 */
	public function __construct( Preferences $preferences ) {
		$this->preferences = $preferences;
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
		if ( ! empty( $args['refresh'] ) ) {
			delete_transient( self::REF_COUNT_CACHE_KEY );
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
		/**
		 * Filters the maximum number of synced patterns fetched in one query.
		 *
		 * Synced patterns are user-created `wp_block` posts. The default cap
		 * bounds memory on edge-case sites with thousands of entries; the
		 * pattern list is also fully loaded into PHP for scoring + enrichment
		 * downstream, so the value isn't purely an SQL concern. Raise only
		 * when a known site exceeds the default and the full set is needed
		 * in a single response.
		 *
		 * @param int $limit Maximum synced patterns to fetch. Default 500.
		 */
		$synced_patterns_limit = (int) apply_filters( 'gk_block_api_synced_patterns_query_limit', 500 );

		$query_args = array(
			'post_type'           => 'wp_block',
			'post_status'         => 'publish',
			'posts_per_page'      => $synced_patterns_limit,
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
		$reference_count = $this->count_pattern_references( $post->ID );

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
	 * Count references to a synced pattern across published content.
	 *
	 * Looks up the pattern ID in the aggregate reference-count map. The map
	 * is built and cached by `get_all_pattern_reference_counts()`; this method
	 * is a thin accessor so callers see a stable per-pattern API.
	 *
	 * @param int $pattern_id Pattern post ID.
	 *
	 * @return int Number of distinct published posts referencing this pattern.
	 */
	private function count_pattern_references( $pattern_id ) {
		$pattern_id = (int) $pattern_id;
		if ( $pattern_id <= 0 ) {
			return 0;
		}

		$counts = $this->get_all_pattern_reference_counts();

		return isset( $counts[ $pattern_id ] ) ? (int) $counts[ $pattern_id ] : 0;
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
		$cached = get_transient( self::REF_COUNT_CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		// Allow-list of real published synced patterns. Extracted refs that
		// don't appear here (orphaned IDs, leftover copy-pastes from other
		// installs) are dropped so the cache stays bounded to actual patterns.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$valid_ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'wp_block' AND post_status = 'publish'"
		);

		// Empty allow-list → no patterns to count. Persist the empty map so
		// repeated cold reads on a site with zero patterns don't keep scanning.
		if ( empty( $valid_ids ) ) {
			set_transient( self::REF_COUNT_CACHE_KEY, array(), self::REF_COUNT_CACHE_TTL );
			return array();
		}

		$valid_lookup = array_flip( array_map( 'intval', $valid_ids ) );

		// LIKE-prefilter, then page through results so peak memory stays
		// bounded to one batch worth of post_content regardless of how many
		// rows match. Sites with hundreds of pages × 200KB content each
		// otherwise risk OOM mid-rebuild.
		$like_pattern = '%' . $wpdb->esc_like( '"ref":' ) . '%';

		/**
		 * Filters the batch size used when paging through post_content rows
		 * to tally synced-pattern references.
		 *
		 * Each batch is loaded into PHP whole (one row per post that contains
		 * a `"ref":` substring), so peak memory is roughly batch_size ×
		 * average matching post_content size. Lower the value on sites with
		 * very large pages; raise it on small sites to cut round-trips.
		 * Values < 1 are clamped to 1.
		 *
		 * @param int $batch_size Rows pulled per chunk. Default 200.
		 */
		$batch_size = (int) apply_filters( 'gk_block_api_pattern_ref_scan_batch_size', self::SCAN_BATCH_SIZE );
		$batch_size = max( 1, $batch_size );

		$offset = 0;
		$counts = array();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_col(
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

			foreach ( (array) $rows as $content ) {
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

			$rows_returned = count( (array) $rows );
			$offset       += $rows_returned;
		} while ( $rows_returned === $batch_size );

		set_transient( self::REF_COUNT_CACHE_KEY, $counts, self::REF_COUNT_CACHE_TTL );

		return $counts;
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
}
