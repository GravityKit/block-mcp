<?php
/**
 * GET /patterns `category` filter and the top-level `categories` vocabulary.
 *
 * Registered patterns are matched against their declared pattern
 * categories; synced patterns have no separate category taxonomy, so they
 * are matched against the block categories used in their content
 * (`Pattern_Manager::get_block_categories_in_content()`).
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

final class PatternsCategoryTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Dispatch GET /patterns with the given params.
	 *
	 * A direct handler call (not full REST dispatch) never applies the
	 * route's declared `args` defaults, so `limit` must be set explicitly
	 * here or it comes through as null — `(int) null` truncates results to
	 * a single pattern via `max( 1, 0 )` in `Pattern_Manager::get_patterns()`.
	 *
	 * @param array $params Query params.
	 *
	 * @return array Decoded response data.
	 */
	private function get_patterns( array $params = array() ): array {
		$params  = wp_parse_args( $params, array( 'limit' => 100 ) );
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/patterns' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$response = $this->controller->get_patterns( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response->get_data();
	}

	public function test_category_narrows_registered_patterns() {
		register_block_pattern(
			'block-types-test/in-category',
			array(
				'title'      => 'In Category',
				'categories' => array( 'block-types-test-category' ),
				'content'    => '<!-- wp:paragraph --><p>in</p><!-- /wp:paragraph -->',
			)
		);
		register_block_pattern(
			'block-types-test/out-of-category',
			array(
				'title'      => 'Out Of Category',
				'categories' => array( 'featured' ),
				'content'    => '<!-- wp:paragraph --><p>out</p><!-- /wp:paragraph -->',
			)
		);

		$data = $this->get_patterns( array( 'category' => 'block-types-test-category', 'synced' => false ) );
		$ids  = wp_list_pluck( $data['patterns'], 'id' );

		$this->assertContains( 'block-types-test/in-category', $ids );
		$this->assertNotContains( 'block-types-test/out-of-category', $ids );

		unregister_block_pattern( 'block-types-test/in-category' );
		unregister_block_pattern( 'block-types-test/out-of-category' );
	}

	public function test_synced_pattern_category_matches_block_categories_in_content() {
		// core/paragraph is in the "text" category; core/image is "media".
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Synced Text Pattern',
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			)
		);

		$matching = $this->get_patterns( array( 'category' => 'text', 'synced' => true ) );
		$this->assertContains( $post_id, wp_list_pluck( $matching['patterns'], 'id' ) );

		$non_matching = $this->get_patterns( array( 'category' => 'media', 'synced' => true ) );
		$this->assertNotContains( $post_id, wp_list_pluck( $non_matching['patterns'], 'id' ) );
	}

	public function test_categories_key_includes_core_categories() {
		$data = $this->get_patterns();

		$this->assertArrayHasKey( 'categories', $data );
		$names = wp_list_pluck( $data['categories'], 'name' );

		$this->assertContains( 'text', $names );
		$this->assertContains( 'buttons', $names );

		// Each entry has both name and label.
		foreach ( $data['categories'] as $category ) {
			$this->assertArrayHasKey( 'name', $category );
			$this->assertArrayHasKey( 'label', $category );
		}
	}
}
