<?php
/**
 * Tests for block.json schema-aware attribute extraction in Block_Reader.
 *
 * Uses real registered WordPress core blocks (core/image, core/heading,
 * core/button) whose block.json schemas define HTML-sourced attributes.
 * These blocks are registered by WordPress core with full attribute
 * definitions in the test environment (real WP loaded via bootstrap-wp.php).
 *
 * Covered extraction sources:
 *   - 'attribute' → core/image alt (img[alt]) and url (img[src])
 *   - 'html'/'rich-text' → core/heading content (h1–h6 inner HTML)
 *   - missing selector match → key absent (not null)
 *   - delimiter-defined attr wins over DOM extraction
 *   - unregistered block type → parsed attrs returned unchanged
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Block_Safety;
use GravityKit\BlockMCP\HTML_Transformer;
use GravityKit\BlockMCP\Preferences;

class BlockReaderSchemaAwareAttrsTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	/** @var \GravityKit\BlockMCP\Block_Reader */
	private $reader;

	public function set_up(): void {
		parent::set_up();

		// Re-create the crud AFTER the parent's block registration so we get
		// a fresh Block_Reader whose block_schema_cache starts empty and will
		// populate lazily from the real WP registry (which already holds the
		// full core block schemas from WordPress's block.json files).
		$preferences   = new Preferences();
		$safety        = new Block_Safety();
		$transformer   = new HTML_Transformer();
		$this->crud    = new Block_CRUD( $preferences, $safety, $transformer, new Block_Inventory() );
		$this->mutator = new \GravityKit\BlockMCP\Block_Mutator( $this->crud, $preferences );

		$prop = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$prop->setAccessible( true );
		$this->reader = $prop->getValue( $this->crud );

		$this->post_id = $this->make_block_post();
	}

	// ── helpers ──────────────────────────────────────────────────────────

	private function set_post_blocks( array $blocks ): void {
		wp_update_post( array(
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( $blocks ),
		) );
		$this->reader->invalidate( $this->post_id );
	}

	/**
	 * Confirm core/image has source='attribute' for alt in this WP version.
	 * Skips the test gracefully if the schema is absent (old WP).
	 */
	private function require_core_image_alt_schema(): void {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( 'core/image' );
		$alt_def    = $block_type ? ( $block_type->attributes['alt'] ?? null ) : null;
		if ( ! $alt_def || ( $alt_def['source'] ?? '' ) !== 'attribute' ) {
			$this->markTestSkipped( 'core/image alt attribute schema not available in this WP version.' );
		}
	}

	private function require_core_heading_content_schema(): void {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( 'core/heading' );
		$content    = $block_type ? ( $block_type->attributes['content'] ?? null ) : null;
		$source     = $content['source'] ?? '';
		if ( ! $content || ! in_array( $source, array( 'html', 'rich-text' ), true ) ) {
			$this->markTestSkipped( 'core/heading content schema not available in this WP version.' );
		}
	}

	// ── source='attribute' via core/image ────────────────────────────────

	/**
	 * core/image defines alt as source='attribute', selector='img', attribute='alt'.
	 * When img[alt] is present in innerHTML but absent from the JSON delimiter,
	 * it must appear in the formatted attributes map.
	 */
	public function test_core_image_alt_extracted_from_innerHTML() {
		$this->require_core_image_alt_schema();

		// Intentionally omit 'alt' from the comment-delimiter attrs so the
		// extractor has to pull it from the DOM.
		$this->set_post_blocks( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'id'       => 1,
					'sizeSlug' => 'large',
					'metadata' => array( 'gk_ref' => 'blk_img_alt' ),
				),
				'innerHTML'    => '<figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A mountain landscape" class="wp-image-1"/></figure>',
				'innerContent' => array( '<figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt="A mountain landscape" class="wp-image-1"/></figure>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$this->assertNotInstanceOf( \WP_Error::class, $blocks );
		$this->assertNotEmpty( $blocks );

		$attrs = $blocks[0]['attributes'];
		$this->assertArrayHasKey( 'alt', $attrs, 'core/image alt must be extracted from img[alt] in innerHTML.' );
		$this->assertSame( 'A mountain landscape', $attrs['alt'] );
	}

	/**
	 * core/image defines url as source='attribute', selector='img', attribute='src'.
	 * When img[src] is in innerHTML but absent from the delimiter, it must surface.
	 */
	public function test_core_image_url_extracted_from_src_attribute() {
		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( 'core/image' );
		$url_def    = $block_type ? ( $block_type->attributes['url'] ?? null ) : null;
		if ( ! $url_def || ( $url_def['source'] ?? '' ) !== 'attribute' ) {
			$this->markTestSkipped( 'core/image url attribute schema not available.' );
		}

		$this->set_post_blocks( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'metadata' => array( 'gk_ref' => 'blk_img_url' ),
				),
				'innerHTML'    => '<figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt=""/></figure>',
				'innerContent' => array( '<figure class="wp-block-image"><img src="https://example.com/photo.jpg" alt=""/></figure>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$attrs  = $blocks[0]['attributes'];

		$this->assertArrayHasKey( 'url', $attrs, 'core/image url must be extracted from img[src] in innerHTML.' );
		$this->assertSame( 'https://example.com/photo.jpg', $attrs['url'] );
	}

	// ── source='rich-text' via core/heading ──────────────────────────────

	/**
	 * core/heading defines content as source='rich-text', selector='h1,h2,...'.
	 * When the heading HTML is present in innerHTML but content is absent from
	 * the comment delimiter, it must be extracted.
	 */
	public function test_core_heading_content_extracted_from_innerHTML() {
		$this->require_core_heading_content_schema();

		$this->set_post_blocks( array(
			array(
				'blockName'    => 'core/heading',
				'attrs'        => array(
					'level'    => 2,
					'metadata' => array( 'gk_ref' => 'blk_heading' ),
					// 'content' deliberately absent from delimiter.
				),
				'innerHTML'    => '<h2 class="wp-block-heading">Hello <strong>Schema</strong></h2>',
				'innerContent' => array( '<h2 class="wp-block-heading">Hello <strong>Schema</strong></h2>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$this->assertNotInstanceOf( \WP_Error::class, $blocks );

		$attrs = $blocks[0]['attributes'];
		$this->assertArrayHasKey( 'content', $attrs, 'core/heading content must be extracted from innerHTML.' );
		$this->assertStringContainsString( 'Hello', $attrs['content'] );
	}

	// ── fallback: unregistered block type ────────────────────────────────

	/**
	 * An unregistered block type must return the parsed delimiter attrs unchanged.
	 * Must not crash; the agent still sees whatever was in the comment delimiter.
	 */
	public function test_unregistered_block_returns_parsed_attrs_unchanged() {
		$this->set_post_blocks( array(
			array(
				'blockName'    => 'unknown/mystery-block',
				'attrs'        => array(
					'myAttr'   => 'preserved',
					'metadata' => array( 'gk_ref' => 'blk_unknown' ),
				),
				'innerHTML'    => '<p>Something</p>',
				'innerContent' => array( '<p>Something</p>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$this->assertNotInstanceOf( \WP_Error::class, $blocks );

		$attrs = $blocks[0]['attributes'];
		$this->assertSame( 'preserved', $attrs['myAttr'] );
	}

	// ── fallback: missing selector match → key absent ─────────────────────

	/**
	 * If the HTML does not contain the selector, the sourced attribute key must
	 * be absent (not set to null). This prevents agents from seeing noise.
	 */
	public function test_missing_selector_omits_attribute_not_null() {
		$this->require_core_image_alt_schema();

		// No img tag at all in innerHTML — alt extraction must yield nothing.
		$this->set_post_blocks( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'metadata' => array( 'gk_ref' => 'blk_noimg' ),
				),
				'innerHTML'    => '<figure class="wp-block-image"></figure>',
				'innerContent' => array( '<figure class="wp-block-image"></figure>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$attrs  = $blocks[0]['attributes'];

		$this->assertArrayNotHasKey( 'alt', $attrs, 'Missing selector match must omit the key.' );
	}

	// ── delimiter value wins over DOM extraction ──────────────────────────

	/**
	 * When both the JSON delimiter AND the DOM carry a value for the same
	 * attribute, the delimiter value must win (round-trip stability).
	 */
	public function test_delimiter_attr_wins_over_dom_extraction() {
		$this->require_core_image_alt_schema();

		// Delimiter says alt='From delimiter'; DOM says alt='From DOM'.
		$this->set_post_blocks( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'alt'      => 'From delimiter',
					'metadata' => array( 'gk_ref' => 'blk_delim_wins' ),
				),
				'innerHTML'    => '<figure class="wp-block-image"><img src="x.jpg" alt="From DOM"/></figure>',
				'innerContent' => array( '<figure class="wp-block-image"><img src="x.jpg" alt="From DOM"/></figure>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$attrs  = $blocks[0]['attributes'];

		$this->assertSame(
			'From delimiter',
			$attrs['alt'],
			'JSON-delimiter attr must win over DOM-extracted value for round-trip stability.'
		);
	}

	// ── multiple sourced attrs on one block ───────────────────────────────

	/**
	 * A block can have multiple sourced attributes. All absent-from-delimiter
	 * ones must be extracted in one pass.
	 */
	public function test_multiple_sourced_attrs_extracted_together() {
		$this->require_core_image_alt_schema();

		$registry   = \WP_Block_Type_Registry::get_instance();
		$block_type = $registry->get_registered( 'core/image' );
		$url_def    = $block_type ? ( $block_type->attributes['url'] ?? null ) : null;
		if ( ! $url_def || ( $url_def['source'] ?? '' ) !== 'attribute' ) {
			$this->markTestSkipped( 'core/image url attribute schema not available.' );
		}

		// Neither alt nor url in delimiter.
		$this->set_post_blocks( array(
			array(
				'blockName'    => 'core/image',
				'attrs'        => array(
					'metadata' => array( 'gk_ref' => 'blk_multi_src' ),
				),
				'innerHTML'    => '<figure class="wp-block-image"><img src="https://example.com/pic.jpg" alt="Nice pic"/></figure>',
				'innerContent' => array( '<figure class="wp-block-image"><img src="https://example.com/pic.jpg" alt="Nice pic"/></figure>' ),
				'innerBlocks'  => array(),
			),
		) );

		$blocks = $this->crud->get_blocks( $this->post_id );
		$attrs  = $blocks[0]['attributes'];

		$this->assertArrayHasKey( 'alt', $attrs );
		$this->assertSame( 'Nice pic', $attrs['alt'] );
		$this->assertArrayHasKey( 'url', $attrs );
		$this->assertSame( 'https://example.com/pic.jpg', $attrs['url'] );
	}
}
