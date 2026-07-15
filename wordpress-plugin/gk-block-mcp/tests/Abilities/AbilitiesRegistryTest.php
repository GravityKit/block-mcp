<?php
/**
 * Abilities API registration and tool execution tests.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Abilities_Registry;
use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Tool_Executor;
use GravityKit\BlockMCP\Yoast_Bridge;

/**
 * Covers the exported tool manifest and in-process tool execution.
 */
class AbilitiesRegistryTest extends RestControllerTestCase {

	/**
	 * Registration defaults to opt-in (off); enable it so the WordPress
	 * Abilities API registry — a lazily-initialized singleton that fires its
	 * `wp_abilities_api_init`/`wp_abilities_api_categories_init` bootstrap
	 * exactly once per process, on first touch — registers Block MCP's
	 * abilities instead of finding the toggle off and no-oping. This file is
	 * the only place in the suite that touches the live Abilities API, so
	 * setting the option here (before any test method can trigger that
	 * first touch) is sufficient regardless of method execution order.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
	}

	/**
	 * The exported manifest must stay aligned with the npm MCP server's tool list.
	 */
	public function test_manifest_lists_all_block_mcp_tools() {
		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$reflection = new \ReflectionClass( $registry );
		$method     = $reflection->getMethod( 'load_manifest' );
		$method->setAccessible( true );
		$manifest = $method->invoke( $registry );

		$this->assertIsArray( $manifest );
		$this->assertCount( 27, $manifest['tools'] );
		$names = wp_list_pluck( $manifest['tools'], 'name' );
		$this->assertContains( 'get_page_blocks', $names );
		$this->assertContains( 'edit_block_tree', $names );
		$this->assertContains( 'site_editor_context', $names );
	}

