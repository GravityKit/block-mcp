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
}
