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
