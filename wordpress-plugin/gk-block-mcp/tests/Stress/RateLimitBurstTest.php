<?php
/**
 * Scenario 5: rate-limit burst — sequential approximation.
 *
 * True concurrent fan-out would need parallel PHP processes; in-process
 * PHPUnit can't reach that level of contention. The next-best thing is
 * a tight loop: fire writes back-to-back as fast as possible and assert
 * the rate limiter rejects after exactly N successes, where N matches
 * the configured RATE_LIMIT_WRITES / RATE_LIMIT_PUT constants.
 *
 * If the limiter were leaky (read-modify-write races, missing record
 * call on success, etc.) we'd see more than N successes.
 *
 * @package GravityKit\BlockMCP\Tests\Stress
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_CRUD;

class RateLimitBurstTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerHTML'    => '<p>seed</p>',
					'innerContent' => array( '<p>seed</p>' ),
					'innerBlocks'  => array(),
				),
			)
		);
	}

	public function test_burst_writes_capped_at_rate_limit() {
		$cap        = Block_CRUD::RATE_LIMIT_WRITES;
		$attempts   = $cap + 5;
		$successes  = 0;
		$rate_limit = 0;
		$other      = 0;

		for ( $i = 0; $i < $attempts; $i++ ) {
			$result = $this->crud->update_block( $this->post_id, 0, array(), "<p>burst-$i</p>" );
			if ( is_wp_error( $result ) ) {
				if ( 'rate_limit_exceeded' === $result->get_error_code() ) {
					$rate_limit++;
				} else {
					$other++;
				}
			} else {
				$successes++;
			}
		}

		$this->assertSame(
			$cap,
			$successes,
			"expected exactly $cap successful writes; got $successes (rate-limited: $rate_limit; other errors: $other)"
		);
		$this->assertSame( $attempts - $cap, $rate_limit, 'all over-cap writes must produce rate_limit_exceeded specifically' );
		$this->assertSame( 0, $other, 'no other error type should appear under burst' );
	}

	public function test_burst_puts_capped_separately_from_writes() {
		$cap        = Block_CRUD::RATE_LIMIT_PUT;
		$attempts   = $cap + 3;
		$successes  = 0;
		$rate_limit = 0;

		for ( $i = 0; $i < $attempts; $i++ ) {
			$result = $this->crud->replace_all_blocks( $this->post_id, array(
				array( 'name' => 'core/paragraph', 'innerHTML' => "<p>put-$i</p>" ),
			) );
			if ( is_wp_error( $result ) && 'rate_limit_exceeded' === $result->get_error_code() ) {
				$rate_limit++;
			} elseif ( ! is_wp_error( $result ) ) {
				$successes++;
			}
		}

		$this->assertSame( $cap, $successes, "PUT cap is $cap; got $successes successes" );
		$this->assertSame( $attempts - $cap, $rate_limit );
	}

	/**
	 * PUT has a tighter cap than writes, AND it also has to pass the
	 * writes check. That means a small number of PUTs alone exhaust
	 * the puts bucket first — they don't need to consume the writes
	 * budget to be rejected.
	 */
	public function test_put_bucket_blocked_independently_of_writes() {
		$put_cap = Block_CRUD::RATE_LIMIT_PUT;

		// Exactly $put_cap PUTs in a row.
		for ( $i = 0; $i < $put_cap; $i++ ) {
			$result = $this->crud->replace_all_blocks( $this->post_id, array(
				array( 'name' => 'core/paragraph', 'innerHTML' => "<p>$i</p>" ),
			) );
			$this->assertNotInstanceOf( \WP_Error::class, $result, "PUT #$i must succeed within cap" );
		}
		// The next PUT must hit the puts bucket, not the writes bucket.
		$over = $this->crud->replace_all_blocks( $this->post_id, array(
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>over</p>' ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $over );
		$this->assertSame( 'rate_limit_exceeded', $over->get_error_code() );

		// We've only made $put_cap writes total (each PUT counts as one
		// write). The writes bucket is far from exhausted; an update_block
		// should still succeed.
		$update = $this->crud->update_block( $this->post_id, 0, array(), '<p>still-have-budget</p>' );
		$this->assertNotInstanceOf( \WP_Error::class, $update, 'writes bucket should not be exhausted by 2 PUTs' );
	}

	/**
	 * Pump 100 attempts. The transient stores only timestamps within
	 * the sliding window — entries older than 60 s expire on the next
	 * check. After the burst, the transient array should be bounded.
	 */
	public function test_transient_does_not_grow_unbounded() {
		for ( $i = 0; $i < 100; $i++ ) {
			$this->crud->update_block( $this->post_id, 0, array(), "<p>p$i</p>" );
		}
		$state = get_transient( 'gk_block_api_rate_' . $this->post_id );
		$this->assertIsArray( $state, 'rate-limit transient must exist after writes' );
		$this->assertArrayHasKey( 'writes', $state );

		// Even if all 100 writes were within 60s, the stored count
		// shouldn't exceed the cap — record_rate_limit prunes on each
		// write.
		$this->assertLessThanOrEqual(
			Block_CRUD::RATE_LIMIT_WRITES + 5, // grace for pre-prune writes
			count( $state['writes'] ),
			'rate-limit transient grew unbounded under burst'
		);
	}
}
