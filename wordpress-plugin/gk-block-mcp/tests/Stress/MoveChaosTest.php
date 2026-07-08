<?php
/**
 * Move-inclusive mutation-chaos walk.
 *
 * The general MutationChaosTest deliberately skips the `move` op (its path
 * math is the most intricate of the nine, and it substitutes update-attrs).
 * This test closes that gap: it drives randomized sequences that are heavily
 * weighted toward `move` — including multi-block moves and moves into and out
 * of nested containers — and asserts the same tree-integrity invariants after
 * every successful op:
 *
 *   - innerContent null-placeholder count equals innerBlocks count at every
 *     depth (the invariant serialize_blocks() depends on),
 *   - gk_ref values are unique within the post,
 *   - parse → serialize → parse is idempotent (no block escapes its container,
 *     none are lost or duplicated).
 *
 * The PRNG seed is fixed per run so a failure reproduces exactly. A final
 * assertion guarantees `move` actually landed cleanly many times, so the test
 * can never silently degrade into "every move was rejected" and stop covering
 * the op it exists for.
 *
 * @package GravityKit\BlockMCP\Tests\Stress
 */

declare( strict_types=1 );

class MoveChaosTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post();
		$this->crud->replace_all_blocks( $this->post_id, $this->seed_tree() );
	}

	/**
	 * Two sibling containers plus nested depth, so moves can cross container
	 * boundaries in both directions and between subtrees.
	 *
	 * @return array[]
	 */
	private function seed_tree(): array {
		return array(
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>top-0</p>' ),
			array(
				'name'        => 'core/group',
				'innerHTML'   => '<div class="wp-block-group"></div>',
				'innerBlocks' => array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>a0.0</p>' ),
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>a0.1</p>' ),
				),
			),
			array(
				'name'        => 'core/group',
				'innerHTML'   => '<div class="wp-block-group"></div>',
				'innerBlocks' => array(
					array( 'name' => 'core/heading', 'attributes' => array( 'level' => 2 ), 'innerHTML' => '<h2>b0.0</h2>' ),
				),
			),
			array( 'name' => 'core/paragraph', 'innerHTML' => '<p>top-3</p>' ),
		);
	}

	/**
	 * Every non-empty block, paired with its raw parse_blocks() path.
	 *
	 * @return array<int, array{path: int[], block: array}>
	 */
	private function collect_paths( array $blocks, array $prefix = array() ): array {
		$out = array();
		foreach ( $blocks as $i => $block ) {
			if ( null === $block['blockName'] ) {
				continue;
			}
			$path  = array_merge( $prefix, array( $i ) );
			$out[] = array( 'path' => $path, 'block' => $block );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, $this->collect_paths( $block['innerBlocks'], $path ) );
			}
		}
		return $out;
	}

	/**
	 * Every valid destination path: for each container (root included), an
	 * insertion index from 0..childCount.
	 *
	 * @return int[][]
	 */
	private function collect_destinations( array $blocks, array $prefix = array() ): array {
		$dests = array();
		$count = 0;
		foreach ( $blocks as $block ) {
			if ( null !== $block['blockName'] ) {
				++$count;
			}
		}
		for ( $i = 0; $i <= $count; $i++ ) {
			$dests[] = array_merge( $prefix, array( $i ) );
		}
		foreach ( $blocks as $i => $block ) {
			if ( null !== $block['blockName'] && ! empty( $block['innerBlocks'] ) ) {
				$dests = array_merge( $dests, $this->collect_destinations( $block['innerBlocks'], array_merge( $prefix, array( $i ) ) ) );
			}
		}
		return $dests;
	}

	/**
	 * Assert the whole tree is well-formed: null-placeholder invariant holds at
	 * every depth, refs are unique, and the serialize round-trip is idempotent.
	 */
	private function assertTreeWellFormed( int $iteration, string $last_op ): void {
		$content = (string) get_post_field( 'post_content', $this->post_id );
		$blocks  = parse_blocks( $content );

		$refs_seen = array();
		$walker    = function ( $blocks, $where ) use ( $iteration, $last_op, &$walker, &$refs_seen ) {
			foreach ( $blocks as $i => $block ) {
				if ( null === $block['blockName'] ) {
					continue;
				}
				$here  = $where . "[$i]";
				$nulls = 0;
				foreach ( $block['innerContent'] as $piece ) {
					if ( null === $piece ) {
						++$nulls;
					}
				}
				$this->assertSame(
					count( $block['innerBlocks'] ),
					$nulls,
					"iter $iteration ($last_op) $here: innerContent null count ($nulls) must equal innerBlocks count (" . count( $block['innerBlocks'] ) . ')'
				);
				$ref = $block['attrs']['metadata']['gk_ref'] ?? null;
				if ( null !== $ref ) {
					$this->assertArrayNotHasKey(
						$ref,
						$refs_seen,
						"iter $iteration ($last_op): duplicate ref $ref at $here AND " . ( $refs_seen[ $ref ] ?? '?' )
					);
					$refs_seen[ $ref ] = $here;
				}
				$walker( $block['innerBlocks'], $here );
			}
		};
		$walker( $blocks, '' );

		$this->assertSame(
			$this->canonicalize( $blocks ),
			$this->canonicalize( parse_blocks( serialize_blocks( $blocks ) ) ),
			"iter $iteration ($last_op): parse→serialize→parse must be idempotent"
		);
	}

	/**
	 * Sorted multiset of every block name in the tree (all depths).
	 *
	 * The load-bearing move invariant: a move relocates blocks, it never adds
	 * or drops one. Checking null-count on re-parsed content can't catch a move
	 * that drops a child at save time — parse_blocks() always yields a
	 * self-consistent null count — but a dropped block vanishes from this
	 * multiset. Compared before/after each move.
	 *
	 * @return string[]
	 */
	private function name_multiset( array $blocks ): array {
		$names = array();
		$walk  = function ( $bl ) use ( &$walk, &$names ) {
			foreach ( $bl as $b ) {
				if ( null === $b['blockName'] ) {
					continue;
				}
				$names[] = $b['blockName'];
				$walk( $b['innerBlocks'] );
			}
		};
		$walk( $blocks );
		sort( $names );
		return $names;
	}

	/**
	 * Structural skeleton (name + attrs + nested shape) for idempotence
	 * comparison; drops innerHTML, which the parser legitimately reconstructs.
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

	/**
	 * A randomized, move-heavy walk leaves the tree well-formed after every op.
	 */
	public function test_move_heavy_chaos_walk_preserves_invariants() {
		$move_ok = 0;

		// Multiple fixed seeds broaden coverage while staying reproducible.
		foreach ( array( 1337, 7, 4242, 90210 ) as $seed ) {
			mt_srand( $seed );

			// Reset to the seed tree for each PRNG stream.
			if ( 1337 !== $seed ) {
				$this->set_up_reseed();
			}

			// Move dominates; the shape-changing ops around it accumulate the
			// varied topology that makes move's index math non-trivial.
			$op_pool = array(
				'move', 'move', 'move', 'move', 'move',
				'insert-child', 'duplicate', 'wrap-in-group',
				'unwrap-group', 'remove-block', 'update-attrs',
			);

			for ( $i = 0; $i < 80; $i++ ) {
				delete_transient( 'gk_block_api_rate_' . $this->post_id );

				$parsed     = parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
				$candidates = $this->collect_paths( $parsed );
				if ( count( $candidates ) < 2 ) {
					$this->crud->insert_blocks( $this->post_id, null, array(
						array( 'name' => 'core/paragraph', 'innerHTML' => "<p>reseed-$i</p>" ),
					) );
					continue;
				}

				$pick = $candidates[ mt_rand( 0, count( $candidates ) - 1 ) ];
				$op   = $op_pool[ mt_rand( 0, count( $op_pool ) - 1 ) ];
				$args = array();

				// Snapshot the block-name multiset before a move so we can prove
				// the move conserved it (relocated, not lost/duplicated a block).
				$names_before = ( 'move' === $op ) ? $this->name_multiset( $parsed ) : null;

				switch ( $op ) {
					case 'move':
						$dests = $this->collect_destinations( $parsed );
						$args  = array( 'destination' => $dests[ mt_rand( 0, count( $dests ) - 1 ) ] );
						if ( 0 === mt_rand( 0, 2 ) ) {
							$args['count'] = mt_rand( 1, 3 );
						}
						break;
					case 'insert-child':
						$args = array( 'block' => array( 'name' => 'core/paragraph', 'innerHTML' => "<p>child-$i</p>" ) );
						break;
					case 'update-attrs':
						$args = array( 'attributes' => array( 'className' => "chaos-$i" ) );
						break;
				}

				$result = $this->mutator->mutate( $this->post_id, $op, $pick['path'], $args );

				if ( is_wp_error( $result ) ) {
					// Clean rejections are expected (e.g. moving a block into its
					// own descendant, unwrapping a leaf). They must never corrupt.
					$this->assertContains(
						$result->get_error_code(),
						array(
							'invalid_path', 'invalid_op', 'invalid_destination', 'invalid_count',
							'missing_block', 'missing_attributes', 'no_inner_blocks',
							'legacy_block', 'invalid_block', 'rate_limit_exceeded',
						),
						"iter $i ($op) path " . wp_json_encode( $pick['path'] ) . ' produced unexpected error: ' . $result->get_error_code()
					);
					continue;
				}

				if ( 'move' === $op ) {
					++$move_ok;
					$this->assertSame(
						$names_before,
						$this->name_multiset( parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) ) ),
						"iter $i: move must conserve the block-name multiset (no block lost or duplicated)"
					);
				}
				$this->assertTreeWellFormed( $i, $op );
			}
		}

		// Guard against silent degradation: if move stopped landing cleanly the
		// test would still pass on invariants alone while covering nothing.
		$this->assertGreaterThan(
			40,
			$move_ok,
			"only $move_ok move ops succeeded across all seeds — the walk is no longer exercising move"
		);
	}

	/**
	 * Re-seed the post to the starting tree between PRNG streams.
	 */
	private function set_up_reseed(): void {
		$this->crud->replace_all_blocks( $this->post_id, $this->seed_tree() );
	}
}
