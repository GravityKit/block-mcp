<?php
/**
 * GET /block-types must expose style variations, nesting constraints, and
 * (opt-in) the full `supports` object.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

final class BlockTypesTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatch GET /block-types with the given params and return the block
	 * type entry matching $name, or null when absent.
	 *
	 * @param string $name   Block name to find.
	 * @param array  $params Extra request params (e.g. include_supports).
	 *
	 * @return array|null
	 */
	private function get_block_type( string $name, array $params = array() ) {
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/block-types' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response    = $this->controller->get_block_types( $request );
		$block_types = $response->get_data()['block_types'];

		foreach ( $block_types as $block_type ) {
			if ( $block_type['name'] === $name ) {
				return $block_type;
			}
		}
		return null;
	}

	/**
	 * Contract: `styles` lists every block.json-declared style variation,
	 * with `is_default` correctly flagging the default one. Regression:
	 * an earlier version only merged register_block_style() variations and
	 * missed block.json's own declared list. Failure mode: "rounded" (or
	 * "default"/`is_default`) missing means block.json styles are dropped.
	 */
	public function test_core_image_returns_declared_styles_including_rounded() {
		$image = $this->get_block_type( 'core/image' );

		$this->assertNotNull( $image );
		$this->assertArrayHasKey( 'styles', $image );

		$names = wp_list_pluck( $image['styles'], 'name' );
		$this->assertContains( 'rounded', $names );
		$this->assertContains( 'default', $names );

		$default = $image['styles'][ array_search( 'default', $names, true ) ];
		$this->assertTrue( $default['is_default'] );
	}

	/**
	 * Contract: a style registered at runtime via register_block_style()
	 * (not declared in block.json) still appears in `styles` alongside the
	 * block.json-declared ones — the two sources are merged, not
	 * either/or. Failure mode: the runtime-registered name missing means
	 * only block.json styles are read.
	 */
	public function test_registered_style_merges_into_the_response() {
		register_block_style(
			'core/paragraph',
			array(
				'name'  => 'block-types-test-highlight',
				'label' => 'Highlight',
			)
		);

		$paragraph = $this->get_block_type( 'core/paragraph' );
		$names     = wp_list_pluck( $paragraph['styles'], 'name' );

		$this->assertContains( 'block-types-test-highlight', $names );

		unregister_block_style( 'core/paragraph', 'block-types-test-highlight' );
	}

	/**
	 * Contract: a block with zero declared/registered styles omits the
	 * `styles` key entirely rather than returning an empty array — callers
	 * branch on key presence, not array emptiness. Failure mode: an
	 * `styles: []` key present here means a caller's `isset()` check would
	 * wrongly read "has styles."
	 */
	public function test_block_with_no_styles_omits_the_styles_key() {
		// Register a bare block type with no block.json styles and nothing
		// registered against it in WP_Block_Styles_Registry.
		register_block_type(
			'block-types-test/no-styles',
			array(
				'title' => 'No Styles',
			)
		);

		$block = $this->get_block_type( 'block-types-test/no-styles' );

		$this->assertNotNull( $block );
		$this->assertArrayNotHasKey( 'styles', $block );

		unregister_block_type( 'block-types-test/no-styles' );
	}

	/**
	 * Contract: a block's declared `parent` nesting constraint (core/column
	 * must nest inside core/columns) is surfaced verbatim in the response,
	 * so a caller can validate nesting before inserting. Failure mode: a
	 * missing/wrong `parent` value means an agent could nest core/column
	 * anywhere without warning.
	 */
	public function test_core_column_reports_parent_nesting_constraint() {
		$column = $this->get_block_type( 'core/column' );

		$this->assertNotNull( $column );
		$this->assertArrayHasKey( 'parent', $column );
		$this->assertSame( array( 'core/columns' ), $column['parent'] );
	}

	/**
	 * Contract: the full `supports` object is opt-in — omitted from every
	 * row unless `include_supports:true` is requested, keeping the default
	 * response lean. Failure mode: `supports` present here means every
	 * list_block_types call pays its cost unconditionally.
	 */
	public function test_supports_absent_by_default() {
		$paragraph = $this->get_block_type( 'core/paragraph' );

		$this->assertNotNull( $paragraph );
		$this->assertArrayNotHasKey( 'supports', $paragraph );
	}

	/**
	 * Contract: `include_supports:true` returns each block's full `supports`
	 * object (e.g. `anchor`), the counterpart to the opt-out-by-default
	 * behavior above. Failure mode: a missing `supports` key or a missing
	 * `anchor` entry means the opt-in path isn't wired to the real registry
	 * data.
	 */
	public function test_supports_present_when_include_supports_requested() {
		$paragraph = $this->get_block_type( 'core/paragraph', array( 'include_supports' => true ) );

		$this->assertNotNull( $paragraph );
		$this->assertArrayHasKey( 'supports', $paragraph );
		$this->assertIsArray( $paragraph['supports'] );
		$this->assertArrayHasKey( 'anchor', $paragraph['supports'] );
	}

	/**
	 * Contract: a block's declared `allowed_blocks` nesting constraint
	 * (only these child block types may be inserted inside it) is
	 * surfaced verbatim. Failure mode: a missing/wrong `allowed_blocks`
	 * value means an agent could insert an unsupported child with no
	 * warning.
	 *
	 * `WP_Block_Type::$allowed_blocks` was added in WordPress 6.5; this
	 * plugin's floor is 6.0, so the assertion is skipped rather than
	 * failing on an older core that never populates the property.
	 */
	public function test_allowed_blocks_passthrough_when_declared() {
		if ( ! property_exists( '\WP_Block_Type', 'allowed_blocks' ) ) {
			$this->markTestSkipped( 'WP_Block_Type::$allowed_blocks was added in WordPress 6.5; not available in this WP version.' );
		}

		register_block_type(
			'block-types-test/with-allowed-blocks',
			array(
				'title'          => 'With Allowed Blocks',
				'allowed_blocks' => array( 'core/paragraph', 'core/heading' ),
			)
		);

		$block = $this->get_block_type( 'block-types-test/with-allowed-blocks' );

		$this->assertNotNull( $block );
		$this->assertArrayHasKey( 'allowed_blocks', $block );
		$this->assertSame( array( 'core/paragraph', 'core/heading' ), $block['allowed_blocks'] );

		unregister_block_type( 'block-types-test/with-allowed-blocks' );
	}

	/**
	 * Contract: a block's declared `ancestor` nesting constraint (must
	 * appear somewhere in this ancestor chain, not necessarily the direct
	 * parent) is surfaced verbatim — the looser counterpart to `parent`.
	 * Failure mode: a missing/wrong `ancestor` value means an agent could
	 * nest the block outside its required ancestor with no warning.
	 */
	public function test_ancestor_passthrough_when_declared() {
		register_block_type(
			'block-types-test/with-ancestor',
			array(
				'title'    => 'With Ancestor',
				'ancestor' => array( 'core/group' ),
			)
		);

		$block = $this->get_block_type( 'block-types-test/with-ancestor' );

		$this->assertNotNull( $block );
		$this->assertArrayHasKey( 'ancestor', $block );
		$this->assertSame( array( 'core/group' ), $block['ancestor'] );

		unregister_block_type( 'block-types-test/with-ancestor' );
	}
}
