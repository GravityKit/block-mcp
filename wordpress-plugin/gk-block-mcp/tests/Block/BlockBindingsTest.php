<?php
/**
 * Tests for WP 6.5+ Block Bindings API support.
 *
 * Uses real registered WordPress blocks and the WP Block Bindings Registry
 * (WP_Block_Bindings_Registry). Blocks tested: core/image and core/paragraph,
 * which are the primary binding targets in WP 6.5+.
 *
 * Read side: blocks with attrs.metadata.bindings surface a `bindings` field
 * and a `bound_attributes` array in the formatted response.
 *
 * Write side: update_block() rejects attempts to overwrite a bound attribute
 * unless the caller passes allow_bound_writes:true. Bindings survive writes
 * that do not touch bound attributes. The deep metadata merge ensures
 * metadata.bindings is not clobbered by a metadata.name update.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Block_Mutator;
use GravityKit\BlockMCP\Block_Safety;
use GravityKit\BlockMCP\HTML_Transformer;
use GravityKit\BlockMCP\Preferences;

class BlockBindingsTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	/** @var \GravityKit\BlockMCP\Block_Reader */
	private $reader;

	public function set_up(): void {
		parent::set_up();

		// Re-create crud so Block_Reader starts with a clean schema cache and
		// sees the real WP registry (core/image is already registered with its
		// full attribute schema including url, alt, etc.).
		$prefs         = new Preferences();
		$safety        = new Block_Safety();
		$transformer   = new HTML_Transformer();
		$this->crud    = new Block_CRUD( $prefs, $safety, $transformer, new Block_Inventory() );
		$this->mutator = new Block_Mutator( $this->crud, $prefs );

		$prop = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$prop->setAccessible( true );
		$this->reader = $prop->getValue( $this->crud );

		$this->post_id = $this->make_block_post();
	}

	// ── guards ───────────────────────────────────────────────────────────

	/**
	 * Skip the test if WP_Block_Bindings_Registry is not available (WP < 6.5).
	 */
	private function require_bindings_api(): void {
		if ( ! class_exists( 'WP_Block_Bindings_Registry' ) ) {
			$this->markTestSkipped( 'WP_Block_Bindings_Registry not available (requires WP 6.5+).' );
		}
	}

	// ── helpers ──────────────────────────────────────────────────────────

	/**
	 * Write serialized blocks to the test post and invalidate the parse cache.
	 */
	private function set_content( array $blocks ): void {
		wp_update_post( array(
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( $blocks ),
		) );
		$this->reader->invalidate( $this->post_id );
	}

	/**
	 * Build a core/image block with bindings sourced from core/post-meta.
	 * core/image is one of the primary binding targets in WP 6.5+ — it supports
	 * binding url, alt, title, and caption via attrs.metadata.bindings.
	 *
	 * @param array  $bindings  Map of attr_name → binding definition.
	 * @param array  $extra_attrs Extra attrs merged into the block.
	 * @param string $gk_ref
	 *
	 * @return array WP-internal block shape.
	 */
	private function make_image_block_with_bindings( array $bindings, array $extra_attrs = array(), $gk_ref = 'blk_img_bound' ) {
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
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerHTML'    => '<figure class="wp-block-image"><img src="https://placeholder.example.com/img.jpg" alt=""/></figure>',
			'innerContent' => array( '<figure class="wp-block-image"><img src="https://placeholder.example.com/img.jpg" alt=""/></figure>' ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Build a core/paragraph block with bindings sourced from core/post-meta.
	 */
	private function make_paragraph_block_with_bindings( array $bindings, $gk_ref = 'blk_para_bound' ) {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(
				'metadata' => array(
					'gk_ref'   => $gk_ref,
					'bindings' => $bindings,
				),
			),
			'innerHTML'    => '<p></p>',
			'innerContent' => array( '<p></p>' ),
			'innerBlocks'  => array(),
		);
	}

	// ── Read: bindings surfaced ───────────────────────────────────────────

	/**
	 * A core/image with bindings in attrs.metadata.bindings must have a top-level
	 * `bindings` field in the formatted response, mirroring the bindings map.
	 */
	public function test_read_surfaces_bindings_field_on_core_image() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_image_url' ) ),
					'alt' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_image_alt' ) ),
				)
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$this->assertNotInstanceOf( \WP_Error::class, $blocks );
		$this->assertNotEmpty( $blocks );

		$block = $blocks[0];
		$this->assertArrayHasKey( 'bindings', $block, 'Formatted block must expose a top-level bindings field.' );
		$this->assertArrayHasKey( 'url', $block['bindings'] );
		$this->assertSame( 'core/post-meta', $block['bindings']['url']['source'] );
		$this->assertArrayHasKey( 'alt', $block['bindings'] );
	}

	/**
	 * A block with bindings must expose a `bound_attributes` array listing the
	 * attribute names that are dynamically bound.
	 */
	public function test_read_surfaces_bound_attributes_array() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_url' ) ),
				)
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$block  = $blocks[0];

		$this->assertArrayHasKey( 'bound_attributes', $block );
		$this->assertContains( 'url', $block['bound_attributes'] );
	}

	/**
	 * A block without bindings must not have `bindings` or `bound_attributes`.
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
	 * The bindings field must include the `args` sub-key when present in the
	 * stored binding definition.
	 */
	public function test_read_bindings_includes_args() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array(
						'source' => 'core/post-meta',
						'args'   => array( 'key' => 'my_url_meta' ),
					),
				)
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$block  = $blocks[0];

		$this->assertArrayHasKey( 'bindings', $block );
		$this->assertArrayHasKey( 'args', $block['bindings']['url'] );
		$this->assertSame( 'my_url_meta', $block['bindings']['url']['args']['key'] );
	}

	/**
	 * core/paragraph can also carry bindings (e.g. binding 'content' to post meta).
	 * Verify the surface works for non-image blocks too.
	 */
	public function test_read_surfaces_bindings_on_core_paragraph() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_paragraph_block_with_bindings(
				array(
					'content' => array( 'source' => 'core/post-data', 'args' => array( 'key' => 'post_title' ) ),
				)
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$block  = $blocks[0];

		$this->assertArrayHasKey( 'bindings', $block );
		$this->assertArrayHasKey( 'content', $block['bindings'] );
		$this->assertSame( 'core/post-data', $block['bindings']['content']['source'] );
		$this->assertContains( 'content', $block['bound_attributes'] );
	}

	// ── Write: rejection of bound attribute updates ───────────────────────

	/**
	 * update_block() must reject an attempt to overwrite a bound attribute on
	 * a real core/image block with error code 'bound_attribute' (HTTP 400).
	 */
	public function test_write_rejects_bound_attribute_on_core_image() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ),
				),
				array( 'url' => 'https://old.example.com' ),
				'blk_guard_img'
			),
		) );

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
	 * attribute is bound. The write goes through the full save pipeline.
	 */
	public function test_write_override_with_allow_bound_writes_on_core_image() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ),
				),
				array( 'url' => 'https://old.example.com' ),
				'blk_override_img'
			),
		) );

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
	 * A write to an unbound attribute ('sizeSlug') on a bound core/image block
	 * must leave the bindings map intact in the saved post_content.
	 */
	public function test_write_preserves_bindings_when_updating_unbound_attr() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array( 'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero' ) ) ),
				array( 'sizeSlug' => 'large', 'url' => 'https://old.example.com' ),
				'blk_preserve_img'
			),
		) );

		// Update sizeSlug — not a bound attribute.
		$result = $this->crud->update_block( $this->post_id, 0, array( 'sizeSlug' => 'medium' ) );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		// Re-parse the raw DB content and confirm bindings survived.
		$raw    = (string) get_post_field( 'post_content', $this->post_id );
		$parsed = parse_blocks( $raw );
		$block  = current( array_filter( $parsed, static fn( $b ) => ! empty( $b['blockName'] ) ) );

		$this->assertNotFalse( $block );
		$bindings = $block['attrs']['metadata']['bindings'] ?? null;
		$this->assertNotEmpty( $bindings, 'Bindings must survive a write to an unbound attribute.' );
		$this->assertArrayHasKey( 'url', $bindings );
	}

	/**
	 * A write to metadata.name (a non-binding metadata field) must not clobber
	 * metadata.bindings. This exercises the deep metadata merge introduced in
	 * apply_block_update_in_place().
	 */
	public function test_write_metadata_name_does_not_clobber_bindings() {
		$this->require_bindings_api();

		$this->set_content( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'url'      => 'https://example.com/img.jpg',
					'metadata' => array(
						'gk_ref'   => 'blk_meta_deep',
						'name'     => 'Old Hero Section',
						'bindings' => array(
							'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_url' ) ),
						),
					),
				),
				'innerHTML'    => '<figure class="wp-block-image"><img src="https://example.com/img.jpg" alt=""/></figure>',
				'innerContent' => array( '<figure class="wp-block-image"><img src="https://example.com/img.jpg" alt=""/></figure>' ),
				'innerBlocks'  => array(),
			),
		) );

		// Update only metadata.name — bindings must not be destroyed.
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'metadata' => array( 'name' => 'New Hero Section' ) )
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$raw    = (string) get_post_field( 'post_content', $this->post_id );
		$parsed = parse_blocks( $raw );
		$block  = current( array_filter( $parsed, static fn( $b ) => ! empty( $b['blockName'] ) ) );

		$this->assertNotFalse( $block );
		$this->assertSame( 'New Hero Section', $block['attrs']['metadata']['name'] ?? null );
		$bindings = $block['attrs']['metadata']['bindings'] ?? null;
		$this->assertNotEmpty( $bindings, 'metadata.bindings must survive a metadata.name update.' );
		$this->assertArrayHasKey( 'url', $bindings );
	}

	/**
	 * Round-trip: a write that does not touch bindings at all must return
	 * the same binding data in the formatted response as the original read.
	 */
	public function test_round_trip_bindings_stable_after_unrelated_write() {
		$this->require_bindings_api();

		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'alt' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'alt_text' ) ),
				),
				array( 'sizeSlug' => 'large' ),
				'blk_roundtrip'
			),
		) );

		$before = $this->crud->get_blocks( $this->post_id );
		$this->assertArrayHasKey( 'bindings', $before[0] );

		// Write an unrelated attr.
		$this->crud->update_block( $this->post_id, 0, array( 'sizeSlug' => 'full' ) );

		$after = $this->crud->get_blocks( $this->post_id );
		$this->assertArrayHasKey( 'bindings', $after[0], 'bindings field must still be present after an unrelated write.' );
		$this->assertSame( $before[0]['bindings'], $after[0]['bindings'] );
		$this->assertSame( $before[0]['bound_attributes'], $after[0]['bound_attributes'] );
	}

	/**
	 * The bindings write-guard must fire on Block_Mutator's update-attrs op too.
	 *
	 * Pre-fix, only Block_Writer::update_block enforced the bound-attribute
	 * guard. An agent could bypass the protection by switching from the
	 * per-block PATCH route to edit_block_tree's update-attrs — the mutator
	 * did a plain array_merge with no bindings check, so an attribute the
	 * editor expected to be dynamically resolved would silently get
	 * clobbered with a static value. Now the same guard applies (with the
	 * allow_bound_writes:true escape hatch) so the contract is uniform
	 * across write paths.
	 */
	public function test_mutate_update_attrs_rejects_bound_attribute_overwrite() {
		$this->require_bindings_api();
		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_url' ) ),
				),
				array(),
				'blk_mut_guard'
			),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array( 'attributes' => array( 'url' => 'https://attacker.example/clobber.png' ) )
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
		$this->assertSame(
			array( 'url' ),
			$result->get_error_data()['bound_attributes'] ?? null,
			'data.bound_attributes must list the attributes that were blocked.'
		);
	}

	/**
	 * The same mutate path with allow_bound_writes:true must succeed — the
	 * escape hatch exists so an agent that knows what it's doing can still
	 * force a write through. Matches the contract on Block_Writer.
	 */
	public function test_mutate_update_attrs_allow_bound_writes_bypasses_guard() {
		$this->require_bindings_api();
		$this->set_content( array(
			$this->make_image_block_with_bindings(
				array(
					'url' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'hero_url' ) ),
				),
				array(),
				'blk_mut_force'
			),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array(
				'attributes'         => array( 'url' => 'https://example.test/forced.png' ),
				'allow_bound_writes' => true,
			)
		);

		$this->assertNotInstanceOf( \WP_Error::class, $result );
	}
}
