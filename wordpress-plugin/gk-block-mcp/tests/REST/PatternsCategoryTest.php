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

	/**
	 * Contract: `category` narrows registered patterns to those whose
	 * declared `categories` array contains the requested slug — a pattern
	 * in a different category is excluded, not just deprioritized.
	 * Failure mode: the in-category pattern missing means the filter
	 * isn't applied; the out-of-category pattern present means it's a
	 * no-op / overly broad match.
	 */
	public function test_category_narrows_registered_patterns() {
		try {
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
		} finally {
			// Run on assertion failure too — an unregistered leftover
			// pattern would otherwise leak into every later test in this run.
			unregister_block_pattern( 'block-types-test/in-category' );
			unregister_block_pattern( 'block-types-test/out-of-category' );
		}
	}

	/**
	 * Contract: a synced pattern (a `wp_block` post, which has no
	 * category taxonomy of its own) is matched by `category` against the
	 * block categories used in its content
	 * (`Pattern_Manager::get_block_categories_in_content()`), not by a
	 * literal taxonomy term. core/paragraph is in the "text" category;
	 * core/image is "media" — a pattern built from a paragraph block must
	 * match `category=text` and must not match `category=media`.
	 * Failure mode: a missing match means content-based category
	 * inference is broken; an unexpected match means it's too broad.
	 */
	public function test_synced_pattern_category_matches_block_categories_in_content() {
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

	/**
	 * Contract: the top-level `categories` vocabulary lists WordPress's
	 * registered pattern categories (e.g. "text", "buttons"), each with
	 * `name` and `label`, regardless of the `category` filter — it's the
	 * full picklist a caller browses before filtering. Failure mode: a
	 * missing core category or a missing name/label means the vocabulary
	 * is incomplete or malformed for building a filter UI.
	 */
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
