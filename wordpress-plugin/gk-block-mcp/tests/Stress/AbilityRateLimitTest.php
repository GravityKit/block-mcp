<?php
/**
 * Per-post write throttling enforced through the ability layer.
 *
 * RateLimitBurstTest exercises the limiter at the engine (Block_CRUD) level;
 * this drives it through wp_get_ability()->execute() to prove the budget still
 * holds on the MCP-facing path. The SQLite harness is single-connection, so the
 * time-based sliding window is exercised sequentially (no real parallelism).
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Block_CRUD;

class AbilityRateLimitTest extends RestControllerTestCase {

	/**
	 * Enable abilities before the one-shot init; act as an editor.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * The per-post write budget (RATE_LIMIT_WRITES/min) is enforced through the
	 * update-block ability: the first N succeed, the next is rate-limited.
	 */
	public function test_write_budget_enforced_through_ability() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Start</p>' ) ) );

		for ( $i = 0; $i < Block_CRUD::RATE_LIMIT_WRITES; $i++ ) {
			$result = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
				array( 'post_id' => $post_id, 'flat_index' => 0, 'innerHTML' => '<p>edit ' . $i . '</p>' )
			);
			$this->assertNotWPError( $result, 'write ' . $i . ' within the budget must succeed' );
		}

		$over = wp_get_ability( 'gk-block-mcp/update-block' )->execute(
			array( 'post_id' => $post_id, 'flat_index' => 0, 'innerHTML' => '<p>too many</p>' )
		);
		$this->assertWPError( $over, 'the write past the budget must be rate-limited' );
	}

	/**
	 * The stricter full-rewrite budget (RATE_LIMIT_PUT/min) is enforced through
	 * the rewrite-post-blocks ability.
	 */
	public function test_put_budget_enforced_through_ability() {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Start</p>' ) ) );

		for ( $i = 0; $i < Block_CRUD::RATE_LIMIT_PUT; $i++ ) {
			$result = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
				array(
					'post_id' => $post_id,
					'blocks'  => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>rewrite ' . $i . '</p>' ) ),
				)
			);
			$this->assertNotWPError( $result, 'rewrite ' . $i . ' within the budget must succeed' );
		}

		$over = wp_get_ability( 'gk-block-mcp/rewrite-post-blocks' )->execute(
			array(
				'post_id' => $post_id,
				'blocks'  => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>too many</p>' ) ),
			)
		);
		$this->assertWPError( $over, 'the rewrite past the budget must be rate-limited' );
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
