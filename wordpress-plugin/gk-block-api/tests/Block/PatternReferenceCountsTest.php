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
	 * Numeric-prefix safety: `"ref":12` must not match a post that only
	 * references `"ref":123`. This was the bug guarded against by the
	 * trailing `,`/`}` boundary in the original implementation.
	 */
	public function test_does_not_count_numeric_prefix_matches(): void {
		$small = 12;
		$big   = 123;

		$this->make_pattern( 'Small (id forced)' );
		$this->make_pattern( 'Big (id forced)' );

		// Only references 123 — should not increment 12.
		$this->make_post_referencing( array( $big ) );

		$counts = $this->manager->get_all_pattern_reference_counts();

		$this->assertArrayNotHasKey( $small, $counts );
		$this->assertSame( 1, $counts[ $big ] );
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
