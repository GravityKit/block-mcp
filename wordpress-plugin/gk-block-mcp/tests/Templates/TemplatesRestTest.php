<?php
/**
 * REST wiring for the FSE template read routes (GET /templates, GET /template).
 *
 * @package GravityKit\BlockMCP\Tests
 */

class TemplatesRestTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();

		$this->ensure_theme_root_resolvable();

		$block_theme = $this->find_block_theme();
		if ( null === $block_theme ) {
			$this->markTestSkipped( 'No block theme is available in this test environment.' );
		}
		switch_theme( $block_theme );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Work around a wp-phpunit quirk that breaks switch_theme() for any
	 * fixture theme other than the WP_DEFAULT_THEME.
	 * See TemplateManagerTest::ensure_theme_root_resolvable() for the rationale.
	 *
	 * @return void
	 */
	private function ensure_theme_root_resolvable() {
		$dummy_root = sys_get_temp_dir() . '/gk-block-mcp-empty-theme-root';
		if ( ! is_dir( $dummy_root ) ) {
			wp_mkdir_p( $dummy_root );
		}
		register_theme_directory( $dummy_root );
	}

	/**
	 * Find a standalone (non-child) block theme in the test theme root.
	 * See TemplateManagerTest::find_block_theme() for the rationale.
	 *
	 * @return string|null Stylesheet slug, or null if none exists.
	 */
	private function find_block_theme() {
		$themes = wp_get_themes();

		if ( isset( $themes['block-theme'] ) && $themes['block-theme']->is_block_theme() && ! $themes['block-theme']->get( 'Template' ) ) {
			return 'block-theme';
		}

		foreach ( $themes as $stylesheet => $theme_obj ) {
			if ( $theme_obj->is_block_theme() && ! $theme_obj->get( 'Template' ) ) {
				return $stylesheet;
			}
		}

		return null;
	}

	private function dispatch( \WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * GET /templates responds 200 with the theme's index template for an
	 * actor holding edit_posts (the read permission the route declares).
	 */
	public function test_get_templates_route_returns_200_for_editor() {
		$request  = new \WP_REST_Request( 'GET', '/gk-block-api/v1/templates' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$slugs = wp_list_pluck( $response->get_data()['templates'], 'slug' );
		$this->assertContains( 'index', $slugs );
	}

	/**
	 * GET /templates is closed to an actor without edit_posts, matching
	 * every other read route on this namespace.
	 */
	public function test_get_templates_route_forbidden_for_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$request  = new \WP_REST_Request( 'GET', '/gk-block-api/v1/templates' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * GET /template takes `id` as a query arg (not a path segment) because
	 * template ids embed "//" — dispatching with the id in the query
	 * string must resolve the same template get_template() would.
	 */
	public function test_get_template_route_resolves_by_query_arg_id() {
		$templates = get_block_templates( array(), 'wp_template' );
		$this->assertNotEmpty( $templates, 'Fixture theme must expose at least one template.' );
		$id = $templates[0]->id;

		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/template' );
		$request->set_param( 'id', $id );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $response->get_data()['id'] );
		$this->assertArrayHasKey( 'content', $response->get_data() );
		$this->assertArrayHasKey( 'blocks', $response->get_data() );
	}

	/**
	 * An id that resolves to nothing is a 404 through the full REST
	 * dispatch path, not just at the service-object level.
	 */
	public function test_get_template_route_unknown_id_returns_404() {
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/template' );
		$request->set_param( 'id', 'nonexistent-theme//nonexistent-slug' );
		$response = $this->dispatch( $request );

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * `area` scopes wp_template_part results through the full REST
	 * dispatch path (sanitize_key applied to the query arg).
	 */
	public function test_get_templates_route_area_filter() {
		$request = new \WP_REST_Request( 'GET', '/gk-block-api/v1/templates' );
		$request->set_param( 'type', 'wp_template_part' );
		$request->set_param( 'area', 'header' );
		$response = $this->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$slugs = wp_list_pluck( $response->get_data()['templates'], 'slug' );
		$this->assertContains( 'small-header', $slugs );
	}
}
