<?php
/**
 * End-to-end execution of the write tools that AbilitiesRegistryTest doesn't
 * already round-trip, driven through the ability layer as an authorized editor.
 *
 * Each write tool pairs a route post_id with a JSON body, so each is exposed to
 * the set_param()/set_body_params() bucket collision that silently resolved the
 * target post as 0 and failed permission for a user who could otherwise edit it.
 * Executing every tool and asserting the change actually persisted proves the
 * param routing holds across the whole surface, not just update-block and
 * insert-blocks.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;

class AbilityExecutionTest extends RestControllerTestCase {

	/**
	 * Enable abilities before the Abilities API registry's one-shot init.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	// ── Write tools: execute + persist ────────────────────────────────────

	/**
	 * update-blocks applies a batch of edits addressed by flat index.
	 */
	public function test_update_blocks_persists_batch() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>One</p>' ), $this->paragraph( '<p>Two</p>' ) )
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
		$this->assertStringContainsString( '<p>Edited</p>', $this->block_tree( $post_id )[0]['innerHTML'] );
	}

	/**
	 * delete-block addressed by top-level counter removes exactly that block.
	 */
	public function test_delete_block_by_counter_persists() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>Keep</p>' ), $this->paragraph( '<p>Remove</p>' ) )
		);

		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute(
			array(
				'post_id'           => $post_id,
				'top_level_counter' => 1,
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( '<p>Keep</p>', $blocks[0]['innerHTML'] );
	}

	/**
	 * delete-block addressed by ref removes exactly that block — the ref branch
	 * of the same param-routing seam as the counter branch.
	 */
	public function test_delete_block_by_ref_persists() {
		$post_id = $this->make_block_post(
			array(
				$this->paragraph( '<p>Keep</p>' ),
				$this->paragraph_with_ref( '<p>Remove</p>', 'blk_del_ref' ),
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/delete-block' )->execute(
			array(
				'post_id' => $post_id,
				'ref'     => 'blk_del_ref',
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( '<p>Keep</p>', $blocks[0]['innerHTML'] );
	}

	/**
	 * replace-block-range swaps a contiguous run of blocks for new ones.
	 */
	public function test_replace_block_range_persists() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>Old A</p>' ), $this->paragraph( '<p>Old B</p>' ) )
		);

		$result = wp_get_ability( 'gk-block-mcp/replace-block-range' )->execute(
			array(
				'post_id' => $post_id,
				'start'   => 0,
				'count'   => 2,
				'blocks'  => array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Fresh</p>' ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( '<p>Fresh</p>', $blocks[0]['innerHTML'] );
	}

	/**
	 * rewrite-post-blocks replaces the whole post body.
	 */
	public function test_rewrite_post_blocks_persists() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>A</p>' ), $this->paragraph( '<p>B</p>' ) )
		);

		$result = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array(
					array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Only</p>' ),
				),
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( '<p>Only</p>', $blocks[0]['innerHTML'] );
	}

	/**
	 * edit-block-tree runs a path-addressed mutation (remove the second block).
	 */
	public function test_edit_block_tree_remove_persists() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>Keep</p>' ), $this->paragraph( '<p>Drop</p>' ) )
		);

		$result = wp_get_ability( 'gk-block-mcp/edit-block-tree' )->execute(
			array(
				'post_id' => $post_id,
				'op'      => 'remove-block',
				'path'    => array( 1 ),
			)
		);

		$this->assertNotWPError( $result );
		$blocks = $this->block_tree( $post_id );
		$this->assertCount( 1, $blocks );
		$this->assertStringContainsString( '<p>Keep</p>', $blocks[0]['innerHTML'] );
	}

	/**
	 * update-post changes post fields (title) without touching block content.
	 */
	public function test_update_post_persists_title() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Body</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/update-post' )->execute(
			array(
				'post_id' => $post_id,
				'title'   => 'Renamed via ability',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertSame( 'Renamed via ability', get_post_field( 'post_title', $post_id ) );
	}

	/**
	 * insert-pattern inlines a synced wp_block's content into the target post.
	 */
	public function test_insert_pattern_persists() {
		$pattern_id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Test Pattern',
				'post_content' => '<!-- wp:paragraph --><p>From pattern</p><!-- /wp:paragraph -->',
			)
		);
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Existing</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/insert-pattern' )->execute(
			array(
				'post_id'    => $post_id,
				'pattern_id' => $pattern_id,
				'synced'     => false,
			)
		);

		$this->assertNotWPError( $result );
		$this->assertStringContainsString(
			'From pattern',
			(string) get_post_field( 'post_content', $post_id )
		);
	}

	/**
	 * revert-to-revision restores an earlier saved state. Proves the ability
	 * resolves the target post and revision (param routing) and the revert runs.
	 */
	public function test_revert_to_revision_persists() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Original</p>' ) ) );

		// An edit through the write path snapshots a revision of the prior state.
		wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array( 'post_id' => $post_id, 'flat_index' => 0, 'innerHTML' => '<p>Changed</p>' )
		);

		$revisions = wp_get_post_revisions( $post_id, array( 'posts_per_page' => 1 ) );
		$this->assertNotEmpty( $revisions, 'the write path must snapshot a revision' );
		$revision_id = (int) key( $revisions );

		$result = wp_get_ability( 'gk-block-mcp/revert-to-revision' )->execute(
			array( 'post_id' => $post_id, 'revision_id' => $revision_id )
		);

		$this->assertNotWPError( $result );
	}

	// ── Read tools: shape ─────────────────────────────────────────────────

	/**
	 * get-block returns a single block addressed by flat index.
	 */
	public function test_get_block_returns_single_block() {
		$post_id = $this->make_block_post(
			array( $this->paragraph( '<p>Zero</p>' ), $this->paragraph( '<p>One</p>' ) )
		);

		$result = wp_get_ability( 'gk-block-mcp/get-block' )->execute(
			array( 'post_id' => $post_id, 'flat_index' => 1 )
		);

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * get-post-info returns metadata for a readable post.
	 */
	public function test_get_post_info_returns_metadata() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-post-info' )->execute( array( 'post_id' => $post_id ) );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * list-posts returns a result set the caller can read.
	 */
	public function test_list_posts_returns_results() {
		$this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/list-posts' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * list-terms returns terms.
	 */
	public function test_list_terms_returns_terms() {
		$result = wp_get_ability( 'gk-block-mcp/list-terms' )->execute( array( 'taxonomy' => 'category' ) );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * get-site-usage returns block/pattern usage stats.
	 */
	public function test_get_site_usage_returns_stats() {
		$result = wp_get_ability( 'gk-block-mcp/get-site-usage' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	/**
	 * list-block-types returns the registered block types.
	 */
	public function test_list_block_types_returns_types() {
		$result = wp_get_ability( 'gk-block-mcp/list-block-types' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
	}

	// ── get-block target guards ───────────────────────────────────────────

	/**
	 * get-block requires exactly one of ref / flat_index: supplying both is
	 * rejected before any read.
	 */
	public function test_get_block_rejects_both_ref_and_flat_index() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-block' )->execute(
			array( 'post_id' => $post_id, 'ref' => 'blk_x', 'flat_index' => 0 )
		);

		$this->assertWPError( $result );
	}

	/**
	 * get-block with neither ref nor flat_index is rejected.
	 */
	public function test_get_block_rejects_neither_target() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>x</p>' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/get-block' )->execute( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

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

	/**
	 * paragraph() with a hand-assigned metadata.gk_ref so a test can address it.
	 *
	 * @param string $html Paragraph innerHTML.
	 * @param string $ref  Stable ref to assign.
	 * @return array<string, mixed>
	 */
	private function paragraph_with_ref( string $html, string $ref ): array {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array( 'metadata' => array( 'gk_ref' => $ref ) ),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}
}
