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
	 * A freeform whitespace block — the inter-block newlines the editor stores
	 * between top-level blocks. `flatten_blocks()`, `resolve_ref_to_top_level()`
	 * and `delete_blocks()` all skip these (empty blockName), so a fixture must
	 * include them to mirror real editor content rather than the tighter output
	 * of serialize_blocks() over a clean array.
	 *
	 * @return array
	 */
	private function whitespace(): array {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerHTML'    => "\n\n",
			'innerContent' => array( "\n\n" ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Dispatch DELETE /posts/{id}/blocks/by-ref/{ref} through the real handler.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ref     Block ref.
	 * @param int    $count   Consecutive top-level blocks to remove from the ref.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function delete_by_ref( int $post_id, string $ref, int $count = 1 ) {
		$request = new \WP_REST_Request( 'DELETE', '/gk-block-api/v1/posts/' . $post_id . '/blocks/by-ref/' . $ref );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'ref', $ref );
		$request->set_param( 'count', $count );
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
	 *
	 * The fixture carries a top-level BYSTANDER so the danger is concrete: the
	 * nested ref's flat index (1) addresses BYSTANDER as a top-level counter, so
	 * an unguarded delete would remove it. The guard must leave it intact.
	 */
	public function test_delete_by_ref_nested_block_is_rejected_without_collateral() {
		$post_id = $this->make_block_post(
			array(
				$this->block(
					'core/group',
					array(),
					'<div>',
					array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>CHILD</p>' ), 'blk_c1' ) )
				),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>BYSTANDER</p>' ), 'blk_c2' ),
			)
		);

		$response = $this->delete_by_ref( $post_id, 'blk_c1' );

		$this->assertWPError( $response );
		$this->assertSame( 'ref_not_top_level', $response->get_error_code() );
		$content = $this->content( $post_id );
		$this->assertStringContainsString( 'CHILD', $content, 'Nested block must remain.' );
		$this->assertStringContainsString( 'BYSTANDER', $content, 'A top-level sibling must NOT be collateral.' );
	}

	/**
	 * The fix holds for real editor content, where `\n\n` freeform blocks sit
	 * between every top-level block. Those are skipped by both the resolver and
	 * the deleter, so the ref must still map to the correct top-level block.
	 *
	 * Named flat indices:  group=0, child=1, TARGET=2, VICTIM=3 (freeform skipped).
	 * Top-level counters:  group=0,          TARGET=1, VICTIM=2.
	 * This mirrors the production post that surfaced the bug (nested blocks plus
	 * editor whitespace), which the whitespace-free fixtures above do not.
	 */
	public function test_delete_by_ref_handles_interleaved_whitespace_blocks() {
		$post_id = $this->make_block_post(
			array(
				$this->block(
					'core/group',
					array(),
					'<div>',
					array( $this->block( 'core/paragraph', array(), '<p>CHILD</p>' ) )
				),
				$this->whitespace(),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>TARGET</p>' ), 'blk_b1' ),
				$this->whitespace(),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>VICTIM</p>' ), 'blk_b2' ),
			)
		);

		$response = $this->delete_by_ref( $post_id, 'blk_b1' );

		$this->assertNotWPError( $response );
		$content = $this->content( $post_id );
		$this->assertStringNotContainsString( 'TARGET', $content, 'The ref target must be deleted.' );
		$this->assertStringContainsString( 'VICTIM', $content, 'A different top-level block must NOT be deleted.' );
		$this->assertStringContainsString( 'CHILD', $content, 'Unrelated nested content must survive.' );
	}

	/**
	 * A by-ref delete with count > 1 removes that many consecutive top-level
	 * blocks starting AT the ref — so the start index must be the ref's
	 * top-level counter, not its flat index, or the wrong run is removed.
	 *
	 * Top-level counters: group=0, TARGET=1, NEXT=2, KEEP=3.
	 * Deleting count=2 from TARGET removes TARGET + NEXT and leaves KEEP.
	 */
	public function test_delete_by_ref_count_removes_consecutive_top_level_blocks() {
		$post_id = $this->make_block_post(
			array(
				$this->block(
					'core/group',
					array(),
					'<div>',
					array( $this->block( 'core/paragraph', array(), '<p>CHILD</p>' ) )
				),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>TARGET</p>' ), 'blk_d1' ),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>NEXT</p>' ), 'blk_d2' ),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_d3' ),
			)
		);

		$response = $this->delete_by_ref( $post_id, 'blk_d1', 2 );

		$this->assertNotWPError( $response );
		$content = $this->content( $post_id );
		$this->assertStringNotContainsString( 'TARGET', $content, 'TARGET (the ref) must be deleted.' );
		$this->assertStringNotContainsString( 'NEXT', $content, 'NEXT (consecutive) must be deleted.' );
		$this->assertStringContainsString( 'KEEP', $content, 'KEEP is past the count and must survive.' );
		$this->assertStringContainsString( 'CHILD', $content, 'Unrelated nested content must survive.' );
	}
}
