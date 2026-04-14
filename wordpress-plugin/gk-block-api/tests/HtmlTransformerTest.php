<?php
/**
 * Tests for the HTML_Transformer class.
 *
 * Tests auto_transform_html() and rebuild_inner_content() logic.
 * If HTML_Transformer is not yet extracted, tests are skipped.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\HTML_Transformer;

class HtmlTransformerTest extends \PHPUnit\Framework\TestCase {

	/**
	 * @var HTML_Transformer|null
	 */
	private $transformer;

	/**
	 * Whether WP_HTML_Tag_Processor is available (needed for some transforms).
	 *
	 * @var bool
	 */
	private static $has_tag_processor;

	public static function setUpBeforeClass(): void {
		self::$has_tag_processor = class_exists( 'WP_HTML_Tag_Processor' );
	}

	private function get_transformer(): HTML_Transformer {
		if ( class_exists( 'GravityKit\BlockAPI\HTML_Transformer' ) ) {
			return new HTML_Transformer();
		}
		$this->markTestSkipped( 'HTML_Transformer class not yet extracted.' );
	}

	private function require_tag_processor(): void {
		if ( ! self::$has_tag_processor ) {
			$this->markTestSkipped( 'WP_HTML_Tag_Processor not available (needs WordPress or polyfill).' );
		}
	}

	// ── Tag name swap tests (regex-based, no WP_HTML_Tag_Processor needed) ──

	public function test_list_ordered_toggle() {
		$t = $this->get_transformer();
		$html = '<ul class="wp-block-list"><li>A</li><li>B</li></ul>';
		$result = $t->auto_transform_html( 'core/list', array( 'ordered' => true ), $html );
		$this->assertStringContainsString( '<ol', $result );
		$this->assertStringContainsString( '</ol>', $result );
		$this->assertStringNotContainsString( '<ul', $result );
	}

	public function test_list_unordered_toggle() {
		$t = $this->get_transformer();
		$html = '<ol class="wp-block-list"><li>A</li><li>B</li></ol>';
		$result = $t->auto_transform_html( 'core/list', array( 'ordered' => false ), $html );
		$this->assertStringContainsString( '<ul', $result );
		$this->assertStringContainsString( '</ul>', $result );
		$this->assertStringNotContainsString( '<ol', $result );
	}

	public function test_list_ordered_preserves_nested() {
		$t = $this->get_transformer();
		$html = '<ul class="wp-block-list"><li>A<ul><li>Sub</li></ul></li></ul>';
		$result = $t->auto_transform_html( 'core/list', array( 'ordered' => true ), $html );
		// Outer should be ol, inner should stay ul.
		$this->assertStringStartsWith( '<ol', $result );
		$this->assertStringContainsString( '<ul>', $result ); // Inner preserved.
	}

	public function test_heading_level_change() {
		$t = $this->get_transformer();
		$html = '<h2 class="wp-block-heading">Title</h2>';
		$result = $t->auto_transform_html( 'core/heading', array( 'level' => 4 ), $html );
		$this->assertStringContainsString( '<h4', $result );
		$this->assertStringContainsString( '</h4>', $result );
		$this->assertStringNotContainsString( '<h2', $result );
	}

	public function test_heading_level_preserves_class() {
		$t = $this->get_transformer();
		$html = '<h2 class="wp-block-heading has-large-font-size">Title</h2>';
		$result = $t->auto_transform_html( 'core/heading', array( 'level' => 3 ), $html );
		$this->assertStringContainsString( '<h3 class="wp-block-heading has-large-font-size">', $result );
	}

	public function test_heading_content_change() {
		$t = $this->get_transformer();
		$html = '<h2 class="wp-block-heading">Old Title</h2>';
		$result = $t->auto_transform_html( 'core/heading', array( 'content' => 'New Title' ), $html );
		$this->assertStringContainsString( 'New Title', $result );
		$this->assertStringNotContainsString( 'Old Title', $result );
	}

	public function test_heading_level_and_content_combined() {
		$t = $this->get_transformer();
		$html = '<h2 class="wp-block-heading">Old</h2>';
		$result = $t->auto_transform_html( 'core/heading', array( 'level' => 5, 'content' => 'New' ), $html );
		$this->assertStringContainsString( '<h5', $result );
		$this->assertStringContainsString( 'New', $result );
	}

	public function test_group_tagname_change() {
		$t = $this->get_transformer();
		$html = "\n<div class=\"wp-block-group\">inner</div>\n";
		$result = $t->auto_transform_html( 'core/group', array( 'tagName' => 'section' ), $html );
		$this->assertStringContainsString( '<section', $result );
		$this->assertStringContainsString( '</section>', $result );
		$this->assertStringNotContainsString( '<div', $result );
	}

	public function test_group_tagname_rejects_invalid_tag() {
		$t = $this->get_transformer();
		$html = "\n<div class=\"wp-block-group\">inner</div>\n";
		$result = $t->auto_transform_html( 'core/group', array( 'tagName' => 'script' ), $html );
		// 'script' is not in the allowed tags list — should return null (no transform).
		$this->assertNull( $result );
	}

	// ── HTML attribute transforms (require WP_HTML_Tag_Processor) ──

	public function test_spacer_height() {
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$html = '<div style="height:50px" class="wp-block-spacer"></div>';
		$result = $t->auto_transform_html( 'core/spacer', array( 'height' => '100px' ), $html );
		$this->assertStringContainsString( 'height:100px', $result );
		$this->assertStringNotContainsString( '50px', $result );
	}

	public function test_details_open() {
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$html = '<details class="wp-block-details"><summary>S</summary></details>';
		$result = $t->auto_transform_html( 'core/details', array( 'showContent' => true ), $html );
		$this->assertStringContainsString( 'open', $result );
	}

	public function test_details_close() {
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$html = '<details class="wp-block-details" open><summary>S</summary></details>';
		$result = $t->auto_transform_html( 'core/details', array( 'showContent' => false ), $html );
		$this->assertStringNotContainsString( ' open', $result );
	}

	// ── Text content transforms (regex-based, no WP_HTML_Tag_Processor needed) ──

	public function test_quote_citation() {
		$t = $this->get_transformer();
		$html = '<blockquote class="wp-block-quote"><p>Quote.</p><cite>Old</cite></blockquote>';
		$result = $t->auto_transform_html( 'core/quote', array( 'citation' => 'New Author' ), $html );
		$this->assertStringContainsString( 'New Author', $result );
		$this->assertStringNotContainsString( '>Old<', $result );
	}

	public function test_citation_with_dollar_sign() {
		$t = $this->get_transformer();
		$html = '<blockquote class="wp-block-quote"><p>Q</p><cite>Old</cite></blockquote>';
		$result = $t->auto_transform_html( 'core/quote', array( 'citation' => 'Price is $100' ), $html );
		$this->assertStringContainsString( 'Price is $100', $result );
	}

	public function test_citation_added_when_missing() {
		$t = $this->get_transformer();
		$html = '<blockquote class="wp-block-quote"><p>Quote.</p></blockquote>';
		$result = $t->auto_transform_html( 'core/quote', array( 'citation' => 'Author' ), $html );
		$this->assertStringContainsString( '<cite>Author</cite>', $result );
	}

	public function test_paragraph_content_change() {
		$t = $this->get_transformer();
		$html = "\n<p class=\"\">Old text</p>\n";
		$result = $t->auto_transform_html( 'core/paragraph', array( 'content' => 'New text' ), $html );
		$this->assertStringContainsString( 'New text', $result );
		$this->assertStringNotContainsString( 'Old text', $result );
	}

	public function test_button_text_change() {
		$t = $this->get_transformer();
		$html = '<div class="wp-block-button"><a class="wp-block-button__link" href="/buy">Old Label</a></div>';
		$result = $t->auto_transform_html( 'core/button', array( 'text' => 'Buy Now' ), $html );
		$this->assertStringContainsString( 'Buy Now', $result );
		$this->assertStringContainsString( 'href="/buy"', $result ); // URL preserved.
	}

	// ── No-op tests ──

	public function test_no_transform_returns_null() {
		$t = $this->get_transformer();
		$html = '<p>Hello</p>';
		// 'align' is editor-only, not handled by auto_transform.
		$result = $t->auto_transform_html( 'core/paragraph', array( 'align' => 'center' ), $html );
		$this->assertNull( $result );
	}

	public function test_unknown_block_returns_null() {
		$t = $this->get_transformer();
		$html = '<div>whatever</div>';
		$result = $t->auto_transform_html( 'some/unknown-block', array( 'foo' => 'bar' ), $html );
		$this->assertNull( $result );
	}

	// ── rebuild_inner_content tests ──

	public function test_rebuild_leaf_block() {
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$old = array( '<p>old</p>' );
		$result = $t->rebuild_inner_content( $old, '<p>new</p>' );
		$this->assertEquals( array( '<p>new</p>' ), $result );
	}

	public function test_rebuild_preserves_nulls() {
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$old = array( '<div class="wp-block-group">', null, null, '</div>' );
		$new_html = '<section class="wp-block-group"></section>';
		$result = $t->rebuild_inner_content( $old, $new_html );
		$null_count = count( array_filter( $result, function( $v ) { return null === $v; } ) );
		$this->assertEquals( 2, $null_count, 'Should preserve 2 null placeholders' );
	}

	public function test_rebuild_single_child() {
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$old = array( '<div class="wp-block-group">', null, '</div>' );
		$new_html = '<section class="wp-block-group"></section>';
		$result = $t->rebuild_inner_content( $old, $new_html );
		$this->assertCount( 3, $result );
		$this->assertNull( $result[1] );
		$this->assertStringContainsString( '<section', $result[0] );
	}
}
