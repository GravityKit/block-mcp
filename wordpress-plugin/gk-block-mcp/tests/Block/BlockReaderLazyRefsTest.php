<?php
/**
 * Tests for lazy ref assignment — reads must not rewrite post_content.
 *
 * Before this fix, get_blocks() called persist_ref_assignments() on every GET
 * for posts that lacked refs, generating WordPress revisions on reads.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;

class BlockReaderLazyRefsTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();

		// Post with NO pre-existing refs — triggers the old assign-and-persist path.
		$this->post_id = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerHTML'    => '<p>Lazy</p>',
					'innerContent' => array( '<p>Lazy</p>' ),
					'innerBlocks'  => array(),
				),
				array(
					'blockName'    => 'core/heading',
					'attrs'        => array( 'level' => 2 ),
					'innerHTML'    => '<h2>Heading</h2>',
					'innerContent' => array( '<h2>Heading</h2>' ),
					'innerBlocks'  => array(),
				),
			)
		);
	}

	// ── helpers ──────────────────────────────────────────────────────────

	private function revision_count(): int {
		$revisions = wp_get_post_revisions( $this->post_id );
		return is_array( $revisions ) ? count( $revisions ) : 0;
	}

	// ── test_get_blocks_does_not_modify_post_content ─────────────────────

	/**
	 * A GET (get_blocks with persist_refs=false) must not touch post_content.
	 *
	 * This is the primary contract: the read path must never trigger a DB write.
	 */
	public function test_get_blocks_does_not_modify_post_content() {
		$content_before  = (string) get_post_field( 'post_content', $this->post_id );
		$revisions_before = $this->revision_count();

		// Read with persist_refs=false — must not write.
		$blocks = $this->crud->get_blocks( $this->post_id, false, false );

		$this->assertNotInstanceOf( \WP_Error::class, $blocks );
		$this->assertNotEmpty( $blocks );

		$content_after   = (string) get_post_field( 'post_content', $this->post_id );
		$revisions_after  = $this->revision_count();

		$this->assertSame(
			$content_before,
			$content_after,
			'get_blocks(persist_refs=false) must not modify post_content.'
		);
		$this->assertSame(
			$revisions_before,
			$revisions_after,
			'get_blocks(persist_refs=false) must not create new revisions.'
		);
	}

	// ── test_get_blocks_returns_consistent_refs_within_request ───────────

	/**
	 * Two get_blocks() calls in the same request must return the same refs.
	 *
	 * This covers the case where refs are generated in-memory but not persisted:
	 * the second call must see the same ephemeral refs, not new random ones.
	 */
	public function test_get_blocks_returns_consistent_refs_within_request() {
		$first  = $this->crud->get_blocks( $this->post_id, false, false );
		$second = $this->crud->get_blocks( $this->post_id, false, false );

		$this->assertNotInstanceOf( \WP_Error::class, $first );
		$this->assertNotInstanceOf( \WP_Error::class, $second );
		$this->assertNotEmpty( $first );
		$this->assertNotEmpty( $second );

		// Both calls must return refs (in-memory assignment is deterministic).
		$this->assertNotEmpty( $first[0]['ref'] ?? '', 'First call must surface a ref.' );
		$this->assertNotEmpty( $second[0]['ref'] ?? '', 'Second call must surface a ref.' );

		$this->assertSame(
			$first[0]['ref'] ?? null,
			$second[0]['ref'] ?? null,
			'Refs from two consecutive reads must be identical.'
		);
	}

	// ── test_write_persists_refs_to_post_content ─────────────────────────

	/**
	 * After an update_block() write, the written block's gk_ref must be baked
	 * into the raw post_content (the block comment delimiter attrs).
	 */
	public function test_write_persists_refs_to_post_content() {
		// First read to surface refs (persist_refs=true default — lets the
		// lock-based path assign & persist).
		$blocks = $this->crud->get_blocks( $this->post_id );
		$this->assertNotEmpty( $blocks );

		// Now write an update.
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'className' => 'updated' ),
			null
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Re-parse raw content from the DB.
		$raw     = (string) get_post_field( 'post_content', $this->post_id );
		$parsed  = parse_blocks( $raw );
		$visible = array_values( array_filter( $parsed, static fn( $b ) => ! empty( $b['blockName'] ) ) );

		$this->assertNotEmpty( $visible );
		$persisted_ref = $visible[0]['attrs']['metadata']['gk_ref'] ?? null;

		$this->assertNotEmpty( $persisted_ref, 'Written block must have gk_ref in saved post_content.' );
		$this->assertStringStartsWith( 'blk_', $persisted_ref );
	}

	// ── test_concurrent_reads_do_not_race_on_persist ─────────────────────

	/**
	 * Two simulated concurrent readers must not both trigger persist_ref_assignments.
	 * The lock-holder writes once; the other reader defers. No duplicate writes.
	 */
	public function test_concurrent_reads_do_not_race_on_persist() {
		// Ensure no stale lock.
		$lock_key = 'gk_block_api_ref_lock_' . $this->post_id;
		wp_cache_delete( $lock_key, 'gk_block_api' );

		$content_before = (string) get_post_field( 'post_content', $this->post_id );

		// Simulate "reader 1" holding the lock.
		wp_cache_add( $lock_key, 1, 'gk_block_api', 5 );

		// "Reader 2" arrives — lock already held.
		$blocks = $this->crud->get_blocks( $this->post_id );

		// post_content must be unchanged because reader 2 deferred.
		$content_after = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertSame(
			$content_before,
			$content_after,
			'When the lock is held by another reader, post_content must not be written.'
		);

		// Blocks are still returned (response is valid for read purposes).
		$this->assertNotEmpty( $blocks );

		// Cleanup.
		wp_cache_delete( $lock_key, 'gk_block_api' );
	}
}
