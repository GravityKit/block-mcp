<?php
/**
 * REST wiring for the FSE template routes: the read routes (GET /templates,
 * GET /template) and the gated write routes (POST /template, POST /template/reset).
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Agent_Provisioner;
use GravityKit\BlockMCP\Template_Manager;

class TemplatesRestTest extends RestControllerTestCase {

	/** @var string Stylesheet slug of the active block theme for this test run. */
	private $theme;

	public function set_up(): void {
		parent::set_up();

		$this->ensure_theme_root_resolvable();

		$block_theme = $this->find_block_theme();
		if ( null === $block_theme ) {
			$this->markTestSkipped( 'No block theme is available in this test environment.' );
		}
		switch_theme( $block_theme );
		$this->theme = $block_theme;

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

	// ── POST /template (gated write) ──────────────────────────────────

	private function update_request( $id, $content ) {
		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/template' );
		$request->set_param( 'id', $id );
		$request->set_param( 'content', $content );
		return $request;
	}

	/**
	 * With the toggle off (the default), POST /template 403s with the
	 * actionable "turned off for this site" message, even for an editor.
	 */
	public function test_update_template_route_403_when_toggle_off() {
		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 403, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'template_edits_disabled', $data['code'] );
		$this->assertStringContainsString( 'turned off', $data['message'] );
	}

	/**
	 * With the toggle on, a plain contributor (edit_posts, no dedicated
	 * cap, no edit_theme_options) must still be denied. The plugin performs
	 * the underlying wp_insert_post()/wp_update_post() itself — those don't
	 * enforce capabilities — so edit_posts alone would let any
	 * contributor-or-above account (or a leaked low-privilege Application
	 * Password) rewrite sitewide template chrome.
	 */
	public function test_update_template_route_403_for_contributor_even_with_toggle_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );

		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Same denial for an editor — edit_posts (plus the wider edit_others_posts/
	 * publish_posts an editor also holds) is still not the dedicated
	 * gk_block_mcp_edit_templates cap, so an editor is denied exactly like a
	 * contributor. Proves the fix isn't just "block the weakest role."
	 */
	public function test_update_template_route_403_for_editor_even_with_toggle_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>Via REST</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * With the toggle on, an actor holding NEITHER edit_posts NOR
	 * edit_theme_options is still forbidden — the toggle widens what an
	 * already-capable actor may do, it does not replace capability checks.
	 */
	public function test_update_template_route_403_for_subscriber_even_with_toggle_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * An actor with edit_theme_options but no dedicated cap (the other half
	 * of the gate) can still write — this is the path a "self" (human
	 * admin) connection uses.
	 */
	public function test_update_template_route_succeeds_via_edit_theme_options_alone() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$role_name = 'gk_test_theme_options_only';
		add_role( $role_name, 'Theme Options Only', array( 'read' => true, 'edit_theme_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role_name ) ) );

		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>Via theme options cap</p><!-- /wp:paragraph -->' ) );

		remove_role( $role_name );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The dedicated agent role is the one actor the toggle is actually
	 * meant to empower: with the toggle on, register_role() grants it
	 * Agent_Provisioner::TEMPLATE_EDIT_CAP (confirmed at the capability
	 * level, not just behaviorally), and POST /template succeeds.
	 */
	public function test_update_template_route_succeeds_for_agent_role_when_toggle_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		Agent_Provisioner::register_role();

		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Agent_Provisioner::ROLE ) ) );

		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>Via agent role</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertTrue( $data['success'] );
		$this->assertTrue( $data['override_created'] );
	}

	/**
	 * Turning the toggle back off and re-running register_role() revokes
	 * Agent_Provisioner::TEMPLATE_EDIT_CAP from the role (the additive
	 * re-assert loop never removes caps — this is the one deliberate
	 * exception) and the route 403s for the same agent-role user. With the
	 * toggle off the 403 is dominated by the toggle gate itself
	 * (template_edits_disabled), so the capability-level assertion is what
	 * actually proves the revoke happened.
	 */
	public function test_agent_role_loses_template_edit_cap_and_route_403s_when_toggle_off() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		Agent_Provisioner::register_role();
		$user_id = self::factory()->user->create( array( 'role' => Agent_Provisioner::ROLE ) );

		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '0' );
		Agent_Provisioner::register_role();

		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ), 'toggle-off must revoke the managed cap, not just leave it granted' );

		wp_set_current_user( $user_id );
		$response = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * A legacy-tier block in `blocks` is rejected on the controller's own
	 * handler.
	 *
	 * Invoked directly on `$this->controller` rather than through
	 * `rest_get_server()->dispatch()`: `rest_api_init` also wires a second,
	 * production `REST_Controller` (the plugin's own bootstrap, per
	 * `gk-block-mcp.php`) onto the global REST server the first time
	 * anything touches it in this process, and that instance's `Preferences`
	 * lazily caches its namespace scores on first read — so a per-test
	 * `update_option( Preferences::OPTION_KEY, … )` seeded after that first
	 * read never reaches it. `$this->controller` is this test's own
	 * freshly-built instance and reads the option live. Permission and arg
	 * sanitization aren't under test here (both are covered by the other
	 * tests in this file that DO go through `dispatch()`), so calling the
	 * handler directly is safe for this assertion.
	 */
	public function test_update_template_route_rejects_legacy_block() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		update_option(
			\GravityKit\BlockMCP\Preferences::OPTION_KEY,
			array( 'namespace_scores' => array( 'ugb' => 0 ) )
		);
		$registry = \WP_Block_Type_Registry::get_instance();
		if ( ! $registry->is_registered( 'ugb/text' ) ) {
			$registry->register( 'ugb/text' );
		}

		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/template' );
		$request->set_param( 'id', $this->theme . '//index' );
		$request->set_param( 'blocks', array( array( 'name' => 'ugb/text', 'attributes' => array(), 'innerHTML' => '<div>legacy</div>' ) ) );

		$response = $this->controller->update_template( $request );

		$this->assertInstanceOf( \WP_Error::class, $response );
		$this->assertSame( 'legacy_block', $response->get_error_code() );
	}

	// ── POST /template/reset (gated write) ────────────────────────────

	/**
	 * With the toggle off, POST /template/reset 403s the same as the
	 * update route.
	 */
	public function test_reset_template_route_403_when_toggle_off() {
		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/template/reset' );
		$request->set_param( 'id', $this->theme . '//index' );
		$response = $this->dispatch( $request );

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * With the toggle on, resetting an existing override through the full
	 * REST path deletes the override post and reports its wp_id.
	 */
	public function test_reset_template_route_deletes_override() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		$role_name = 'gk_test_reset_theme_options_only';
		add_role( $role_name, 'Theme Options Only', array( 'read' => true, 'edit_theme_options' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role_name ) ) );

		$created = $this->dispatch( $this->update_request( $this->theme . '//index', '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ) );
		$wp_id   = $created->get_data()['wp_id'];

		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/template/reset' );
		$request->set_param( 'id', $this->theme . '//index' );
		$response = $this->dispatch( $request );

		remove_role( $role_name );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $wp_id, $response->get_data()['wp_id'] );
		$this->assertNull( get_post( $wp_id ) );
	}
}
