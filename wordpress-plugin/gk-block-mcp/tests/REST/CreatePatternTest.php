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

	// ── Title sanitize-then-check contract ──────────────────────────────

	/**
	 * A whitespace-only title passes the raw non-empty-string check but
	 * `sanitize_text_field()` trims it to '' when building post_title,
	 * silently creating a nameless pattern. The emptiness check must run
	 * against the sanitized value.
	 */
	public function test_whitespace_only_title_is_rejected() {
		$response = $this->create_pattern(
			array(
				'title'   => '   ',
				'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'missing_title', $response->get_error_code() );
	}

	/**
	 * A title that is entirely markup with no surviving text (e.g. a bare
	 * tag pair) passes the raw non-empty-string check but
	 * `sanitize_text_field()` (which strips tags) collapses it to '' —
	 * same failure mode as the whitespace-only case, different input shape.
	 */
	public function test_markup_only_title_that_sanitizes_to_empty_is_rejected() {
		$response = $this->create_pattern(
			array(
				'title'   => '<script></script>',
				'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'missing_title', $response->get_error_code() );
	}

	/**
	 * A title with real text plus incidental surrounding whitespace and
	 * markup must still succeed, with the markup stripped and text
	 * preserved — sanitize-then-check must not reject every title that
	 * needs sanitizing, and sanitization must not be so aggressive it
	 * loses the underlying text.
	 */
	public function test_title_with_real_text_and_surrounding_whitespace_is_accepted() {
		$response = $this->create_pattern(
			array(
				'title'   => '  <em>Real Title</em>  ',
				'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 'Real Title', $response->get_data()['title'] );
	}

	// ── reference / insert_hint response shape ──────────────────────────

	/**
	 * A synced pattern (the default) keeps returning the ready-to-insert
	 * `core/block` reference — this pins the backward-compatible shape for
	 * the common case.
	 */
	public function test_synced_pattern_returns_core_block_reference() {
		$response = $this->create_pattern(
			array(
				'title'   => 'Synced Reference Pattern',
				'content' => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
			)
		);

		$data = $response->get_data();
		$this->assertSame( 'core/block', $data['reference']['blockName'] );
		$this->assertSame( $data['pattern_id'], $data['reference']['attrs']['ref'] );
		$this->assertArrayNotHasKey( 'insert_hint', $data );
	}

	/**
	 * An unsynced pattern must NOT return a `core/block` reference: that
	 * shape is WordPress's live, propagating synced-block reference, so
	 * handing it to a caller who explicitly asked for a non-propagating
	 * one-off would silently re-link content they asked to keep
	 * independent. Instead it gets an explicit insert_hint naming
	 * insert_pattern's inline path (`synced: false` against this same
	 * pattern_id, which Block_Writer::insert_pattern() already supports
	 * regardless of the pattern's own sync_status meta).
	 */
	public function test_unsynced_pattern_does_not_return_synced_reference() {
		$response = $this->create_pattern(
			array(
				'title'       => 'Unsynced Reference Pattern',
				'content'     => '<!-- wp:paragraph --><p>hi</p><!-- /wp:paragraph -->',
				'sync_status' => 'unsynced',
			)
		);

		$data = $response->get_data();
		$this->assertArrayNotHasKey( 'reference', $data, 'an unsynced pattern must not surface a synced core/block reference' );
		$this->assertArrayHasKey( 'insert_hint', $data );
		$this->assertSame( 'insert_pattern', $data['insert_hint']['tool'] );
		$this->assertSame( $data['pattern_id'], $data['insert_hint']['params']['pattern_id'] );
		$this->assertFalse( $data['insert_hint']['params']['synced'] );
	}
}
