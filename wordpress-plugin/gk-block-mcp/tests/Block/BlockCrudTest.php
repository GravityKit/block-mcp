<?php
/**
 * Tests for the Block_CRUD class.
 *
 * Covers format_blocks, validate_block_def, get_blocks, update_block,
 * insert_blocks, delete_blocks, replace_all_blocks, save_post_content,
 * and rate limiting.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;

require_once __DIR__ . '/RateLimitLockWpdbDouble.php';

class BlockCrudTest extends BlockApiTestCase {

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->post_id = $this->make_block_post();
	}

	// ── Helpers ────────────────────────────────────────────────────

	private function make_post( array $blocks ): void {
		wp_update_post( array(
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( $blocks ),
		) );
	}

	private function current_blocks(): array {
		return parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
	}

	private function block( string $name, array $attrs = array(), string $html = '', array $children = array() ): array {
		if ( ! empty( $children ) ) {
			$opening = $html !== '' ? $html : '<div>';
			$closing = '</div>';
			$content = array_merge(
				array( $opening ),
				array_fill( 0, count( $children ), null ),
				array( $closing )
			);
			return array(
				'blockName'    => $name,
				'attrs'        => $attrs,
				'innerHTML'    => $opening . $closing,
				'innerContent' => $content,
				'innerBlocks'  => $children,
			);
		}
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerHTML'    => $html,
			'innerContent' => $html !== '' ? array( $html ) : array(),
			'innerBlocks'  => array(),
		);
	}

	// ── format_blocks ──────────────────────────────────────────────

	public function test_format_blocks_includes_path_and_index() {
		$blocks = array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
		);
		$formatted = $this->crud->format_blocks( $blocks );

		$this->assertCount( 2, $formatted );
		$this->assertEquals( 0, $formatted[0]['index'] );
		$this->assertEquals( array( 0 ), $formatted[0]['path'] );
		$this->assertEquals( 'core/paragraph', $formatted[0]['name'] );
		$this->assertEquals( 1, $formatted[1]['index'] );
		$this->assertEquals( array( 1 ), $formatted[1]['path'] );
	}

	public function test_format_blocks_skips_empty_blocks() {
		// Whitespace-only block has empty blockName.
		$blocks = array(
			array( 'blockName' => null, 'attrs' => array(), 'innerHTML' => "\n\n", 'innerContent' => array( "\n\n" ), 'innerBlocks' => array() ),
			$this->block( 'core/paragraph', array(), '<p>Real</p>' ),
		);
		$formatted = $this->crud->format_blocks( $blocks );

		$this->assertCount( 1, $formatted );
		$this->assertEquals( 'core/paragraph', $formatted[0]['name'] );
		// Path preserves raw indices — skipped block is still at raw position 0.
		$this->assertEquals( array( 1 ), $formatted[0]['path'] );
	}

	public function test_format_blocks_nested_paths() {
		$blocks = array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>Child</p>' ),
				$this->block( 'core/paragraph', array(), '<p>Child 2</p>' ),
			) ),
		);
		$formatted = $this->crud->format_blocks( $blocks );

		$this->assertEquals( array( 0 ), $formatted[0]['path'] );
		$this->assertCount( 2, $formatted[0]['innerBlocks'] );
		$this->assertEquals( array( 0, 0 ), $formatted[0]['innerBlocks'][0]['path'] );
		$this->assertEquals( array( 0, 1 ), $formatted[0]['innerBlocks'][1]['path'] );
		// Flat index continues across depth.
		$this->assertEquals( 0, $formatted[0]['index'] );
		$this->assertEquals( 1, $formatted[0]['innerBlocks'][0]['index'] );
		$this->assertEquals( 2, $formatted[0]['innerBlocks'][1]['index'] );
	}

	public function test_format_blocks_includes_section_name_from_metadata() {
		$blocks = array(
			$this->block( 'core/group', array( 'metadata' => array( 'name' => 'Hero' ) ), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>X</p>' ),
			) ),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertEquals( 'Hero', $formatted[0]['section'] );
	}

	public function test_format_blocks_includes_dynamic_flag() {
		$blocks = array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) );
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertArrayHasKey( 'dynamic', $formatted[0] );
		$this->assertFalse( $formatted[0]['dynamic'] );
	}

	public function test_format_blocks_synced_pattern_ref() {
		$pattern_id = self::factory()->post->create( array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_title'   => 'Pattern Seven',
			'post_content' => '',
		) );

		$blocks = array(
			array(
				'blockName'    => 'core/block',
				'attrs'        => array( 'ref' => $pattern_id ),
				'innerHTML'    => '',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		);
		$formatted = $this->crud->format_blocks( $blocks );

		$this->assertArrayHasKey( 'pattern_ref', $formatted[0] );
		$this->assertEquals( $pattern_id, $formatted[0]['pattern_ref']['id'] );
		$this->assertEquals( 'Pattern Seven', $formatted[0]['pattern_ref']['name'] );
	}

	public function test_format_blocks_empty_returns_empty_array() {
		$this->assertEquals( array(), $this->crud->format_blocks( array() ) );
	}

	/**
	 * A block whose namespace has no registered block type is flagged orphaned.
	 *
	 * When a block's provider plugin or theme is not active, WordPress cannot
	 * render the block properly. The reader sets preference.orphaned so an agent
	 * editing the page knows the block's source is missing rather than treating
	 * it as a normal acceptable-tier block. ghostpkg is not registered in this
	 * suite, so its block must carry the flag.
	 */
	public function test_format_blocks_flags_orphaned_block_for_unregistered_namespace() {
		$formatted = $this->crud->format_blocks(
			array( $this->block( 'ghostpkg/widget', array(), '<div>orphan</div>' ) )
		);

		$this->assertArrayHasKey( 'preference', $formatted[0] );
		$this->assertArrayHasKey( 'orphaned', $formatted[0]['preference'] );
		$this->assertTrue( $formatted[0]['preference']['orphaned'] );
	}

	/**
	 * A block whose namespace is registered is never flagged orphaned.
	 *
	 * core is registered and preferred, so it gets no preference block at all and
	 * therefore no orphaned flag. stackable is registered but scored avoid, so it
	 * gets a preference block whose orphaned key must stay absent.
	 */
	public function test_format_blocks_does_not_flag_registered_namespace_as_orphaned() {
		$formatted = $this->crud->format_blocks(
			array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
				$this->block( 'stackable/heading', array(), '<h2>B</h2>' ),
			)
		);

		$this->assertArrayNotHasKey( 'preference', $formatted[0], 'a registered preferred block carries no preference block' );
		$this->assertArrayHasKey( 'preference', $formatted[1], 'a registered avoid-tier block carries a preference block' );
		$this->assertArrayNotHasKey( 'orphaned', $formatted[1]['preference'], 'a registered namespace must not be flagged orphaned' );
	}

	// ── text_preview ───────────────────────────────────────────────

	public function test_text_preview_stripped_tags() {
		$blocks = array(
			$this->block( 'core/paragraph', array(), '<p><strong>Hello</strong> world</p>' ),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertArrayHasKey( 'text_preview', $formatted[0] );
		$this->assertEquals( 'Hello world', $formatted[0]['text_preview'] );
	}

	public function test_text_preview_decoded_entities() {
		$blocks = array(
			$this->block(
				'core/paragraph',
				array(),
				'<p>Tom &amp; Jerry&nbsp;say&#8217;hi</p>'
			),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertArrayHasKey( 'text_preview', $formatted[0] );
		// &amp; → &, &nbsp; → non-breaking space (normalized to space), &#8217; → ’
		$this->assertStringContainsString( 'Tom & Jerry', $formatted[0]['text_preview'] );
		$this->assertStringContainsString( "\xE2\x80\x99", $formatted[0]['text_preview'] ); // right single quote
	}

	public function test_text_preview_truncated_to_100_chars() {
		$long = str_repeat( 'a', 250 );
		$blocks = array(
			$this->block( 'core/paragraph', array(), '<p>' . $long . '</p>' ),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertArrayHasKey( 'text_preview', $formatted[0] );
		$this->assertEquals( 100, mb_strlen( $formatted[0]['text_preview'] ) );
	}

	public function test_text_preview_not_present_for_empty_innerHTML() {
		// core/block with empty innerHTML
		$blocks = array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertArrayNotHasKey( 'text_preview', $formatted[0] );
	}

	public function test_text_preview_collapses_whitespace() {
		$multiline = "<p>Line one\n\nLine two\t\tLine\tthree</p>";
		$blocks = array(
			$this->block( 'core/paragraph', array(), $multiline ),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		$this->assertArrayHasKey( 'text_preview', $formatted[0] );
		// Whitespace runs collapsed to single spaces.
		$this->assertEquals( 'Line one Line two Line three', $formatted[0]['text_preview'] );
		// No newlines or tabs remain.
		$this->assertDoesNotMatchRegularExpression( '/[\r\n\t]/', $formatted[0]['text_preview'] );
	}

	public function test_text_preview_not_present_when_only_whitespace() {
		// innerHTML that produces empty preview after stripping/trimming.
		$blocks = array(
			$this->block( 'core/paragraph', array(), '<p>   </p>' ),
		);
		$formatted = $this->crud->format_blocks( $blocks );
		// Preview is empty after strip → trim, so key is not set.
		$this->assertArrayNotHasKey( 'text_preview', $formatted[0] );
	}

	// ── validate_block_def ─────────────────────────────────────────

	public function test_validate_block_def_empty_name_rejects() {
		// Previously empty name silently passed; serialize_blocks would then
		// drop the resulting block (blockName='' produces no output), so the
		// agent's insert appeared to succeed but nothing landed on disk.
		$result = $this->crud->validate_block_def( '' );
		$this->assertInstanceOf( \WP_Error::class, $result['error'] );
		$this->assertSame( 'invalid_block', $result['error']->get_error_code() );
	}

	public function test_validate_block_def_core_passes_silently() {
		$result = $this->crud->validate_block_def( 'core/paragraph' );
		$this->assertNull( $result['error'] );
		$this->assertEmpty( $result['warnings'] );
	}

	public function test_validate_block_def_unregistered_errors() {
		$result = $this->crud->validate_block_def( 'totally/nonexistent-block' );
		$this->assertInstanceOf( \WP_Error::class, $result['error'] );
		$this->assertEquals( 'invalid_block', $result['error']->get_error_code() );
	}

	public function test_validate_block_def_legacy_errors() {
		$result = $this->crud->validate_block_def( 'ugb/text' );
		$this->assertInstanceOf( \WP_Error::class, $result['error'] );
		$this->assertEquals( 'legacy_block', $result['error']->get_error_code() );
		// Error message should mention replacement.
		$this->assertStringContainsString( 'core/paragraph', $result['error']->get_error_message() );
	}

	public function test_validate_block_def_avoid_warns() {
		$result = $this->crud->validate_block_def( 'stackable/heading' );
		$this->assertNull( $result['error'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertEquals( 'stackable/heading', $result['warnings'][0]['block'] );
		$this->assertEquals( 'core/heading', $result['warnings'][0]['suggested_replacement'] );
	}

	// ── get_blocks ─────────────────────────────────────────────────

	public function test_get_blocks_returns_formatted_blocks() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>Hi</p>' ),
		) );
		$result = $this->crud->get_blocks( $this->post_id );
		$this->assertIsArray( $result );
		$this->assertCount( 1, $result );
		$this->assertEquals( 'core/paragraph', $result[0]['name'] );
	}

	public function test_get_blocks_post_not_found() {
		$result = $this->crud->get_blocks( 999999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	public function test_get_blocks_empty_content_returns_empty() {
		$empty_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '',
		) );
		$result = $this->crud->get_blocks( $empty_id );
		$this->assertEquals( array(), $result );
	}

	// ── update_block ───────────────────────────────────────────────

	public function test_update_block_merges_attributes() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array( 'align' => 'left' ), '<p>A</p>' ),
		) );
		$result = $this->crud->update_block( $this->post_id, 0, array( 'fontSize' => 'large' ), null );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 'left', $saved[0]['attrs']['align'] );
		$this->assertEquals( 'large', $saved[0]['attrs']['fontSize'] );
	}

	public function test_update_block_replaces_inner_html() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>Old</p>' ),
		) );
		$result = $this->crud->update_block( $this->post_id, 0, array(), '<p>New</p>' );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>New</p>', $saved[0]['innerHTML'] );
	}

	/**
	 * The write field `is_dynamic` (update_block's `saved`) and the read field
	 * `dynamic` (format_blocks) must agree for one block, including an orphan
	 * block whose namespace has no registered provider. Both now derive from the
	 * single authority Block_CRUD::is_block_dynamic (Block_Inventory), which
	 * classifies an unregistered block as static. update_block formerly read the
	 * field from Block_Safety, which reports an unknown block as dynamic for its
	 * own warning-suppression purpose, so the read said static while the write
	 * said dynamic for the very same block.
	 */
	public function test_is_dynamic_write_and_read_agree_for_orphan_block() {
		$this->make_post( array(
			$this->block( 'ghost/widget', array(), '<div>orphan</div>' ),
		) );

		$formatted    = $this->crud->format_blocks( $this->current_blocks() );
		$read_dynamic = $formatted[0]['dynamic'];

		$result = $this->crud->update_block( $this->post_id, 0, array( 'foo' => 'bar' ), null );
		$this->assertTrue( $result['success'] );
		$write_dynamic = $result['saved']['is_dynamic'];

		$this->assertFalse( $read_dynamic, 'An orphan block reads as not dynamic.' );
		$this->assertSame( $read_dynamic, $write_dynamic, 'is_dynamic (write) and dynamic (read) must agree for one block.' );
	}

	/**
	 * update_block's `saved` snapshot must reflect the persisted (normalized)
	 * block after a gk/block-mcp/block/normalize repair.
	 *
	 * Contract pin: update_block now rebuilds the response from the persisted node
	 * in $result['blocks'] (like update_blocks_batch) rather than reading its local
	 * $block, whose match with post_content otherwise relies on fragile PHP
	 * reference aliasing into the saved tree. This pins that `saved` carries the
	 * normalizer's change so a future edit that breaks the aliasing is caught.
	 */
	public function test_update_block_saved_reflects_normalized_persisted_content() {
		$normalizer = static function ( $block, $name ) {
			if ( 'core/paragraph' === $name && isset( $block['innerHTML'] ) ) {
				$block['innerHTML']    = str_replace( '</p>', ' [normalized]</p>', (string) $block['innerHTML'] );
				$block['innerContent'] = array( $block['innerHTML'] );
			}
			return $block;
		};
		add_filter( 'gk/block-mcp/block/normalize', $normalizer, 10, 2 );

		// A NESTED paragraph: save_blocks reassigns the parent group during
		// normalize, breaking update_block's reference into the child, so the
		// pre-normalization node no longer tracks the persisted content.
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div>', array(
				$this->block( 'core/paragraph', array(), '<p>child</p>' ),
			) ),
		) );
		$result = $this->crud->update_block( $this->post_id, 1, array(), '<p>updated</p>' );

		remove_filter( 'gk/block-mcp/block/normalize', $normalizer, 10 );

		$this->assertTrue( $result['success'] );
		$this->assertStringContainsString(
			'[normalized]',
			get_post_field( 'post_content', $this->post_id ),
			'precondition: the normalizer must have changed the persisted content'
		);
		$this->assertStringContainsString(
			'[normalized]',
			$result['saved']['inner_html'],
			'saved.inner_html must reflect the normalized, persisted block'
		);
	}

	public function test_update_block_invalid_index_error() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->crud->update_block( $this->post_id, 99, array( 'align' => 'left' ), null );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_index', $result->get_error_code() );
	}

	public function test_update_block_post_not_found() {
		$result = $this->crud->update_block( 999999, 0, array( 'a' => 'b' ), null );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	public function test_update_block_uses_flat_index_for_nested() {
		// Flat index 1 should target the nested child.
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>Child</p>' ),
			) ),
		) );
		$result = $this->crud->update_block( $this->post_id, 1, array(), '<p>Changed</p>' );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>Changed</p>', $saved[0]['innerBlocks'][0]['innerHTML'] );
	}

	// ── update_blocks_batch ────────────────────────────────────────

	public function test_update_blocks_batch_applies_all_in_one_revision() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>One</p>' ),
			$this->block( 'core/paragraph', array(), '<p>Two</p>' ),
			$this->block( 'core/paragraph', array(), '<p>Three</p>' ),
		) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'flat_index' => 0, 'innerHTML' => '<p>1</p>' ),
			array( 'flat_index' => 1, 'innerHTML' => '<p>2</p>' ),
			array( 'flat_index' => 2, 'innerHTML' => '<p>3</p>' ),
		) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 3, $result['count'] );
		$this->assertCount( 3, $result['results'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>1</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>2</p>', $saved[1]['innerHTML'] );
		$this->assertEquals( '<p>3</p>', $saved[2]['innerHTML'] );
		// Single revision regardless of N — exposed via revision_id == before_revision_id + 1
		// in the test bootstrap. Just assert presence here.
		$this->assertArrayHasKey( 'revision_id', $result );
		$this->assertArrayHasKey( 'before_revision_id', $result );
	}

	/**
	 * A batch item that writes a bound attribute directly must be rejected, the
	 * same as the single-block path — not silently clobbered.
	 *
	 * update_block guards the final attributes with reject_bound_write
	 * unconditionally, but the batch ran that guard only inside the dual-storage
	 * derive branch (gated on empty attributes), so a batch item supplying
	 * attributes directly skipped it and overwrote a bound attribute. This pins
	 * that a direct bound-attribute write fails the batch and leaves the value
	 * intact.
	 */
	public function test_update_blocks_batch_guards_bound_attributes_on_direct_write() {
		$this->make_post( array(
			$this->block(
				'core/paragraph',
				array(
					'content'  => 'ORIGINAL',
					'metadata' => array( 'bindings' => array( 'content' => array( 'source' => 'core/post-meta' ) ) ),
				),
				'<p>ORIGINAL</p>'
			),
		) );

		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'flat_index' => 0, 'attributes' => array( 'content' => 'HACKED' ) ),
		) );

		$this->assertInstanceOf( \WP_Error::class, $result, 'a direct bound-attribute write must fail the batch' );
		$this->assertEquals( 'batch_validation_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 'bound_attribute', $data['errors'][0]['code'], 'the item error must be the bound-attribute rejection' );

		$saved = $this->current_blocks();
		$this->assertEquals( 'ORIGINAL', $saved[0]['attrs']['content'], 'the bound attribute must not be clobbered' );
	}

	public function test_update_blocks_batch_rejects_empty_updates() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'empty_batch', $result->get_error_code() );
	}

	public function test_update_blocks_batch_rejects_oversized_batch() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$updates = array_fill( 0, Block_CRUD::MAX_BATCH_SIZE + 1, array(
			'flat_index' => 0,
			'innerHTML'  => '<p>x</p>',
		) );
		$result = $this->crud->update_blocks_batch( $this->post_id, $updates );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'batch_too_large', $result->get_error_code() );
	}

	public function test_update_blocks_batch_aborts_on_any_invalid_item() {
		// One valid + one out-of-range; whole batch must reject.
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>One</p>' ),
		) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'flat_index' => 0,  'innerHTML' => '<p>NEW</p>' ),
			array( 'flat_index' => 99, 'innerHTML' => '<p>BAD</p>' ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'batch_validation_failed', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data['errors'] );
		$this->assertCount( 1, $data['errors'] );
		$this->assertEquals( 1, $data['errors'][0]['index'] );
		$this->assertEquals( 'invalid_index', $data['errors'][0]['code'] );
		// The valid item must NOT have been applied — atomicity.
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>One</p>', $saved[0]['innerHTML'] );
	}

	public function test_update_blocks_batch_rejects_duplicate_target_path() {
		// Two items targeting the same block (one by ref, one by flat_index)
		// must be flagged as a duplicate.
		$this->make_post( array(
			$this->block(
				'core/paragraph',
				array( 'metadata' => array( 'gk_ref' => 'blk_aaaa1111' ) ),
				'<p>One</p>'
			),
		) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'ref' => 'blk_aaaa1111', 'innerHTML' => '<p>X</p>' ),
			array( 'flat_index' => 0,       'innerHTML' => '<p>Y</p>' ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'batch_validation_failed', $result->get_error_code() );
		$codes = array_column( $result->get_error_data()['errors'], 'code' );
		$this->assertContains( 'duplicate_target', $codes );
	}

	public function test_update_blocks_batch_rejects_missing_payload() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'flat_index' => 0 ), // neither attributes nor innerHTML
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'batch_validation_failed', $result->get_error_code() );
		$this->assertEquals( 'missing_payload', $result->get_error_data()['errors'][0]['code'] );
	}

	public function test_update_blocks_batch_rejects_both_ref_and_flat_index() {
		$this->make_post( array(
			$this->block(
				'core/paragraph',
				array( 'metadata' => array( 'gk_ref' => 'blk_bbbb2222' ) ),
				'<p>A</p>'
			),
		) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'ref' => 'blk_bbbb2222', 'flat_index' => 0, 'innerHTML' => 'x' ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'batch_validation_failed', $result->get_error_code() );
		$this->assertEquals( 'invalid_target', $result->get_error_data()['errors'][0]['code'] );
	}

	public function test_update_blocks_batch_resolves_refs_to_correct_blocks() {
		// Two blocks with different refs; batch routes each update correctly.
		$this->make_post( array(
			$this->block(
				'core/paragraph',
				array( 'metadata' => array( 'gk_ref' => 'blk_first' ) ),
				'<p>One</p>'
			),
			$this->block(
				'core/paragraph',
				array( 'metadata' => array( 'gk_ref' => 'blk_second' ) ),
				'<p>Two</p>'
			),
		) );
		$result = $this->crud->update_blocks_batch( $this->post_id, array(
			array( 'ref' => 'blk_second', 'innerHTML' => '<p>SECOND</p>' ),
			array( 'ref' => 'blk_first',  'innerHTML' => '<p>FIRST</p>' ),
		) );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>FIRST</p>',  $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>SECOND</p>', $saved[1]['innerHTML'] );
	}

	public function test_update_blocks_batch_post_not_found() {
		$result = $this->crud->update_blocks_batch( 999999, array(
			array( 'flat_index' => 0, 'innerHTML' => 'x' ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	// ── insert_blocks ──────────────────────────────────────────────

	public function test_insert_blocks_appends_when_position_is_null() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		$this->assertEquals( '<p>NEW</p>', $saved[1]['innerHTML'] );
	}

	public function test_insert_blocks_at_start() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			'start',
			array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		$this->assertEquals( '<p>NEW</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>A</p>', $saved[1]['innerHTML'] );
	}

	/**
	 * Position -1 is the documented "append" sentinel (matches the MCP
	 * server's `after_top_level: -1` / `insert_pattern` position contract)
	 * and must keep working as append, not be swept up by rejecting other
	 * negative positions.
	 */
	public function test_insert_blocks_position_negative_one_still_appends() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			-1,
			array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		$this->assertEquals( '<p>NEW</p>', $saved[1]['innerHTML'] );
	}

	/**
	 * A position more negative than the documented -1 "append" sentinel has
	 * no defined meaning and was previously silently clamped to a prepend
	 * (array_splice at 0) instead of erroring — surprising an agent that
	 * passed a bad value. It must now be rejected with a 400 error, the same
	 * style update_block/delete_blocks use for an out-of-range index.
	 */
	public function test_insert_blocks_rejects_position_more_negative_than_append_sentinel() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			-2,
			array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_position', $result->get_error_code() );
		$this->assertCount( 1, $this->current_blocks(), 'the rejected insert must not have modified the post' );
	}

	public function test_insert_blocks_legacy_rejected() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'ugb/text', 'innerHTML' => '<p>x</p>' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'legacy_block', $result->get_error_code() );
	}

	public function test_insert_blocks_avoid_produces_warning() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'stackable/heading', 'innerHTML' => '<h2>X</h2>' ) )
		);
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_insert_blocks_unregistered_block_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'nonexistent/block' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_block', $result->get_error_code() );
	}

	/**
	 * Empty class="" / class=' ' attributes get stripped from innerHTML on insert.
	 *
	 * Live audit on /assist/ surfaced nine paragraph blocks stored as
	 * `<p class="">...</p>` while their attributes had nothing that would
	 * make save() emit a class attribute. On the next edit Gutenberg's
	 * parser reads `class=""`, save() produces `<p>...</p>`, the two
	 * disagree, and "Block contains unexpected or invalid content" fires.
	 *
	 * `class=""` is never legitimate Gutenberg save() output — the JS
	 * `useBlockProps.save()` helper omits the class attribute entirely
	 * when there are no classes to emit. So normalising it out on the
	 * write path is information-preserving (an empty class attribute is
	 * semantically identical to no class attribute in HTML) and prevents
	 * the round-trip mismatch on next reload.
	 */
	public function test_insert_blocks_strips_empty_class_attribute_from_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'      => 'core/paragraph',
				'innerHTML' => '<p class="">Hello world</p>',
			) )
		);
		$this->assertTrue( $result['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( '<p>Hello world</p>', $post_content );
		$this->assertStringNotContainsString( 'class=""', $post_content );
	}

	/**
	 * Whitespace-only class values are equally invalid and must be stripped.
	 *
	 * Same rationale as the empty-string case — `<p class="   ">…</p>` parses
	 * to a class list of [], so save() will not reproduce it, and on next
	 * reload the editor flags the block as invalid.
	 */
	public function test_insert_blocks_strips_whitespace_only_class_attribute_from_inner_html() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'      => 'core/paragraph',
				'innerHTML' => "<p class=\"   \">Whitespace class</p>",
			) )
		);
		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( '<p>Whitespace class</p>', $post_content );
		$this->assertStringNotContainsString( 'class="', $post_content );
	}

	/**
	 * Real classes must NOT be touched by the empty-class normalisation.
	 *
	 * Negative-space test guarding against the strip being too aggressive.
	 */
	public function test_insert_blocks_preserves_non_empty_class_attribute() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'      => 'core/paragraph',
				'innerHTML' => '<p class="has-text-align-center">Aligned</p>',
			) )
		);
		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( 'class="has-text-align-center"', $post_content );
	}

	/**
	 * Single-quoted empty class attribute is handled identically.
	 *
	 * Some HTML tooling emits single-quoted attributes; we accept and
	 * strip them with the same logic.
	 */
	public function test_insert_blocks_strips_single_quoted_empty_class_attribute() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'      => 'core/paragraph',
				'innerHTML' => "<p class=''>Single quoted</p>",
			) )
		);
		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringNotContainsString( "class=''", $post_content );
		$this->assertStringNotContainsString( 'class=""', $post_content );
	}

	/**
	 * Empty-class stripping must also apply when update_block replaces the
	 * innerHTML — agents who recover from the bug by patching just the HTML
	 * shouldn't be able to re-introduce the same broken markup.
	 */
	public function test_update_block_strips_empty_class_attribute_from_inner_html() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>Initial</p>' ) ) );
		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array(),
			'<p class="">Replaced</p>'
		);
		$this->assertTrue( $result['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( '<p>Replaced</p>', $post_content );
		$this->assertStringNotContainsString( 'class=""', $post_content );
	}

	/**
	 * Blocks in a legacy-configured namespace surface `legacy_block` even when not registered.
	 *
	 * Preference scoring is namespace-based and resolves from site
	 * configuration regardless of whether the source plugin is installed.
	 * Returning the generic `invalid_block` for an unregistered block whose
	 * namespace is configured as legacy hides the actionable replacement
	 * guidance the agent needs. This test pins the reordered
	 * validate_block_def: legacy tier wins over registry-not-registered.
	 *
	 * Uses an arbitrary block name picked from the default legacy namespace
	 * set in `Preferences::get_defaults()` — change the test fixture if
	 * the default tier configuration changes.
	 */
	public function test_insert_blocks_legacy_namespace_rejects_as_legacy_even_when_not_registered() {
		// Pick the first namespace whose default tier is "legacy" from the
		// shipped preference defaults. Test stays valid as the policy
		// configuration evolves.
		// Legacy is admin-configured now (the shipped defaults are opinion-free), so
		// resolve the legacy namespace from the effective preferences the test base
		// seeds — not from get_defaults(), which no longer brands anything legacy.
		$prefs            = ( new \GravityKit\BlockMCP\Preferences() )->get_preferences();
		$legacy_namespace = null;
		foreach ( ( $prefs['namespace_scores'] ?? array() ) as $ns => $score ) {
			if ( \GravityKit\BlockMCP\Preferences::score_to_tier( $score ) === 'legacy' ) {
				$legacy_namespace = $ns;
				break;
			}
		}
		$this->assertNotNull( $legacy_namespace, 'A legacy namespace must be configured for this test.' );

		$block_name = $legacy_namespace . '/never-installed';
		$registry   = \WP_Block_Type_Registry::get_instance();
		if ( $registry->is_registered( $block_name ) ) {
			$registry->unregister( $block_name );
		}
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => $block_name, 'innerHTML' => '<div>x</div>' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'legacy_block', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( $legacy_namespace, $data['namespace'] );
	}

	/**
	 * Reject attributes-only inserts for blocks whose attribute schema is HTML-sourced.
	 *
	 * Callers (notably MCP agents) frequently send
	 * { name: "core/paragraph", attributes: { content: "Hello" } } and omit
	 * innerHTML. With no innerHTML and no innerBlocks, serialize_blocks() emits
	 * the self-closing form `<!-- wp:paragraph {"content":"Hello"} /-->`.
	 * On reload Gutenberg's rich-text source selector runs against an empty
	 * DOM, returns "", and reports "Block contains unexpected or invalid
	 * content" because the parsed attribute disagrees with the saved comment.
	 * Scaffolding the markup in PHP would re-implement each block's JS save()
	 * and rot whenever core changes — instead we reject with a message that
	 * names the offending attribute, so the caller learns the contract.
	 */
	public function test_insert_blocks_rejects_paragraph_with_content_attr_but_no_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Hello world' ) ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'inner_html_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 'core/paragraph', $data['block'] );
		$this->assertContains( 'content', $data['source_bound_attributes'] );
	}

	/**
	 * Same rejection applies to core/heading — its `content` attribute is
	 * rich-text sourced, so an attributes-only insert produces the same
	 * "invalid content" warning Gutenberg shows on reload.
	 */
	public function test_insert_blocks_rejects_heading_with_content_attr_but_no_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/heading', 'attributes' => array( 'level' => 3, 'content' => 'Section title' ) ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'inner_html_required', $result->get_error_code() );
	}

	/**
	 * Providing innerHTML alongside attributes is the canonical form and must
	 * still succeed. This pins the workaround agents already use and prevents
	 * the rejection check from regressing into a blanket "no attributes-only"
	 * block.
	 */
	public function test_insert_blocks_accepts_paragraph_with_content_attr_and_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'content' => 'Hello world' ),
				'innerHTML'  => '<p>Hello world</p>',
			) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 1, $saved );
		$this->assertStringContainsString( 'Hello world', $saved[0]['innerHTML'] );
	}

	/**
	 * Attribute-sourced fields (e.g., core/image.url, source: attribute) ARE
	 * rejected by the inner_html_required guard.
	 *
	 * Initially the guard's allow-list omitted `source: attribute` on the
	 * theory that the failure mode was "block can't render at all" rather
	 * than the same round-trip mismatch. A later schema review against
	 * https://schemas.wp.org/trunk/block.json showed the failure surfaces
	 * identically in the editor — Gutenberg's parser reads the wrapper
	 * selector against an empty DOM, the parsed attribute value disagrees
	 * with the saved comment payload, and the block is flagged as invalid.
	 * Every core block using `source: attribute` (core/image, core/button,
	 * core/audio, core/video, core/cover, core/details, core/file,
	 * core/media-text) is static, so adding the source to the allow-list
	 * carries no false-positive risk among shipping core blocks.
	 */
	public function test_insert_blocks_rejects_attribute_sourced_block_without_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/image', 'attributes' => array( 'url' => 'https://example.com/img.jpg' ) ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'inner_html_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertContains( 'url', $data['source_bound_attributes'] );
	}

	/**
	 * Blocks whose attribute schema has no HTML-sourced attributes (e.g.,
	 * core/spacer's `height`) must continue to allow attributes-only inserts
	 * since their save output legitimately has no inner markup. This guards
	 * against the rejection check becoming over-broad.
	 */
	public function test_insert_blocks_allows_attributes_only_when_no_source_bound_attrs() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/spacer', 'attributes' => array( 'height' => '50px' ) ) )
		);
		$this->assertTrue( $result['success'] );
	}

	/**
	 * core/html stores its `content` attribute with `source: raw` (one of
	 * the canonical sources in the block.json meta-schema). The guard's
	 * allow-list now includes `raw`, so an attribute-only insert here
	 * must be rejected the same way `rich-text` blocks are.
	 *
	 * Regression for the gap found by reviewing the meta-schema at
	 * https://schemas.wp.org/trunk/block.json — the prior allow-list of
	 * ['rich-text','html','children'] silently passed source=raw blocks
	 * through, producing self-closing inserts on core/html and
	 * core/shortcode that Gutenberg flagged on next edit.
	 */
	public function test_insert_blocks_rejects_raw_source_block_with_attr_but_no_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/html', 'attributes' => array( 'content' => '<div>raw markup</div>' ) ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'inner_html_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertContains( 'content', $data['source_bound_attributes'] );
	}

	/**
	 * core/html canonical form: attribute + matching innerHTML succeeds and
	 * round-trips through parse_blocks/serialize_blocks intact.
	 *
	 * core/html's `content` attribute is `source: raw, selector: '*'` —
	 * the value IS the inner HTML. The canonical insert mirrors the
	 * attribute value inside `innerHTML` so the saved block parses back
	 * to the same content.
	 */
	public function test_insert_blocks_accepts_core_html_with_attr_and_inner_html() {
		$this->make_post( array() );
		$html_value = '<div class="ext-widget"><p>Embed me</p></div>';
		$result     = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'       => 'core/html',
				'attributes' => array( 'content' => $html_value ),
				'innerHTML'  => $html_value,
			) )
		);
		$this->assertTrue( $result['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$this->assertSame( 'core/html', $reparsed[0]['blockName'] );
		$this->assertStringContainsString( 'Embed me', $reparsed[0]['innerHTML'] );
	}

	/**
	 * core/shortcode also uses `source: raw` (on the `text` attribute) and
	 * must trip the same rejection when sent without matching innerHTML.
	 */
	public function test_insert_blocks_rejects_core_shortcode_with_text_attr_but_no_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/shortcode', 'attributes' => array( 'text' => '[gallery ids="1,2,3"]' ) ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'inner_html_required', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertEquals( 'core/shortcode', $data['block'] );
		$this->assertContains( 'text', $data['source_bound_attributes'] );
	}

	/**
	 * core/shortcode canonical form: shortcode text in both the attribute
	 * and the innerHTML. Confirms the rejection isn't blanket — the proper
	 * form still works and survives the round-trip.
	 */
	public function test_insert_blocks_accepts_core_shortcode_with_attr_and_inner_html() {
		$this->make_post( array() );
		$shortcode = '[gallery ids="10,11,12"]';
		$result    = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'       => 'core/shortcode',
				'attributes' => array( 'text' => $shortcode ),
				'innerHTML'  => $shortcode,
			) )
		);
		$this->assertTrue( $result['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$this->assertSame( 'core/shortcode', $reparsed[0]['blockName'] );
		$this->assertStringContainsString( 'gallery', $reparsed[0]['innerHTML'] );
	}

	/**
	 * Update path applies to raw-source blocks too — attribute-only update
	 * on a core/html that previously had content + innerHTML must use the
	 * auto-transform path (the writer pairs the new attribute value with
	 * the updated innerHTML on the same write).
	 *
	 * Mirrors the regression check for rich-text blocks at
	 * test_round_trip_paragraph_update_block_content_attr_rewrites_inner_html.
	 */
	public function test_round_trip_core_html_update_block_content_attr_rewrites_inner_html() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'       => 'core/html',
				'attributes' => array( 'content' => '<p>Before</p>' ),
				'innerHTML'  => '<p>Before</p>',
			) )
		);

		$update = $this->crud->update_block(
			$this->post_id,
			0,
			array(),
			'<p>After</p>'
		);
		$this->assertTrue( $update['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$this->assertStringContainsString( 'After', $reparsed[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'Before', $reparsed[0]['innerHTML'] );
	}

	/**
	 * Same rejection applies to core/list-item — its `content` attribute is
	 * rich-text sourced, so an attributes-only insert produces the same
	 * "invalid content" warning Gutenberg shows on reload.
	 */
	public function test_insert_blocks_rejects_list_item_with_content_attr_but_no_inner_html() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/list-item', 'attributes' => array( 'content' => 'A bullet' ) ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'inner_html_required', $result->get_error_code() );
	}

	// ── Round-trip coverage ────────────────────────────────────────
	//
	// The bug at the root of this fix is a round-trip failure: a block
	// passes through insert_blocks → save → parse_blocks and ends up with
	// an inconsistent state (comment carries a content attribute, inner
	// HTML is empty, editor rejects on reload). These tests exhaust the
	// round-trip contract for the canonical form (attributes + innerHTML
	// together): the persisted post_content, when re-parsed, must surface
	// the same block name, the same attributes, and a non-empty innerHTML
	// that still contains the caller's text.

	/**
	 * Paragraph: insert → save → re-parse round-trip preserves attribute,
	 * innerHTML, and the text payload. The serialized comment must NOT be
	 * self-closing — the presence of `/-->` with a `content` attribute is
	 * the exact failure mode the editor flags as "invalid content".
	 */
	public function test_round_trip_paragraph_preserves_attrs_and_inner_html() {
		$this->make_post( array() );
		$insert = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'       => 'core/paragraph',
				'attributes' => array( 'content' => 'Round-trip paragraph' ),
				'innerHTML'  => '<p>Round-trip paragraph</p>',
			) )
		);
		$this->assertTrue( $insert['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$this->assertStringContainsString( '<!-- wp:paragraph', $post_content );
		$this->assertStringNotContainsString( '/-->', $post_content, 'Block comment must not be self-closing (would trigger Gutenberg invalid-content warning)' );
		$this->assertStringContainsString( '<p>Round-trip paragraph</p>', $post_content );

		$reparsed = parse_blocks( $post_content );
		$this->assertCount( 1, $reparsed );
		$this->assertSame( 'core/paragraph', $reparsed[0]['blockName'] );
		$this->assertSame( 'Round-trip paragraph', $reparsed[0]['attrs']['content'] );
		$this->assertStringContainsString( 'Round-trip paragraph', $reparsed[0]['innerHTML'] );
	}

	/**
	 * Heading: each level (h1–h6) survives the round-trip with the correct
	 * tag name in innerHTML. Heading is the canonical case for a block whose
	 * save() output depends on TWO attributes (level governs the tag name,
	 * content governs the text); the caller-supplied innerHTML is the
	 * source of truth in the post_content blob.
	 *
	 * @dataProvider provide_heading_levels
	 */
	public function test_round_trip_heading_levels( $level ) {
		$tag = 'h' . $level;
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'       => 'core/heading',
				'attributes' => array( 'level' => $level, 'content' => 'Heading ' . $level ),
				'innerHTML'  => '<' . $tag . '>Heading ' . $level . '</' . $tag . '>',
			) )
		);

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$this->assertSame( 'core/heading', $reparsed[0]['blockName'] );
		$this->assertSame( $level, $reparsed[0]['attrs']['level'] );
		$this->assertStringContainsString( '<' . $tag . '>', $reparsed[0]['innerHTML'] );
		$this->assertStringContainsString( '</' . $tag . '>', $reparsed[0]['innerHTML'] );
	}

	public static function provide_heading_levels(): array {
		return array(
			'h1' => array( 1 ),
			'h2' => array( 2 ),
			'h3' => array( 3 ),
			'h4' => array( 4 ),
			'h5' => array( 5 ),
			'h6' => array( 6 ),
		);
	}

	/**
	 * Container with inner blocks: a core/group containing children must
	 * round-trip with the wrapper innerHTML opening/closing split and the
	 * children re-parsed at the correct positions. This pins that the
	 * source-bound-attr rejection doesn't fire when innerBlocks are
	 * present (the inner content lives in the children, not in the
	 * top-level innerHTML).
	 */
	public function test_round_trip_group_with_inner_blocks_preserves_children() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array(
				'name'        => 'core/group',
				'attributes'  => array( 'tagName' => 'section' ),
				'innerHTML'   => '<section class="wp-block-group"></section>',
				'innerBlocks' => array(
					array(
						'name'       => 'core/paragraph',
						'attributes' => array( 'content' => 'Inside group' ),
						'innerHTML'  => '<p>Inside group</p>',
					),
				),
			) )
		);

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$this->assertCount( 1, $reparsed );
		$this->assertSame( 'core/group', $reparsed[0]['blockName'] );
		$this->assertCount( 1, $reparsed[0]['innerBlocks'] );
		$this->assertSame( 'core/paragraph', $reparsed[0]['innerBlocks'][0]['blockName'] );
		$this->assertStringContainsString( 'Inside group', $reparsed[0]['innerBlocks'][0]['innerHTML'] );
	}

	/**
	 * Batch insert: every block in a multi-block insert round-trips
	 * independently. Catches a class of bug where one well-formed insert
	 * could mask a malformed sibling.
	 */
	public function test_round_trip_multiple_blocks_preserved_in_order() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array(
				array( 'name' => 'core/heading',   'attributes' => array( 'level' => 2, 'content' => 'Title' ), 'innerHTML' => '<h2>Title</h2>' ),
				array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'First paragraph' ),     'innerHTML' => '<p>First paragraph</p>' ),
				array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Second paragraph' ),    'innerHTML' => '<p>Second paragraph</p>' ),
			)
		);

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$reparsed     = array_values( array_filter( $reparsed, static function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );
		$this->assertCount( 3, $reparsed );
		$this->assertSame( 'core/heading',   $reparsed[0]['blockName'] );
		$this->assertSame( 'core/paragraph', $reparsed[1]['blockName'] );
		$this->assertSame( 'core/paragraph', $reparsed[2]['blockName'] );
		$this->assertStringContainsString( 'Title',            $reparsed[0]['innerHTML'] );
		$this->assertStringContainsString( 'First paragraph',  $reparsed[1]['innerHTML'] );
		$this->assertStringContainsString( 'Second paragraph', $reparsed[2]['innerHTML'] );
	}

	/**
	 * Insert → update_block (via apply_block_update_in_place auto-transform)
	 * round-trip preserves the changed text in innerHTML. Without
	 * auto_transform_html applied to the existing innerHTML, a content-attr
	 * update would leave the saved markup stale and the editor would flag
	 * the block on reload — same failure mode as the insert bug, different
	 * mutation path.
	 */
	public function test_round_trip_paragraph_update_block_content_attr_rewrites_inner_html() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Before' ), 'innerHTML' => '<p>Before</p>' ) )
		);

		$result = $this->crud->update_block(
			$this->post_id,
			0,
			array( 'content' => 'After' )
		);
		$this->assertTrue( $result['success'] );

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$this->assertSame( 'After', $reparsed[0]['attrs']['content'] );
		$this->assertStringContainsString( 'After', $reparsed[0]['innerHTML'] );
		$this->assertStringNotContainsString( 'Before', $reparsed[0]['innerHTML'] );
	}

	/**
	 * Insert → save → fetch via Block_CRUD::get_blocks() returns the same
	 * innerHTML the caller posted. This pins the read-path round-trip in
	 * addition to the raw parse_blocks() round-trip, since real callers
	 * (MCP get_page_blocks) round-trip through format_blocks(), not
	 * parse_blocks() directly.
	 */
	public function test_round_trip_get_blocks_returns_inserted_inner_html() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/paragraph', 'attributes' => array( 'content' => 'Read me back' ), 'innerHTML' => '<p>Read me back</p>' ) )
		);

		$fetched = $this->crud->get_blocks( $this->post_id, false );
		$top     = array_values( array_filter( $fetched, static function ( $b ) {
			return ! empty( $b['name'] );
		} ) );
		$this->assertCount( 1, $top );
		$this->assertSame( 'core/paragraph', $top[0]['name'] );
		$this->assertStringContainsString( 'Read me back', $top[0]['innerHTML'] );
		$this->assertSame( 'Read me back', $top[0]['attributes']['content'] );
	}

	/**
	 * Spacer round-trip: blocks with no source-bound attributes serialize
	 * cleanly even without innerHTML and re-parse with attributes intact.
	 * Pins that the rejection branch doesn't accidentally start failing
	 * legitimate self-closing block forms.
	 */
	public function test_round_trip_spacer_attributes_only_preserves_attrs() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array( array( 'name' => 'core/spacer', 'attributes' => array( 'height' => '40px' ) ) )
		);

		$post_content = (string) get_post_field( 'post_content', $this->post_id );
		$reparsed     = parse_blocks( $post_content );
		$reparsed     = array_values( array_filter( $reparsed, static function ( $b ) {
			return ! empty( $b['blockName'] );
		} ) );
		$this->assertCount( 1, $reparsed );
		$this->assertSame( 'core/spacer',  $reparsed[0]['blockName'] );
		$this->assertSame( '40px',         $reparsed[0]['attrs']['height'] );
	}

	// ── delete_blocks ──────────────────────────────────────────────

	public function test_delete_blocks_removes_at_index() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
			$this->block( 'core/paragraph', array(), '<p>C</p>' ),
		) );
		$result = $this->crud->delete_blocks( $this->post_id, 1, 1 );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 1, $result['deleted_count'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		$this->assertEquals( '<p>A</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>C</p>', $saved[1]['innerHTML'] );
	}

	public function test_delete_blocks_invalid_index_error() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->crud->delete_blocks( $this->post_id, 10, 1 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_index', $result->get_error_code() );
	}

	public function test_delete_blocks_count_caps_at_available() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
		) );
		$result = $this->crud->delete_blocks( $this->post_id, 0, 99 );
		$this->assertTrue( $result['success'] );
		$this->assertEquals( 2, $result['deleted_count'] );
		$this->assertCount( 0, $this->current_blocks() );
	}

	// ── replace_all_blocks ─────────────────────────────────────────

	public function test_replace_all_blocks_rewrites_content() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>Old A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>Old B</p>' ),
		) );
		$result = $this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array( 'name' => 'core/heading', 'innerHTML' => '<h2>Title</h2>' ),
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Body</p>' ),
			)
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		$this->assertEquals( 'core/heading', $saved[0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $saved[1]['blockName'] );
	}

	public function test_replace_all_blocks_legacy_rejected() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->replace_all_blocks(
			$this->post_id,
			array( array( 'name' => 'ugb/text' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'legacy_block', $result->get_error_code() );
	}

	public function test_replace_all_blocks_empty_array() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->crud->replace_all_blocks( $this->post_id, array() );
		$this->assertTrue( $result['success'] );
		$this->assertCount( 0, $this->current_blocks() );
	}

	// ── replace_all_blocks: innerBlocks regression ────────────────────

	public function test_replace_all_blocks_preserves_inner_blocks() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>Old</p>' ) ) );
		$result = $this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array(
					'name'        => 'core/list',
					'innerHTML'   => '<ul class="wp-block-list"></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>One</li>' ),
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>Two</li>' ),
					),
				),
			)
		);

		$this->assertTrue( $result['success'] );

		$blocks = $this->current_blocks();
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/list', $blocks[0]['blockName'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );
		$this->assertSame( 'core/list-item', $blocks[0]['innerBlocks'][0]['blockName'] );
		$this->assertSame( '<li>One</li>', $blocks[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( 'core/list-item', $blocks[0]['innerBlocks'][1]['blockName'] );
		$this->assertSame( '<li>Two</li>', $blocks[0]['innerBlocks'][1]['innerHTML'] );
	}

	public function test_replace_all_blocks_inner_content_has_null_per_child() {
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array(
					'name'        => 'core/list',
					'innerHTML'   => '<ul></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>A</li>' ),
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>B</li>' ),
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>C</li>' ),
					),
				),
			)
		);

		$blocks      = $this->current_blocks();
		$null_count  = count( array_filter( $blocks[0]['innerContent'], fn( $p ) => null === $p ) );
		$this->assertSame( 3, $null_count, 'innerContent must have one null placeholder per child for serialize_blocks() round-trip.' );
	}

	public function test_replace_all_blocks_deeply_nested_inner_blocks() {
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array(
					'name'        => 'core/columns',
					'innerHTML'   => '<div class="wp-block-columns"></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/column',
							'innerHTML'   => '<div class="wp-block-column"></div>',
							'innerBlocks' => array(
								array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Nested</p>' ),
							),
						),
					),
				),
			)
		);

		$blocks = $this->current_blocks();
		// Depth 0 — columns.
		$this->assertSame( 'core/columns', $blocks[0]['blockName'] );
		$this->assertCount( 1, $blocks[0]['innerBlocks'] );
		// Depth 1 — column.
		$col = $blocks[0]['innerBlocks'][0];
		$this->assertSame( 'core/column', $col['blockName'] );
		$this->assertCount( 1, $col['innerBlocks'] );
		// Depth 2 — paragraph leaf.
		$leaf = $col['innerBlocks'][0];
		$this->assertSame( 'core/paragraph', $leaf['blockName'] );
		$this->assertSame( '<p>Nested</p>', $leaf['innerHTML'] );
	}

	public function test_replace_all_blocks_assigns_refs_to_nested_blocks() {
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array(
					'name'        => 'core/list',
					'innerHTML'   => '<ul></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>One</li>' ),
					),
				),
			)
		);

		$blocks = $this->current_blocks();
		$this->assertArrayHasKey( 'metadata', $blocks[0]['attrs'] );
		$this->assertArrayHasKey( 'gk_ref', $blocks[0]['attrs']['metadata'], 'Top-level block must receive a stable ref.' );
		$this->assertArrayHasKey( 'metadata', $blocks[0]['innerBlocks'][0]['attrs'] );
		$this->assertArrayHasKey( 'gk_ref', $blocks[0]['innerBlocks'][0]['attrs']['metadata'], 'Nested blocks must also receive refs so subsequent edit_block_tree calls can target them.' );
	}

	// ── replace_all_blocks: XSS sanitization across the tree ──────────

	public function test_replace_all_blocks_strips_script_from_leaf_inner_html() {
		$this->make_post( array() );

		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p>ok</p><script>alert(1)</script>' ),
			)
		);

		$blocks = $this->current_blocks();
		// Real wp_kses_post strips the <script> tag markup. The text content
		// ("alert(1)") survives as inert text — that's authentic WP behavior;
		// we assert only the security invariant.
		$this->assertStringNotContainsStringIgnoringCase( '<script', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<p>ok</p>', $blocks[0]['innerHTML'] );
		$this->assertSame( $blocks[0]['innerHTML'], $blocks[0]['innerContent'][0] );
	}

	public function test_replace_all_blocks_strips_event_handler_attribute() {
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p><img src="x" onerror="alert(1)"></p>' ),
			)
		);

		$blocks = $this->current_blocks();
		$this->assertStringNotContainsStringIgnoringCase( 'onerror', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( 'alert(1)', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<img src="x"', $blocks[0]['innerHTML'] );
	}

	public function test_replace_all_blocks_neutralizes_javascript_url() {
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p><a href="javascript:alert(1)">x</a></p>' ),
			)
		);

		$blocks = $this->current_blocks();
		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<a ', $blocks[0]['innerHTML'] );
	}

	public function test_replace_all_blocks_strips_xss_in_container_split_innerhtml() {
		// Regression guard for the strpos-based wrapper split in
		// build_block_from_def. kses runs before the split — executable tag
		// markers must not resurface in the opening or closing slice of
		// innerContent.
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array(
					'name'        => 'core/list',
					'innerHTML'   => '<script>alert(1)</script><ul></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>one</li>' ),
					),
				),
			)
		);

		$container = $this->current_blocks()[0];
		foreach ( $container['innerContent'] as $piece ) {
			if ( null === $piece ) {
				continue;
			}
			$this->assertStringNotContainsStringIgnoringCase( '<script', $piece );
		}
	}

	public function test_replace_all_blocks_strips_xss_from_deeply_nested_inner_blocks() {
		$this->make_post( array() );
		$this->crud->replace_all_blocks(
			$this->post_id,
			array(
				array(
					'name'        => 'core/columns',
					'innerHTML'   => '<div class="wp-block-columns"></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/column',
							'innerHTML'   => '<div class="wp-block-column"></div>',
							'innerBlocks' => array(
								array(
									'name'      => 'core/paragraph',
									'innerHTML' => '<p onclick="alert(1)">deep <script>alert(2)</script></p>',
								),
							),
						),
					),
				),
			)
		);

		$leaf = $this->current_blocks()[0]['innerBlocks'][0]['innerBlocks'][0];
		// Executable surface gone: no <script>, no on*= handler. The
		// onclick="alert(1)" value is removed with the attribute; the text
		// "alert(2)" inside the stripped <script> survives as inert text,
		// matching real wp_kses behavior.
		$this->assertStringNotContainsStringIgnoringCase( 'onclick', $leaf['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $leaf['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( 'alert(1)', $leaf['innerHTML'] );
		$this->assertStringContainsString( 'deep', $leaf['innerHTML'] );
		$this->assertSame( $leaf['innerHTML'], $leaf['innerContent'][0] );
	}

	// ── Rate limiting ──────────────────────────────────────────────

	public function test_rate_limit_passes_when_empty() {
		$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		$this->assertTrue( $result );
	}

	/**
	 * check_rate_limit() reserves the slot at check time.
	 *
	 * The limiter consumes a slot on the ATTEMPT, not on later success: the
	 * check and the append are one atomic critical section (record_rate_limit()
	 * is a no-op). Two passing checks therefore leave two timestamps in the
	 * window's `writes` bucket.
	 */
	public function test_rate_limit_check_reserves_write_slot() {
		$this->assertTrue( $this->crud->check_rate_limit( $this->post_id, 'write' ) );
		$this->assertTrue( $this->crud->check_rate_limit( $this->post_id, 'write' ) );
		$data = get_transient( 'gk_block_api_rate_' . $this->post_id );
		$this->assertCount( 2, $data['writes'] );
	}

	/**
	 * The (limit+1)th write in the window is rejected with a 429.
	 *
	 * Because each check reserves its own slot, RATE_LIMIT_WRITES passing checks
	 * fill the bucket and the next one exceeds it.
	 */
	public function test_rate_limit_exceeded_after_max_writes() {
		for ( $i = 0; $i < Block_CRUD::RATE_LIMIT_WRITES; $i++ ) {
			$this->assertTrue( $this->crud->check_rate_limit( $this->post_id, 'write' ) );
		}
		$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rate_limit_exceeded', $result->get_error_code() );
	}

	/**
	 * Full-rewrite (`put`) requests draw from their own smaller budget.
	 *
	 * RATE_LIMIT_PUT passing `put` checks fill the put bucket; the next `put`
	 * check is rejected even though the general write budget is untouched.
	 */
	public function test_rate_limit_put_separate_bucket() {
		for ( $i = 0; $i < Block_CRUD::RATE_LIMIT_PUT; $i++ ) {
			$this->assertTrue( $this->crud->check_rate_limit( $this->post_id, 'put' ) );
		}
		$result = $this->crud->check_rate_limit( $this->post_id, 'put' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_rate_limit_old_entries_expire() {
		// Manually plant ancient timestamps (outside the 60s window).
		$old = time() - 120;
		set_transient(
			'gk_block_api_rate_' . $this->post_id,
			array(
				'writes' => array_fill( 0, Block_CRUD::RATE_LIMIT_WRITES + 5, $old ),
				'puts'   => array(),
			),
			120
		);
		// Stale entries should be filtered; check should pass.
		$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		$this->assertTrue( $result );
	}

	/**
	 * On MySQL, a failed lock acquisition fails CLOSED, not open.
	 *
	 * When the per-post advisory lock can't be acquired the post is under
	 * concurrent write pressure. Reserving unlocked there would let the burst
	 * race past the limit (the very bypass the lock exists to close), so the
	 * limiter must return a 429 instead of true.
	 */
	public function test_rate_limit_fails_closed_when_lock_unavailable_on_mysql() {
		global $wpdb;
		$real   = $wpdb;
		$double = new \GravityKit\BlockMCP\Tests\RateLimitLockWpdbDouble( $real, 0 );

		$GLOBALS['wpdb'] = $double;
		try {
			$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_locked', $result->get_error_code() );
		$this->assertSame( 429, $result->get_error_data()['status'] );
		$issued_get_lock = array_filter(
			$double->captured,
			static function ( $q ) {
				return false !== strpos( $q, 'GET_LOCK' );
			}
		);
		$this->assertNotEmpty( $issued_get_lock, 'the acquire must issue GET_LOCK' );
	}

	/**
	 * A granted lock is acquired and then released around the reserve.
	 *
	 * Exercises the GET_LOCK / RELEASE_LOCK SQL that the SQLite harness never
	 * runs (is_mysql is false there): a successful reserve issues GET_LOCK and,
	 * on the way out, RELEASE_LOCK.
	 */
	public function test_rate_limit_acquires_and_releases_the_lock_on_mysql() {
		global $wpdb;
		$real   = $wpdb;
		$double = new \GravityKit\BlockMCP\Tests\RateLimitLockWpdbDouble( $real, 1 );

		$GLOBALS['wpdb'] = $double;
		try {
			$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}

		$this->assertTrue( $result );
		$this->assertNotEmpty(
			array_filter(
				$double->captured,
				static function ( $q ) {
					return false !== strpos( $q, 'GET_LOCK' );
				}
			),
			'a successful reserve must acquire the lock'
		);
		$this->assertNotEmpty(
			array_filter(
				$double->captured,
				static function ( $q ) {
					return false !== strpos( $q, 'RELEASE_LOCK' );
				}
			),
			'the lock must be released after the reserve'
		);
	}

	/**
	 * The lock is released even when the reserve is rejected for being over budget.
	 *
	 * The release lives in a finally, so an over-limit 429 must still free the
	 * lock rather than leaking it and blocking the post for the connection's life.
	 */
	public function test_rate_limit_releases_the_lock_when_over_budget() {
		$now = time();
		set_transient(
			'gk_block_api_rate_' . $this->post_id,
			array(
				'writes' => array_fill( 0, Block_CRUD::RATE_LIMIT_WRITES, $now ),
				'puts'   => array(),
			),
			120
		);

		global $wpdb;
		$real   = $wpdb;
		$double = new \GravityKit\BlockMCP\Tests\RateLimitLockWpdbDouble( $real, 1 );

		$GLOBALS['wpdb'] = $double;
		try {
			$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		} finally {
			$GLOBALS['wpdb'] = $real;
		}

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$this->assertNotEmpty(
			array_filter(
				$double->captured,
				static function ( $q ) {
					return false !== strpos( $q, 'RELEASE_LOCK' );
				}
			),
			'the lock must be released even when the write is rejected for being over budget'
		);
	}

	// ── insert_blocks with innerBlocks ────────────────────────────

	public function test_insert_blocks_with_inner_blocks_preserved() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array(
				array(
					'name'        => 'core/list',
					'innerHTML'   => '<ul class="wp-block-list"></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>One</li>' ),
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>Two</li>' ),
					),
				),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );

		$blocks = $this->current_blocks();
		$this->assertCount( 1, $blocks );
		$this->assertEquals( 'core/list', $blocks[0]['blockName'] );
		$this->assertCount( 2, $blocks[0]['innerBlocks'] );
		$this->assertEquals( 'core/list-item', $blocks[0]['innerBlocks'][0]['blockName'] );
		$this->assertEquals( '<li>One</li>', $blocks[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertEquals( 'core/list-item', $blocks[0]['innerBlocks'][1]['blockName'] );
		$this->assertEquals( '<li>Two</li>', $blocks[0]['innerBlocks'][1]['innerHTML'] );
	}

	public function test_insert_blocks_inner_blocks_build_inner_content_nulls() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array(
				array(
					'name'        => 'core/list',
					'innerHTML'   => '<ul></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/list-item', 'innerHTML' => '<li>A</li>' ),
					),
				),
			)
		);

		$blocks = $this->current_blocks();
		// innerContent must have nulls for each child so serialize_blocks() round-trips correctly.
		$null_count = count( array_filter( $blocks[0]['innerContent'], fn( $p ) => null === $p ) );
		$this->assertEquals( 1, $null_count, 'innerContent must have one null placeholder per child.' );
	}

	public function test_insert_blocks_no_inner_blocks_unchanged() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Hello</p>' ),
			)
		);

		$this->assertIsArray( $result );
		$blocks = $this->current_blocks();
		$this->assertEquals( '<p>Hello</p>', $blocks[0]['innerHTML'] );
		$this->assertEmpty( $blocks[0]['innerBlocks'] );
	}

	public function test_insert_blocks_deeply_nested_inner_blocks() {
		$this->make_post( array() );
		$this->crud->insert_blocks(
			$this->post_id,
			null,
			array(
				array(
					'name'        => 'core/columns',
					'innerBlocks' => array(
						array(
							'name'        => 'core/column',
							'innerBlocks' => array(
								array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Deep</p>' ),
							),
						),
					),
				),
			)
		);

		$blocks = $this->current_blocks();
		$this->assertEquals( 'core/columns', $blocks[0]['blockName'] );
		$this->assertEquals( 'core/column', $blocks[0]['innerBlocks'][0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $blocks[0]['innerBlocks'][0]['innerBlocks'][0]['blockName'] );
		$this->assertEquals( '<p>Deep</p>', $blocks[0]['innerBlocks'][0]['innerBlocks'][0]['innerHTML'] );
	}

	public function test_insert_blocks_inner_block_legacy_name_rejected() {
		$this->make_post( array() );
		$result = $this->crud->insert_blocks(
			$this->post_id,
			null,
			array(
				array(
					'name'        => 'core/group',
					'innerBlocks' => array(
						array( 'name' => 'ugb/text', 'innerHTML' => '<p>Bad</p>' ),
					),
				),
			)
		);

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'legacy_block', $result->get_error_code() );
	}

	// ── save_post_content ──────────────────────────────────────────

	public function test_save_post_content_updates_post_content() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>Old</p>' ) ) );
		$new_content = json_encode( array( $this->block( 'core/paragraph', array(), '<p>New</p>' ) ) );
		$result = $this->crud->save_post_content( $this->post_id, $new_content );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'before_revision_id', $result );
		$this->assertArrayHasKey( 'revision_id', $result );
		$saved_post = get_post( $this->post_id );
		$this->assertEquals( $new_content, $saved_post->post_content );
	}

	/**
	 * tree_depth must early-exit so an over-deep input can't blow the PHP stack.
	 *
	 * Pre-fix, tree_depth recursed all the way to the bottom of the tree
	 * before returning, so an adversarial 100k-deep input would stack-overflow
	 * before validate_tree_depth could reject it. Now the recursive walker
	 * threads $depth_so_far through and returns as soon as it exceeds
	 * MAX_BLOCK_DEPTH; depth-of-input never exceeds MAX+1 frames on stack.
	 */
	public function test_tree_depth_early_exits_on_over_deep_input() {
		// Build a 10k-deep nested tree by reference threading — would
		// recurse 10k times pre-fix.
		$leaf = array( 'innerBlocks' => array() );
		for ( $i = 0; $i < 10000; $i++ ) {
			$leaf = array( 'innerBlocks' => array( $leaf ) );
		}

		$depth = \GravityKit\BlockMCP\Block_CRUD::tree_depth( array( $leaf ) );
		$this->assertGreaterThan(
			\GravityKit\BlockMCP\Block_CRUD::MAX_BLOCK_DEPTH,
			$depth,
			'tree_depth must report a value over the cap so validate_tree_depth can reject it.'
		);
		// And critically: we got here without segfaulting / stack overflow.
		$this->assertTrue( true, 'tree_depth must return cleanly for an over-deep tree.' );
	}

	/**
	 * revert_to_revision must consume the per-post write rate-limit budget.
	 *
	 * revert is a write path: if it skipped the rate-limit a caller could cycle
	 * write -> revert -> write -> revert at unbounded frequency and bypass the
	 * per-post budget. revert reserves a slot on the same writes bucket as the
	 * per-block writes, so a full bucket rejects it with rate_limit_exceeded.
	 */
	public function test_revert_to_revision_respects_rate_limit() {
		// Pre-fill the writes bucket to the cap.
		$now = time();
		set_transient(
			'gk_block_api_rate_' . $this->post_id,
			array(
				'writes' => array_fill( 0, \GravityKit\BlockMCP\Block_CRUD::RATE_LIMIT_WRITES, $now ),
				'puts'   => array(),
			),
			120
		);

		$result = $this->crud->revert_to_revision( $this->post_id, 99999 );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'rate_limit_exceeded',
			$result->get_error_code(),
			'revert_to_revision must be gated by the write rate-limit, not slip through it.'
		);
	}

	/**
	 * insert_pattern must refuse to inline / reference a non-published wp_block.
	 *
	 * Pre-fix, get_post() returned drafts / private / trash / password-protected
	 * wp_block entries; insert_pattern checked only post_type. Inline mode
	 * copied the gated content into the target post verbatim; synced mode
	 * embedded a core/block ref that anonymous front-end visitors saw on
	 * render. Both leaked content the caller may not have rights to see.
	 * Routed through Block_CRUD::is_post_readable().
	 */
	public function test_insert_pattern_rejects_draft_pattern_for_subscriber() {
		$draft_pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'draft',
				'post_author'  => 1,
				'post_title'   => 'Hidden draft pattern',
				'post_content' => '<!-- wp:paragraph --><p>protected</p><!-- /wp:paragraph -->',
			)
		);

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$result = $this->crud->insert_pattern( $this->post_id, $draft_pattern_id, 'end', true );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame(
			'pattern_not_found',
			$result->get_error_code(),
			'Non-public wp_block CPT entries must surface pattern_not_found rather than leaking content via insert_pattern.'
		);

		wp_set_current_user( 0 );
		wp_delete_post( $draft_pattern_id, true );
	}

	/**
	 * is_post_readable must allow publish-no-password but not publish-with-password.
	 *
	 * Pre-fix, the visibility gate used `post_status === 'publish'` alone —
	 * password-protected published posts (a valid WP state) passed through
	 * as fully public. Subscribers could read their content via expanded
	 * pattern_ref or insert it via insert_pattern. The gate now also checks
	 * empty(post_password), so password-protected posts require an explicit
	 * read_post cap.
	 */
	public function test_is_post_readable_respects_password_protection() {
		$public_post     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$pw_protected    = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$subscriber      = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertTrue(
			\GravityKit\BlockMCP\Block_CRUD::is_post_readable( get_post( $public_post ) ),
			'A plainly-published post with no password must be readable by any caller.'
		);
		$this->assertFalse(
			\GravityKit\BlockMCP\Block_CRUD::is_post_readable( get_post( $pw_protected ) ),
			'A published-but-password-protected post must NOT be readable to a subscriber-level caller.'
		);

		wp_set_current_user( 0 );
	}

	/**
	 * Literal "0" must be treated as a real password, not "no password".
	 *
	 * empty('0') is true in PHP — a classic foot-gun. Pre-fix, a post
	 * with post_password === '0' would be classified as "no password set"
	 * and treated as fully public. Strict '' !== check is the canonical
	 * "is the password slot non-empty" gate.
	 */
	public function test_is_post_readable_treats_zero_string_password_as_real() {
		$pw_zero    = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => '0',
			)
		);
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse(
			\GravityKit\BlockMCP\Block_CRUD::is_post_readable( get_post( $pw_zero ) ),
			'post_password = "0" must be treated as a password (not as no-password) — empty("0") is true in PHP.'
		);

		wp_set_current_user( 0 );
	}

	/**
	 * insert_pattern must mint fresh metadata.gk_ref for every inlined block.
	 *
	 * Pre-fix, inline patterns were spliced as-is, preserving the gk_ref
	 * values they carried in the source wp_block CPT. The same pattern
	 * inlined twice (or on a post that already used those refs) would
	 * produce duplicate refs, so subsequent write-by-ref calls would land
	 * on the wrong block — silently corrupting unrelated content. Fix
	 * runs assign_fresh_refs_recursive() over the parsed pattern tree
	 * before array_splice.
	 */
	public function test_insert_pattern_inline_mints_fresh_refs() {
		$pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Hero',
				// Carries a hand-written gk_ref so we can prove it was replaced.
				'post_content' => '<!-- wp:paragraph {"metadata":{"gk_ref":"prePATTERN_REF000000"}} --><p>X</p><!-- /wp:paragraph -->',
			)
		);

		$result = $this->crud->insert_pattern( $this->post_id, $pattern_id, 'end', false );
		$this->assertIsArray( $result );
		$this->assertNotEmpty( $result['inserted'] );

		$blocks = $this->current_blocks();
		$first  = $blocks[0];
		$this->assertSame( 'core/paragraph', $first['blockName'] );

		$persisted_ref = $first['attrs']['metadata']['gk_ref'] ?? '';
		$this->assertNotSame(
			'prePATTERN_REF000000',
			$persisted_ref,
			'insert_pattern must overwrite source pattern gk_ref to keep refs globally unique.'
		);
		$this->assertNotEmpty( $persisted_ref, 'inlined block must end up with a fresh, non-empty gk_ref.' );

		wp_delete_post( $pattern_id, true );
	}

	/**
	 * insert_pattern response index is the VISIBLE index, not the raw position.
	 *
	 * Pre-fix, the returned `inserted.index` was $insert_at — the raw array
	 * index that counts whitespace blocks. A caller using that index against
	 * the flat-index vocabulary used by insert_blocks / update_block would
	 * land off-by-N whenever the post contained whitespace between blocks.
	 * This test seeds whitespace-only blocks around the insertion site to
	 * make the divergence observable.
	 */
	public function test_insert_pattern_returns_visible_index() {
		// Whitespace BEFORE a paragraph forces visible_count < raw_count.
		$content = "\n\n<!-- wp:paragraph --><p>before</p><!-- /wp:paragraph -->\n\n";
		wp_update_post( array(
			'ID'           => $this->post_id,
			'post_content' => $content,
		) );

		$pattern_id = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => 'Footer',
				'post_content' => '<!-- wp:paragraph --><p>P</p><!-- /wp:paragraph -->',
			)
		);

		// Append at end. Visible insert position must equal 1 (after the
		// single existing visible paragraph); raw position would be ≥ 2.
		$result = $this->crud->insert_pattern( $this->post_id, $pattern_id, 'end', true );
		$this->assertIsArray( $result );
		$this->assertSame(
			1,
			(int) $result['inserted']['index'],
			'insert_pattern must report the visible index, not the raw-array index.'
		);

		wp_delete_post( $pattern_id, true );
	}

	// ── gk/block-mcp/block/refs-persisted action ───────────────────────

	/**
	 * gk/block-mcp/block/refs-persisted fires once, with the post ID, after fresh
	 * gk_ref UUIDs are written to a post.
	 *
	 * persist_ref_assignments() writes the ref-stamped content straight to the DB
	 * via $wpdb->update (no save_post hook, no revision), so secondary caches keyed
	 * on post_content — search indexes, CDN edge caches, page-builder CSS — get no
	 * core signal to invalidate. The action is the only invalidation hook for that
	 * direct write. This exercises the REAL trigger: a post whose blocks lack
	 * gk_ref, read through the Block_CRUD facade (get_blocks), which lazy-assigns
	 * refs and persists them — and asserts the action fired exactly once carrying
	 * the correct post ID.
	 */
	public function test_refs_persisted_action_fires_after_ref_write() {
		// A block with no attrs.metadata.gk_ref, so the lazy reader must assign one.
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>needs a ref</p>' ) ) );

		$fired   = array();
		$capture = static function ( $post_id ) use ( &$fired ) {
			$fired[] = $post_id;
		};
		add_action( 'gk/block-mcp/block/refs-persisted', $capture );

		// Real trigger: reading through the facade lazy-assigns + persists the ref.
		$blocks = $this->crud->get_blocks( $this->post_id );

		remove_action( 'gk/block-mcp/block/refs-persisted', $capture );

		// Precondition: a ref really was written (otherwise the action would not
		// fire and the test would prove nothing).
		$this->assertNotEmpty( $blocks[0]['ref'], 'the reader must have assigned a ref' );
		$persisted = $this->current_blocks();
		$this->assertNotEmpty( $persisted[0]['attrs']['metadata']['gk_ref'], 'the ref must be persisted to post_content' );

		// The action fired exactly once, with the post that received the refs.
		$this->assertCount( 1, $fired, 'refs-persisted must fire exactly once for the ref write' );
		$this->assertSame( $this->post_id, $fired[0], 'refs-persisted must carry the post ID that received the refs' );
	}
}
