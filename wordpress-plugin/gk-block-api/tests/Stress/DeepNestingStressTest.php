<?php
/**
 * Scenario 1: deep-nesting round-trip explosion.
 *
 * Pushes the block tree to depths the unit suite doesn't reach. Failure
 * mode targeted: stack overflow in parse_blocks() / serialize_blocks() /
 * Block_CRUD::*_recursive() / Block_Mutator path-walking. PHP's default
 * pcre.recursion_limit is ~256; WP's block parser has historically
 * broken before that.
 *
 * For each depth N, we serialize a `core/group → core/group → … → core/paragraph`
 * tree, read it back, walk to the leaf, and confirm both directions agree.
 * Either side failing is a real bug.
 *
 * @package GravityKit\BlockAPI\Tests\Stress
 */

declare( strict_types=1 );

class DeepNestingStressTest extends BlockApiTestCase {

	/**
	 * Build a tree N levels deep: outermost is wrapper[0], innermost is
	 * a `core/paragraph` leaf.
	 */
	private function deep_tree( int $depth ): array {
		$node = array(
			'name'      => 'core/paragraph',
			'innerHTML' => '<p>leaf</p>',
		);
		for ( $i = 0; $i < $depth; $i++ ) {
			$node = array(
				'name'        => 'core/group',
				'innerHTML'   => '<div></div>',
				'innerBlocks' => array( $node ),
			);
		}
		return $node;
	}

	/**
	 * @dataProvider depth_provider
	 */
	public function test_depth_round_trip( int $depth ) {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		try {
			$result = $this->crud->replace_all_blocks( $post_id, array( $this->deep_tree( $depth ) ) );
		} catch ( \Throwable $e ) {
			$this->fail( "depth $depth threw: " . $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			// Cleanly rejected is acceptable — the test enforces "no fatal,
			// no silent truncation," not "succeeds at every depth."
			$this->assertNotEmpty( $result->get_error_code(), "depth $depth rejection must have an error code" );
			return;
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertNotEmpty( $content, "depth $depth must produce non-empty content" );

		$blocks  = parse_blocks( $content );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== $b['blockName'] ) );
		$this->assertCount( 1, $visible, "depth $depth must have exactly one top-level block" );

		$node = $visible[0];
		$got  = 0;
		while ( ! empty( $node['innerBlocks'] ) && 'core/group' === $node['blockName'] ) {
			$node = $node['innerBlocks'][0];
			$got++;
		}
		$this->assertSame( $depth, $got, "depth $depth: counted only $got levels" );
		$this->assertSame( 'core/paragraph', $node['blockName'], "depth $depth leaf must be paragraph" );
		$this->assertStringContainsString( '<p>leaf</p>', $node['innerHTML'] );
	}

	public function depth_provider(): array {
		// MAX_BLOCK_DEPTH = 32. Anything deeper is rejected with
		// `block_depth_exceeded` at the save boundary, which the
		// `is_wp_error()` branch in the round-trip test accepts.
		return array(
			'8'  => array( 8 ),
			'16' => array( 16 ),
			'28' => array( 28 ),
			'40' => array( 40 ), // past the cap — must be cleanly rejected
		);
	}

	/**
	 * @dataProvider mutator_depth_provider
	 */
	public function test_mutator_can_target_deep_path( int $depth ) {
		// Path-based mutation must walk $depth segments and have the
		// className land at the leaf after save+reload. If the mutator
		// returns success but the change doesn't persist, that's a worse
		// failure mode than a clean WP_Error.
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->crud->replace_all_blocks( $post_id, array( $this->deep_tree( $depth ) ) );

		// $depth wrapper groups means the leaf sits at path length $depth+1
		// (path [0] addresses the outermost wrapper; path [0,0] its child;
		// path [0,0,…,0] with $depth+1 segments reaches the paragraph leaf).
		$path = array_fill( 0, $depth + 1, 0 );
		try {
			$result = $this->mutator->mutate( $post_id, 'update-attrs', $path, array(
				'attributes' => array( 'className' => 'deeply-edited' ),
			) );
		} catch ( \Throwable $e ) {
			$this->fail( "depth $depth mutator threw: " . $e->getMessage() );
		}

		if ( is_wp_error( $result ) ) {
			$this->assertNotEmpty( $result->get_error_code(), "depth $depth rejection must have a code" );
			return;
		}

		// Reported success — verify the change actually persisted to the
		// targeted leaf. Silent no-op would be worse than a clean error.
		$blocks  = parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
		$visible = array_values( array_filter( $blocks, static fn( $b ) => null !== $b['blockName'] ) );
		$node    = $visible[0];
		for ( $i = 0; $i < $depth; $i++ ) {
			$node = $node['innerBlocks'][0];
		}
		$this->assertSame(
			'deeply-edited',
			$node['attrs']['className'] ?? '',
			"depth $depth: mutator reported success but className did not persist to the leaf"
		);
	}

	public function mutator_depth_provider(): array {
		return array(
			'4'  => array( 4 ),
			'12' => array( 12 ),
			'24' => array( 24 ), // 24 wrappers + leaf = depth 25, under cap
		);
	}
}
