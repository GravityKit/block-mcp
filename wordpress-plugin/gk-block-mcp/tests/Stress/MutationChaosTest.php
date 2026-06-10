<?php
/**
 * Scenario 7: mutation-chaos walk.
 *
 * Property-based test for the 9 path-based mutation operations.
 * Generates a randomized sequence of mutations against a seed post and
 * asserts that NO operation produces:
 *   - a PHP error / WP_Error with no code,
 *   - a parse/serialize round-trip mismatch,
 *   - a broken `innerContent` placeholder count (null entries must equal
 *     count of innerBlocks),
 *   - duplicate `gk_ref` values within the post,
 *   - a post_content that re-parses into a different tree.
 *
 * The PRNG seed is deterministic (PHP's `mt_srand` with a fixed seed)
 * so failures can be reproduced exactly. To investigate a failure, run
 * the failing test in isolation and print the mutation log.
 *
 * @package GravityKit\BlockMCP\Tests\Stress
 */

declare( strict_types=1 );


class MutationChaosTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post();

		// Seed with a small but interesting starter tree.
		$this->crud->replace_all_blocks( $this->post_id, array(
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>seed-0</p>' ),
			array(
				'name'        => 'core/group',
				'innerHTML'   => '<div></div>',
				'innerBlocks' => array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>g0.0</p>' ),
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>g0.1</p>' ),
				),
			),
			array( 'name' => 'core/heading', 'attributes' => array( 'level' => 2 ), 'innerHTML' => '<h2>seed-h</h2>' ),
		) );
	}

	/**
	 * Recursively collect every block in the tree, paired with the path
	 * to it. Used to pick a random valid path for the next op.
	 *
	 * @return array<int, array{path: int[], block: array}>
	 */
	private function collect_paths( array $blocks, array $prefix = array() ): array {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			if ( null === $block['blockName'] ) {
				continue; // freeform / whitespace
			}
			$path  = array_merge( $prefix, array( $i ) );
			$out[] = array( 'path' => $path, 'block' => $block );
			if ( ! empty( $block['innerBlocks'] ) ) {
				foreach ( $this->collect_paths( $block['innerBlocks'], $path ) as $entry ) {
					$out[] = $entry;
				}
			}
		}
		return $out;
	}

	/**
	 * Tree well-formedness assertions. Every step of the chaos walk must
	 * leave the post in a state that satisfies all of these.
	 */
	private function assertTreeWellFormed( int $iteration ): void {
		$content = (string) get_post_field( 'post_content', $this->post_id );
		$blocks  = parse_blocks( $content );

		// Initialize before the closure so the `&$refs_seen` reference
		// captured below has a defined target at every call.
		$refs_seen = array();
		$walker    = function ( $blocks, $where ) use ( $iteration, &$walker, &$refs_seen ) {
			foreach ( $blocks as $i => $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$here = $where . "[$i]";
				// innerContent nulls == innerBlocks count.
				$nulls = 0;
				foreach ( $block['innerContent'] as $piece ) {
					if ( null === $piece ) {
						$nulls++;
					}
				}
				$this->assertSame(
					count( $block['innerBlocks'] ),
					$nulls,
					"iter $iteration $here: innerContent null count ($nulls) must equal innerBlocks count (" . count( $block['innerBlocks'] ) . ')'
				);
				// Refs unique within the post.
				$ref = $block['attrs']['metadata']['gk_ref'] ?? null;
				if ( null !== $ref ) {
					$this->assertArrayNotHasKey(
						$ref,
						$refs_seen,
						"iter $iteration: ref $ref appears at $here AND " . ( $refs_seen[ $ref ] ?? '?' )
					);
					$refs_seen[ $ref ] = $here;
				}
				$walker( $block['innerBlocks'], $here );
			}
		};
		$walker( $blocks, '' );

		// Re-parse of re-serialized content matches the parsed tree —
		// the parse → serialize → parse cycle is idempotent.
		$re_serialized = serialize_blocks( $blocks );
		$re_parsed     = parse_blocks( $re_serialized );
		$this->assertSame(
			$this->canonicalize( $blocks ),
			$this->canonicalize( $re_parsed ),
			"iter $iteration: parse→serialize→parse must be idempotent"
		);
	}

	/**
	 * Strip out fields that legitimately differ between the in-memory
	 * shape and the re-parsed shape (notably innerHTML reconstruction).
	 * Compares only the structural skeleton: blockName, attrs, child count.
	 */
	private function canonicalize( array $blocks ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$out[] = array(
				'name'  => $block['blockName'],
				'attrs' => $block['attrs'],
				'inner' => $this->canonicalize( $block['innerBlocks'] ),
			);
		}
		return $out;
	}

	public function test_random_mutation_walk_preserves_invariants() {
		// Deterministic PRNG: failures reproduce on the same seed.
		mt_srand( 1337 );

		// All 9 ops, weighted toward the ones that change tree shape
		// (those are the most likely to introduce invariant violations).
		$op_weights = array(
			'update-attrs'  => 3,
			'update-html'   => 3,
			'replace-block' => 2,
			'wrap-in-group' => 2,
			'insert-child'  => 2,
			'duplicate'     => 2,
			'move'          => 1,
			'unwrap-group'  => 1,
			'remove-block'  => 1,
		);
		$op_pool = array();
		foreach ( $op_weights as $op => $w ) {
			for ( $j = 0; $j < $w; $j++ ) {
				$op_pool[] = $op;
			}
		}

		$iterations = 60;
		$ok_count   = 0;

		for ( $i = 0; $i < $iterations; $i++ ) {
			$content    = (string) get_post_field( 'post_content', $this->post_id );
			$parsed     = parse_blocks( $content );
			$candidates = $this->collect_paths( $parsed );
			if ( empty( $candidates ) ) {
				// Tree might have been emptied by remove ops — reseed.
				$this->crud->insert_blocks( $this->post_id, null, array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>reseed</p>' ),
				) );
				continue;
			}

			$pick = $candidates[ mt_rand( 0, count( $candidates ) - 1 ) ];
			$op   = $op_pool[ mt_rand( 0, count( $op_pool ) - 1 ) ];

			$args = array();
			switch ( $op ) {
				case 'update-attrs':
					$args = array( 'attributes' => array( 'className' => 'chaos-' . $i ) );
					break;
				case 'update-html':
					$args = array( 'innerHTML' => "<p>chaos-html-$i</p>" );
					break;
				case 'replace-block':
					$args = array( 'block' => array( 'name' => 'core/paragraph', 'innerHTML' => "<p>replaced-$i</p>" ) );
					break;
				case 'wrap-in-group':
					$args = array();
					break;
				case 'insert-child':
					// Only valid for container blocks. If the pick has
					// no inner-blocks slot, fall back to a different op
					// to keep the walk moving.
					if ( empty( $pick['block']['innerBlocks'] ) && empty( $pick['block']['innerContent'] ) ) {
						$op   = 'update-attrs';
						$args = array( 'attributes' => array( 'className' => 'chaos-fallback-' . $i ) );
					} else {
						$args = array( 'block' => array( 'name' => 'core/paragraph', 'innerHTML' => "<p>child-$i</p>" ) );
					}
					break;
				case 'duplicate':
					break;
				case 'remove-block':
					// Don't completely empty the tree.
					if ( count( $candidates ) <= 1 ) {
						$op   = 'update-attrs';
						$args = array( 'attributes' => array( 'className' => 'chaos-fallback-' . $i ) );
					}
					break;
				case 'move':
					// Skip move; the path math is intricate and the spec
					// already covers move-specific invariants in the unit
					// suite. Replace with update-attrs.
					$op   = 'update-attrs';
					$args = array( 'attributes' => array( 'className' => 'chaos-move-skip-' . $i ) );
					break;
				case 'unwrap-group':
					if ( 'core/group' !== $pick['block']['blockName'] ) {
						$op   = 'update-attrs';
						$args = array( 'attributes' => array( 'className' => 'chaos-fallback-' . $i ) );
					}
					break;
			}

			// Drop the rate-limit transient between iterations — this test
			// targets tree-shape invariants, not the rate limiter (which
			// has dedicated coverage).
			delete_transient( 'gk_block_api_rate_' . $this->post_id );

			$result = $this->mutator->mutate( $this->post_id, $op, $pick['path'], $args );

			if ( is_wp_error( $result ) ) {
				// Clean errors are fine — they prove the validation layer
				// catches a non-applicable op. The codes most commonly
				// emitted by valid-but-unreachable scenarios.
				$this->assertContains(
					$result->get_error_code(),
					array( 'invalid_path', 'invalid_op', 'path_not_container', 'missing_block', 'missing_attributes', 'rate_limit_exceeded' ),
					"iter $i: $op at path " . json_encode( $pick['path'] ) . ' produced unexpected error: ' . $result->get_error_code()
				);
				continue;
			}

			$ok_count++;
			$this->assertTreeWellFormed( $i );
		}

		// At least half the ops should land cleanly; otherwise the
		// generator is producing nothing but rejections (bad signal).
		$this->assertGreaterThan(
			$iterations / 2,
			$ok_count,
			"only $ok_count / $iterations chaos ops succeeded — generator is producing too-many rejections to be useful"
		);
	}
}
