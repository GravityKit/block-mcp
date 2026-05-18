<?php
/**
 * Tests for WP 6.5+ Block Bindings API support.
 *
 * Read side: blocks with attrs.metadata.bindings surface a `bindings` field
 * and a `bound_attributes` array in the formatted response.
 *
 * Write side: update_block() rejects attempts to overwrite a bound attribute
 * unless the caller passes `allow_bound_writes: true` in the update options.
 * Bindings are preserved through writes that don't touch them.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_CRUD;

class BlockBindingsTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post();
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/**
	 * Build a block array with bindings in attrs.metadata.bindings.
	 *
	 * @param string $block_name
	 * @param array  $bindings  e.g. ['url' => ['source' => 'core/post-meta', 'args' => ['key' => 'my_url']]]
	 * @param array  $extra_attrs
	 * @param string $html
	 * @param string $gk_ref
	 *
	 * @return array
	 */
	private function make_bound_block( $block_name, array $bindings, array $extra_attrs = array(), $html = '', $gk_ref = 'blk_bound1' ) {
		$attrs = array_merge(
			$extra_attrs,
			array(
				'metadata' => array(
					'gk_ref'   => $gk_ref,
					'bindings' => $bindings,
				),
			)
		);
		return array(
			'blockName'    => $block_name,
			'attrs'        => $attrs,
			'innerHTML'    => $html ?: '<p>bound content</p>',
			'innerContent' => array( $html ?: '<p>bound content</p>' ),
			'innerBlocks'  => array(),
		);
	}

	private function set_content( array $blocks ): void {
		wp_update_post( array(
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( $blocks ),
		) );
		// Invalidate parse cache via reflection.
		$prop = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$prop->setAccessible( true );
		$prop->getValue( $this->crud )->invalidate( $this->post_id );
	}

	// ── Read: bindings surfaced ───────────────────────────────────────────

	/**
	 * A block with attrs.metadata.bindings must have a top-level `bindings`
	 * field in the formatted response.
	 */
	public function test_read_surfaces_bindings_field() {
		$this->set_content( array(
			$this->make_bound_block(
				'core/image',
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_image' ) ),
					'alt' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_alt' ) ),
				),
				array( 'url' => '', 'alt' => '' ),
				'<img src="" alt=""/>'
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$this->assertNotInstanceOf( \WP_Error::class, $blocks );
		$this->assertNotEmpty( $blocks );

		$block = $blocks[0];
		$this->assertArrayHasKey( 'bindings', $block, 'Formatted block must have a top-level bindings field.' );
		$this->assertArrayHasKey( 'url', $block['bindings'] );
		$this->assertSame( 'core/post-meta', $block['bindings']['url']['source'] );
		$this->assertArrayHasKey( 'alt', $block['bindings'] );
	}

	/**
	 * A block with bindings must expose a `bound_attributes` array listing
	 * the attribute names that are dynamically bound.
	 */
	public function test_read_surfaces_bound_attributes_array() {
		$this->set_content( array(
			$this->make_bound_block(
				'core/image',
				array( 'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ) ),
				array( 'url' => '' ),
				'<img src="" alt=""/>'
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$block  = $blocks[0];

		$this->assertArrayHasKey( 'bound_attributes', $block );
		$this->assertContains( 'url', $block['bound_attributes'] );
	}

	/**
	 * A block without bindings must not have `bindings` or `bound_attributes` fields.
	 */
	public function test_read_omits_bindings_fields_when_absent() {
		$this->set_content( array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array( 'metadata' => array( 'gk_ref' => 'blk_no_bind' ) ),
				'innerHTML'    => '<p>No bindings</p>',
				'innerContent' => array( '<p>No bindings</p>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$block  = $blocks[0];

		$this->assertArrayNotHasKey( 'bindings', $block );
		$this->assertArrayNotHasKey( 'bound_attributes', $block );
	}

	/**
	 * The bindings field must include the `args` sub-key when present.
	 */
	public function test_read_bindings_includes_args() {
		$this->set_content( array(
			$this->make_bound_block(
				'core/image',
				array(
					'url' => array(
						'source' => 'core/post-meta',
						'args'   => array( 'key' => 'my_url_meta' ),
					),
				),
				array(),
				'<img src="" alt=""/>'
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$block  = $blocks[0];

		$this->assertArrayHasKey( 'bindings', $block );
		$this->assertArrayHasKey( 'args', $block['bindings']['url'] );
		$this->assertSame( 'my_url_meta', $block['bindings']['url']['args']['key'] );
	}

	// ── Write: rejection of bound attribute updates ───────────────────────

	/**
	 * update_block() must reject an attempt to overwrite a bound attribute
	 * with error code 'bound_attribute' (HTTP 400).
	 */
	public function test_write_rejects_bound_attribute_update() {
		$this->set_content( array(
			$this->make_bound_block(
				'core/image',
				array( 'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ) ),
				array( 'url' => 'https://old.example.com' ),
				'<img src="https://old.example.com" alt=""/>',
				'blk_guard_test'
			),
		) );

		// Try to update the bound 'url' attribute.
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'url' => 'https://new.example.com' )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	/**
	 * update_block() with allow_bound_writes=true must succeed even when the
	 * attribute is bound.
	 */
	public function test_write_override_with_allow_bound_writes() {
		$this->set_content( array(
			$this->make_bound_block(
				'core/image',
				array( 'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ) ),
				array( 'url' => 'https://old.example.com' ),
				'<img src="https://old.example.com" alt=""/>',
				'blk_override_test'
			),
		) );

		// Update with allow_bound_writes=true — must succeed.
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'url' => 'https://new.example.com' ),
			null,
			array( 'allow_bound_writes' => true )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$this->assertTrue( $result['success'] ?? false );
	}

	// ── Write: bindings preserved through non-binding writes ─────────────

	/**
	 * A write that doesn't touch any bound attribute must leave the bindings
	 * map intact in the saved post_content.
	 */
	public function test_write_preserves_bindings_on_unrelated_update() {
		$this->set_content( array(
			$this->make_bound_block(
				'core/image',
				array( 'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ) ),
				array(
					'url'   => 'https://old.example.com',
					'align' => 'left',
				),
				'<img src="https://old.example.com" alt=""/>',
				'blk_preserve_test'
			),
		) );

		// Update a NON-bound attribute ('align').
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'align' => 'right' )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Re-read from raw post_content and verify bindings are intact.
		$raw    = (string) get_post_field( 'post_content', $this->post_id );
		$parsed = parse_blocks( $raw );
		$block  = null;
		foreach ( $parsed as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$block = $b;
				break;
			}
		}

		$this->assertNotNull( $block );
		$bindings = $block['attrs']['metadata']['bindings'] ?? null;
		$this->assertNotEmpty( $bindings, 'Bindings must survive a write to an unbound attribute.' );
		$this->assertArrayHasKey( 'url', $bindings );
	}

	/**
	 * Other metadata fields (e.g. metadata.name) must still be writable
	 * through update_block() regardless of bindings.
	 */
	public function test_write_unrelated_metadata_still_mutable() {
		$this->set_content( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'url'      => 'https://example.com/img.jpg',
					'metadata' => array(
						'gk_ref'   => 'blk_meta_mut',
						'name'     => 'Old Section Name',
						'bindings' => array(
							'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ),
						),
					),
				),
				'innerHTML'    => '<img src="https://example.com/img.jpg" alt=""/>',
				'innerContent' => array( '<img src="https://example.com/img.jpg" alt=""/>' ),
				'innerBlocks'  => array(),
			),
		) );

		// Update metadata.name (not a bound attribute).
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'metadata' => array( 'name' => 'New Section Name' ) )
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Re-read — bindings must still be present (not overwritten by the
		// partial metadata merge).
		$raw    = (string) get_post_field( 'post_content', $this->post_id );
		$parsed = parse_blocks( $raw );
		$block  = null;
		foreach ( $parsed as $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$block = $b;
				break;
			}
		}

		$this->assertNotNull( $block );
		$bindings = $block['attrs']['metadata']['bindings'] ?? null;
		$this->assertNotEmpty( $bindings, 'Bindings must survive a metadata.name update.' );
	}
}
