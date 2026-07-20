<?php
/**
 * Large / pathological payloads driven through the ability layer.
 *
 * The block engine is stress-tested directly via $this->crud elsewhere; this
 * runs the same shapes through wp_get_ability()->execute(), where argument
 * coercion and the JSON body round-trip add a distinct failure surface. Every
 * case must return a clean result or a clean WP_Error — never a fatal, timeout,
 * or OOM.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Block_CRUD;

class AbilityPayloadStressTest extends RestControllerTestCase {

	/**
	 * Enable abilities before the one-shot init; act as an editor.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * A moderately deep nested tree (within MAX_BLOCK_DEPTH) round-trips through
	 * rewrite-post-blocks without corrupting the innerContent null invariant.
	 */
	public function test_deep_nesting_within_limit_round_trips() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array( $this->nested_groups( 12, '<p>deep</p>' ) ),
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( '<p>deep</p>', (string) get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Nesting beyond MAX_BLOCK_DEPTH is rejected with a clean WP_Error, not a
	 * stack overflow.
	 */
	public function test_nesting_over_max_depth_is_rejected_cleanly() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array( $this->nested_groups( Block_CRUD::MAX_BLOCK_DEPTH + 10, '<p>toodeep</p>' ) ),
			)
		);

		$this->assertWPError( $result, 'over-deep nesting must be a clean error, not a fatal' );
	}

	/**
	 * Thousands of top-level blocks in one rewrite complete or fail cleanly.
	 */
	public function test_thousands_of_blocks_do_not_fatal() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$blocks = array();
		for ( $i = 0; $i < 2000; $i++ ) {
			$blocks[] = array( 'name' => 'core/paragraph', 'innerHTML' => '<p>' . $i . '</p>' );
		}

		$result = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
			array( 'post_id' => $post_id, 'blocks' => $blocks )
		);

		$this->assertTrue( is_array( $result ) || is_wp_error( $result ), 'a huge block count must not fatal' );
	}

	/**
	 * A very large innerHTML on a single block is accepted and bounded, not a
	 * memory fatal.
	 */
	public function test_large_innerhtml_does_not_fatal() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$big = '<p>' . str_repeat( 'A', 500000 ) . '</p>';
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array( 'post_id' => $post_id, 'flat_index' => 0, 'innerHTML' => $big )
		);

		$this->assertTrue( is_array( $result ) || is_wp_error( $result ), 'a 500KB innerHTML must not fatal' );
	}

	/**
	 * Pathological Unicode (combining marks, RTL override, zero-width, emoji)
	 * round-trips through update-block without corrupting the save.
	 */
	public function test_pathological_unicode_round_trips() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$html = "<p>a\u{0301}\u{202E}gnirts\u{200B}\u{1F4A9} \u{0645}\u{0631}\u{062D}\u{0628}\u{0627}</p>";
		$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array( 'post_id' => $post_id, 'flat_index' => 0, 'innerHTML' => $html )
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString( "\u{1F4A9}", (string) get_post_field( 'post_content', $post_id ) );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	/**
	 * Build a core/group nested $depth levels deep with a paragraph at the core,
	 * in the MCP {name, innerHTML, innerBlocks} shape ability payloads use.
	 *
	 * @param int    $depth Nesting depth.
	 * @param string $leaf  innerHTML of the innermost paragraph.
	 * @return array<string, mixed>
	 */
	private function nested_groups( int $depth, string $leaf ): array {
		$node = array( 'name' => 'core/paragraph', 'innerHTML' => $leaf );
		for ( $i = 0; $i < $depth; $i++ ) {
			$node = array(
				'name'        => 'core/group',
				'innerHTML'   => '<div class="wp-block-group"></div>',
				'innerBlocks' => array( $node ),
			);
		}
		return $node;
	}

	/**
	 * Build a flat core/paragraph block in WP-internal shape for make_block_post().
	 *
	 * @param string $html Paragraph innerHTML.
	 * @return array<string, mixed>
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
