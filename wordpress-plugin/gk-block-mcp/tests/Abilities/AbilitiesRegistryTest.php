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
use function GravityKit\BlockMCP\get_abilities_registry;

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

	/**
	 * Registration is opt-in: with the option unset (a fresh install's
	 * default), Block_Abilities::is_enabled() reads the '0' fallback and
	 * reports disabled. set_up() above forces the option on for every other
	 * test in this file, so this test explicitly overrides it back rather
	 * than relying on an untouched option.
	 */
	public function test_abilities_disabled_by_default() {
		delete_option( Block_Abilities::ENABLED_OPTION );
		$this->assertFalse( Block_Abilities::is_enabled() );
	}

	/**
	 * The stored option enables registration when set to '1'.
	 */
	public function test_setting_enables_abilities() {
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		$this->assertTrue( Block_Abilities::is_enabled() );
	}

	/**
	 * The stored option keeps registration off when explicitly '0'.
	 */
	public function test_setting_disables_abilities() {
		update_option( Block_Abilities::ENABLED_OPTION, '0' );
		$this->assertFalse( Block_Abilities::is_enabled() );
	}

	/**
	 * The gk/block-mcp/abilities/enabled filter overrides a truthy option,
	 * for programmatic control (matching the allow-trash pattern used
	 * elsewhere in the plugin).
	 */
	public function test_filter_can_disable_abilities() {
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		add_filter( 'gk/block-mcp/abilities/enabled', '__return_false' );
		$enabled = Block_Abilities::is_enabled();
		remove_filter( 'gk/block-mcp/abilities/enabled', '__return_false' );
		$this->assertFalse( $enabled );
	}

	/**
	 * End-to-end: with the option unset, the plugin bootstrap's
	 * get_abilities_registry() (gk-block-mcp.php) returns null and never
	 * builds an Abilities_Registry — the gate holds at the bootstrap seam
	 * actually wired to WordPress, not only inside Block_Abilities::is_enabled().
	 *
	 * get_abilities_registry() memoizes a successful build in a process-wide
	 * static, and WP_Abilities_Registry::get_instance() (WordPress core)
	 * fires its own init hooks exactly once per process, on the first
	 * wp_get_ability()/wp_has_ability() touch. Every other test in this file
	 * enables abilities in set_up() and is the sole intended toucher of the
	 * live Abilities API for the whole suite (see set_up() docblock), so
	 * running this assertion in-process would either observe an
	 * already-warmed registry (false pass) or permanently consume the
	 * one-shot WordPress init hook while abilities are disabled, breaking
	 * every other test in this file for the rest of the process.
	 * @runInSeparateProcess guarantees a pristine process where this is the
	 * first and only touch of both statics.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_bootstrap_does_not_build_abilities_registry_when_disabled_by_default() {
		delete_option( Block_Abilities::ENABLED_OPTION );
		$this->assertNull( get_abilities_registry() );
	}

	/**
	 * The permission callback gates writes on edit_post for the target post:
	 * a user without any capability gets the Abilities API's
	 * ability_invalid_permissions, never the effect. WP_Ability::execute()
	 * logs a _doing_it_wrong() when the permission callback itself returns a
	 * WP_Error (rather than false), which this test must expect.
	 */
	public function test_update_block_ability_denies_without_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Old</p>' ) ) );

		wp_set_current_user( 0 );
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'flat_index' => 0,
				'innerHTML'  => '<p>New</p>',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertStringContainsString( '<p>Old</p>', $this->block_tree( $post_id )[0]['innerHTML'], 'content must be untouched' );
	}

	/**
	 * Read/discovery abilities are annotated readonly; the mutating
	 * update-block ability is not. The annotation is the hint MCP clients
	 * use to label a tool.
	 */
	public function test_read_abilities_are_annotated_readonly() {
		$get    = wp_get_ability( 'gk-block-mcp/get-page-blocks' )->get_meta();
		$list   = wp_get_ability( 'gk-block-mcp/list-block-types' )->get_meta();
		$update = wp_get_ability( 'gk-block-mcp/update-block' )->get_meta();

		$this->assertTrue( $get['annotations']['readonly'] );
		$this->assertTrue( $list['annotations']['readonly'] );
		$this->assertFalse( $update['annotations']['readonly'] );
	}

	/**
	 * Every write ability declares an explicit destructive annotation
	 * matching its behavior rather than the core null default (which MCP
	 * treats as destructive). update-block and update-blocks overwrite
	 * existing block content — both TRUE, the manifest's current
	 * destructiveHint semantics; insert-blocks and create-post only add
	 * content — FALSE; delete-block removes blocks — TRUE.
	 */
	public function test_write_abilities_declare_destructive_annotation() {
		$update  = wp_get_ability( 'gk-block-mcp/update-block' )->get_meta()['annotations'];
		$updates = wp_get_ability( 'gk-block-mcp/update-blocks' )->get_meta()['annotations'];
		$insert  = wp_get_ability( 'gk-block-mcp/insert-blocks' )->get_meta()['annotations'];
		$create  = wp_get_ability( 'gk-block-mcp/create-post' )->get_meta()['annotations'];
		$delete  = wp_get_ability( 'gk-block-mcp/delete-block' )->get_meta()['annotations'];

		$this->assertTrue( $update['destructive'], 'update-block overwrites existing block content' );
		$this->assertTrue( $updates['destructive'], 'update-blocks overwrites existing block content' );
		$this->assertFalse( $insert['destructive'], 'insert-blocks only adds blocks' );
		$this->assertFalse( $create['destructive'], 'create-post only adds a post' );
		$this->assertTrue( $delete['destructive'], 'delete-block removes blocks' );
	}

	/**
	 * A non-numeric post_id is rejected by input-schema validation
	 * (rest_validate_value_from_schema against the manifest's declared
	 * "type": "number") before the execute callback ever runs — a
	 * WordPress Abilities API structural guarantee, not bespoke plugin code.
	 */
	public function test_update_block_rejects_non_numeric_post_id() {
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => 'not-a-number',
				'flat_index' => 0,
				'innerHTML'  => '<p>x</p>',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * insert-blocks position -1 is the documented "append" sentinel
	 * (after_top_level: -1, matching the REST `after` and npm MCP server
	 * contracts) and must keep working through the ability layer. Pins
	 * commit 2e8428d's -1 contract; a pure pin of current behavior (proven
	 * green-on-current, no revert needed — the sibling rejection test below
	 * is the one that proves the actual guard with red/green teeth).
	 */
	public function test_insert_blocks_ability_position_negative_one_still_appends() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>A</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/insert-blocks' )->execute(
			array(
				'post_id'         => $post_id,
				'after_top_level' => -1,
				'blocks'          => array(
					array(
						'name'      => 'core/paragraph',
						'innerHTML' => '<p>NEW</p>',
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 2, $blocks );
		$this->assertStringContainsString( '<p>NEW</p>', $blocks[1]['innerHTML'] );
	}

	/**
	 * A position more negative than the documented -1 append sentinel has no
	 * defined meaning. Block_Writer::insert_blocks() rejects it with a 400
	 * invalid_position WP_Error (commit 2e8428d) rather than silently
	 * clamping to a prepend; the ability layer must surface that error
	 * unchanged, not swallow or remap it. This pins the fix itself, so it
	 * carries red/green teeth (proven by temporarily reverting the guard).
	 */
	public function test_insert_blocks_ability_rejects_position_more_negative_than_append_sentinel() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>A</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/insert-blocks' )->execute(
			array(
				'post_id'         => $post_id,
				'after_top_level' => -2,
				'blocks'          => array(
					array(
						'name'      => 'core/paragraph',
						'innerHTML' => '<p>NEW</p>',
					),
				),
			)
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertWPError( $result, 'a position more negative than -1 must error, not silently prepend' );
		$this->assertSame( 'invalid_position', $result->get_error_code() );
		$this->assertStringNotContainsString( '<p>NEW</p>', $content, 'no block may be inserted' );
		$this->assertStringContainsString( '<p>A</p>', $content );
	}

	/**
	 * Executing update-block delegates through Tool_Executor to the same
	 * REST_Controller::update_block() path the REST endpoint uses, and
	 * persists.
	 */
	public function test_update_block_ability_persists_change() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Old</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'flat_index' => 0,
				'innerHTML'  => '<p>New</p>',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '<p>New</p>', $this->block_tree( $post_id )[0]['innerHTML'] );
	}

	/**
	 * Executing create-post delegates through Tool_Executor to Post_Manager,
	 * emitting canonical block-comment markup rather than a corrupted
	 * classic-editor blob.
	 */
	public function test_create_post_ability_creates_post() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/create-post' )->execute(
			array(
				'title'  => 'Via Ability',
				'blocks' => array(
					array(
						'name'      => 'core/paragraph',
						'innerHTML' => '<p>Hi</p>',
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertStringContainsString( '<!-- wp:paragraph', (string) get_post_field( 'post_content', $result['id'] ) );
	}

	/**
	 * Executing insert-blocks delegates through Tool_Executor to
	 * Block_CRUD::insert_blocks() and appends.
	 */
	public function test_insert_blocks_ability_appends() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>First</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/insert-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array(
					array(
						'name'      => 'core/paragraph',
						'innerHTML' => '<p>Second</p>',
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 2, $blocks );
		$this->assertStringContainsString( '<p>Second</p>', $blocks[1]['innerHTML'] );
	}

	/**
	 * Executing get-page-blocks returns the structured block tree from the
	 * real reader, in the same {summary, blocks} shape REST_Controller::
	 * get_post_blocks() returns to the REST endpoint.
	 */
	public function test_get_page_blocks_ability_returns_tree() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Hello</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-page-blocks' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'blocks', $result );
		$this->assertNotEmpty( $result['blocks'] );
	}

	/**
	 * Build a flat core/paragraph block in WP-internal shape, for seeding
	 * make_block_post() fixtures (distinct from the {name, innerHTML} MCP
	 * shape ability payloads use for insert-blocks/create-post `blocks`).
	 *
	 * @param string $html Paragraph innerHTML.
	 * @return array
	 */
	private function paragraph( string $html ): array {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}
}
