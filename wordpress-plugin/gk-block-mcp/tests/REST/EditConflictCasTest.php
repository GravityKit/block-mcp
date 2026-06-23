<?php
/**
 * Regression — block writes fail CLOSED with a 409 edit_conflict when the
 * post content changed since the writer read it, rather than silently
 * clobbering the concurrent edit.
 *
 * The merged cache-freshen makes a write read the current DB state, which
 * defeats object-cache staleness on a single primary. It does NOT serialize
 * two genuinely concurrent writers, nor protect a read served by a lagging
 * replica: in both cases the writer's in-memory tree is based on a snapshot
 * the database has already moved past, and an unconditional wp_update_post
 * would overwrite the change it never saw.
 *
 * The fix threads the exact pre-mutation post_content snapshot the writer
 * parsed into save_post_content(), which runs a portable compare-and-swap
 * (UPDATE ... WHERE ID = %d AND post_content = %s) as a write — so it is
 * routed to the primary and reports a truthful affected-rows. 0 rows means
 * the snapshot is stale: return edit_conflict (409) and never call
 * wp_update_post (so revisions + save_post hooks stay intact). This pins that
 * a stale-based save neither applies nor resurrects a concurrently-removed
 * block.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

class EditConflictCasTest extends RestControllerTestCase {

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
	 * Commit different post_content straight to the DB — a concurrent writer
	 * that already won, whose change the current writer's snapshot predates.
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
	 * Current serialized post_content read straight from the database.
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
	 * A save whose snapshot predates a concurrent commit must return 409
	 * edit_conflict and leave the database untouched — the writer's edit is
	 * not applied and the concurrently-deleted block is not resurrected.
	 */
	public function test_save_blocks_fails_closed_when_content_changed_since_snapshot() {
		$post_id = $this->make_block_post(
			array(
				$this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_keep' ),
				$this->block( 'core/paragraph', array(), '<p>GHOST</p>' ),
			)
		);

		// The exact bytes the writer parsed (raw stored column value).
		$snapshot = $this->persisted_content( $post_id );

		// A concurrent writer deleted GHOST and committed AFTER that snapshot.
		$this->commit_behind_cache(
			$post_id,
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP</p>' ), 'blk_keep' ) )
		);

		// The current writer tries to persist its stale-based mutation.
		$new_blocks = array(
			$this->with_ref( $this->block( 'core/paragraph', array(), '<p>KEEP-EDITED</p>' ), 'blk_keep' ),
			$this->block( 'core/paragraph', array(), '<p>GHOST</p>' ),
		);

		$result = $this->crud->save_blocks( $post_id, $new_blocks, $snapshot );

		$this->assertWPError( $result );
		$this->assertSame( 'edit_conflict', $result->get_error_code() );
		$content = $this->persisted_content( $post_id );
		$this->assertStringNotContainsString( 'KEEP-EDITED', $content, 'A stale-based write must not apply.' );
		$this->assertStringContainsString( 'KEEP', $content, 'The concurrent winner must survive.' );
		$this->assertStringNotContainsString( 'GHOST', $content, 'A concurrently-deleted block must not be resurrected.' );
	}

	/**
	 * The lazy gk_ref persist writes straight to post_content via $wpdb,
	 * bypassing wp_update_post. With a snapshot it must compare-and-swap: a
	 * concurrent content edit that landed after the ref walk read the post
	 * must not be whole-column-overwritten by the ref-only tree.
	 */
	public function test_persist_ref_assignments_does_not_clobber_concurrent_edit() {
		$post_id = $this->make_block_post(
			array( $this->block( 'core/paragraph', array(), '<p>NEEDS-REF</p>' ) )
		);

		// The content the ref walk parsed.
		$snapshot = $this->persisted_content( $post_id );

		// A concurrent writer changed the content after that snapshot.
		$this->commit_behind_cache(
			$post_id,
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>CONCURRENT-EDIT</p>' ), 'blk_live' ) )
		);

		// The ref walk now tries to persist refs onto its stale tree.
		$ref_tree = array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>NEEDS-REF</p>' ), 'blk_assigned' ) );

		$result = $this->crud->persist_ref_assignments( $post_id, $ref_tree, $snapshot );

		$this->assertFalse( $result, 'A stale ref-persist must report no-op.' );
		$content = $this->persisted_content( $post_id );
		$this->assertStringContainsString( 'CONCURRENT-EDIT', $content, 'The concurrent edit must survive.' );
		$this->assertStringNotContainsString( 'blk_assigned', $content, 'Stale refs must not be persisted over a changed post.' );
	}

	/**
	 * A non-conflicting write must still run through wp_update_post, so
	 * save_post fires and a revision is created. This pins the hard
	 * constraint that the CAS is a precondition, not a raw-UPDATE replacement:
	 * a raw UPDATE would skip every save_post hook the live site's cache
	 * purgers + integrations depend on and stop creating revisions. Teeth: a
	 * cas-as-write regression turns this red.
	 */
	public function test_non_conflicting_write_preserves_save_post_and_revisions() {
		$post_id = $this->make_block_post(
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>ORIGINAL</p>' ), 'blk_p' ) )
		);
		$revisions_before = count( wp_get_post_revisions( $post_id ) );
		$fired            = 0;
		$counter          = function ( $id ) use ( $post_id, &$fired ) {
			if ( (int) $id === (int) $post_id ) {
				++$fired;
			}
		};
		add_action( 'save_post', $counter );

		$request = new \WP_REST_Request( 'PATCH', '/gk-block-api/v1/posts/' . $post_id . '/blocks/by-ref/blk_p' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'ref', 'blk_p' );
		$request->set_param( 'innerHTML', '<p>UPDATED</p>' );
		$response = $this->controller->update_block_by_ref( $request );
		remove_action( 'save_post', $counter );

		$this->assertNotWPError( $response );
		$this->assertGreaterThanOrEqual( 1, $fired, 'save_post must fire — wp_update_post is still the writer.' );
		$this->assertGreaterThan( $revisions_before, count( wp_get_post_revisions( $post_id ) ), 'A revision must be created.' );
		$this->assertStringContainsString( 'UPDATED', $this->persisted_content( $post_id ), 'The edit must apply.' );
	}

	/**
	 * The CAS must stay portable: no MySQL-only locking/comparison SQL
	 * (FOR UPDATE / GET_LOCK / a bare BINARY operator) may reach the database.
	 * Those are silent no-ops or hard syntax errors on the SQLite harness and
	 * an untested prod-only surface; this guards against a future pessimistic
	 * layer being added ungated.
	 */
	public function test_write_path_emits_no_mysql_only_sql() {
		$post_id = $this->make_block_post(
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>X</p>' ), 'blk_q' ) )
		);

		$queries = array();
		$capture = function ( $query ) use ( &$queries ) {
			$queries[] = $query;
			return $query;
		};
		add_filter( 'query', $capture );

		$request = new \WP_REST_Request( 'PATCH', '/gk-block-api/v1/posts/' . $post_id . '/blocks/by-ref/blk_q' );
		$request->set_param( 'id', $post_id );
		$request->set_param( 'ref', 'blk_q' );
		$request->set_param( 'innerHTML', '<p>Y</p>' );
		$this->controller->update_block_by_ref( $request );
		remove_filter( 'query', $capture );

		$joined = strtoupper( implode( "\n", $queries ) );
		$this->assertStringNotContainsString( 'FOR UPDATE', $joined, 'No SELECT ... FOR UPDATE.' );
		$this->assertStringNotContainsString( 'GET_LOCK', $joined, 'No advisory GET_LOCK.' );
		$this->assertDoesNotMatchRegularExpression( '/\bBINARY\s+[`\'"a-z_]/i', implode( "\n", $queries ), 'No bare BINARY comparison operator.' );
		$this->assertNotEmpty( $queries, 'The write path issued queries (sanity).' );
	}

	/**
	 * A concurrent edit that differs from the snapshot only by letter case must
	 * be detected as a conflict, not treated as identical by the database's
	 * case-insensitive collation. "café" and "Café" compare equal under the
	 * default collation, so a collation-only WHERE would persist the stale refs
	 * over the live change; the byte-exact compare rejects it.
	 */
	public function test_persist_ref_assignments_detects_case_only_concurrent_change() {
		$post_id = $this->make_block_post(
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>café</p>' ), 'blk_p' ) )
		);

		$snapshot = $this->persisted_content( $post_id );

		// A concurrent writer changed only the letter case (café -> Café).
		$this->commit_behind_cache(
			$post_id,
			array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>Café</p>' ), 'blk_p' ) )
		);

		$ref_tree = array( $this->with_ref( $this->block( 'core/paragraph', array(), '<p>café</p>' ), 'blk_p' ) );

		$result = $this->crud->persist_ref_assignments( $post_id, $ref_tree, $snapshot );

		$this->assertFalse( $result, 'A case-only concurrent change must be detected as a conflict.' );
		$this->assertStringContainsString( 'Café', $this->persisted_content( $post_id ), 'The concurrent case change must survive.' );
	}
}
