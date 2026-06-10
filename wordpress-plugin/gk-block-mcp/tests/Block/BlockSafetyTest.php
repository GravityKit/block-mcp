<?php
/**
 * Tests for the Block_Safety class.
 *
 * Tests static block safety checks, editor-only attribute detection,
 * and dynamic block handling.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_Safety;

class BlockSafetyTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		// Register some block types for testing.
		$registry = WP_Block_Type_Registry::get_instance();

		// Static block (no render callback).
		if ( ! $registry->get_registered( 'core/paragraph' ) ) {
			$registry->register( 'core/paragraph' );
		}

		// Dynamic block (has render callback).
		if ( ! $registry->get_registered( 'core/latest-posts' ) ) {
			$registry->register( 'core/latest-posts', array(
				'render_callback' => function() { return '<div>posts</div>'; },
			) );
		}
	}

	public function test_dynamic_block_is_always_safe() {
		$s = new Block_Safety();
		$warnings = $s->check_mutation( 'core/latest-posts', array( 'content', 'level' ), false );
		$this->assertEmpty( $warnings );
	}

	public function test_unknown_block_is_safe() {
		$s = new Block_Safety();
		// Unknown blocks are treated as dynamic (safe).
		$warnings = $s->check_mutation( 'totally-unknown/block', array( 'content', 'level' ), false );
		$this->assertEmpty( $warnings );
	}

	public function test_editor_only_attrs_no_warning() {
		$s = new Block_Safety();
		// className, align, lock are editor-only — should not trigger warning even on static blocks.
		$warnings = $s->check_mutation( 'core/paragraph', array( 'className', 'align', 'lock' ), false );
		$this->assertEmpty( $warnings );
	}

	public function test_static_block_render_affecting_warns() {
		$s = new Block_Safety();
		// 'content' is render-affecting on a static block without new HTML.
		$warnings = $s->check_mutation( 'core/paragraph', array( 'content' ), false );
		$this->assertNotEmpty( $warnings );
		$this->assertEquals( 'static_markup_stale_risk', $warnings[0]['type'] );
		$this->assertStringContainsString( 'content', $warnings[0]['message'] );
	}

	public function test_with_new_html_is_safe() {
		$s = new Block_Safety();
		// Even if attrs are render-affecting, providing HTML makes it safe.
		$warnings = $s->check_mutation( 'core/paragraph', array( 'content' ), true );
		$this->assertEmpty( $warnings );
	}

	public function test_mixed_attrs_only_warns_for_render_affecting() {
		$s = new Block_Safety();
		// Mix of editor-only and render-affecting attrs.
		$warnings = $s->check_mutation( 'core/paragraph', array( 'className', 'content' ), false );
		$this->assertNotEmpty( $warnings );
		$this->assertContains( 'content', $warnings[0]['changed_attrs'] );
		$this->assertNotContains( 'className', $warnings[0]['changed_attrs'] );
	}

	public function test_get_editor_only_attrs() {
		$attrs = Block_Safety::get_editor_only_attrs();
		$this->assertContains( 'lock', $attrs );
		$this->assertContains( 'className', $attrs );
		$this->assertContains( 'anchor', $attrs );
		$this->assertContains( 'metadata', $attrs );
		$this->assertContains( 'align', $attrs );
		$this->assertContains( 'fontFamily', $attrs );
		$this->assertContains( 'fontSize', $attrs );
	}

	public function test_is_dynamic_block_registered_static() {
		$s = new Block_Safety();
		$this->assertFalse( $s->is_dynamic_block( 'core/paragraph' ) );
	}

	public function test_is_dynamic_block_registered_dynamic() {
		$s = new Block_Safety();
		$this->assertTrue( $s->is_dynamic_block( 'core/latest-posts' ) );
	}

	public function test_is_dynamic_block_unknown() {
		$s = new Block_Safety();
		// Unknown blocks are treated as dynamic.
		$this->assertTrue( $s->is_dynamic_block( 'nonexistent/block' ) );
	}
}
