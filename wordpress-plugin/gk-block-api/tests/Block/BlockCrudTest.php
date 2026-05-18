<?php
/**
 * Tests for the Block_CRUD class.
 *
 * Covers format_blocks, validate_block_def, get_blocks, update_block,
 * insert_blocks, delete_blocks, replace_all_blocks, save_post_content,
 * and rate limiting.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_CRUD;

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

	public function test_validate_block_def_empty_name_no_error() {
		$result = $this->crud->validate_block_def( '' );
		$this->assertNull( $result['error'] );
		$this->assertEmpty( $result['warnings'] );
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

	public function test_rate_limit_records_writes() {
		$this->crud->record_rate_limit( $this->post_id, 'write' );
		$this->crud->record_rate_limit( $this->post_id, 'write' );
		$data = get_transient( 'gk_block_api_rate_' . $this->post_id );
		$this->assertCount( 2, $data['writes'] );
	}

	public function test_rate_limit_exceeded_after_max_writes() {
		for ( $i = 0; $i < Block_CRUD::RATE_LIMIT_WRITES; $i++ ) {
			$this->crud->record_rate_limit( $this->post_id, 'write' );
		}
		$result = $this->crud->check_rate_limit( $this->post_id, 'write' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'rate_limit_exceeded', $result->get_error_code() );
	}

	public function test_rate_limit_put_separate_bucket() {
		for ( $i = 0; $i < Block_CRUD::RATE_LIMIT_PUT; $i++ ) {
			$this->crud->record_rate_limit( $this->post_id, 'put' );
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
}
