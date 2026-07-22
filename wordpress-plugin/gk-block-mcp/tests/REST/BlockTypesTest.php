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

	public function test_core_column_reports_parent_nesting_constraint() {
		$column = $this->get_block_type( 'core/column' );

		$this->assertNotNull( $column );
		$this->assertArrayHasKey( 'parent', $column );
		$this->assertSame( array( 'core/columns' ), $column['parent'] );
	}

	public function test_supports_absent_by_default() {
		$paragraph = $this->get_block_type( 'core/paragraph' );

		$this->assertNotNull( $paragraph );
		$this->assertArrayNotHasKey( 'supports', $paragraph );
	}

	public function test_supports_present_when_include_supports_requested() {
		$paragraph = $this->get_block_type( 'core/paragraph', array( 'include_supports' => true ) );

		$this->assertNotNull( $paragraph );
		$this->assertArrayHasKey( 'supports', $paragraph );
		$this->assertIsArray( $paragraph['supports'] );
		$this->assertArrayHasKey( 'anchor', $paragraph['supports'] );
	}

	public function test_allowed_blocks_passthrough_when_declared() {
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
