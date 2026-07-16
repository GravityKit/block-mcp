<?php
/**
 * Auto-derive of sourced attributes for simple dual-storage blocks.
 *
 * Contract: an innerHTML-only edit to a dual-storage block is allowed when the
 * block's structured content is re-derivable from the new markup (e.g. a heading
 * whose `content` is an HTML/rich-text source) — the write paths recompute those
 * attributes and apply both halves together so nothing desyncs. A block whose
 * structured content lives ONLY in the delimiter JSON (the yoast/faq-block
 * `questions[]` shape) is NOT re-derivable and stays rejected. Pinning that
 * boundary is the whole point of this suite; if it moves, an agent editing a FAQ
 * block's innerHTML would silently drop its questions.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Reader;
use GravityKit\BlockMCP\Block_Inventory;

class DualStorageAutoDeriveTest extends BlockApiTestCase {

	public function set_up(): void {
		parent::set_up();

		$registry = \WP_Block_Type_Registry::get_instance();

		// Simple dual-storage: `content` is re-derivable from innerHTML;
		// `level` is a plain scalar the innerHTML-only edit leaves untouched.
		if ( ! $registry->is_registered( 'test/heading-like' ) ) {
			$registry->register(
				'test/heading-like',
				array(
					'attributes' => array(
						'content' => array( 'type' => 'string', 'source' => 'rich-text', 'selector' => 'h2' ),
						'level'   => array( 'type' => 'number' ),
					),
				)
			);
		}

		// The unsafe shape: a sourced `title` (so it clears the "has a derivable
		// source" gate) PLUS a delimiter-only `questions[]` array that innerHTML
		// cannot reconstruct — stands in for yoast/faq-block.
		if ( ! $registry->is_registered( 'test/faq-like' ) ) {
			$registry->register(
				'test/faq-like',
				array(
					'attributes' => array(
						'title'     => array( 'type' => 'string', 'source' => 'html', 'selector' => 'h3' ),
						'questions' => array( 'type' => 'array' ),
					),
				)
			);
		}

		// Dual-storage but nothing derivable from innerHTML (no sourced attrs).
		if ( ! $registry->is_registered( 'test/opaque' ) ) {
			$registry->register(
				'test/opaque',
				array(
					'attributes' => array(
						'data' => array( 'type' => 'string' ),
					),
				)
			);
		}

		// The trap the adversarial pass found: a STRUCTURED (array) attribute
		// that declares an innerHTML source. The source resolves to a string, so
		// re-deriving would clobber the array — this must NOT be auto-synced.
		if ( ! $registry->is_registered( 'test/list-like' ) ) {
			$registry->register(
				'test/list-like',
				array(
					'attributes' => array(
						'items' => array( 'type' => 'array', 'source' => 'html', 'selector' => 'ul' ),
					),
				)
			);
		}

		// Mark the fixtures dual via the authoritative storage-modes option
		// rather than the gk/block-mcp/block/dual-storage filter: the filter's
		// defaults are cached in a process-static inside is_block_dual_storage(),
		// so a filter added here is ignored once any earlier test in the run has
		// primed that cache. The option is consulted first and rolls back per test.
		update_option(
			Block_Inventory::STORAGE_MODES_OPTION,
			array(
				'test/heading-like'       => Block_Inventory::STORAGE_MODE_DUAL,
				'test/faq-like'           => Block_Inventory::STORAGE_MODE_DUAL,
				'test/opaque'             => Block_Inventory::STORAGE_MODE_DUAL,
				'test/list-like'          => Block_Inventory::STORAGE_MODE_DUAL,
				'test/unregistered-dual'  => Block_Inventory::STORAGE_MODE_DUAL,
			)
		);
	}

	public function tear_down(): void {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'test/heading-like', 'test/faq-like', 'test/opaque' ) as $name ) {
			if ( $registry->is_registered( $name ) ) {
				$registry->unregister( $name );
			}
		}
		parent::tear_down();
	}

	/**
	 * Build a WP-internal block array with matching innerContent.
	 *
	 * @param string $name       Block name.
	 * @param array  $attrs      Delimiter attributes.
	 * @param string $inner_html Rendered markup.
	 * @return array
	 */
	private function blk( string $name, array $attrs, string $inner_html ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => $inner_html,
			'innerContent' => array( $inner_html ),
			'innerBlocks'  => array(),
		);
	}

	private function reader(): Block_Reader {
		$ref = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$ref->setAccessible( true );
		return $ref->getValue( $this->crud );
	}

	// ── derive_sourced_attributes ─────────────────────────────────────────

	/**
	 * Fresh-derives the sourced attribute from new markup, ignoring any prior
	 * delimiter value — the recompute a re-synced innerHTML edit depends on.
	 */
	public function test_derive_returns_sourced_content_from_markup() {
		$derived = $this->reader()->derive_sourced_attributes( 'test/heading-like', '<h2>Fresh text</h2>' );
		$this->assertSame( 'Fresh text', $derived['content'] ?? null );
	}

	/**
	 * When the markup matches no sourced selector there is nothing to derive,
	 * so the orchestrator has no safe value to apply and must fall back.
	 */
	public function test_derive_returns_empty_when_markup_has_no_match() {
		$derived = $this->reader()->derive_sourced_attributes( 'test/heading-like', '<p>not a heading</p>' );
		$this->assertArrayNotHasKey( 'content', $derived );
	}

	// ── is_innerhtml_rederivable discriminator ────────────────────────────

	public function test_rederivable_true_for_simple_sourced_block() {
		$inv = new Block_Inventory();
		$this->assertTrue( $inv->is_innerhtml_rederivable( 'test/heading-like', array( 'level' => 2 ) ) );
	}

	/**
	 * A non-presentational array attribute with no innerHTML source is the
	 * delimiter-only-data signal — never safe to auto-sync.
	 */
	public function test_rederivable_false_when_delimiter_only_array_present() {
		$inv = new Block_Inventory();
		$attrs = array( 'questions' => array( array( 'q' => 'x' ) ) );
		$this->assertFalse( $inv->is_innerhtml_rederivable( 'test/faq-like', $attrs ) );
	}

	/**
	 * The `style` object is presentational and must not disqualify an otherwise
	 * simple block from auto-sync.
	 */
	public function test_rederivable_true_despite_style_object() {
		$inv = new Block_Inventory();
		$attrs = array( 'level' => 2, 'style' => array( 'color' => array( 'text' => '#f00' ) ) );
		$this->assertTrue( $inv->is_innerhtml_rederivable( 'test/heading-like', $attrs ) );
	}

	public function test_rederivable_false_for_unregistered_block() {
		$inv = new Block_Inventory();
		$this->assertFalse( $inv->is_innerhtml_rederivable( 'test/unregistered-dual', array() ) );
	}

	public function test_rederivable_false_when_no_sourced_attribute() {
		$inv = new Block_Inventory();
		$this->assertFalse( $inv->is_innerhtml_rederivable( 'test/opaque', array( 'data' => 'x' ) ) );
	}

	// ── update_block ──────────────────────────────────────────────────────

	/**
	 * innerHTML-only update on a simple dual block syncs the derived content and
	 * preserves untouched scalar attributes (`level`), instead of rejecting.
	 */
	public function test_update_block_auto_derives_simple_dual() {
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/heading-like', array( 'level' => 2, 'content' => 'Old' ), '<h2>Old</h2>' ),
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<h2>New text</h2>' );

		$this->assertNotWPError( $result );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'New text', $tree[0]['attrs']['content'] );
		$this->assertSame( 2, $tree[0]['attrs']['level'], 'untouched scalar attr preserved' );
		$this->assertStringContainsString( '<h2>New text</h2>', $tree[0]['innerHTML'] );
	}

	/**
	 * A `style` object on the block does not block auto-sync and survives it.
	 */
	public function test_update_block_auto_derives_with_style_preserved() {
		$style   = array( 'color' => array( 'text' => '#ff0000' ) );
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/heading-like', array( 'level' => 2, 'style' => $style, 'content' => 'Old' ), '<h2>Old</h2>' ),
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<h2>Restyled</h2>' );

		$this->assertNotWPError( $result );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Restyled', $tree[0]['attrs']['content'] );
		$this->assertSame( $style, $tree[0]['attrs']['style'], 'presentational style preserved' );
	}

	/**
	 * The delimiter-only-data block still refuses an innerHTML-only edit, and the
	 * structured `questions[]` is left exactly as it was. Protection pin.
	 */
	public function test_update_block_still_rejects_delimiter_only_dual() {
		$questions = array( array( 'q' => 'Why?', 'a' => 'Because.' ) );
		$post_id   = $this->make_block_post( array(
			$this->blk( 'test/faq-like', array( 'questions' => $questions ), '<h3>FAQ</h3>' ),
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<h3>Changed</h3>' );

		$this->assertWPError( $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( $questions, $tree[0]['attrs']['questions'], 'structured data untouched' );
	}

	/**
	 * When nothing derives from the supplied markup we do not silently allow the
	 * write — the dual-storage rejection stands.
	 */
	public function test_update_block_rejects_when_nothing_derivable() {
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/heading-like', array( 'level' => 2, 'content' => 'Old' ), '<h2>Old</h2>' ),
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<p>no heading here</p>' );

		$this->assertWPError( $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );
	}

	// ── update_blocks_batch ───────────────────────────────────────────────

	public function test_batch_auto_derives_simple_dual_item() {
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/heading-like', array( 'level' => 2, 'content' => 'Old' ), '<h2>Old</h2>' ),
		) );

		$result = $this->crud->update_blocks_batch(
			$post_id,
			array( array( 'flat_index' => 0, 'innerHTML' => '<h2>Batch new</h2>' ) ),
			true
		);

		$this->assertNotWPError( $result );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Batch new', $tree[0]['attrs']['content'] );
	}

	/**
	 * One non-re-derivable item aborts the whole batch with no writes, exactly
	 * like any other item-level dual-storage failure.
	 */
	public function test_batch_aborts_when_any_item_not_rederivable() {
		$questions = array( array( 'q' => 'Why?' ) );
		$post_id   = $this->make_block_post( array(
			$this->blk( 'test/heading-like', array( 'level' => 2, 'content' => 'Old' ), '<h2>Old</h2>' ),
			$this->blk( 'test/faq-like', array( 'questions' => $questions ), '<h3>FAQ</h3>' ),
		) );

		$result = $this->crud->update_blocks_batch(
			$post_id,
			array(
				array( 'flat_index' => 0, 'innerHTML' => '<h2>Would sync</h2>' ),
				array( 'flat_index' => 1, 'innerHTML' => '<h3>Would desync</h3>' ),
			),
			true
		);

		$this->assertWPError( $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Old', $tree[0]['attrs']['content'], 'no partial write on the good item' );
		$this->assertSame( $questions, $tree[1]['attrs']['questions'] );
	}

	// ── mutator update-html ───────────────────────────────────────────────

	public function test_mutator_update_html_auto_derives_simple_dual() {
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/heading-like', array( 'level' => 2, 'content' => 'Old' ), '<h2>Old</h2>' ),
		) );

		$result = $this->mutator->mutate( $post_id, 'update-html', array( 0 ), array( 'innerHTML' => '<h2>Mutated</h2>' ) );

		$this->assertNotWPError( $result );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Mutated', $tree[0]['attrs']['content'] );
	}

	public function test_mutator_update_html_still_rejects_delimiter_only_dual() {
		$questions = array( array( 'q' => 'Why?' ) );
		$post_id   = $this->make_block_post( array(
			$this->blk( 'test/faq-like', array( 'questions' => $questions ), '<h3>FAQ</h3>' ),
		) );

		$result = $this->mutator->mutate( $post_id, 'update-html', array( 0 ), array( 'innerHTML' => '<h3>Changed</h3>' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );
	}

	// ── Structured-attribute clobber (adversarial finding) ────────────────

	/**
	 * A structured (array/object) attribute is never re-derivable even when it
	 * declares an innerHTML source, because every source resolves to a string.
	 * The discriminator must reject it so array_merge can't overwrite the array.
	 */
	public function test_rederivable_false_for_structured_attr_with_source() {
		$inv   = new Block_Inventory();
		$attrs = array( 'items' => array( array( 'label' => 'Apple' ), array( 'label' => 'Pear' ) ) );
		$this->assertFalse( $inv->is_innerhtml_rederivable( 'test/list-like', $attrs ) );
	}

	/**
	 * An innerHTML-only edit to a block whose sourced attribute is an ARRAY must
	 * be rejected, not auto-synced — otherwise the derived string clobbers the
	 * canonical array and the structured data is lost. This is the exact
	 * data-loss the adversarial verification caught.
	 */
	public function test_update_block_rejects_structured_sourced_attr_and_preserves_array() {
		$items   = array( array( 'label' => 'Apple', 'url' => '/a' ), array( 'label' => 'Pear', 'url' => '/p' ) );
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/list-like', array( 'items' => $items ), '<ul><li>Apple</li><li>Pear</li></ul>' ),
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<ul><li>Cherry</li></ul>' );

		$this->assertWPError( $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( $items, $tree[0]['attrs']['items'], 'structured array must survive intact' );
	}

	// ── Bound-attribute guard on the derive paths (adversarial finding) ───

	/**
	 * Build a simple dual block whose `content` is bound via Block Bindings.
	 *
	 * @param string $inner_html Rendered markup.
	 * @return array
	 */
	private function bound_heading( string $inner_html ): array {
		return $this->blk(
			'test/heading-like',
			array(
				'level'    => 2,
				'content'  => 'Bound',
				'metadata' => array( 'bindings' => array( 'content' => array( 'source' => 'core/post-meta', 'args' => array( 'key' => 'k' ) ) ) ),
			),
			$inner_html
		);
	}

	/**
	 * The batch derive path must run the same bound-write guard as update_block,
	 * so an auto-derived `content` can't overwrite a `content` binding.
	 */
	public function test_batch_guards_bound_derived_attribute() {
		$post_id = $this->make_block_post( array( $this->bound_heading( '<h2>Bound</h2>' ) ) );

		$result = $this->crud->update_blocks_batch(
			$post_id,
			array( array( 'flat_index' => 0, 'innerHTML' => '<h2>Overwrite</h2>' ) ),
			true
		);

		$this->assertWPError( $result );
		$this->assertSame( 'batch_validation_failed', $result->get_error_code() );
		$errors = $result->get_error_data()['errors'] ?? array();
		$this->assertSame( 'bound_attribute', $errors[0]['code'] ?? null );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Bound', $tree[0]['attrs']['content'], 'bound value not clobbered' );
	}

	/**
	 * The mutator update-html derive path must run the same bound-write guard.
	 */
	public function test_mutator_guards_bound_derived_attribute() {
		$post_id = $this->make_block_post( array( $this->bound_heading( '<h2>Bound</h2>' ) ) );

		$result = $this->mutator->mutate( $post_id, 'update-html', array( 0 ), array( 'innerHTML' => '<h2>Overwrite</h2>' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'bound_attribute', $result->get_error_code() );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Bound', $tree[0]['attrs']['content'], 'bound value not clobbered' );
	}

	// ── Codex review hardening ────────────────────────────────────────────

	/**
	 * Defense in depth: the block grammar only admits object attrs, so
	 * parse_blocks yields array|null, never a scalar. A hand-built block array
	 * (mutator input, build_block_from_def) could still carry a scalar, so the
	 * public helpers must treat a non-array attrs as "not safe to derive" rather
	 * than fatal on their array type hints.
	 */
	public function test_derive_helpers_tolerate_non_array_attrs() {
		$this->assertNull( $this->crud->auto_derive_dual_attributes( 'test/heading-like', 'scalar', '<h2>x</h2>' ) );
		$this->assertNull( $this->crud->reject_bound_write( array( 'content' => 'x' ), 'scalar', false ) );
	}

	/**
	 * The reachable malformed case: a block whose delimiter JSON is invalid
	 * parses with attrs = null. The isset() ternary at each call site resolves
	 * that to array(), so an innerHTML-only edit auto-syncs cleanly, no fatal.
	 */
	public function test_update_block_null_attrs_does_not_crash() {
		$content = '<!-- wp:test/heading-like {bad json} --><h2>Old</h2><!-- /wp:test/heading-like -->';
		$post_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => $content,
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<h2>New</h2>' );

		$this->assertNotWPError( $result );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'New', $tree[0]['attrs']['content'] );
	}

	/**
	 * An EMPTY sourced array attribute is still structured: re-deriving would
	 * write a string into an array slot. The discriminator must reject it on the
	 * schema shape, not just on a non-empty current value.
	 */
	public function test_update_block_rejects_empty_sourced_array_attr() {
		$post_id = $this->make_block_post( array(
			$this->blk( 'test/list-like', array( 'items' => array() ), '<ul></ul>' ),
		) );

		$result = $this->crud->update_block( $post_id, 0, array(), '<ul><li>New</li></ul>' );

		$this->assertWPError( $result );
		$this->assertSame( 'dual_storage_requires_both', $result->get_error_code() );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( array(), $tree[0]['attrs']['items'], 'array-typed attr must not become a string' );
	}

	/**
	 * Batch parity: `allow_bound_writes` on an item forces a bound derived write
	 * through, the same escape hatch update_block and the mutator honor.
	 */
	public function test_batch_honors_allow_bound_writes_on_derived_item() {
		$post_id = $this->make_block_post( array( $this->bound_heading( '<h2>Bound</h2>' ) ) );

		$result = $this->crud->update_blocks_batch(
			$post_id,
			array( array( 'flat_index' => 0, 'innerHTML' => '<h2>Forced</h2>', 'allow_bound_writes' => true ) ),
			true
		);

		$this->assertNotWPError( $result );
		$tree = $this->block_tree_visible( $post_id );
		$this->assertSame( 'Forced', $tree[0]['attrs']['content'], 'forced bound write applied' );
	}
}
