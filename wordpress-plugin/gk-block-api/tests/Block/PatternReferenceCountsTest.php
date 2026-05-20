<?php
/**
 * Regression coverage for Pattern_Manager::get_all_pattern_reference_counts().
 *
 * The aggregate replaces the per-pattern LIKE scan that previously ran twice
 * per synced pattern. These tests pin the contract:
 *
 *   - one map covering every distinct published reference, built lazily
 *   - trailing-boundary regex: `"ref":12` does not match `"ref":123`
 *   - per-post de-duplication: a post referencing the same pattern twice
 *     counts once (matches the historical COUNT(DISTINCT ID) semantic)
 *   - draft / private / trashed posts are excluded
 *   - orphaned refs (IDs without a real published wp_block) are dropped
 *   - results are persisted to a transient for cross-request reuse
 *   - chunked scan totals match a single-pass scan across batch boundaries
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Pattern_Manager;
use GravityKit\BlockAPI\Preferences;

/**
 * @covers \GravityKit\BlockAPI\Pattern_Manager::get_all_pattern_reference_counts
 */
class PatternReferenceCountsTest extends BlockApiTestCase {

	/** @var Pattern_Manager */
	private $manager;

	public function set_up(): void {
		parent::set_up();
		$this->manager = new Pattern_Manager( new Preferences() );
		// Ensure each test starts from a cold cache.
		delete_transient( Pattern_Manager::REF_COUNT_CACHE_KEY );
	}

	public function tear_down(): void {
		delete_transient( Pattern_Manager::REF_COUNT_CACHE_KEY );
		parent::tear_down();
	}

	private function make_pattern( string $title = 'Pattern' ): int {
		return $this->make_block_post(
			array(),
			array(
				'post_type'  => 'wp_block',
				'post_title' => $title,
			)
		);
	}

	private function make_post_referencing( array $ref_ids, string $status = 'publish' ): int {
		$content = '';
		foreach ( $ref_ids as $ref_id ) {
			$content .= sprintf( '<!-- wp:block {"ref":%d} /-->' . "\n", (int) $ref_id );
		}
		return self::factory()->post->create( array(
			'post_type'    => 'post',
			'post_status'  => $status,
			'post_content' => $content,
		) );
	}

	/** Empty site → empty map, no errors. */
	public function test_returns_empty_when_no_references_exist(): void {
		$this->make_pattern( 'Lonely' );

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertSame( array(), $counts );
	}

	/** Basic counting: distinct posts per pattern. */
	public function test_counts_distinct_posts_per_pattern(): void {
		$pa = $this->make_pattern( 'A' );
		$pb = $this->make_pattern( 'B' );
		$pc = $this->make_pattern( 'C' );

		$this->make_post_referencing( array( $pa ) );          // pa = 1
		$this->make_post_referencing( array( $pa, $pb ) );     // pa = 2, pb = 1
		$this->make_post_referencing( array( $pb ) );          // pb = 2
		// pc has zero referencing posts.

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertSame( 2, $counts[ $pa ] );
		$this->assertSame( 2, $counts[ $pb ] );
		$this->assertArrayNotHasKey( $pc, $counts );
	}

	/**
	 * Per-post de-duplication: a post that references the same pattern twice
	 * counts as ONE referencing post — matching the legacy COUNT(DISTINCT ID).
	 */
	public function test_same_pattern_referenced_twice_in_one_post_counts_once(): void {
		$pa = $this->make_pattern();

		$this->make_post_referencing( array( $pa, $pa, $pa ) );

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertSame( 1, $counts[ $pa ] );
	}

