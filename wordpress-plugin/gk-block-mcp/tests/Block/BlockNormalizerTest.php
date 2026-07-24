<?php
/**
 * Tests for Block_Normalizer — write-time static-block markup normalization.
 *
 * The normalizer framework repairs the narrow, provably-invalid markup
 * signatures that agents produce and that WordPress can detect server-side, so
 * the editor stops reporting "Block contains unexpected or invalid content". It
 * does NOT attempt editor-parity validation (save() is JS-only); each pluggable
 * normalizer repairs only a signature that no valid (including deprecated)
 * serialization can produce. The engine itself is block-agnostic.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_Normalizer;

class BlockNormalizerTest extends BlockApiTestCase {

	/**
	 * The invalid `<img>` innerHTML used across the tests — width only in the
	 * inline style, no width attribute, no is-resized.
	 */
	private const INVALID_IMAGE_HTML = '<figure class="wp-block-image"><img src="https://example.com/x.png" alt="x" style="width: 560px"></figure>';

	/**
	 * Build a flat core/image block array in WP-internal shape.
	 *
	 * @param array  $attrs Block attributes (the JSON-comment delimiter payload).
	 * @param string $html  The figure/img innerHTML.
	 *
	 * @return array
	 */
	private function image_block( array $attrs, string $html ): array {
		return array(
			'blockName'    => 'core/image',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * The reported bug: an image whose width lives only in the <img> inline
	 * style, with no `width` block attribute and no `is-resized` figure class,
	 * is exactly what Gutenberg flags as invalid — save() is driven by the
	 * `width` attribute, so it regenerates markup without that style and the
	 * stored HTML no longer matches.
	 *
	 * Normalization must lift the inline width into the block attribute (the
	 * value save() reads) and add `is-resized` to the figure, leaving the
	 * existing inline style in place. This signature is unique to agent-authored
	 * markup: no core/image deprecation stores width as an inline style.
	 */
	public function test_core_image_inline_width_without_attribute_is_lifted_and_resized() {
		$block = $this->image_block(
			array(
				'url' => 'https://example.com/x.png',
				'alt' => 'x',
			),
			self::INVALID_IMAGE_HTML
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertSame( '560px', $out[0]['attrs']['width'], 'inline-style width must be lifted into the width block attribute' );
		$this->assertStringContainsString( 'is-resized', $out[0]['innerHTML'], 'figure must gain the is-resized class' );
		$this->assertStringContainsString( 'width: 560px', $out[0]['innerHTML'], 'the existing inline style must be preserved verbatim' );
		$this->assertSame( $out[0]['innerHTML'], $out[0]['innerContent'][0], 'innerContent must track the repaired innerHTML' );
	}

	/**
	 * Locate the first core/image block in a parsed tree.
	 *
	 * @param array $blocks parse_blocks() output.
	 *
	 * @return array|null
	 */
	private function find_image( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'core/image' === $block['blockName'] ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Assert that the stored core/image at the given post has been normalized.
	 *
	 * @param int $post_id Post to read.
	 */
	private function assert_stored_image_normalized( int $post_id ): void {
		$image = $this->find_image( $this->block_tree( $post_id ) );
		$this->assertNotNull( $image, 'a core/image block must be present in stored content' );
		$this->assertArrayHasKey( 'width', $image['attrs'], 'stored image must carry the width attribute after normalization' );
		$this->assertSame( '560px', $image['attrs']['width'] );
		$this->assertStringContainsString( 'is-resized', $image['innerHTML'], 'stored figure must carry is-resized after normalization' );
	}

	/**
	 * insert_blocks must normalize invalid image markup before it is persisted.
	 * insert_blocks funnels through Block_Writer::save_blocks(), the documented
	 * single chokepoint for every structured write, so normalizing there covers
	 * insert / update / replace / mutate / pattern-insert at once.
	 */
	public function test_insert_blocks_normalizes_invalid_image() {
		$post_id = $this->make_block_post();

		$result = $this->crud->insert_blocks(
			$post_id,
			null,
			array(
				array(
					'name'       => 'core/image',
					'attributes' => array(
						'url' => 'https://example.com/x.png',
						'alt' => 'x',
					),
					'innerHTML'  => self::INVALID_IMAGE_HTML,
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assert_stored_image_normalized( $post_id );
	}

	/**
	 * create_post is the sibling write funnel: it builds and serializes blocks
	 * directly (Post_Manager), bypassing save_blocks(). It must normalize too,
	 * so a post created with an invalid image is stored valid.
	 */
	public function test_create_post_normalizes_invalid_image() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$pm = new \GravityKit\BlockMCP\Post_Manager( $this->crud );

		$result = $pm->create_post(
			array(
				'title'  => 'Normalization',
				'blocks' => array(
					array(
						'name'       => 'core/image',
						'attributes' => array(
							'url' => 'https://example.com/x.png',
							'alt' => 'x',
						),
						'innerHTML'  => self::INVALID_IMAGE_HTML,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assert_stored_image_normalized( (int) $result['id'] );
	}

	/**
	 * update_block routes through save_blocks; replacing a block's innerHTML
	 * with the invalid form must be normalized before persistence.
	 */
	public function test_update_block_normalizes_invalid_image() {
		$seed_html = '<figure class="wp-block-image"><img src="https://example.com/x.png" alt="x"/></figure>';
		$post_id   = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/image',
					'attrs'        => array(
						'url' => 'https://example.com/x.png',
						'alt' => 'x',
					),
					'innerHTML'    => $seed_html,
					'innerContent' => array( $seed_html ),
					'innerBlocks'  => array(),
				),
			)
		);

		$result = $this->crud->update_block( $post_id, 0, array(), self::INVALID_IMAGE_HTML );

		$this->assertNotWPError( $result );
		$this->assert_stored_image_normalized( $post_id );
	}

	/**
	 * edit_block_tree's mutate ops (here `update-html`) duplicate write logic
	 * but still persist via save_blocks, so they are normalized too.
	 */
	public function test_edit_block_tree_update_html_normalizes_invalid_image() {
		$seed_html = '<figure class="wp-block-image"><img src="https://example.com/x.png" alt="x"/></figure>';
		$post_id   = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/image',
					'attrs'        => array(
						'url' => 'https://example.com/x.png',
						'alt' => 'x',
					),
					'innerHTML'    => $seed_html,
					'innerContent' => array( $seed_html ),
					'innerBlocks'  => array(),
				),
			)
		);

		$result = $this->mutator->mutate(
			$post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => self::INVALID_IMAGE_HTML )
		);

		$this->assertNotWPError( $result );
		$this->assert_stored_image_normalized( $post_id );
	}

	/**
	 * Normalization is idempotent: a second pass over an already-repaired block
	 * is a byte-for-byte no-op. Without the "attribute absent" guard the second
	 * pass would keep re-adding is-resized / re-deriving the attribute.
	 */
	public function test_normalize_is_idempotent() {
		$block = $this->image_block(
			array(
				'url' => 'https://example.com/x.png',
				'alt' => 'x',
			),
			self::INVALID_IMAGE_HTML
		);

		$once  = Block_Normalizer::normalize_tree( array( $block ) );
		$twice = Block_Normalizer::normalize_tree( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * No false repair: a current, valid resized image (width attribute present,
	 * figure already is-resized, inline style present) is returned untouched.
	 */
	public function test_valid_resized_image_is_not_modified() {
		$html  = '<figure class="wp-block-image is-resized"><img src="https://example.com/x.png" alt="x" style="width:560px"/></figure>';
		$block = $this->image_block(
			array(
				'url'   => 'https://example.com/x.png',
				'alt'   => 'x',
				'width' => '560px',
			),
			$html
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertSame( array( $block ), $out );
	}

	/**
	 * No false repair on deprecated content: older core/image serializations
	 * store width as an HTML attribute on <img> (no inline style). The
	 * normalizer reads only inline styles, so it must leave that form
	 * byte-identical — repairing it would rewrite editor-valid content.
	 */
	public function test_deprecated_html_attribute_image_is_not_modified() {
		$html  = '<figure class="wp-block-image"><img src="https://example.com/x.png" alt="x" width="560" height="300"/></figure>';
		$block = $this->image_block(
			array(
				'url' => 'https://example.com/x.png',
				'alt' => 'x',
			),
			$html
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertSame( array( $block ), $out, 'HTML-attribute (deprecated) dimensions must not be touched' );
		$this->assertArrayNotHasKey( 'width', $out[0]['attrs'] );
		$this->assertStringNotContainsString( 'is-resized', $out[0]['innerHTML'] );
	}

	/**
	 * The engine must contain no block-specific rules: all repair logic lives in
	 * pluggable normalizers registered on `gk/block-mcp/block/normalize`. With
	 * every registered normalizer stripped, the engine must be a pure
	 * pass-through — even for the invalid image it would otherwise repair. This
	 * is the contract that keeps the engine flexible rather than hard-coding any
	 * one block.
	 */
	public function test_engine_has_no_builtin_block_rules() {
		// The test framework restores all hooks in tear_down(), so stripping
		// the filter here cannot leak into other tests.
		remove_all_filters( 'gk/block-mcp/block/normalize' );

		$block = $this->image_block(
			array(
				'url' => 'https://example.com/x.png',
				'alt' => 'x',
			),
			self::INVALID_IMAGE_HTML
		);
		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertSame( array( $block ), $out, 'the engine itself must not know about any specific block' );
	}

	/**
	 * The engine applies any normalizer registered on the filter to any block —
	 * the extension point third parties (and the built-in rules) use. Proves the
	 * framework is generic, not a fixed list.
	 */
	public function test_engine_applies_any_registered_normalizer() {
		$callback = static function ( $block, $block_name ) {
			if ( 'core/spacer' === $block_name ) {
				$block['attrs']['data-marked'] = 'yes';
			}
			return $block;
		};
		add_filter( 'gk/block-mcp/block/normalize', $callback, 10, 2 );

		$block = array(
			'blockName'    => 'core/spacer',
			'attrs'        => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
			'innerBlocks'  => array(),
		);
		$out = Block_Normalizer::normalize_tree( array( $block ) );

		remove_filter( 'gk/block-mcp/block/normalize', $callback, 10 );

		$this->assertSame( 'yes', $out[0]['attrs']['data-marked'] );
	}

	/**
	 * The `values` HTML used across the core/list tests — two <li> items that
	 * live in the deprecated `values` block attribute, not inside the wrapper.
	 */
	private const INVALID_LIST_VALUES = '<li>First</li><li>Second</li>';

	/**
	 * The invalid core/list innerHTML: an empty <ul> wrapper with no <li> items
	 * and no core/list-item innerBlocks. The items are stranded in `values`.
	 */
	private const INVALID_LIST_HTML = '<ul class="wp-block-list"></ul>';

	/**
	 * Build a flat core/list block array in WP-internal shape.
	 *
	 * @param array  $attrs Block attributes (the JSON-comment delimiter payload).
	 * @param string $html  The list wrapper innerHTML.
	 *
	 * @return array
	 */
	private function list_block( array $attrs, string $html ): array {
		return array(
			'blockName'    => 'core/list',
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}

	/**
	 * Locate the first core/list block in a parsed tree.
	 *
	 * @param array $blocks parse_blocks() output.
	 *
	 * @return array|null
	 */
	private function find_list( array $blocks ) {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'core/list' === $block['blockName'] ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * Assert that the stored core/list at the given post has its `values` items
	 * baked into the wrapper.
	 *
	 * @param int $post_id Post to read.
	 */
	private function assert_stored_list_baked( int $post_id ): void {
		$list = $this->find_list( $this->block_tree( $post_id ) );
		$this->assertNotNull( $list, 'a core/list block must be present in stored content' );
		$this->assertStringContainsString( '<li>First</li>', $list['innerHTML'], 'the first values item must be inside the wrapper' );
		$this->assertStringContainsString( '<li>Second</li>', $list['innerHTML'], 'the second values item must be inside the wrapper' );
		$this->assertStringNotContainsString( '<ul class="wp-block-list"></ul>', $list['innerHTML'], 'the wrapper must no longer be empty' );
	}

	/**
	 * The reported bug: a core/list whose `<li>` items live in the deprecated
	 * `values` attribute, with an EMPTY <ul> wrapper and no core/list-item
	 * innerBlocks, renders an empty list on modern WordPress (which builds the
	 * list from core/list-item children, not `values`).
	 *
	 * Normalization must bake the `values` HTML into the wrapper so the front
	 * end serves a populated list and the stored form matches core/list's
	 * values-based deprecation (Gutenberg migrates it cleanly on next edit).
	 * This signature is unique to agent-authored markup: a real values-based
	 * deprecation carries the <li> items INSIDE the wrapper.
	 */
	public function test_core_list_values_with_empty_wrapper_are_baked_in() {
		$block = $this->list_block(
			array( 'values' => self::INVALID_LIST_VALUES ),
			self::INVALID_LIST_HTML
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertStringContainsString( '<li>First</li>', $out[0]['innerHTML'], 'first item must be baked into the wrapper' );
		$this->assertStringContainsString( '<li>Second</li>', $out[0]['innerHTML'], 'second item must be baked into the wrapper' );
		$this->assertStringContainsString( 'wp-block-list', $out[0]['innerHTML'], 'the wp-block-list class must be preserved' );
		$this->assertSame( $out[0]['innerHTML'], $out[0]['innerContent'][0], 'innerContent must track the repaired innerHTML' );
		$this->assertSame( array(), $out[0]['innerBlocks'], 'the block must stay a leaf: no child blocks added' );
	}

	/**
	 * insert_blocks funnels through save_blocks(), the single write chokepoint.
	 * An invalid core/list inserted there must be baked before it is persisted.
	 */
	public function test_insert_blocks_normalizes_invalid_list() {
		$post_id = $this->make_block_post();

		$result = $this->crud->insert_blocks(
			$post_id,
			null,
			array(
				array(
					'name'       => 'core/list',
					'attributes' => array( 'values' => self::INVALID_LIST_VALUES ),
					'innerHTML'  => self::INVALID_LIST_HTML,
				),
			)
		);

		$this->assertNotWPError( $result );
		$this->assert_stored_list_baked( $post_id );
	}

	/**
	 * create_post is the sibling write funnel (Post_Manager serializes blocks
	 * directly, bypassing save_blocks). It must bake an invalid list too.
	 */
	public function test_create_post_normalizes_invalid_list() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$pm = new \GravityKit\BlockMCP\Post_Manager( $this->crud );

		$result = $pm->create_post(
			array(
				'title'  => 'List Normalization',
				'blocks' => array(
					array(
						'name'       => 'core/list',
						'attributes' => array( 'values' => self::INVALID_LIST_VALUES ),
						'innerHTML'  => self::INVALID_LIST_HTML,
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assert_stored_list_baked( (int) $result['id'] );
	}

	/**
	 * Normalization is idempotent: once the items are inside the wrapper the
	 * "wrapper already contains an <li>" guard makes a second pass a
	 * byte-for-byte no-op. Without it the second pass would re-append `values`.
	 */
	public function test_normalize_list_is_idempotent() {
		$block = $this->list_block(
			array( 'values' => self::INVALID_LIST_VALUES ),
			self::INVALID_LIST_HTML
		);

		$once  = Block_Normalizer::normalize_tree( array( $block ) );
		$twice = Block_Normalizer::normalize_tree( $once );

		$this->assertSame( $once, $twice );
	}

	/**
	 * No false repair: a correctly-authored modern core/list stores its items as
	 * core/list-item innerBlocks (and carries no `values`). The non-empty
	 * innerBlocks guard must leave it byte-identical.
	 */
	public function test_valid_list_with_list_item_children_is_not_modified() {
		$child_html = '<li class="wp-block-list-item">Item</li>';
		$list_item  = array(
			'blockName'    => 'core/list-item',
			'attrs'        => array(),
			'innerHTML'    => $child_html,
			'innerContent' => array( $child_html ),
			'innerBlocks'  => array(),
		);
		$block      = array(
			'blockName'    => 'core/list',
			'attrs'        => array(),
			'innerHTML'    => '<ul class="wp-block-list"></ul>',
			'innerContent' => array( '<ul class="wp-block-list">', null, '</ul>' ),
			'innerBlocks'  => array( $list_item ),
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertSame( array( $block ), $out, 'a modern list with child blocks must not be touched' );
	}

	/**
	 * A nested sublist and an inline link carried inside `values` must survive
	 * the bake: the whole values fragment (its <a> links and any nested
	 * <ul>/<ol>) lands inside the wrapper verbatim.
	 */
	public function test_core_list_nested_sublist_and_inline_link_survive() {
		$values = '<li><a href="https://example.com">Link</a></li><li>Parent<ul class="wp-block-list"><li>Child</li></ul></li>';
		$block  = $this->list_block(
			array( 'values' => $values ),
			self::INVALID_LIST_HTML
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertStringContainsString( '<a href="https://example.com">Link</a>', $out[0]['innerHTML'], 'inline link must survive' );
		$this->assertStringContainsString( '<li>Child</li>', $out[0]['innerHTML'], 'nested sublist item must survive' );
		$this->assertStringContainsString( $values, $out[0]['innerHTML'], 'the values fragment must be baked in verbatim' );
	}

	/**
	 * The wrapper tag is reconciled with the `ordered` attribute. A block that
	 * (wrongly) supplies a <ul> while `ordered` is true must be emitted as an
	 * <ol> carrying the ordered HTML attributes (here `start`).
	 */
	public function test_ordered_list_with_ul_supplied_emits_ol() {
		$block = $this->list_block(
			array(
				'values'  => self::INVALID_LIST_VALUES,
				'ordered' => true,
				'start'   => 3,
			),
			self::INVALID_LIST_HTML
		);

		$out = Block_Normalizer::normalize_tree( array( $block ) );

		$this->assertStringContainsString( '<ol', $out[0]['innerHTML'], 'an ordered list must be emitted as <ol>' );
		$this->assertStringNotContainsString( '<ul', $out[0]['innerHTML'], 'the <ul> wrapper must be reconciled away' );
		$this->assertStringContainsString( 'start="3"', $out[0]['innerHTML'], 'the ordered start attribute must be carried onto the <ol>' );
		$this->assertStringContainsString( '<li>First</li>', $out[0]['innerHTML'], 'the values items must be baked into the <ol>' );
	}

	/**
	 * update_block's `saved` snapshot must echo what actually landed on disk.
	 *
	 * The snapshot is the documented verification channel ("the response IS
	 * the verification" — format_saved_block()), but it was built from the
	 * handler's in-memory block, which save_blocks() normalizes only on a
	 * local copy. For a repaired image the agent saw pre-repair markup that
	 * differed from post_content: no width attribute, no is-resized class.
	 * The snapshot must reflect the persisted, normalized block.
	 */
	public function test_update_block_saved_snapshot_reflects_persisted_normalization() {
		$seed_html = '<figure class="wp-block-image"><img src="https://example.com/x.png" alt="x"/></figure>';
		$post_id   = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/image',
					'attrs'        => array(
						'url' => 'https://example.com/x.png',
						'alt' => 'x',
					),
					'innerHTML'    => $seed_html,
					'innerContent' => array( $seed_html ),
					'innerBlocks'  => array(),
				),
			)
		);

		$result = $this->crud->update_block( $post_id, 0, array(), self::INVALID_IMAGE_HTML );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'saved', $result );
		$this->assertSame( '560px', $result['saved']['attributes']['width'], 'saved snapshot must carry the lifted width attribute' );
		$this->assertStringContainsString( 'is-resized', $result['saved']['inner_html'], 'saved snapshot must carry the repaired figure markup' );
	}

	/**
	 * Batch update's verbose `saved` snapshots must echo the persisted blocks.
	 *
	 * Same contract as the single-update snapshot: with verbose:true each
	 * result item's `saved` was built in the apply phase, before save_blocks()
	 * normalized the tree, so a repaired image echoed pre-repair markup.
	 */
	public function test_batch_update_saved_snapshot_reflects_persisted_normalization() {
		$seed_html = '<figure class="wp-block-image"><img src="https://example.com/x.png" alt="x"/></figure>';
		$post_id   = $this->make_block_post(
			array(
				array(
					'blockName'    => 'core/image',
					'attrs'        => array(
						'url' => 'https://example.com/x.png',
						'alt' => 'x',
					),
					'innerHTML'    => $seed_html,
					'innerContent' => array( $seed_html ),
					'innerBlocks'  => array(),
				),
			)
		);

		$result = $this->crud->update_blocks_batch(
			$post_id,
			array(
				array(
					'flat_index' => 0,
					'innerHTML'  => self::INVALID_IMAGE_HTML,
				),
			),
			true
		);

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'saved', $result['results'][0] );
		$saved = $result['results'][0]['saved'];
		$this->assertSame( '560px', $saved['attributes']['width'], 'verbose saved snapshot must carry the lifted width attribute' );
		$this->assertStringContainsString( 'is-resized', $saved['inner_html'], 'verbose saved snapshot must carry the repaired figure markup' );
	}
}
