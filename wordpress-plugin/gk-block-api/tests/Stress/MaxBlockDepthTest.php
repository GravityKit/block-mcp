<?php
/**
 * Tests for `Block_CRUD::MAX_BLOCK_DEPTH` enforcement.
 *
 * Pins the contract that every write path validates the depth of the
 * outgoing block tree against `MAX_BLOCK_DEPTH` (32) and rejects with
 * `block_depth_exceeded` (HTTP 400). The limit is a hard guard against
 * stack overflow / pcre.recursion_limit failures inside
 * `parse_blocks()` / `serialize_blocks()` and quadratic walks in
 * `format_blocks_recursive()`, `assign_missing_refs_recursive()`, and
 * `Block_Mutator` path traversal — not a tunable knob.
 *
 * @package GravityKit\BlockAPI\Tests\Stress
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Mutator;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Preferences;

class MaxBlockDepthTest extends WP_UnitTestCase {

	/** @var Block_CRUD */
	private $crud;

	/** @var Block_Mutator */
	private $mutator;

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$preferences  = new Preferences();
		$safety       = new Block_Safety();
		$transformer  = new HTML_Transformer();
		$this->crud   = new Block_CRUD( $preferences, $safety, $transformer, new Block_Inventory() );
		$this->mutator = new Block_Mutator( $this->crud, $preferences, $safety, $transformer );
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
	}

	/**
	 * Build a tree where leaf sits at depth $total_depth (1-indexed).
	 *
	 * @param int $total_depth Final tree depth. 1 = flat single block.
	 * @return array
	 */
	private function tree( int $total_depth ): array {
		$node = array( 'name' => 'core/paragraph', 'innerHTML' => '<p>leaf</p>' );
		for ( $i = 1; $i < $total_depth; $i++ ) {
			$node = array(
				'name'        => 'core/group',
				'innerHTML'   => '<div></div>',
				'innerBlocks' => array( $node ),
			);
		}
		return $node;
	}

	public function test_tree_depth_helper_counts_correctly() {
		$flat = array( array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerHTML' => '', 'innerContent' => array(), 'innerBlocks' => array() ) );
		$this->assertSame( 1, Block_CRUD::tree_depth( $flat ) );

		$nested = array(
			array(
				'blockName'    => 'core/group',
				'attrs'        => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
				'innerBlocks'  => array(
					array(
						'blockName'    => 'core/paragraph',
						'attrs'        => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
						'innerBlocks'  => array(),
					),
				),
			),
		);
		$this->assertSame( 2, Block_CRUD::tree_depth( $nested ) );
		$this->assertSame( 0, Block_CRUD::tree_depth( array() ) );
	}

	/**
	 * Pins the constant to a value site owners can rely on. If this needs
	 * to change, callers and documentation need to too.
	 */
	public function test_max_depth_constant_is_32() {
		$this->assertSame( 32, Block_CRUD::MAX_BLOCK_DEPTH );
	}

	/**
	 * `MAX_BLOCK_DEPTH` = 32. A tree of total depth 32 must be allowed.
	 */
	public function test_replace_all_blocks_accepts_at_cap() {
		$result = $this->crud->replace_all_blocks(
			$this->post_id,
			array( $this->tree( Block_CRUD::MAX_BLOCK_DEPTH ) )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result, 'tree at depth 32 must be accepted' );
	}

	/**
	 * Tree of depth 33 — one over. Must reject cleanly.
	 */
	public function test_replace_all_blocks_rejects_one_past_cap() {
		$result = $this->crud->replace_all_blocks(
			$this->post_id,
			array( $this->tree( Block_CRUD::MAX_BLOCK_DEPTH + 1 ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertSame( 400, $data['status'] );
		$this->assertSame( 32, $data['max_depth'] );
		$this->assertSame( 33, $data['actual_depth'] );
	}

	/**
	 * Empty post, then try to insert a tree past the cap. Must reject.
	 */
	public function test_insert_blocks_rejects_when_inserted_tree_exceeds_cap() {
		$result = $this->crud->insert_blocks( $this->post_id, null, array(
			array(
				'name'        => 'core/group',
				'innerHTML'   => '<div></div>',
				'innerBlocks' => $this->tree_def_blocks( 35 ),
			),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}

	/**
	 * Build a nested API-shape block array (uses `name` and `innerBlocks`
	 * keys, not the WP-internal `blockName`/`innerContent`).
	 *
	 * @param int $total_depth
	 * @return array
	 */
	private function tree_def_blocks( int $total_depth ): array {
		return array( $this->tree( $total_depth ) );
	}

	/**
	 * A rejected write leaves the post untouched. A naive implementation
	 * might serialize then validate, leaking partial state.
	 */
	public function test_rejection_does_not_write() {
		$original = '<!-- wp:paragraph --><p>original</p><!-- /wp:paragraph -->';
		wp_update_post( array( 'ID' => $this->post_id, 'post_content' => $original ) );

		$over = $this->crud->replace_all_blocks(
			$this->post_id,
			array( $this->tree( Block_CRUD::MAX_BLOCK_DEPTH + 5 ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $over );

		$this->assertSame(
			$original,
			(string) get_post_field( 'post_content', $this->post_id ),
			'post_content must be unchanged after a rejected write'
		);
	}

	/**
	 * Seed a tree at depth 32 (the cap). Wrapping any block adds 1 level —
	 * the resulting depth-33 tree must be rejected at save time.
	 */
	public function test_mutator_wrap_in_group_at_cap_is_rejected() {
		$this->crud->replace_all_blocks( $this->post_id, array( $this->tree( Block_CRUD::MAX_BLOCK_DEPTH ) ) );

		$result = $this->mutator->mutate( $this->post_id, 'wrap-in-group', array( 0 ), array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'block_depth_exceeded', $result->get_error_code() );
	}
}
