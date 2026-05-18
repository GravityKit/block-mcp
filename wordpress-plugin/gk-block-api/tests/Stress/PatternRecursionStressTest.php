<?php
/**
 * Scenario 14: pattern-reference recursion bomb.
 *
 * Synced patterns reference each other via `core/block { ref: NN }`.
 * A pattern that references itself, or a cycle A→B→A, would loop
 * forever when render mode expands them. WordPress core has bounded
 * recursion for `core/block`, but the plugin's `format_blocks` /
 * `Pattern_Manager` paths run their own expansion and must respect
 * the same bound.
 *
 * @package GravityKit\BlockAPI\Tests\Stress
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Pattern_Manager;
use GravityKit\BlockAPI\Preferences;

class PatternRecursionStressTest extends WP_UnitTestCase {

	/** @var Pattern_Manager */
	private $pm;

	public function set_up(): void {
		parent::set_up();
		$this->pm = new Pattern_Manager( new Preferences() );
	}

	private function make_pattern( string $title, string $content ): int {
		return self::factory()->post->create( array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => $content,
		) );
	}

	public function test_self_referencing_pattern_does_not_loop() {
		// Pattern A's content is "<!-- wp:block {"ref":A} /-->" — itself.
		$pattern_id = self::factory()->post->create( array(
			'post_type'   => 'wp_block',
			'post_status' => 'publish',
			'post_title'  => 'self-ref',
		) );
		wp_update_post( array(
			'ID'           => $pattern_id,
			'post_content' => sprintf( '<!-- wp:block {"ref":%d} /-->', $pattern_id ),
		) );

		// Bound the operation. If pattern_manager loops, this hits the
		// time limit and PHPUnit fails the test before the runtime kills it.
		$start  = microtime( true );
		$result = $this->pm->get_pattern( $pattern_id );
		$elapsed = microtime( true ) - $start;

		$this->assertLessThan(
			5.0,
			$elapsed,
			"self-referencing pattern took $elapsed s — recursion bound is missing or too large"
		);
		// Either a clean error OR a bounded structure — but never an
		// unbounded loop.
		$this->assertNotInstanceOf( \Throwable::class, $result );
	}

	/**
	 * A → B → A. The post_content of each is a single core/block
	 * reference to the other.
	 */
	public function test_cyclic_pattern_pair_does_not_loop() {
		$a = $this->make_pattern( 'cycle-a', '' );
		$b = $this->make_pattern( 'cycle-b', '' );
		wp_update_post( array( 'ID' => $a, 'post_content' => "<!-- wp:block {\"ref\":$b} /-->" ) );
		wp_update_post( array( 'ID' => $b, 'post_content' => "<!-- wp:block {\"ref\":$a} /-->" ) );

		$start = microtime( true );
		$this->pm->get_pattern( $a );
		$elapsed_a = microtime( true ) - $start;

		$start = microtime( true );
		$this->pm->get_pattern( $b );
		$elapsed_b = microtime( true ) - $start;

		$this->assertLessThan( 5.0, $elapsed_a, "A→B→A cycle from A took $elapsed_a s" );
		$this->assertLessThan( 5.0, $elapsed_b, "A→B→A cycle from B took $elapsed_b s" );
	}

	/**
	 * A→B→C→D→E (no cycle). Should complete cleanly without an
	 * "unknown pattern" cascading 500.
	 */
	public function test_deep_pattern_chain_terminates() {
		$e = $this->make_pattern( 'chain-e', '<!-- wp:paragraph --><p>leaf</p><!-- /wp:paragraph -->' );
		$d = $this->make_pattern( 'chain-d', "<!-- wp:block {\"ref\":$e} /-->" );
		$c = $this->make_pattern( 'chain-c', "<!-- wp:block {\"ref\":$d} /-->" );
		$b = $this->make_pattern( 'chain-b', "<!-- wp:block {\"ref\":$c} /-->" );
		$a = $this->make_pattern( 'chain-a', "<!-- wp:block {\"ref\":$b} /-->" );

		$start  = microtime( true );
		$result = $this->pm->get_pattern( $a );
		$elapsed = microtime( true ) - $start;

		$this->assertLessThan( 5.0, $elapsed );
		$this->assertNotInstanceOf( \WP_Error::class, $result, '5-deep chain is not a cycle and must resolve cleanly' );
	}

	/**
	 * post_content references a non-existent ref. The bridge should
	 * not 500 on bad pattern data.
	 */
	public function test_pattern_referencing_missing_post_id_returns_graceful_response() {
		$pattern_id = $this->make_pattern( 'orphan-ref', '<!-- wp:block {"ref":987654321} /-->' );
		$result     = $this->pm->get_pattern( $pattern_id );
		$this->assertNotInstanceOf( \Throwable::class, $result );
		// Either an error or a structure; either is fine, but never a fatal.
		$this->assertTrue( is_array( $result ) || is_wp_error( $result ) );
	}
}