	/**
	 * resolve_url ability execution must reach the REST resolve handler.
	 */
	public function test_tool_executor_resolve_url_returns_post_id() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'  => 'Abilities Resolve Target',
				'post_status' => 'publish',
			)
		);

		$user_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $user_id );

		$executor = new Tool_Executor( $this->controller, new Yoast_Bridge() );
		$result   = $executor->execute(
			'resolve_url',
			array( 'url' => get_permalink( $post_id ) )
		);

		$this->assertIsArray( $result );
		$this->assertSame( $post_id, (int) $result['post_id'] );
	}

	/**
	 * Ability ids use the gk-block-mcp namespace and dashed slugs.
	 *
	 * Count excludes the three yoast_* tools: get_ability_ids() reflects what
	 * actually registers, which is conditional on Yoast being active, unlike
	 * the manifest itself (still 27 — test_manifest_lists_all_block_mcp_tools).
	 * This test is untagged, so it only ever runs in the default (non-Yoast)
	 * suite.
	 */
	public function test_ability_ids_use_expected_namespace() {
		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$ids = $registry->get_ability_ids();
		$this->assertCount( 24, $ids );
		$this->assertContains( 'gk-block-mcp/get-page-blocks', $ids );
		$this->assertContains( 'gk-block-mcp/edit-block-tree', $ids );
		$this->assertContains( 'gk-block-mcp/site-editor-context', $ids );
		$this->assertNotContains( 'gk-block-mcp/yoast-get-seo', $ids );
	}

	/**
	 * Yoast abilities must not be registered when Yoast SEO isn't active —
	 * Tool_Executor's yoast_* handlers hard-fail with `yoast_unavailable`
	 * otherwise, so registering them would advertise abilities that always
	 * error. Runs under the default (non-Yoast) suite only; the positive
	 * case is covered by test_yoast_abilities_registered_when_yoast_active()
	 * under tests/phpunit/yoast.xml.
	 */
	public function test_yoast_abilities_not_registered_when_yoast_inactive() {
		$this->assertFalse( Yoast_Bridge::is_yoast_active(), 'sanity: this test must run where Yoast is not loaded' );

		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$ids = $registry->get_ability_ids();
		$this->assertNotContains( 'gk-block-mcp/yoast-get-seo', $ids );
		$this->assertNotContains( 'gk-block-mcp/yoast-update-seo', $ids );
		$this->assertNotContains( 'gk-block-mcp/yoast-bulk-update-seo', $ids );

		// The plugin bootstrap already registered abilities on the init hooks
		// (see test_registered_abilities_are_mcp_public for why this reads
		// live registration instead of re-registering). wp_get_ability() on an
		// unregistered id triggers WP core's own _doing_it_wrong() — expect it
		// rather than let the test framework flag it as unexpected.
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$this->assertNull( wp_get_ability( 'gk-block-mcp/yoast-get-seo' ) );
		$this->assertNull( wp_get_ability( 'gk-block-mcp/yoast-update-seo' ) );
		$this->assertNull( wp_get_ability( 'gk-block-mcp/yoast-bulk-update-seo' ) );
	}

	/**
	 * Mirror of test_yoast_abilities_not_registered_when_yoast_inactive() for
	 * the positive case: when Yoast SEO is active, the three yoast_* tools
	 * ARE registered as abilities (and the manifest's full 27 count is
	 * reachable). Tagged `@group yoast` so it only runs under
	 * tests/phpunit/yoast.xml, the one config that loads Yoast SEO
	 * (GK_LOAD_YOAST=1) — the default suite excludes the yoast group, so
	 * this never runs where Yoast is absent.
	 *
	 * @group yoast
	 */
	public function test_yoast_abilities_registered_when_yoast_active() {
		$this->assertTrue( Yoast_Bridge::is_yoast_active(), 'sanity: this test must run where Yoast is loaded' );

		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$ids = $registry->get_ability_ids();
		$this->assertCount( 27, $ids );
		$this->assertContains( 'gk-block-mcp/yoast-get-seo', $ids );
		$this->assertContains( 'gk-block-mcp/yoast-update-seo', $ids );
		$this->assertContains( 'gk-block-mcp/yoast-bulk-update-seo', $ids );

		$this->assertNotNull( wp_get_ability( 'gk-block-mcp/yoast-get-seo' ) );
		$this->assertNotNull( wp_get_ability( 'gk-block-mcp/yoast-update-seo' ) );
		$this->assertNotNull( wp_get_ability( 'gk-block-mcp/yoast-bulk-update-seo' ) );
	}

	/**
	 * Every registered ability carries meta.mcp.public = true and type = tool.
	 *
	 * This is what the MCP Adapter's discover-abilities meta-tool filters on;
	 * without it the abilities register in WordPress but are invisible over MCP.
	 * Pin it so the flag can never silently regress.
	 */
	public function test_registered_abilities_are_mcp_public() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable (requires WordPress 6.9+).' );
		}

		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		// The plugin bootstrap already registered these on the init hooks; read
		// the live registration rather than re-registering (WP 6.9 warns on
		// duplicate registration).
		$ability = wp_get_ability( 'gk-block-mcp/get-page-blocks' );
		$this->assertNotNull( $ability, 'ability must be registered' );

		$meta = $ability->get_meta();
		$this->assertArrayHasKey( 'mcp', $meta, 'meta.mcp is required for MCP discovery' );
		$this->assertTrue( $meta['mcp']['public'], 'meta.mcp.public must be true' );

		foreach ( $registry->get_ability_ids() as $id ) {
			$a = wp_get_ability( $id );
			$this->assertNotNull( $a, $id . ' must be registered' );
			$m = $a->get_meta();
			$this->assertTrue(
				isset( $m['mcp']['public'] ) && true === $m['mcp']['public'],
				$id . ' must be mcp.public'
			);
		}
	}

	/**
	 * The site-editor-context ability returns the site's design tokens so an
	 * agent composes theme-aligned, valid block markup (preset slugs instead
	 * of raw hex/px) — the WordPress-recommended prevention move. Restored
	 * from develop's hand-written Block_Abilities into the manifest-driven
	 * registry; execution now lives in Tool_Executor::execute_site_editor_context().
	 */
	public function test_site_editor_context_ability_returns_presets() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/site-editor-context' )->execute();

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'theme', $result );
		$this->assertArrayHasKey( 'presets', $result );
		$this->assertArrayHasKey( 'colors', $result['presets'] );
		$this->assertIsArray( $result['presets']['colors'] );
	}

	/**
	 * site-editor-context is a read-only discovery ability, per the manifest
	 * annotation restored alongside it (see tools.manifest.json EXTRA_TOOLS
	 * entry in scripts/export-abilities-manifest.mjs).
	 */
	public function test_site_editor_context_is_readonly() {
		$meta = wp_get_ability( 'gk-block-mcp/site-editor-context' )->get_meta();
		$this->assertTrue( $meta['annotations']['readonly'] );
	}
}
