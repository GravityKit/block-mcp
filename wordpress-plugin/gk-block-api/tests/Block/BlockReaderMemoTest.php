<?php
/**
 * Tests for Block_Reader per-request parse_blocks() memoization.
 *
 * Covers:
 *   - parse() returns memoized result on second call
 *   - invalidate() clears the cache for a specific post
 *   - A write operation invalidates the cache (end-to-end)
 *   - Cache does not leak between posts
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Reader;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Preferences;

class BlockReaderMemoTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	/** @var Block_Reader */
	private $reader;

	public function set_up(): void {
		parent::set_up();

		$this->post_id = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array( 'metadata' => array( 'gk_ref' => 'blk_memo1' ) ),
					'innerHTML'    => '<p>Hello</p>',
					'innerContent' => array( '<p>Hello</p>' ),
					'innerBlocks'  => array(),
				),
			)
		);

		// Access the reader via reflection — it's a private property on Block_CRUD.
		$reflection  = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$reflection->setAccessible( true );
		$this->reader = $reflection->getValue( $this->crud );
	}

	// ── test_parse_is_memoized_within_same_instance ─────────────────────

	/**
	 * Calling parse() twice on the same post should return the same array
	 * reference (same data, cache populated).
	 */
	public function test_parse_is_memoized_within_same_instance() {
		$first  = $this->reader->parse( $this->post_id );
		$second = $this->reader->parse( $this->post_id );

		// Both calls return arrays.
		$this->assertIsArray( $first );
		$this->assertIsArray( $second );

		// The cache key should be present — confirm by checking that the
		// second result is identical in structure to the first.
		$this->assertSame( $first, $second, 'Second parse() call must return the memoized value.' );

		// Verify the cache is populated by inspecting the private $parse_cache.
		$cache_prop = new ReflectionProperty( Block_Reader::class, 'parse_cache' );
		$cache_prop->setAccessible( true );
		$cache = $cache_prop->getValue( $this->reader );

		$this->assertNotEmpty( $cache, 'parse_cache must be non-empty after two calls.' );
	}

	// ── test_invalidate_clears_cache_for_post ────────────────────────────

	public function test_invalidate_clears_cache_for_post() {
		// Populate the cache.
		$this->reader->parse( $this->post_id );

		$cache_prop = new ReflectionProperty( Block_Reader::class, 'parse_cache' );
		$cache_prop->setAccessible( true );

		// Confirm it's cached.
		$before = $cache_prop->getValue( $this->reader );
		$this->assertNotEmpty( $before );

		// Invalidate.
		$this->reader->invalidate( $this->post_id );

		$after = $cache_prop->getValue( $this->reader );

		// The cache for this post_id should be cleared.
		// The cache is keyed by "{post_id}:{md5(content)}", so after invalidate
		// there should be no entries with this post_id prefix.
		$post_prefix = (string) $this->post_id . ':';
		foreach ( array_keys( $after ) as $key ) {
			$this->assertStringNotContainsString(
				$post_prefix,
				$key,
				"Cache entry '$key' for post {$this->post_id} should have been cleared."
			);
		}
	}

	// ── test_write_invalidates_cache ─────────────────────────────────────

	/**
	 * Functional/end-to-end: read post, write to it via Block_Writer, read
	 * again. The second read must see the new content (not stale cache).
	 */
	public function test_write_invalidates_cache() {
		// First read — populates cache.
		$before = $this->crud->get_blocks( $this->post_id );
		$this->assertNotEmpty( $before );
		$first_text = $before[0]['innerHTML'] ?? '';

		// Write a different paragraph via update_block.
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array(),
			'<p>Updated</p>'
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Second read — must see the updated content.
		$after = $this->crud->get_blocks( $this->post_id );
		$this->assertNotEmpty( $after );
		$second_text = $after[0]['innerHTML'] ?? '';

		$this->assertNotSame(
			$first_text,
			$second_text,
			'get_blocks() after a write must see the new content, not stale cache.'
		);
		$this->assertStringContainsString( 'Updated', $second_text );
	}

	// ── test_cache_does_not_leak_across_posts ────────────────────────────

	public function test_cache_does_not_leak_across_posts() {
		// Create a second post with different content.
		$post_b = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/heading',
					'attrs'        => array(
						'level'    => 2,
						'metadata' => array( 'gk_ref' => 'blk_head_b' ),
					),
					'innerHTML'    => '<h2>Post B Heading</h2>',
					'innerContent' => array( '<h2>Post B Heading</h2>' ),
					'innerBlocks'  => array(),
				),
			)
		);

		// Parse post A — populates cache for A.
		$blocks_a = $this->reader->parse( $this->post_id );
		$this->assertSame( 'core/paragraph', $blocks_a[0]['blockName'] ?? '' );

		// Directly update post B's content via wpdb (bypasses any API-level
		// invalidation) so the DB content differs from what would be in a stale
		// cache if it leaked.
		global $wpdb;
		$new_content = serialize_blocks(
			array(
				array(
					'blockName'    => 'core/heading',
					'attrs'        => array(
						'level'    => 3,
						'metadata' => array( 'gk_ref' => 'blk_head_b_new' ),
					),
					'innerHTML'    => '<h3>Post B Updated</h3>',
					'innerContent' => array( '<h3>Post B Updated</h3>' ),
					'innerBlocks'  => array(),
				),
			)
		);
		$wpdb->update( $wpdb->posts, array( 'post_content' => $new_content ), array( 'ID' => $post_b ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post_b );

		// Parse post B — must return B's content, not A's.
		$blocks_b = $this->reader->parse( $post_b );

		$this->assertIsArray( $blocks_b );
		$this->assertNotEmpty( $blocks_b );
		$this->assertSame( 'core/heading', $blocks_b[0]['blockName'] ?? '' );
		$this->assertStringContainsString(
			'Post B Updated',
			$blocks_b[0]['innerHTML'] ?? ''
		);

		// And A's parse is still A (no cross-contamination).
		$blocks_a_recheck = $this->reader->parse( $this->post_id );
		$this->assertSame( 'core/paragraph', $blocks_a_recheck[0]['blockName'] ?? '' );
	}
}