	/**
	 * Numeric-prefix safety: the regex must not let `"ref":<X>9...` falsely
	 * increment pattern `X`. The trailing `[,}]` boundary on
	 * `/"ref":(\d+)\s*[,}]/` is what guarantees this.
	 *
	 * Uses a real pattern `$pa` plus a synthetic longer ref starting with
	 * `$pa`'s digits (e.g. pa=4 → longer=499). The longer ID is filtered
	 * out separately by the orphan filter (covered in
	 * test_excludes_refs_to_non_existent_patterns); here we only assert
	 * that the real pattern's count is NOT incremented by the prefix-y ref.
	 */
	public function test_does_not_count_numeric_prefix_matches(): void {
		$pa     = $this->make_pattern();
		$longer = (int) ( $pa . '99' );

		// One post that legitimately references $pa.
		$this->make_post_referencing( array( $pa ) );

		// Another post whose only ref is the longer-id-starting-with-$pa.
		// A regex without the trailing boundary would also count $pa here.
		self::factory()->post->create( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_content' => sprintf( '<!-- wp:block {"ref":%d} /-->', $longer ),
		) );

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertSame( 1, $counts[ $pa ], 'pa must not be incremented by the longer-prefix ref.' );
	}

	/** Drafts, private posts, and trashed posts must NOT contribute. */
	public function test_excludes_non_published_posts(): void {
		$pa = $this->make_pattern();

		$this->make_post_referencing( array( $pa ), 'publish' );
		$this->make_post_referencing( array( $pa ), 'draft' );
		$this->make_post_referencing( array( $pa ), 'private' );
		$this->make_post_referencing( array( $pa ), 'trash' );

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertSame( 1, $counts[ $pa ] );
	}

	/** Cache populates the transient and is read back identically. */
	public function test_persists_and_reads_from_transient(): void {
		$pa = $this->make_pattern();
		$this->make_post_referencing( array( $pa ) );

		$first = $this->manager->get_all_pattern_reference_counts();

		$transient = get_transient( Pattern_Manager::REF_COUNT_CACHE_KEY );
		$this->assertIsArray( $transient );
		$this->assertSame( $first, $transient );
	}

	/**
	 * Refs to a pattern post ID that doesn't exist on this site (orphaned
	 * imports, copy-pasted content from another install) must NOT appear
	 * in the counts map.
	 */
	public function test_excludes_refs_to_non_existent_patterns(): void {
		$pa    = $this->make_pattern();
		$bogus = 9999999;

		$this->make_post_referencing( array( $pa, $bogus ) );

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertSame( 1, $counts[ $pa ] );
		$this->assertArrayNotHasKey( $bogus, $counts, 'Orphaned refs are dropped from the cached map.' );
	}

	/**
	 * Realistic-scale stress: thousands of patterns + thousands of
	 * referencing posts at the production batch size (200), spanning
	 * many chunk iterations. Validates that:
	 *
	 *   - the chunked do/while loop terminates correctly across many batches
	 *   - the orphan-filter allow-list (also capped at 500 by default) is
	 *     raised in lock-step so all real patterns are recognized
	 *   - per-pattern counts add up correctly under volume — every random
	 *     reference inserted is reflected in the final map
	 *
	 * Bumps the shared `gk_block_api_synced_patterns_query_limit` filter
	 * so the allow-list covers all inserted patterns. Test is marked @group
	 * stress so it can be skipped in fast runs if it becomes painful.
	 *
	 * @group stress
	 */
	public function test_thousands_of_patterns_and_references(): void {
		$pattern_count = 2000;
		$post_count    = 2000;
		$cap           = static function () use ( $pattern_count ): int {
			return $pattern_count + 100;
		};
		add_filter( 'gk_block_api_synced_patterns_query_limit', $cap );

		try {
			// Insert $pattern_count synced patterns.
			$pattern_ids = array();
			for ( $i = 0; $i < $pattern_count; $i++ ) {
				$pattern_ids[] = $this->make_pattern( 'P' . $i );
			}

			// Insert $post_count referencing posts. Each one references 1-3
			// random patterns; track the expected count per pattern locally.
			$expected = array();
			mt_srand( 0xC0DEC0DE );
			for ( $i = 0; $i < $post_count; $i++ ) {
				$refs_per_post = 1 + ( $i % 3 );
				$picked        = array();
				for ( $j = 0; $j < $refs_per_post; $j++ ) {
					$picked[] = $pattern_ids[ mt_rand( 0, $pattern_count - 1 ) ];
				}
				$this->make_post_referencing( $picked );
				// De-dupe within a post to match COUNT(DISTINCT ID) semantics.
				foreach ( array_unique( $picked ) as $pid ) {
					$expected[ $pid ] = isset( $expected[ $pid ] ) ? $expected[ $pid ] + 1 : 1;
				}
			}

			$counts = $this->manager->get_all_pattern_reference_counts();

			// Spot-check: total count of referencing-post-pattern pairs
			// across the map equals the sum of our expected map.
			$this->assertSame(
				array_sum( $expected ),
				array_sum( $counts ),
				'Sum of per-pattern counts must match the expected total under load.'
			);

			// Spot-check: every expected entry matches exactly.
			foreach ( $expected as $pid => $count ) {
				$this->assertSame(
					$count,
					$counts[ $pid ] ?? 0,
					sprintf( 'Pattern %d expected %d refs, got %d', $pid, $count, $counts[ $pid ] ?? 0 )
				);
			}

			// Spot-check: no extra entries leaked in (e.g. orphan-filter bypass).
			$this->assertSame(
				count( $expected ),
				count( $counts ),
				'Map must contain exactly the patterns that were referenced — no orphans or duplicates.'
			);
		} finally {
			remove_filter( 'gk_block_api_synced_patterns_query_limit', $cap );
		}
	}

	/**
	 * The aggregate pages through `wp_posts` in batches to bound peak memory.
	 * This test forces a tiny batch size via filter so we can exercise the
	 * pagination boundary cheaply, then asserts that the chunked totals sum
	 * to the same per-pattern counts a single-pass scan would produce.
	 *
	 * Regression guard against an off-by-one in the do/while loop termination
	 * or an incorrect offset increment dropping rows on a batch boundary.
	 */
	public function test_chunked_scan_counts_match_across_batches(): void {
		$tiny_batch = static function (): int {
			return 2;
		};
		add_filter( 'gk_block_api_pattern_ref_scan_batch_size', $tiny_batch );

		try {
			$pa = $this->make_pattern( 'Hot pattern' );
			$pb = $this->make_pattern( 'Cool pattern' );

			// 5 posts referencing pa — exceeds batch size of 2 (3 batches).
			for ( $i = 0; $i < 5; $i++ ) {
				$this->make_post_referencing( array( $pa ) );
			}
			// 3 posts referencing pb — also spans batches (2 batches).
			for ( $i = 0; $i < 3; $i++ ) {
				$this->make_post_referencing( array( $pb ) );
			}

			$counts = $this->manager->get_all_pattern_reference_counts();

			$this->assertSame( 5, $counts[ $pa ] );
			$this->assertSame( 3, $counts[ $pb ] );
		} finally {
			remove_filter( 'gk_block_api_pattern_ref_scan_batch_size', $tiny_batch );
		}
	}
}
