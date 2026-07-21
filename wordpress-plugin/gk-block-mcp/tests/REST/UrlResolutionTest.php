<?php
/**
 * URL resolution must honour query-string permalinks.
 *
 * resolve_url() and post_info() reduced the incoming URL to PHP_URL_PATH and
 * dropped the query string, so the ?p= / ?page_id= / ?post_type=&p= forms
 * collapsed to home_url('/') and resolved to the front page (or 404) instead
 * of the intended post. These tests pin that the query-permalink forms
 * resolve to the right post, and that pretty permalinks keep resolving.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

final class UrlResolutionTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Dispatch /resolve for a URL and return the handler result.
	 *
	 * @param string $url URL to resolve.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function resolve( string $url ) {
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/resolve' );
		$request->set_param( 'url', $url );
		return $this->controller->resolve_url( $request );
	}

	/**
	 * The ?p={id} query permalink resolves to that post, not the front page.
	 *
	 * Pre-fix the query was stripped, so the lookup became home_url('/') and
	 * resolved to the front page (or 404). This is the core regression.
	 */
	public function test_resolve_url_query_permalink_p_resolves_to_post() {
		$id  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$res = $this->resolve( home_url( '/?p=' . $id ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( $id, $res->get_data()['post_id'] );
	}

	/**
	 * The ?post_type=&p={id} form (how a non-pretty CPT URL looks) resolves.
	 *
	 * This is the exact shape that sent internal docs to the homepage.
	 */
	public function test_resolve_url_query_permalink_with_post_type_resolves_to_post() {
		$id  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$res = $this->resolve( home_url( '/?post_type=page&p=' . $id ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( $id, $res->get_data()['post_id'] );
	}

	/**
	 * Pretty permalinks keep resolving — preserving the query must not regress
	 * the path-only resolution that already worked.
	 */
	public function test_resolve_url_pretty_permalink_still_resolves() {
		$this->set_permalink_structure( '/%postname%/' );
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->set_permalink_structure( '/%postname%/' );

		$res = $this->resolve( get_permalink( $id ) );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( $id, $res->get_data()['post_id'] );
	}

	/**
	 * post_info() shared the same query-stripping bug and must resolve the
	 * ?p={id} form to the post rather than the front page.
	 */
	public function test_post_info_query_permalink_resolves_to_post() {
		$id      = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Resolve Target',
			)
		);
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/post-info' );
		$request->set_param( 'url', home_url( '/?p=' . $id ) );

		$res = $this->controller->post_info( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $res );
		$this->assertSame( $id, $res->get_data()['post_id'] );
	}
}
