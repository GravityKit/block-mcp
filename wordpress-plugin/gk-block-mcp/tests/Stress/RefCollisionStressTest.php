<?php
/**
 * Scenario 6: ref-collision sweep under high churn.
 *
 * The plugin's per-post uniqueness invariant: every block in a post has
 * a `gk_ref` that no other block in that post shares. That's what makes
 * ref-based addressing work across mutations.
 *
 * Block_CRUD::generate_ref() emits 9-hex-char refs (36 bits of entropy)
 * — birthday-paradox collisions appear around sqrt(2^36 / 2) ≈ 185k
 * raw rolls, far past any realistic per-post count. On top of that the
 * recursive assigners (`assign_missing_refs_recursive` /
 * `assign_fresh_refs_recursive`) thread an in-use set through the
 * recursion via `generate_unique_ref()` and re-roll on collision,
 * making the per-post invariant deterministic.
 *
 * @package GravityKit\BlockMCP\Tests\Stress
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_CRUD;

class RefCollisionStressTest extends BlockApiTestCase {

	/**
	 * 2000 sibling blocks — well above any plausible page, well within
	 * the deterministic-uniqueness contract.
	 */
	public function test_per_post_assignment_produces_unique_refs_at_realistic_scale() {
		$count  = 2000;
		$blocks = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$blocks[] = array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerHTML'    => "<p>$i</p>",
				'innerContent' => array( "<p>$i</p>" ),
				'innerBlocks'  => array(),
			);
		}

		$this->crud->assign_missing_refs_recursive( $blocks );

		$seen = array();
		foreach ( $blocks as $i => $block ) {
			$ref = $block['attrs']['metadata']['gk_ref'];
			$this->assertNotEmpty( $ref, "block $i did not receive a ref" );
			if ( isset( $seen[ $ref ] ) ) {
				$this->fail( "ref collision: block $i and block {$seen[$ref]} share $ref" );
			}
			$seen[ $ref ] = $i;
		}
		$this->assertCount( $count, $seen );
	}

	/**
	 * Build a 100-block tree, assign refs, deep-clone, then call
	 * `assign_fresh_refs_recursive` on the clone. The two trees must
	 * have completely disjoint ref sets.
	 */
	public function test_fresh_refs_clone_invariant() {
		$blocks = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$blocks[] = array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerHTML'    => "<p>$i</p>",
				'innerContent' => array( "<p>$i</p>" ),
				'innerBlocks'  => array(),
			);
		}
		$this->crud->assign_missing_refs_recursive( $blocks );
		$source_refs = array_map( static fn( $b ) => $b['attrs']['metadata']['gk_ref'], $blocks );

		$clone = $blocks; // deep copy (arrays of primitives are copy-on-write)
		$this->crud->assign_fresh_refs_recursive( $clone );
		$clone_refs = array_map( static fn( $b ) => $b['attrs']['metadata']['gk_ref'], $clone );

		$intersect = array_intersect( $source_refs, $clone_refs );
		$this->assertEmpty( $intersect, 'duplicate clones must produce disjoint ref sets' );
		// And the clone refs are themselves unique.
		$this->assertSame( count( $clone_refs ), count( array_unique( $clone_refs ) ) );
	}

	/**
	 * Seed half the blocks with pre-assigned refs, half without.
	 * The assigner must skip the seeded ones AND not mint a fresh
	 * ref that happens to match one already in the tree.
	 */
	public function test_assign_missing_refs_does_not_collide_with_existing() {
		$blocks = array();
		for ( $i = 0; $i < 50; $i++ ) {
			$blocks[] = array(
				'blockName' => 'core/paragraph',
				'attrs'     => array( 'metadata' => array( 'gk_ref' => 'blk_seed' . sprintf( '%03d', $i ) ) ),
				'innerHTML' => "<p>seeded-$i</p>",
				'innerBlocks' => array(),
			);
		}
		for ( $i = 0; $i < 50; $i++ ) {
			$blocks[] = array(
				'blockName' => 'core/paragraph',
				'attrs'     => array(),
				'innerHTML' => "<p>unseeded-$i</p>",
				'innerBlocks' => array(),
			);
		}

		$this->crud->assign_missing_refs_recursive( $blocks );

		// Seeded refs survive untouched.
		for ( $i = 0; $i < 50; $i++ ) {
			$this->assertSame(
				'blk_seed' . sprintf( '%03d', $i ),
				$blocks[ $i ]['attrs']['metadata']['gk_ref'],
				"seeded ref at index $i must not have been overwritten"
			);
		}
		// All 100 refs are unique.
		$refs = array_map( static fn( $b ) => $b['attrs']['metadata']['gk_ref'], $blocks );
		$this->assertSame( count( $refs ), count( array_unique( $refs ) ) );
	}

	public function test_refs_match_url_safe_regex() {
		// The by-ref REST route uses [\w-]+; refs must match.
		for ( $i = 0; $i < 1_000; $i++ ) {
			$ref = Block_CRUD::generate_ref();
			$this->assertMatchesRegularExpression( '/^[\w-]+$/', $ref, "ref $ref must be URL-safe" );
		}
	}

	public function test_refs_prefixed_with_blk_for_visibility() {
		for ( $i = 0; $i < 100; $i++ ) {
			$ref = Block_CRUD::generate_ref();
			$this->assertStringStartsWith( 'blk_', $ref );
		}
	}

	public function test_generate_unique_ref_avoids_seeded_collisions() {
		// Pretend every hash in some set is already taken — generate_unique_ref
		// must still return something outside that set.
		$in_use = array();
		// Seed with 50 fresh refs.
		for ( $i = 0; $i < 50; $i++ ) {
			$in_use[ Block_CRUD::generate_ref() ] = true;
		}
		for ( $i = 0; $i < 100; $i++ ) {
			$ref = Block_CRUD::generate_unique_ref( $in_use );
			$this->assertArrayNotHasKey( $ref, $in_use, "generate_unique_ref returned a colliding ref: $ref" );
			$in_use[ $ref ] = true;
		}
	}
}
