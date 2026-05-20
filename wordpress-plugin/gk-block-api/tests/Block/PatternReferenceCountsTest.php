<?php
/**
 * Regression coverage for Pattern_Manager::get_all_pattern_reference_counts().
 *
 * The aggregate replaces the per-pattern LIKE scan that ran twice per synced
 * pattern in `count_pattern_references()`. These tests pin the contract:
 *
 *   - one map built from one DB scan, no per-pattern queries
 *   - trailing-boundary match: `"ref":12` does not collide with `"ref":123`
 *   - per-post de-duplication: a post that references the same pattern
 *     twice counts once (matches the historical COUNT(DISTINCT ID) semantic)
 *   - draft / private / trashed posts are excluded
 *   - results are cached in a transient and a per-request memo
 *   - the cache survives across calls and is busted by the transient API
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Pattern_Manager;
use GravityKit\BlockAPI\Preferences;

/**
 * @covers \GravityKit\BlockAPI\Pattern_Manager::get_all_pattern_reference_counts
 * @covers \GravityKit\BlockAPI\Pattern_Manager::count_pattern_references
 */
class PatternReferenceCountsTest extends WP_UnitTestCase {

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
		return self::factory()->post->create( array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
		) );
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
	 * `/"ref":(\d+)\s*[,}]/` is what guarantees this. We use real pattern
	 * `$pa` plus a synthetic longer ref starting with `$pa`'s digits
	 * (e.g. pa=4 → longer=499). The longer ID isn't a real pattern, so
	 * the orphan-id filter drops it from the map; the real pattern's
	 * count must remain 1 from its one explicit reference.
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
		$this->assertArrayNotHasKey( $longer, $counts, 'longer is not a real pattern, so it is filtered out.' );
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
	 * Subsequent calls on a fresh Pattern_Manager (no per-request memo)
	 * must read from the transient instead of rescanning. We prove this
	 * by mutating the transient between calls — if a rescan ran, the
	 * planted value would be overwritten with the real count.
	 */
	public function test_second_call_uses_transient_not_db(): void {
		$pa = $this->make_pattern();
		$this->make_post_referencing( array( $pa ) );

		// Prime the transient with a known non-real value.
		set_transient( Pattern_Manager::REF_COUNT_CACHE_KEY, array( $pa => 999 ), HOUR_IN_SECONDS );

		// Fresh instance to bypass any per-request static memo from a
		// previous call inside the same test.
		$fresh = new Pattern_Manager( new Preferences() );

		$counts = $fresh->get_all_pattern_reference_counts();

		$this->assertSame( 999, $counts[ $pa ], 'Transient should be returned without rescanning the DB.' );
	}

	/**
	 * Refs to a pattern post ID that doesn't exist on this site (orphaned
	 * imports, copy-pasted content from another install) must NOT appear
	 * in the counts map. The previous implementation tallied any numeric
	 * ref it saw, which let the transient grow with bogus keys.
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
	 * The aggregate pages through `wp_posts` in batches to bound peak memory.
	 * This test forces a tiny batch size via filter so we can exercise the
	 * pagination boundary cheaply, then asserts that the chunked totals sum
	 * to the same per-pattern counts a single-pass scan would produce.
	 *
	 * Regression guard against an off-by-one in the do/while loop termination
	 * or an incorrect offset increment dropping rows on a batch boundary.
	 */
	public function test_chunked_scan_counts_match_across_batches(): void {
		add_filter(
			'gk_block_api_pattern_ref_scan_batch_size',
			static function (): int {
				return 2;
			}
		);

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
			remove_all_filters( 'gk_block_api_pattern_ref_scan_batch_size' );
		}
	}

	/** count_pattern_references() returns the map's value for the given pattern. */
	public function test_count_pattern_references_delegates_to_map(): void {
		$pa = $this->make_pattern();
		$pb = $this->make_pattern();

		$this->make_post_referencing( array( $pa ) );
		$this->make_post_referencing( array( $pa ) );
		// pb has no references.

		// reflection: count_pattern_references is private; invoke via reflection.
		$ref    = new ReflectionMethod( $this->manager, 'count_pattern_references' );
		$ref->setAccessible( true );

		$this->assertSame( 2, $ref->invoke( $this->manager, $pa ) );
		$this->assertSame( 0, $ref->invoke( $this->manager, $pb ) );
		$this->assertSame( 0, $ref->invoke( $this->manager, 0 ), 'Non-positive IDs short-circuit to 0.' );
		$this->assertSame( 0, $ref->invoke( $this->manager, -5 ), 'Negative IDs short-circuit to 0.' );
	}
}
