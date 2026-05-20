<?php
/**
 * Authorization gate on the `/patterns?refresh=true` parameter.
 *
 * The base `/patterns` route is reachable by any user with `edit_posts`.
 * Cache invalidation, however, is expensive (full post_content scan) and
 * is intentionally restricted to `manage_options` to prevent an
 * authenticated editor from looping `refresh=true` and forcing repeated
 * heavy DB scans. These tests pin both halves of that contract:
 *
 *   - editor + refresh=true  → 403
 *   - admin  + refresh=true  → 200
 *   - editor + no refresh    → 200 (baseline — refresh check doesn't
 *     leak into the normal read path)
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare( strict_types=1 );

class PatternsRefreshAuthTest extends RestControllerTestCase {

	private function request_with_refresh( bool $refresh ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/patterns' );
		if ( $refresh ) {
			$request->set_param( 'refresh', true );
		}
		return $request;
	}

	/**
	 * `refresh=true` issued by an editor is rejected with a 403-equivalent
	 * WP_Error. The editor would normally be permitted on the base route;
	 * the gate only fires when refresh is explicitly requested.
	 */
	public function test_editor_cannot_force_refresh(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$result = $this->controller->get_patterns( $this->request_with_refresh( true ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden_refresh', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( rest_authorization_required_code(), $data['status'] );
	}

	/**
	 * Admins (manage_options) may force a refresh. Confirms the gate
	 * doesn't accidentally lock out the role we want it to allow.
	 */
	public function test_admin_can_force_refresh(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$result = $this->controller->get_patterns( $this->request_with_refresh( true ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}

	/**
	 * Refresh gate only fires when refresh is set. An editor making the
	 * normal read call must still get a 200 — the gate must not bleed
	 * into the base read path.
	 */
	public function test_editor_can_still_read_without_refresh(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor_id );

		$result = $this->controller->get_patterns( $this->request_with_refresh( false ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $result );
		$this->assertSame( 200, $result->get_status() );
	}
}
