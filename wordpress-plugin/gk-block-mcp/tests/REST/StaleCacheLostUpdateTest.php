<?php
/**
 * Regression — block writes operate on current DB content, not a stale post
 * object cache, so they never resurrect a concurrently-deleted block.
 *
 * Every write handler reads the post through `get_post()`, which is served
 * from the object cache. When a prior request (or another worker) has already
 * changed `post_content` but the cache for this request still holds the old
 * post, the read-modify-write serializes the STALE tree back to the database —
 * silently undoing the concurrent change (a lost update). In production this
 * surfaced as a delete that "came back" after a following edit.
 *
 * The fix freshens the post cache at the single write chokepoint
 * (`with_post_edit_context`) so the operation always reads the database's
 * current state. This suite pins that contract through the real REST handlers.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

class StaleCacheLostUpdateTest extends RestControllerTestCase {

	/** @var int Editor — holds edit_post on the fixture page. */
	private $editor_id;

	public function set_up(): void {
		parent::set_up();
		$this->editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $this->editor_id );
	}

	/**
	 * Build a leaf block array.
	 *
	 * @param string $name Block name.
	 * @param array  $attrs Attributes.
	 * @param string $html innerHTML.
	 *
	 * @return array
	 */
	private function block( string $name, array $attrs, string $html ): array {
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
	 * Overwrite post_content directly in the database WITHOUT clearing the
	 * object cache — the precondition for a stale-cache lost update. Mirrors a
	 * concurrent worker/request that already committed a change this request's
	 * cache hasn't seen.
	 *
	 * @param int     $post_id Post ID.
	 * @param array[] $blocks  WP-internal block arrays.
	 *
	 * @return void
	 */
	private function commit_behind_cache( int $post_id, array $blocks ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_content' => serialize_blocks( $blocks ) ),
			array( 'ID' => $post_id )
		);
	}

	/**
	 * Current serialized post_content read straight from the database (never
	 * the object cache), so assertions reflect what was actually persisted.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private function persisted_content( int $post_id ): string {
		global $wpdb;
		return (string) $wpdb->get_var(
			$wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post_id )
		);
	}

	/**
	 * Dispatch PATCH /posts/{id}/blocks/by-ref/{ref}.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $ref     Block ref.
	 * @param string $html    Replacement innerHTML.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function update_by_ref( int $post_id, string $ref, string $html ) {
		$request = new \WP_REST_Request( 'PATCH', '/gk-block-api/v1/posts/' . $post_id . '/blocks/by-ref/' . $ref );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'ref', $ref );
		$request->set_param( 'innerHTML', $html );
		return $this->controller->update_block_by_ref( $request );
	}

	/**
	 * Dispatch DELETE /posts/{id}/blocks/by-ref/{ref}.
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
		$request->set_param( 'count', 1 );
		return $this->controller->delete_block_by_ref( $request );
	}

	/**
	 * A by-ref update must not resurrect a block another request already
	 * deleted. The cache still holds KEEP + GHOST; the database holds only
	 * KEEP. Updating KEEP must persist [KEEP'] — never [KEEP', GHOST].
	 */
	public function test_update_by_ref_does_not_resurrect_block_deleted_behind_stale_cache() {
		$post_id = $this->make_block_post(
			array(
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_keep' ),
				$this->block( 'core/paragraph', array(), '<p>GHOST</p>' ),
			)
		);

		// Prime the cache with the original two-block tree, then commit a
		// one-block tree straight to the DB behind that cache.
		get_post( $post_id );
		$this->commit_behind_cache(
			$post_id,
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_keep' ) )
		);

		$response = $this->update_by_ref( $post_id, 'blk_keep', '<p>KEEPER</p>' );

		$this->assertNotWPError( $response );
		$content = $this->persisted_content( $post_id );
		$this->assertStringContainsString( 'KEEPER', $content, 'The targeted update must apply.' );
		$this->assertStringNotContainsString( 'GHOST', $content, 'A stale cache must not resurrect a concurrently-deleted block.' );
	}

	/**
	 * A by-ref delete must not resurrect a different block another request
	 * already deleted, and must resolve the ref against current state. The
	 * cache holds KEEP + GHOST + DROP (DROP at top-level #2); the database
	 * holds KEEP + DROP (DROP at top-level #1). Deleting DROP must persist
	 * [KEEP] — never [KEEP, GHOST], and never the wrong block from the stale
	 * top-level position.
	 */
	public function test_delete_by_ref_does_not_resurrect_block_deleted_behind_stale_cache() {
		$post_id = $this->make_block_post(
			array(
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_keep' ),
				$this->block( 'core/paragraph', array(), '<p>GHOST</p>' ),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>DROP</p>' ), 'blk_drop' ),
			)
		);

		get_post( $post_id );
		$this->commit_behind_cache(
			$post_id,
			array(
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_keep' ),
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>DROP</p>' ), 'blk_drop' ),
			)
		);

		$response = $this->delete_by_ref( $post_id, 'blk_drop' );

		$this->assertNotWPError( $response );
		$content = $this->persisted_content( $post_id );
		$this->assertStringContainsString( 'KEEP', $content, 'An untouched block must survive.' );
		$this->assertStringNotContainsString( 'DROP', $content, 'The targeted delete must apply.' );
		$this->assertStringNotContainsString( 'GHOST', $content, 'A stale cache must not resurrect a concurrently-deleted block.' );
	}
}
