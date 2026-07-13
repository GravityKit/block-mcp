<?php
/**
 * Tests for Block_Abilities — exposing Block MCP operations through the
 * WordPress 6.9 Abilities API so the official MCP Adapter (and any Abilities
 * consumer) can discover and invoke them.
 *
 * The Abilities registry lazily fires `wp_abilities_api_categories_init` then
 * `wp_abilities_api_init` on first access. Each test wires a fresh registrar to
 * those hooks and fires them; the registrar is idempotent so re-entrant firing
 * is safe. tear_down unregisters to keep the global registry clean.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Media_Manager;
use GravityKit\BlockMCP\Pattern_Manager;
use GravityKit\BlockMCP\Block_Registry;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Post_Manager;
use GravityKit\BlockMCP\Preferences;
use GravityKit\BlockMCP\REST_Controller;
use GravityKit\BlockMCP\Term_Manager;
use GravityKit\BlockMCP\Yoast_Bridge;

class BlockAbilitiesTest extends BlockApiTestCase {

	/** @var Block_Abilities|null */
	private $registrar = null;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable (requires WordPress 6.9+).' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->registrar = $this->make_registrar();

		add_action( 'wp_abilities_api_categories_init', array( $this->registrar, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->registrar, 'register_abilities' ) );
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	public function tear_down(): void {
		if ( null !== $this->registrar && function_exists( 'wp_unregister_ability' ) ) {
			foreach ( $this->registrar->ability_names() as $name ) {
				if ( wp_has_ability( $name ) ) {
					wp_unregister_ability( $name );
				}
			}
		}
		parent::tear_down();
	}

	/**
	 * Build the same service graph the plugin bootstrap gives Block_Abilities.
	 *
	 * @return Block_Abilities
	 */
	private function make_registrar() {
		$preferences = new Preferences();
		$inventory   = new Block_Inventory();
		$registry    = new Block_Registry( $preferences, $inventory );
		$patterns    = new Pattern_Manager( $preferences );
		$posts       = new Post_Manager( $this->crud );
		$controller  = new REST_Controller(
			$registry,
			$patterns,
			$this->crud,
			$inventory,
			$this->mutator,
			$posts,
			new Term_Manager(),
			new Media_Manager(),
			$preferences
		);

		return new Block_Abilities( $this->crud, $posts, $registry, $controller, new Yoast_Bridge() );
	}

	/**
	 * The block-tree operations register as namespaced abilities under the
	 * `gk-block-mcp` category once the Abilities init hooks fire.
	 */
	public function test_core_abilities_are_registered() {
		$this->assertTrue( wp_has_ability_category( 'gk-block-mcp' ), 'category registered' );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/get-page-blocks' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/update-block' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/insert-blocks' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/create-post' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/list-block-types' ) );
	}

	/**
	 * Read/discovery abilities are annotated readonly; write abilities are not.
	 * The annotation is the hint MCP clients use to label a tool.
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
	 * Every write ability declares an explicit `destructive` annotation matching
	 * its behavior, rather than leaving the core null default (which MCP treats
	 * as destructive). Per the MCP destructiveHint spec, false means "additive
	 * only": insert-blocks and create-post add content → non-destructive;
	 * update-block overwrites a block's content and delete-block removes blocks →
	 * destructive.
	 */
	public function test_write_abilities_declare_destructive_annotation() {
		$update = wp_get_ability( 'gk-block-mcp/update-block' )->get_meta()['annotations'];
		$insert = wp_get_ability( 'gk-block-mcp/insert-blocks' )->get_meta()['annotations'];
		$create = wp_get_ability( 'gk-block-mcp/create-post' )->get_meta()['annotations'];
		$delete = wp_get_ability( 'gk-block-mcp/delete-block' )->get_meta()['annotations'];

		$this->assertTrue( $update['destructive'], 'update-block overwrites existing block content' );
		$this->assertFalse( $insert['destructive'], 'insert-blocks only adds blocks' );
		$this->assertFalse( $create['destructive'], 'create-post only adds a post' );
		$this->assertTrue( $delete['destructive'], 'delete-block removes blocks' );
	}

	/**
	 * The permission callback gates writes on `edit_post` for the target post:
	 * a user without the capability gets `ability_invalid_permissions`, never the
	 * effect.
	 */
	public function test_update_block_ability_denies_without_capability() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Old</p>' ) ) );

		wp_set_current_user( 0 );
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => 0,
				'inner_html' => '<p>New</p>',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertStringContainsString( '<p>Old</p>', $this->block_tree( $post_id )[0]['innerHTML'], 'content must be untouched' );
	}

	/**
	 * Executing the update-block ability delegates to Block_CRUD and persists —
	 * the same path the REST endpoint uses.
	 */
	public function test_update_block_ability_persists_change() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Old</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => 0,
				'inner_html' => '<p>New</p>',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '<p>New</p>', $this->block_tree( $post_id )[0]['innerHTML'] );
	}

	/**
	 * The read ability returns a structured block tree from the real reader.
	 */
	public function test_get_page_blocks_ability_returns_tree() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Hello</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-page-blocks' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * The create-post ability creates a real post through Post_Manager, emitting
	 * canonical block-comment markup (not a corrupted classic blob).
	 */
	public function test_create_post_ability_creates_post() {
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
	 * The insert-blocks ability appends through Block_CRUD.
	 */
	public function test_insert_blocks_ability_appends() {
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
	 * The list-block-types discovery ability returns registered types, and
	 * accepts the empty default input.
	 */
	public function test_list_block_types_ability_returns_types() {
		$result = wp_get_ability( 'gk-block-mcp/list-block-types' )->execute();

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	/**
	 * Pattern discovery delegates to the shared pattern manager/controller graph
	 * and returns the MCP pagination envelope around real synced patterns.
	 */
	public function test_list_patterns_ability_returns_synced_pattern() {
		$pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Ability Pattern',
				'post_content' => '<!-- wp:paragraph --><p>Pattern body</p><!-- /wp:paragraph -->',
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/list-patterns' )->execute(
			array(
				'synced' => true,
				'limit'  => 20,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'patterns', $result );
		$this->assertContains( $pattern_id, wp_list_pluck( $result['patterns'], 'id' ) );
	}

	/**
	 * Term discovery delegates to Term_Manager and returns real taxonomy terms
	 * with the same pagination envelope as the REST endpoint.
	 */
	public function test_list_terms_ability_returns_category() {
		$term = self::factory()->term->create_and_get(
			array(
				'taxonomy' => 'category',
				'name'     => 'Ability Category',
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/list-terms' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertNotWPError( $result );
		$this->assertContains( $term->term_id, wp_list_pluck( $result['terms'], 'id' ) );
	}

	/**
	 * Yoast abilities are absent when Yoast SEO is inactive, matching the bridge
	 * route gate and preventing dead integration tools from being advertised.
	 */
	public function test_yoast_abilities_are_not_registered_without_yoast() {
		$this->assertFalse( Yoast_Bridge::is_yoast_active() );
		$this->assertFalse( wp_has_ability( 'gk-block-mcp/yoast-get-seo' ) );
		$this->assertFalse( wp_has_ability( 'gk-block-mcp/yoast-update-seo' ) );
		$this->assertFalse( wp_has_ability( 'gk-block-mcp/yoast-bulk-update-seo' ) );
	}

	/**
	 * With Yoast SEO loaded, its read ability registers and delegates to the
	 * active bridge for a real post.
	 *
	 * @group yoast
	 */
	public function test_yoast_get_seo_ability_registers_and_reads_when_active() {
		// Executing the ability boots Yoast's own surface, which re-registers
		// Yoast's abilities without an is-registered guard — a Yoast quirk,
		// not a Block MCP one. Scope the expected notices to this test.
		$this->setExpectedIncorrectUsage( 'WP_Ability_Categories_Registry::register' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertTrue( Yoast_Bridge::is_yoast_active() );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/yoast-get-seo' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/yoast-update-seo' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/yoast-bulk-update-seo' ) );

		$result = wp_get_ability( 'gk-block-mcp/yoast-get-seo' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertSame( $post_id, $result['post_id'] );
	}

	/**
	 * The delete-block and site-editor-context abilities are registered.
	 */
	public function test_delete_and_context_abilities_are_registered() {
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/delete-block' ) );
		$this->assertTrue( wp_has_ability( 'gk-block-mcp/site-editor-context' ) );
	}

	/**
	 * The delete-block ability removes the addressed block via Block_CRUD.
	 */
	public function test_delete_block_ability_removes_block() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>One</p>' ),
				$this->paragraph( '<p>Two</p>' ),
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute(
			array(
				'post_id' => $post_id,
				'index'   => 0,
			)
		);

		$this->assertNotWPError( $result );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringNotContainsString( '<p>One</p>', $content );
		$this->assertStringContainsString( '<p>Two</p>', $content );
	}

	/**
	 * delete-block is annotated as a destructive, non-readonly operation.
	 */
	public function test_delete_block_ability_is_annotated_destructive() {
		$annotations = wp_get_ability( 'gk-block-mcp/delete-block' )->get_meta()['annotations'];
		$this->assertFalse( $annotations['readonly'] );
		$this->assertTrue( $annotations['destructive'] );
	}

	/**
	 * The site-editor-context ability returns the site's design tokens so an
	 * agent can compose theme-aligned, valid block markup (preset slugs instead
	 * of raw hex/px) — the WordPress-recommended prevention move.
	 */
	public function test_site_editor_context_ability_returns_presets() {
		$result = wp_get_ability( 'gk-block-mcp/site-editor-context' )->execute();

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'theme', $result );
		$this->assertArrayHasKey( 'presets', $result );
		$this->assertArrayHasKey( 'colors', $result['presets'] );
		$this->assertIsArray( $result['presets']['colors'] );
	}

	/**
	 * site-editor-context is a read-only discovery ability.
	 */
	public function test_site_editor_context_is_readonly() {
		$this->assertTrue( wp_get_ability( 'gk-block-mcp/site-editor-context' )->get_meta()['annotations']['readonly'] );
	}

	// ── Enable/disable setting ────────────────────────────────────────

	/**
	 * Registration is enabled by default (opt-out).
	 */
	public function test_abilities_enabled_by_default() {
		$this->assertTrue( Block_Abilities::is_enabled() );
	}

	/**
	 * The stored setting disables registration when turned off.
	 */
	public function test_setting_disables_abilities() {
		update_option( Block_Abilities::ENABLED_OPTION, '0' );
		$this->assertFalse( Block_Abilities::is_enabled() );
	}

	/**
	 * The gk/block-mcp/abilities/enabled filter overrides the option, for
	 * programmatic control (matching the allow-trash pattern).
	 */
	public function test_filter_can_disable_abilities() {
		add_filter( 'gk/block-mcp/abilities/enabled', '__return_false' );
		$enabled = Block_Abilities::is_enabled();
		remove_filter( 'gk/block-mcp/abilities/enabled', '__return_false' );
		$this->assertFalse( $enabled );
	}

	/**
	 * register() wires no Abilities hooks when the setting is off — turning it
	 * off removes the entire surface, not just REST exposure.
	 */
	public function test_register_is_noop_when_disabled() {
		update_option( Block_Abilities::ENABLED_OPTION, '0' );
		$registrar = $this->make_registrar();

		$registrar->register();

		$this->assertFalse( has_action( 'wp_abilities_api_init', array( $registrar, 'register_abilities' ) ) );
	}

	// ── Adversarial / hardening ───────────────────────────────────────

	/**
	 * A non-numeric post_id is rejected by input-schema validation before the
	 * execute callback ever runs.
	 */
	public function test_update_block_rejects_non_numeric_post_id() {
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => 'not-a-number',
				'index'      => 0,
				'inner_html' => '<p>x</p>',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * A missing required field is rejected before execution — the callback must
	 * never read an undefined index.
	 */
	public function test_update_block_rejects_missing_required_index() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'inner_html' => '<p>y</p>',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * A scalar where an object is expected (attributes) is rejected.
	 */
	public function test_update_block_rejects_scalar_attributes() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => 0,
				'attributes' => 'oops',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * An out-of-range index returns a graceful WP_Error, never a fatal.
	 */
	public function test_update_block_out_of_range_index_is_graceful() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => 999,
				'inner_html' => '<p>y</p>',
			)
		);
		$this->assertWPError( $result );
	}

	/**
	 * post_id 0 fails the capability check — you can't edit a nonexistent post.
	 */
	public function test_writes_deny_post_id_zero() {
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => 0,
				'index'      => 0,
				'inner_html' => '<p>x</p>',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * Script tags in innerHTML are stripped via the shared sanitization path —
	 * the ability inherits the engine's XSS hardening.
	 */
	public function test_update_block_strips_script_tags() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>safe</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => 0,
				'inner_html' => '<p>hi<script>alert(1)</script></p>',
			)
		);
		$this->assertNotWPError( $result );
		$this->assertStringNotContainsString( '<script', (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * An empty title returns the engine's WP_Error, not a fatal.
	 */
	public function test_create_post_empty_title_is_graceful() {
		$result = wp_get_ability( 'gk-block-mcp/create-post' )->execute( array( 'title' => '' ) );
		$this->assertWPError( $result );
		$this->assertSame( 'missing_title', $result->get_error_code() );
	}

	/**
	 * content + blocks together is rejected by the engine.
	 */
	public function test_create_post_content_and_blocks_mutually_exclusive() {
		$result = wp_get_ability( 'gk-block-mcp/create-post' )->execute(
			array(
				'title'   => 'X',
				'content' => 'hi',
				'blocks'  => array(
					array(
						'name'      => 'core/paragraph',
						'innerHTML' => '<p>h</p>',
					),
				),
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'mutually_exclusive', $result->get_error_code() );
	}

	/**
	 * A scalar where an array is expected (blocks) is rejected.
	 */
	public function test_insert_blocks_rejects_scalar_blocks() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/insert-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => 'nope',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * A negative delete count is clamped to a single block — never a runaway
	 * mass delete.
	 */
	public function test_delete_block_clamps_negative_count() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>One</p>' ),
				$this->paragraph( '<p>Two</p>' ),
				$this->paragraph( '<p>Three</p>' ),
			)
		);
		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute(
			array(
				'post_id' => $post_id,
				'index'   => 0,
				'count'   => -5,
			)
		);
		$this->assertNotWPError( $result );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringNotContainsString( '<p>One</p>', $content );
		$this->assertStringContainsString( '<p>Two</p>', $content );
		$this->assertStringContainsString( '<p>Three</p>', $content );
	}

	/**
	 * A negative index must not address a block from the end of the array
	 * (array_splice semantics) — the ability talks to Block_CRUD directly, so it
	 * cannot inherit the REST controller's index sanitization.
	 */
	public function test_delete_block_rejects_negative_index() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>One</p>' ),
				$this->paragraph( '<p>Two</p>' ),
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute(
			array(
				'post_id' => $post_id,
				'index'   => -1,
			)
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertWPError( $result, 'a negative index must error, not delete from the end' );
		$this->assertStringContainsString( '<p>One</p>', $content, 'no block may be removed' );
		$this->assertStringContainsString( '<p>Two</p>', $content );
	}

	/**
	 * A negative update index must error, never wrap to the last block.
	 */
	public function test_update_block_rejects_negative_index() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => -1,
				'inner_html' => '<p>y</p>',
			)
		);
		$this->assertWPError( $result );
	}

	/**
	 * innerHTML carrying block-comment delimiters must not break out of the block
	 * and inject sibling blocks on the next parse.
	 */
	public function test_update_block_neutralizes_block_comment_injection() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>safe</p>' ) ) );

		wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array(
				'post_id'    => $post_id,
				'index'      => 0,
				'inner_html' => '<p>x</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>injected</p>',
			)
		);

		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertSame( 1, substr_count( $content, '<!-- wp:paragraph' ), 'injection must not add a block delimiter' );
	}

	/**
	 * A non-object input to the context ability is rejected, not fatal.
	 */
	public function test_site_editor_context_rejects_scalar_input() {
		$result = wp_get_ability( 'gk-block-mcp/site-editor-context' )->execute( 'garbage' );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	// ── Adapter contract: stable name set + full-set structural invariants ─

	/**
	 * The full set of registered ability names is the adapter's public
	 * contract: each name is a fully-qualified MCP tool id baked into every
	 * client config. A silent rename or an accidental add/drop breaks existing
	 * MCP clients, so pin the complete non-Yoast set rather than spot-checking.
	 * A failure here means the change is breaking (or the expected set below
	 * must be updated deliberately, in lockstep with a client-facing note).
	 */
	public function test_ability_names_match_the_published_set() {
		$expected = array(
			'gk-block-mcp/get-page-blocks',
			'gk-block-mcp/update-block',
			'gk-block-mcp/insert-blocks',
			'gk-block-mcp/create-post',
			'gk-block-mcp/list-block-types',
			'gk-block-mcp/list-patterns',
			'gk-block-mcp/get-pattern',
			'gk-block-mcp/get-site-usage',
			'gk-block-mcp/scan-storage-modes',
			'gk-block-mcp/resolve-url',
			'gk-block-mcp/list-posts',
			'gk-block-mcp/get-post-info',
			'gk-block-mcp/get-block',
			'gk-block-mcp/delete-block',
			'gk-block-mcp/update-blocks',
			'gk-block-mcp/replace-block-range',
			'gk-block-mcp/rewrite-post-blocks',
			'gk-block-mcp/revert-to-revision',
			'gk-block-mcp/insert-pattern',
			'gk-block-mcp/edit-block-tree',
			'gk-block-mcp/update-post',
			'gk-block-mcp/list-terms',
			'gk-block-mcp/upload-media',
			'gk-block-mcp/site-editor-context',
		);

		$this->assertEqualsCanonicalizing( $expected, $this->registrar->ability_names() );
	}

	/**
	 * Every registered ability must satisfy the structural contract the MCP
	 * Adapter reads to generate a tool: it resolves to a live WP_Ability; its
	 * meta declares show_in_rest === true (so the Abilities REST/MCP surface
	 * exposes it) alongside an `annotations` array (the readonly/destructive
	 * hints); and it sits under the gk-block-mcp category the adapter scopes to.
	 * Iterating the whole set — not a fixed few — catches a malformed NEW
	 * ability before it ever reaches a client.
	 */
	public function test_every_ability_satisfies_the_adapter_structural_contract() {
		foreach ( $this->registrar->ability_names() as $name ) {
			$ability = wp_get_ability( $name );
			$this->assertInstanceOf( \WP_Ability::class, $ability, $name . ' must resolve to a live WP_Ability' );

			$meta = $ability->get_meta();
			$this->assertArrayHasKey( 'show_in_rest', $meta, $name . ' must declare show_in_rest' );
			$this->assertTrue( $meta['show_in_rest'], $name . ' must set show_in_rest === true' );
			$this->assertArrayHasKey( 'annotations', $meta, $name . ' must declare annotations' );
			$this->assertIsArray( $meta['annotations'], $name . ' annotations must be an array' );
			$this->assertSame( Block_Abilities::CATEGORY, $ability->get_category(), $name . ' must be in the gk-block-mcp category' );
		}
	}

	// ── Per-ability permission wiring ─────────────────────────────────

	/**
	 * insert-blocks wires the edit_post permission callback: a subscriber (no
	 * edit_post on the target) is denied with ability_invalid_permissions and
	 * the page gains no block. Proves the write gate is on this definition, not
	 * just on update-block (the only write previously covered for denial).
	 */
	public function test_insert_blocks_ability_denies_without_capability() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Only</p>' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/insert-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array(
					array(
						'name'      => 'core/paragraph',
						'innerHTML' => '<p>Nope</p>',
					),
				),
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertCount( 1, $this->block_tree_visible( $post_id ), 'no block may be inserted' );
	}

	/**
	 * delete-block wires the edit_post permission callback: a subscriber is
	 * denied with ability_invalid_permissions and every block survives — the
	 * denial never reaches the destructive effect.
	 */
	public function test_delete_block_ability_denies_without_capability() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>One</p>' ),
				$this->paragraph( '<p>Two</p>' ),
			)
		);
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute(
			array(
				'post_id' => $post_id,
				'index'   => 0,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( '<p>One</p>', $content, 'no block may be removed' );
		$this->assertStringContainsString( '<p>Two</p>', $content );
	}

	/**
	 * create-post wires can_create → edit_posts: a subscriber (who lacks
	 * edit_posts) is denied with ability_invalid_permissions, so the
	 * execute callback — and any post creation — never runs.
	 */
	public function test_create_post_ability_denies_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/create-post' )->execute( array( 'title' => 'Should Not Exist' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * get-page-blocks — read-only, but still gated: it wires the edit_post
	 * permission callback, so a subscriber is denied with
	 * ability_invalid_permissions rather than reading a post they cannot edit.
	 * Pins that even the read ability carries a permission_callback, not a
	 * public one.
	 */
	public function test_get_page_blocks_ability_denies_subscriber() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Secret</p>' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-page-blocks' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	// ── Idempotent re-registration ────────────────────────────────────

	/**
	 * Firing wp_abilities_api_init again must not fatal, must not re-register
	 * (which the core registry answers with a _doing_it_wrong the harness turns
	 * into a test failure), and must leave each ability registered exactly once
	 * as the same live instance. The registrar claims idempotency via
	 * wp_has_ability; this pins it — and it matters because the Abilities
	 * registry lazily (re-)fires this hook on access in production.
	 */
	public function test_re_firing_abilities_init_is_idempotent() {
		$before = array();
		foreach ( $this->registrar->ability_names() as $name ) {
			$before[ $name ] = wp_get_ability( $name );
		}

		do_action( 'wp_abilities_api_init' );

		foreach ( $this->registrar->ability_names() as $name ) {
			$this->assertTrue( wp_has_ability( $name ), $name . ' still registered after re-fire' );
			$this->assertSame( $before[ $name ], wp_get_ability( $name ), $name . ' must not be re-registered as a new instance' );
		}
		// 24 without Yoast (this suite), 27 when Yoast SEO is active — the
		// yoast.xml suite pins the 27 case.
		$this->assertCount( 24, $this->registrar->ability_names() );
	}

	// ── Input-schema required fields (representative sample) ───────────

	/**
	 * insert-blocks requires 'blocks': omitting it is rejected by input-schema
	 * validation with ability_invalid_input before execute_insert_blocks runs,
	 * so the callback never reads an undefined key.
	 */
	public function test_insert_blocks_rejects_missing_required_blocks() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );
		$result  = wp_get_ability( 'gk-block-mcp/insert-blocks' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * delete-block requires 'index': omitting it is rejected with
	 * ability_invalid_input before any block is removed.
	 */
	public function test_delete_block_rejects_missing_required_index() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>One</p>' ),
				$this->paragraph( '<p>Two</p>' ),
			)
		);
		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertStringContainsString( '<p>One</p>', (string) get_post_field( 'post_content', $post_id ), 'no block may be removed' );
	}

	/**
	 * create-post requires 'title': omitting the field entirely is rejected by
	 * input-schema validation with ability_invalid_input — distinct from an
	 * empty-string title, which passes the schema and reaches the engine's
	 * missing_title (see test_create_post_empty_title_is_graceful). Pins that
	 * the schema's required marker is enforced before execution.
	 */
	public function test_create_post_rejects_missing_required_title() {
		$result = wp_get_ability( 'gk-block-mcp/create-post' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	// ── Execution coverage for the write / destructive / admin abilities ──
	// Each of these delegates through call_rest_handler to the same controller
	// the REST route uses. Registration and structural contracts are covered
	// above; these prove the delegate actually runs and produces the effect.

	/**
	 * update-blocks applies a batch edit atomically through the controller.
	 */
	public function test_update_blocks_ability_applies_batch() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>Zero</p>' ), $this->paragraph( '<p>One</p>' ) )
		);

		$result = wp_get_ability( 'gk-block-mcp/update-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'updates' => array(
					array( 'flat_index' => 0, 'innerHTML' => '<p>Edited</p>' ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( '<p>Edited</p>', $content );
		$this->assertStringNotContainsString( '<p>Zero</p>', $content );
	}

	/**
	 * replace-block-range swaps a top-level range for new blocks in one revision.
	 */
	public function test_replace_block_range_ability_swaps_range() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>One</p>' ),
				$this->paragraph( '<p>Two</p>' ),
				$this->paragraph( '<p>Three</p>' ),
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/replace-block-range' )->execute(
			array(
				'post_id' => $post_id,
				'start'   => 0,
				'count'   => 1,
				'blocks'  => array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Replaced</p>' ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( '<p>Replaced</p>', $content );
		$this->assertStringNotContainsString( '<p>One</p>', $content );
		$this->assertStringContainsString( '<p>Two</p>', $content );
	}

	/**
	 * rewrite-post-blocks replaces the entire block content of a post.
	 */
	public function test_rewrite_post_blocks_ability_replaces_all() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Old body</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array(
					array(
						'name'       => 'core/heading',
						'attributes' => array( 'level' => 2 ),
						'innerHTML'  => '<h2>Fresh</h2>',
					),
				),
			)
		);

		$this->assertNotWPError( $result );
		$content = (string) get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString( '<!-- wp:heading', $content );
		$this->assertStringNotContainsString( 'Old body', $content );
	}

	/**
	 * revert-to-revision restores a prior revision's content through the engine.
	 */
	public function test_revert_to_revision_ability_restores_content() {
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Revisioned',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:paragraph --><p>Seed</p><!-- /wp:paragraph -->',
			)
		);
		// Each wp_update_post() snapshots the content it sets as a revision, so
		// transition through Version A (captured) then Version B (current).
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>Version A</p><!-- /wp:paragraph -->',
			)
		);
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>Version B</p><!-- /wp:paragraph -->',
			)
		);
		// Pick the revision that actually holds Version A (revisions are newest
		// first, and the most recent captures Version B).
		$revision_id = 0;
		foreach ( wp_get_post_revisions( $post_id ) as $revision ) {
			if ( false !== strpos( (string) $revision->post_content, 'Version A' ) ) {
				$revision_id = (int) $revision->ID;
				break;
			}
		}
		$this->assertGreaterThan( 0, $revision_id, 'a revision holding Version A must exist' );

		$result = wp_get_ability( 'gk-block-mcp/revert-to-revision' )->execute(
			array( 'post_id' => $post_id, 'revision_id' => $revision_id )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Version A', (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * insert-pattern places a synced core/block reference and returns the synced
	 * note so the caller knows the copy tracks the source pattern.
	 */
	public function test_insert_pattern_ability_inserts_synced_reference() {
		$pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Synced Pattern',
				'post_content' => '<!-- wp:paragraph --><p>Shared</p><!-- /wp:paragraph -->',
			)
		);
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Host</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/insert-pattern' )->execute(
			array( 'post_id' => $post_id, 'pattern_id' => $pattern_id, 'synced' => true )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'wp:block', (string) get_post_field( 'post_content', $post_id ) );
		$this->assertStringContainsString( (string) $pattern_id, (string) get_post_field( 'post_content', $post_id ) );
		$this->assertStringContainsString( 'synced reference', (string) $result['note'] );
	}

	/**
	 * edit-block-tree runs a path-based mutation through the shared mutator.
	 */
	public function test_edit_block_tree_ability_mutates() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Target</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/edit-block-tree' )->execute(
			array(
				'post_id'    => $post_id,
				'op'         => 'update-attrs',
				'path'       => array( 0 ),
				'attributes' => array( 'className' => 'edited-via-ability' ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'edited-via-ability', (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * update-post updates post metadata (here, the title) through Post_Manager.
	 */
	public function test_update_post_ability_updates_title() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/update-post' )->execute(
			array( 'post_id' => $post_id, 'title' => 'Renamed via ability' )
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Renamed via ability', get_post_field( 'post_title', $post_id ) );
	}

	/**
	 * upload-media sideloads a base64 payload through Media_Manager and returns
	 * a real attachment. Exercises the ability's only non-URL fetch path and the
	 * uploads-enabled gate (Media_Manager rejects when the option is off).
	 */
	public function test_upload_media_ability_creates_attachment() {
		update_option( Media_Manager::UPLOADS_OPTION, '1' );

		$result = wp_get_ability( 'gk-block-mcp/upload-media' )->execute(
			array(
				'data_base64' => $this->tiny_png_base64(),
				'filename'    => 'pixel.png',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'id', $result );
		$this->assertTrue( wp_attachment_is_image( (int) $result['id'] ), 'a real image attachment must be created' );
	}

	/**
	 * scan-storage-modes runs the admin-only inventory scan and returns a result.
	 * Requires manage_options, so it runs as an administrator.
	 */
	public function test_scan_storage_modes_ability_runs_as_admin() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/scan-storage-modes' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	// ── Execution coverage for the new read abilities ─────────────────────

	/**
	 * get-block returns the canonical saved snapshot for a single block.
	 */
	public function test_get_block_ability_returns_snapshot() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Only</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-block' )->execute(
			array( 'post_id' => $post_id, 'flat_index' => 0 )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'paragraph', wp_json_encode( $result ) );
	}

	/**
	 * get-post-info returns metadata for the addressed post.
	 */
	public function test_get_post_info_ability_returns_metadata() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Info Target', 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'gk-block-mcp/get-post-info' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Info Target', wp_json_encode( $result ) );
	}

	/**
	 * list-posts finds a post by search term.
	 */
	public function test_list_posts_ability_finds_post() {
		$post_id = self::factory()->post->create( array( 'post_title' => 'Findable Marker', 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'gk-block-mcp/list-posts' )->execute( array( 'search' => 'Findable Marker' ) );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( (string) $post_id, wp_json_encode( $result ) );
	}

	/**
	 * resolve-url maps a permalink back to its post id.
	 */
	public function test_resolve_url_ability_resolves_permalink() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'gk-block-mcp/resolve-url' )->execute(
			array( 'url' => get_permalink( $post_id ) )
		);

		$this->assertNotWPError( $result );
		$this->assertContains( (string) $post_id, array_map( 'strval', array_values( (array) $result ) ) );
	}

	/**
	 * get-pattern returns a registered/synced pattern by id.
	 */
	public function test_get_pattern_ability_returns_pattern() {
		$pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Fetchable Pattern',
				'post_content' => '<!-- wp:paragraph --><p>Body</p><!-- /wp:paragraph -->',
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/get-pattern' )->execute( array( 'pattern_id' => $pattern_id ) );

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( 'Fetchable Pattern', wp_json_encode( $result ) );
	}

	/**
	 * get-site-usage returns the site-wide block usage structure.
	 */
	public function test_get_site_usage_ability_returns_usage() {
		$this->make_block_post( array( $this->paragraph( '<p>counted</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-site-usage' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result );
	}

	// ── Permission-denial coverage for the new gates ──────────────────────

	/**
	 * Every new post-targeted write ability wires the edit_post gate: a
	 * subscriber (no edit_post) is denied with ability_invalid_permissions and
	 * the write never runs. Covers the write surface that previously had zero
	 * denial coverage. Input is schema-valid so the permission check — not
	 * input validation — is what rejects.
	 */
	public function test_new_write_abilities_deny_subscriber() {
		$post_id    = $this->make_block_post( array( $this->paragraph( '<p>Guarded</p>' ) ) );
		$pattern_id = self::factory()->post->create(
			array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_content' => '<!-- wp:paragraph --><p>p</p><!-- /wp:paragraph -->' )
		);
		$before = (string) get_post_field( 'post_content', $post_id );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$cases = array(
			'update-blocks'       => array( 'post_id' => $post_id, 'updates' => array( array( 'flat_index' => 0, 'innerHTML' => '<p>x</p>' ) ) ),
			'replace-block-range' => array( 'post_id' => $post_id, 'start' => 0, 'count' => 1, 'blocks' => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>x</p>' ) ) ),
			'rewrite-post-blocks' => array( 'post_id' => $post_id, 'blocks' => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>x</p>' ) ) ),
			'revert-to-revision'  => array( 'post_id' => $post_id, 'revision_id' => 999999 ),
			'edit-block-tree'     => array( 'post_id' => $post_id, 'op' => 'update-attrs', 'path' => array( 0 ), 'attributes' => array( 'className' => 'x' ) ),
			'update-post'         => array( 'post_id' => $post_id, 'title' => 'Nope' ),
			'insert-pattern'      => array( 'post_id' => $post_id, 'pattern_id' => $pattern_id ),
		);

		foreach ( $cases as $slug => $input ) {
			$result = wp_get_ability( 'gk-block-mcp/' . $slug )->execute( $input );
			$this->assertWPError( $result, "$slug must deny a subscriber" );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code(), "$slug denial code" );
		}

		$this->assertSame( $before, (string) get_post_field( 'post_content', $post_id ), 'no denied write may alter the post' );
	}

	/**
	 * upload-media wires can_upload_files: a subscriber (no upload_files) is
	 * denied and no attachment is created, even with uploads enabled. This is the
	 * only ability introducing that gate, and it guards the remote-fetch path.
	 */
	public function test_upload_media_denies_without_upload_files() {
		update_option( Media_Manager::UPLOADS_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/upload-media' )->execute(
			array( 'data_base64' => $this->tiny_png_base64(), 'filename' => 'pixel.png' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * scan-storage-modes wires can_manage_options: an editor (who has edit_posts
	 * but not manage_options) is denied. Pins that the only admin-gated ability
	 * cannot be triggered by a mere content editor.
	 */
	public function test_scan_storage_modes_denies_non_admin() {
		// set_up() already logged in an editor — an editor lacks manage_options.
		$result = wp_get_ability( 'gk-block-mcp/scan-storage-modes' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * yoast-bulk-update-seo enforces per-post edit_post inside the delegated
	 * bridge, not just the coarse edit_posts entry gate. A user with edit_posts
	 * but no edit_post on the target (an author editing someone else's post) is
	 * skipped with a per-entry "Permission denied", and the field is not written.
	 * This is why the coarse can_create gate is not a weakness (see the executor).
	 *
	 * @group yoast
	 */
	public function test_yoast_bulk_skips_posts_without_per_post_edit() {
		$this->setExpectedIncorrectUsage( 'WP_Ability_Categories_Registry::register' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		$owner   = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $owner ) );

		// An author has edit_posts (passes the coarse gate) but cannot edit
		// another user's post (fails the per-post edit_post check).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/yoast-bulk-update-seo' )->execute(
			array( 'posts' => array( array( 'post_id' => $post_id, 'title' => 'Hijacked SEO title' ) ) )
		);

		$this->assertNotWPError( $result, 'bulk itself is authorized; the per-post entry is what is denied' );
		$encoded = wp_json_encode( $result );
		$this->assertStringContainsString( 'Permission denied', $encoded );
		$this->assertStringNotContainsString( 'Hijacked SEO title', (string) get_post_meta( $post_id, '_yoast_wpseo_title', true ) );
	}

	/**
	 * yoast-update-seo writes a single post's SEO field through the active bridge.
	 *
	 * @group yoast
	 */
	public function test_yoast_update_seo_ability_writes_field() {
		$this->setExpectedIncorrectUsage( 'WP_Ability_Categories_Registry::register' );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::register' );

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$result = wp_get_ability( 'gk-block-mcp/yoast-update-seo' )->execute(
			array( 'post_id' => $post_id, 'title' => 'SEO Title Set' )
		);

		$this->assertNotWPError( $result );
		$read = wp_get_ability( 'gk-block-mcp/yoast-get-seo' )->execute( array( 'post_id' => $post_id ) );
		$this->assertNotWPError( $read );
		$this->assertStringContainsString( 'SEO Title Set', wp_json_encode( $read ) );
	}

	/**
	 * A 1x1 transparent PNG as base64, for exercising the media sideload path
	 * without a network fetch.
	 */
	private function tiny_png_base64(): string {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
	}

	/**
	 * Build a flat core/paragraph block in WP-internal shape.
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
