<?php
/**
 * Regression — DELETE by ref removes the block the ref points to.
 *
 * Two index spaces address blocks here: `resolve_ref_to_index()` returns a
 * FLAT index that counts nested innerBlocks, while `delete_blocks()` interprets
 * its argument as a TOP-LEVEL visible counter (it only splices the top-level
 * array). They diverge the moment a nested block precedes the target. By-ref
 * delete must therefore resolve through `resolve_ref_to_top_level()` — which
 * yields the top-level counter `delete_blocks()` expects and refuses a nested
 * ref with `ref_not_top_level` — not `resolve_ref_to_index()`, whose flat index
 * would address a different top-level block.
 *
 * This suite pins both halves: the correct top-level block is deleted, and a
 * nested ref is rejected rather than mis-deleting.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

class DeleteBlockByRefTest extends RestControllerTestCase {

	/** @var int Editor — holds edit_post on the fixture page. */
	private $editor_id;

	public function set_up(): void {
		parent::set_up();
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	// ── fixture helpers ──────────────────────────────────────────────────

	/**
	 * Build a WP-internal block array, optionally with nested children.
	 *
	 * @param string  $name     Block name.
	 * @param array   $attrs    Block attributes.
	 * @param string  $html     innerHTML (wrapper opening tag when children present).
	 * @param array[] $children Child block arrays.
	 *
	 * @return array
	 */
	private function block( string $name, array $attrs = array(), string $html = '', array $children = array() ): array {
		if ( ! empty( $children ) ) {
			$opening = '' !== $html ? $html : '<div>';
			$closing = '</div>';
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerHTML'    => $opening . $closing,
				'innerContent' => array_merge( array( $opening ), array_fill( 0, count( $children ), null ), array( $closing ) ),
				'innerBlocks'  => $children,
			);
		}
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => '' !== $html ? array( $html ) : array(),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Stamp a stable ref onto a block.
	 *
	 * @param array  $block Block array.
	 * @param string $ref   gk_ref value.
	 *
	 * @return array
	 */
	private function with_ref( array $block, string $ref ): array {
		$block['attrs']['metadata']['gk_ref'] = $ref;
		return $block;
	}

	/**
	 * Dispatch DELETE /posts/{id}/blocks/by-ref/{ref} through the real handler.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ref     Block ref.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function delete_by_ref( int $post_id, string $ref ) {
		$request = new \WP_REST_Request( 'DELETE', '/gk-block-api/v1/posts/' . $post_id . '/blocks/by-ref/' . $ref );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'ref', $ref );
		return $this->controller->delete_block_by_ref( $request );
	}

	/**
	 * Current serialized post_content.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private function content( int $post_id ): string {
		return (string) get_post_field( 'post_content', $post_id );
	}

	// ── tests ─────────────────────────────────────────────────────────────

	/**
	 * Deleting a top-level block by ref removes that block — not the top-level
	 * block whose position equals the target's flat (nested-inclusive) index.
	 *
	 * Flat indices:       group=0, child=1, TARGET=2, VICTIM=3.
	 * Top-level counters: group=0,          TARGET=1, VICTIM=2.
	 * The ref must resolve to top-level #1 (TARGET); a flat index of 2 would
	 * address VICTIM (top-level #2).
	 */
	public function test_delete_by_ref_removes_target_not_block_at_flat_index() {
		$post_id = $this->make_block_post(
			array(
				$this->block(
					'core/group',
					array(),
					'<div>',
					array( $this->block( 'core/paragraph', array(), '<p>CHILD</p>' ) )
				),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>TARGET</p>' ), 'blk_a1' ),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>VICTIM</p>' ), 'blk_a2' ),
			)
		);

		$response = $this->delete_by_ref( $post_id, 'blk_a1' );

		$this->assertNotWPError( $response );
		$content = $this->content( $post_id );
		$this->assertStringNotContainsString( 'TARGET', $content, 'The ref target must be deleted.' );
		$this->assertStringContainsString( 'VICTIM', $content, 'A different top-level block must NOT be deleted.' );
		$this->assertStringContainsString( 'CHILD', $content, 'Unrelated nested content must survive.' );
	}

	/**
	 * A by-ref delete that targets a NESTED block is refused with
	 * `ref_not_top_level` instead of silently removing a top-level block.
	 * `delete_blocks()` only operates on the top-level array, so the only safe
	 * answer for a nested ref is a clear error.
	 */
	public function test_delete_by_ref_nested_block_is_rejected() {
		$post_id = $this->make_block_post(
			array(
				$this->block(
					'core/group',
					array(),
					'<div>',
					array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>CHILD</p>' ), 'blk_c1' ) )
				),
			)
		);

		$response = $this->delete_by_ref( $post_id, 'blk_c1' );

		$this->assertWPError( $response );
		$this->assertSame( 'ref_not_top_level', $response->get_error_code() );
		$this->assertStringContainsString( 'CHILD', $this->content( $post_id ), 'Nested block must remain.' );
	}
}
