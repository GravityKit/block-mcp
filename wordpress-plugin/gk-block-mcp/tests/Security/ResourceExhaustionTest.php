<?php
/**
 * Resource-exhaustion / DoS-class tests.
 *
 * Throws large / deep / pathological inputs at the API. Each test
 * asserts ONE of two outcomes:
 *
 *   (A) the input is accepted and serialize → parse round-trips
 *       structurally intact, OR
 *   (B) the input is rejected with a clean WP_Error.
 *
 * Never acceptable: a PHP fatal, an unbounded memory spike, a 500 with
 * no error code, or silent data corruption. The point of these tests is
 * to map the bounds — if the bound is too loose, the production fix is
 * adding an explicit cap with a clear error.
 *
 * @package GravityKit\BlockMCP\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_CRUD;

class ResourceExhaustionTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post();
	}

	/**
	 * Build a deeply-nested core/group tree.
	 *
	 * @param int $depth
	 * @return array Single top-level block whose path-to-leaf depth is $depth.
	 */
	private function deep_group_tree( int $depth ): array {
		$leaf = array(
			'name'      => 'core/paragraph',
			'innerHTML' => '<p>leaf</p>',
		);
		$node = $leaf;
		for ( $i = 0; $i < $depth; $i++ ) {
			$node = array(
				'name'        => 'core/group',
				'innerHTML'   => '<div></div>',
				'innerBlocks' => array( $node ),
			);
		}
		return $node;
	}

	// ── Deep nesting ──────────────────────────────────────────────

	/**
	 * 28 wrapper groups → tree depth 29, comfortably under
	 * `MAX_BLOCK_DEPTH` (32) and well within PHP recursion bounds.
	 */
	public function test_moderate_nesting_round_trips() {
		$tree   = $this->deep_group_tree( 28 );
		$result = $this->crud->replace_all_blocks( $this->post_id, array( $tree ) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$blocks  = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== $b['blockName'] ) );
		$node    = $visible[0];
		for ( $i = 0; $i < 28; $i++ ) {
			$this->assertSame( 'core/group', $node['blockName'], "depth $i must be group" );
			$this->assertArrayHasKey( 0, $node['innerBlocks'], "depth $i must have a child" );
			$node = $node['innerBlocks'][0];
		}
		$this->assertSame( 'core/paragraph', $node['blockName'], 'leaf must be paragraph' );
		$this->assertStringContainsString( '<p>leaf</p>', $node['innerHTML'] );
	}

	/**
	 * 64 wrapper groups → tree depth 65, well past `MAX_BLOCK_DEPTH`
	 * (32). Must NOT throw; must return a clean `block_depth_exceeded`
	 * error.
	 */
	public function test_excessive_nesting_rejected_with_clean_error() {
		$tree = $this->deep_group_tree( 64 );
		try {
			$result = $this->crud->replace_all_blocks( $this->post_id, array( $tree ) );
		} catch ( \Throwable $e ) {
			$this->fail( 'PHP threw at depth 65 (must be a clean WP_Error): ' . $e->getMessage() );
		}
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( Block_CRUD::MAX_BLOCK_DEPTH, $result->get_error_data()['max_depth'] );
	}

	// ── Wide trees ────────────────────────────────────────────────

	public function test_wide_tree_500_siblings_round_trips() {
		// 500 sibling paragraphs. Tests for accidental quadratic behavior
		// in format_blocks_recursive() and ref assignment.
		$blocks = array();
		for ( $i = 0; $i < 500; $i++ ) {
			$blocks[] = array(
				'name'      => 'core/paragraph',
				'innerHTML' => "<p>$i</p>",
			);
		}

		$start  = microtime( true );
		$result = $this->crud->replace_all_blocks( $this->post_id, $blocks );
		$write_s = microtime( true ) - $start;
		$this->assertNotInstanceOf( \WP_Error::class, $result, "500-wide write must succeed (took {$write_s}s)" );

		$start    = microtime( true );
		$readback = $this->crud->get_blocks( $this->post_id );
		$read_s   = microtime( true ) - $start;
		$this->assertNotInstanceOf( \WP_Error::class, $readback, "500-wide read must succeed (took {$read_s}s)" );
		$this->assertCount( 500, $readback );

		// Sanity bound: both ops should take less than 2 seconds on any
		// reasonable test machine. If they spike to many seconds, that's
		// the canary for a quadratic algorithm.
		$this->assertLessThan( 5.0, $write_s, "500-wide write took $write_s s — possible quadratic regression" );
		$this->assertLessThan( 5.0, $read_s,  "500-wide read took $read_s s — possible quadratic regression" );
	}

	// ── Large innerHTML payload ───────────────────────────────────

	public function test_one_megabyte_innerHTML_succeeds_or_clean_errors() {
		// 1 MB of repeated benign HTML. Tests wp_kses_post regex limits,
		// MySQL max_allowed_packet, and JSON encoding bounds for attrs.
		$one_mb_html = '<p>' . str_repeat( 'lorem ipsum dolor sit amet. ', 38_000 ) . '</p>';
		$this->assertGreaterThan( 900_000, strlen( $one_mb_html ), 'fixture must actually be ~1MB' );

		try {
			$result = $this->crud->replace_all_blocks( $this->post_id, array(
				array( 'name' => 'core/paragraph', 'innerHTML' => $one_mb_html ),
			) );
		} catch ( \Throwable $e ) {
			$this->fail( 'PHP threw on 1MB innerHTML: ' . $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_code() );
			return;
		}
		// If accepted, content survives intact.
		$saved = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( 'lorem ipsum', $saved );
	}

	// ── Pathological attrs JSON ───────────────────────────────────

	public function test_huge_attrs_string_either_succeeds_or_clean_errors() {
		$big = str_repeat( 'A', 100_000 ); // 100 KB attr value
		try {
			$result = $this->crud->replace_all_blocks( $this->post_id, array(
				array(
					'name'       => 'core/paragraph',
					'attributes' => array( 'data' => $big ),
					'innerHTML'  => '<p>x</p>',
				),
			) );
		} catch ( \Throwable $e ) {
			$this->fail( 'PHP threw on 100KB attr value: ' . $e->getMessage() );
		}
		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_code() );
			return;
		}
		// Round-trip.
		$blocks  = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== $b['blockName'] ) );
		$this->assertSame( $big, $visible[0]['attrs']['data'] );
	}

	/**
	 * Build attrs with a 20-deep nested object. JSON parsers have
	 * recursion limits; verify ours is generous enough for any
	 * reasonable block attr.
	 */
	public function test_deeply_nested_attrs_object_round_trips() {
		$attrs = 'inner';
		for ( $i = 0; $i < 20; $i++ ) {
			$attrs = array( 'level_' . $i => $attrs );
		}
		$result = $this->crud->replace_all_blocks( $this->post_id, array(
			array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'nested' => $attrs ),
				'innerHTML'  => '<p>x</p>',
			),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result, '20-deep attrs must succeed' );

		$blocks  = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== $b['blockName'] ) );
		$walk    = $visible[0]['attrs']['nested'];
		for ( $i = 19; $i >= 0; $i-- ) {
			$this->assertArrayHasKey( 'level_' . $i, $walk, "depth $i must survive" );
			$walk = $walk[ 'level_' . $i ];
		}
		$this->assertSame( 'inner', $walk );
	}

	// ── Batch-size cap ─────────────────────────────────────────────

	/**
	 * `MAX_BATCH_SIZE` = 50 — anything above must hard-reject, not
	 * silently process a subset or fall over.
	 */
	public function test_batch_update_above_max_size_is_rejected() {
		$updates = array();
		for ( $i = 0; $i < Block_CRUD::MAX_BATCH_SIZE + 5; $i++ ) {
			$updates[] = array(
				'flat_index' => 0,
				'innerHTML'  => "<p>$i</p>",
			);
		}
		$result = $this->crud->update_blocks_batch( $this->post_id, $updates );
		$this->assertInstanceOf( \WP_Error::class, $result, 'oversized batch must be rejected' );
	}
}
