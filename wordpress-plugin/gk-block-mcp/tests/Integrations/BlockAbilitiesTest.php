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
use GravityKit\BlockMCP\Post_Manager;
use GravityKit\BlockMCP\Block_Registry;
use GravityKit\BlockMCP\Preferences;
use GravityKit\BlockMCP\Block_Inventory;

class BlockAbilitiesTest extends BlockApiTestCase {

	/** @var Block_Abilities|null */
	private $registrar = null;

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wp_register_ability' ) ) {
			$this->markTestSkipped( 'Abilities API unavailable (requires WordPress 6.9+).' );
		}

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->registrar = new Block_Abilities(
			$this->crud,
			new Post_Manager( $this->crud ),
			new Block_Registry( new Preferences(), new Block_Inventory() )
		);

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
