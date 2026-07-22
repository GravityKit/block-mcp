<?php
/**
 * POST /patterns — create_pattern.
 *
 * Synced by default (no `wp_pattern_sync_status` meta row); `sync_status:
 * "unsynced"` sets the meta explicitly. Structured `blocks` go through the
 * same registry/tier/dual-storage validation `create_post` uses; `content`
 * XOR `blocks` is enforced.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Pattern_Manager;
use GravityKit\BlockMCP\Preferences;

final class CreatePatternTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatch POST /patterns with a JSON body.
	 *
	 * @param array $body Request body.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function create_pattern( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/patterns' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return $this->controller->create_pattern( $request );
	}

	public function test_synced_by_default_meta_absent() {
		$response = $this->create_pattern(
			array(
				'title'   => 'Synced Test Pattern',
				'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'synced', $data['sync_status'] );

		$meta = get_post_meta( $data['pattern_id'], 'wp_pattern_sync_status', true );
		$this->assertSame( '', $meta, 'synced pattern must have no meta row (empty string = absent)' );
	}

	public function test_unsynced_sets_the_meta_key() {
		$response = $this->create_pattern(
			array(
				'title'       => 'Unsynced Test Pattern',
				'content'     => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
				'sync_status' => 'unsynced',
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'unsynced', $data['sync_status'] );

		$meta = get_post_meta( $data['pattern_id'], 'wp_pattern_sync_status', true );
		$this->assertSame( 'unsynced', $meta );
	}

	public function test_created_pattern_appears_in_get_patterns_with_correct_sync_status() {
		$response = $this->create_pattern(
			array(
				'title'       => 'Findable Pattern',
				'content'     => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
				'sync_status' => 'unsynced',
			)
		);
		$pattern_id = $response->get_data()['pattern_id'];

		$list_request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/patterns' );
		$list_request->set_param( 'limit', 100 );
		$list_response = $this->controller->get_patterns( $list_request );
		$patterns       = $list_response->get_data()['patterns'];

		$found = null;
		foreach ( $patterns as $pattern ) {
			if ( $pattern['id'] === $pattern_id ) {
				$found = $pattern;
				break;
			}
		}

		$this->assertNotNull( $found, 'the newly created pattern must appear in GET /patterns' );
		$this->assertSame( 'unsynced', $found['sync_status'] );
	}

	public function test_legacy_tier_block_rejected() {
		update_option(
			Preferences::OPTION_KEY,
			array(
				'namespace_scores' => array( 'ugb' => 0 ),
				'replacement_map'  => array( 'ugb/text' => 'core/paragraph' ),
			)
		);

		$response = $this->create_pattern(
			array(
				'title'  => 'Legacy Block Pattern',
				'blocks' => array(
					array( 'name' => 'ugb/text', 'innerHTML' => '<div>legacy</div>' ),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'legacy_block', $response->get_error_code() );
	}

	public function test_xor_violation_both_content_and_blocks_returns_400() {
		$response = $this->create_pattern(
			array(
				'title'   => 'XOR Violation',
				'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
				'blocks'  => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>hi</p>' ) ),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $response );
		$data = $response->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_xor_violation_neither_content_nor_blocks_returns_400() {
		$response = $this->create_pattern( array( 'title' => 'Neither' ) );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$data = $response->get_error_data();
		$this->assertSame( 400, $data['status'] );
	}

	public function test_missing_cap_returns_403() {
		// Editor role: has edit_posts + publish_posts by default, so use a
		// contributor (no publish_posts) to exercise the create_posts (=
		// publish_posts) cap gate specifically, not the base edit_posts check.
		$contributor_id = self::factory()->user->create( array( 'role' => 'contributor' ) );
		wp_set_current_user( $contributor_id );

		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/patterns' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'title'   => 'Should Be Forbidden',
					'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
				)
			)
		);

		$permission_result = $this->controller->check_create_pattern_permissions();

		$this->assertInstanceOf( \WP_Error::class, $permission_result );
		$data = $permission_result->get_error_data();
		$this->assertSame( 403, $data['status'] );
	}
}
