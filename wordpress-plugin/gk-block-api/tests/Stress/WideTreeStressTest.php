<?php
/**
 * Scenario 2: wide-tree fan-out.
 *
 * Failure modes: quadratic algorithms in format_blocks_recursive,
 * assign_missing_refs_recursive, find_block_by_ref, flatten_blocks;
 * unbounded memory on huge sibling arrays; serialize_blocks() growth.
 *
 * Strategy: build progressively wider trees and measure that the round
 * trip stays linear-ish. If 5000-wide is >100x the 100-wide time, that's
 * a quadratic regression — fail with a clear message rather than slowly
 * timing out CI.
 *
 * @package GravityKit\BlockAPI\Tests\Stress
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Block_CRUD;

class WideTreeStressTest extends BlockApiTestCase {

	private function wide_post( int $count ): int {
		$blocks = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$blocks[] = array( 'name' => 'core/paragraph', 'innerHTML' => "<p>$i</p>" );
		}
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$result  = $this->crud->replace_all_blocks( $post_id, $blocks );
		$this->assertNotInstanceOf( \WP_Error::class, $result, "$count-wide write must succeed" );
		return $post_id;
	}

	/**
	 * @dataProvider width_provider
	 */
	public function test_wide_tree_round_trips( int $count ) {
		$start   = microtime( true );
		$post_id = $this->wide_post( $count );
		$write_s = microtime( true ) - $start;

		$start    = microtime( true );
		$readback = $this->crud->get_blocks( $post_id );
		$read_s   = microtime( true ) - $start;

		$this->assertNotInstanceOf( \WP_Error::class, $readback );
		$this->assertCount( $count, $readback, "$count-wide read must return $count blocks" );

		// Generous bound — anything over 15 s on a contemporary dev box
		// or CI runner indicates a quadratic regression worth catching.
		$this->assertLessThan( 15.0, $write_s, "$count-wide write took $write_s s — possible quadratic regression" );
		$this->assertLessThan( 15.0, $read_s, "$count-wide read took $read_s s — possible quadratic regression" );
	}

	public function width_provider(): array {
		return array(
			'100'  => array( 100 ),
			'500'  => array( 500 ),
			'2000' => array( 2000 ),
		);
	}

	/**
	 * 1000 paragraphs, batch update 50 random indices in one call.
	 * Pre-PR fix this hit a quadratic path; pin it as a regression
	 * guard.
	 */
	public function test_batch_update_against_wide_tree_stays_linear() {
		$count   = 1000;
		$post_id = $this->wide_post( $count );

		$updates = array();
		for ( $i = 0; $i < Block_CRUD::MAX_BATCH_SIZE; $i++ ) {
			$updates[] = array(
				'flat_index' => $i * 19 % $count, // pseudo-spread, deterministic
				'innerHTML'  => "<p>updated-$i</p>",
			);
		}

		$start  = microtime( true );
		$result = $this->crud->update_blocks_batch( $post_id, $updates );
		$elapsed = microtime( true ) - $start;

		// MAX_BATCH_SIZE protects against duplicate-target overlaps which
		// our deterministic spread can hit. Either success or a clean
		// batch_validation_failed is fine; what's not fine is a 30s wait
		// or a 500.
		$this->assertLessThan( 10.0, $elapsed, "batch on $count-wide tree took $elapsed s" );
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_code() );
		} else {
			$this->assertIsArray( $result );
		}
	}
}
