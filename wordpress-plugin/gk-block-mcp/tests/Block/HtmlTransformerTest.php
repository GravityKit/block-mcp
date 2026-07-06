<?php
/**
 * Tests for the HTML_Transformer class.
 *
 * Tests auto_transform_html() and rebuild_inner_content() logic.
 * If HTML_Transformer is not yet extracted, tests are skipped.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\HTML_Transformer;

class HtmlTransformerTest extends WP_UnitTestCase {

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
		if ( class_exists( 'GravityKit\BlockMCP\HTML_Transformer' ) ) {
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

	/**
	 * core/group must reject void tags as a `tagName`.
	 *
	 * Pre-fix, core/group and core/separator shared one allowlist that
	 * included 'hr'. Asking for `core/group` with `tagName: 'hr'` would
	 * rewrite `<div class="wp-block-group">…</div>` to `<hr>…</hr>` — a
	 * void element holding inner content, which is invalid HTML and parses
	 * unpredictably across browsers. The fix splits the allowlist so void
	 * tags stay off the container path entirely.
	 */
	public function test_group_tagname_rejects_void_tag() {
		$t      = $this->get_transformer();
		$html   = "\n<div class=\"wp-block-group\">inner</div>\n";
		$result = $t->auto_transform_html( 'core/group', array( 'tagName' => 'hr' ), $html );
		$this->assertNull( $result, 'core/group must not rewrite to a void element such as <hr>.' );
	}

	/**
	 * core/separator's tagName only swaps among void tags and emits self-closing form.
	 *
	 * Pre-fix, the shared core/group + core/separator branch would happily
	 * rewrite `<hr>` to `<div>` (or any container tag), producing
	 * `<div>…</div>` for a separator — at best meaningless markup, at worst
	 * a layout-breaking wrapper. The fix limits core/separator's allowed
	 * tags to the void set (currently just `hr`) and normalizes the
	 * resulting tag to the self-closing form so serialization stays
	 * deterministic.
	 */
	public function test_separator_tagname_normalizes_void_form() {
		$t      = $this->get_transformer();
		$html   = '<hr class="wp-block-separator">';
		$result = $t->auto_transform_html( 'core/separator', array( 'tagName' => 'hr' ), $html );
		// hr → hr with `/>` is the only normalization; the test pins the
		// self-closing form so serialize_blocks output stays stable.
		$this->assertSame(
			'<hr class="wp-block-separator" />',
			$result,
			'core/separator must emit <hr ... /> self-closing form.'
		);
	}

	public function test_separator_tagname_rejects_container_tag() {
		$t      = $this->get_transformer();
		$html   = '<hr class="wp-block-separator">';
		$result = $t->auto_transform_html( 'core/separator', array( 'tagName' => 'div' ), $html );
		$this->assertNull(
			$result,
			'core/separator must reject container tags — emitting <div></div> for a separator is silently destructive.'
		);
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

	public function test_height_does_not_match_compound_properties() {
		// Regression: when `line-height` precedes `height` in the style attribute,
		// a naive regex without a word boundary rewrites `line-height` (first match)
		// and leaves the real `height` stale.
		$this->require_tag_processor();
		$t = $this->get_transformer();
		$html = '<div style="line-height: 1.5; height: 50px;" class="wp-block-spacer"></div>';
		$result = $t->auto_transform_html( 'core/spacer', array( 'height' => '100px' ), $html );
		$this->assertStringContainsString( 'line-height: 1.5', $result, 'line-height must not be rewritten.' );
		$this->assertStringContainsString( 'height:100px', $result, 'height must be rewritten to the new value.' );
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

	// ── Regression: string "false" / "0" must disable (not enable) the attr ──

	public function test_details_close_with_string_false() {
		$this->require_tag_processor();
		$t      = $this->get_transformer();
		$html   = '<details class="wp-block-details" open><summary>S</summary></details>';
		$result = $t->auto_transform_html( 'core/details', array( 'showContent' => 'false' ), $html );
		$this->assertStringNotContainsString( ' open', $result, 'String "false" must disable showContent, not enable it via PHP truthiness.' );
	}

	public function test_video_autoplay_with_string_false() {
		$this->require_tag_processor();
		$t      = $this->get_transformer();
		$html   = '<video src="x.mp4" autoplay></video>';
		$result = $t->auto_transform_html( 'core/video', array( 'autoplay' => 'false' ), $html );
		$this->assertStringNotContainsString( 'autoplay', $result, 'String "false" must disable autoplay.' );
	}

	// ── Regression: preg_replace replacement injection ($N, \N) in user input ─

	public function test_height_with_dollar_n_in_value() {
		$this->require_tag_processor();
		$t      = $this->get_transformer();
		// A literal "$1" in the width value would be interpreted as a backreference
		// by preg_replace, swallowing the captured trailing semicolon and producing
		// corrupted CSS like `width:100;;` or worse.
		$html   = '<div style="width: 50px; padding: 10px;"></div>';
		$result = $t->auto_transform_html( 'core/spacer', array( 'width' => '100$1px' ), $html );
		$this->assertStringContainsString( 'width:100$1px', $result, 'Dollar-N in value must survive verbatim — not be interpreted as a regex backreference.' );
	}

	public function test_codeHTML_with_dollar_n_in_value() {
		$t      = $this->get_transformer();
		$html   = '<div><pre class="shiki"><code>old</code></pre></div>';
		// codeHTML with $1 inside — old code used preg_replace and would have
		// substituted the captured group, mangling the output.
		$new    = '<pre class="shiki"><code>new $1 still $1</code></pre>';
		$result = $t->auto_transform_html( 'kevinbatdorf/code-block-pro', array( 'codeHTML' => $new ), $html );
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'new $1 still $1', $result, 'Dollar-N must survive verbatim inside codeHTML replacement.' );
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

	/**
	 * core/code stores `content` as source:html, selector:code — its markup is
	 * two-level (`<pre class="wp-block-code"><code>…</code></pre>`). The generic
	 * content transform replaces everything between the first opening tag and the
	 * last closing tag, which for core/code spans the whole `<code>…</code>`
	 * element and discards the `<code>` wrapper — the editor then can't find the
	 * content via the `code` selector and flags the block invalid. The new
	 * content must land inside `<code>`, leaving the `<pre>`/`<code>` structure
	 * intact.
	 */
	public function test_code_content_change_preserves_code_wrapper() {
		$t      = $this->get_transformer();
		$html   = '<pre class="wp-block-code"><code>old()</code></pre>';
		$result = $t->auto_transform_html( 'core/code', array( 'content' => 'new()' ), $html );
		$this->assertStringContainsString( '<code>new()</code>', $result, 'content must stay inside the <code> wrapper' );
		$this->assertStringContainsString( 'wp-block-code', $result, 'the <pre> wrapper and its class must be preserved' );
		$this->assertStringNotContainsString( 'old()', $result );
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

	// ── CBP auto-transform tests ──

	public function test_cbp_codehtml_replaces_pre() {
		$t = $this->get_transformer();
		$html = '<div class="wp-block-kevinbatdorf-code-block-pro">'
			. '<pre class="shiki css-variables" style="background:#fff"><code>OLD</code></pre>'
			. '</div>';
		$new_pre = '<pre class="shiki css-variables" style="background:#000"><code>NEW</code></pre>';
		$result = $t->auto_transform_html(
			'kevinbatdorf/code-block-pro',
			array( 'codeHTML' => $new_pre ),
			$html
		);
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'NEW', $result );
		$this->assertStringNotContainsString( 'OLD', $result );
	}

	public function test_cbp_code_updates_textarea() {
		$t = $this->get_transformer();
		$html = '<div class="wp-block-kevinbatdorf-code-block-pro">'
			. '<textarea class="code-block-pro-copy-button-textarea" tabindex="-1" aria-hidden="true" readonly>old code</textarea>'
			. '</div>';
		$result = $t->auto_transform_html(
			'kevinbatdorf/code-block-pro',
			array( 'code' => 'new code' ),
			$html
		);
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'new code', $result );
		$this->assertStringNotContainsString( 'old code', $result );
	}

	public function test_cbp_codehtml_and_code_together() {
		$t = $this->get_transformer();
		$html = '<div class="wp-block-kevinbatdorf-code-block-pro">'
			. '<pre class="shiki css-variables" style="background:#fff"><code>OLD_PRE</code></pre>'
			. '<textarea class="code-block-pro-copy-button-textarea" tabindex="-1" aria-hidden="true" readonly>OLD_CODE</textarea>'
			. '</div>';
		$new_pre = '<pre class="shiki css-variables" style="background:#000"><code>NEW_PRE</code></pre>';
		$result = $t->auto_transform_html(
			'kevinbatdorf/code-block-pro',
			array(
				'codeHTML' => $new_pre,
				'code'     => 'NEW_CODE',
			),
			$html
		);
		$this->assertNotNull( $result );
		$this->assertStringContainsString( 'NEW_PRE', $result );
		$this->assertStringNotContainsString( 'OLD_PRE', $result );
		$this->assertStringContainsString( 'NEW_CODE', $result );
		$this->assertStringNotContainsString( 'OLD_CODE', $result );
	}

	public function test_cbp_no_pre_returns_null() {
		$t = $this->get_transformer();
		// innerHTML has no <pre class="shiki"> — the codeHTML replacement finds nothing to swap.
		$html = '<div class="wp-block-kevinbatdorf-code-block-pro"><p>placeholder</p></div>';
		$result = $t->auto_transform_html(
			'kevinbatdorf/code-block-pro',
			array( 'codeHTML' => '<pre class="shiki css-variables"><code>X</code></pre>' ),
			$html
		);
		// preg_replace with no match returns the original string unchanged → null.
		$this->assertNull( $result );
	}

	public function test_cbp_other_block_unaffected() {
		$t = $this->get_transformer();
		$html = '<p class="">Hello</p>';
		// core/paragraph doesn't have a CBP transform; codeHTML is not handled for it.
		$result = $t->auto_transform_html(
			'core/paragraph',
			array( 'codeHTML' => '<pre class="shiki css-variables"><code>X</code></pre>' ),
			$html
		);
		$this->assertNull( $result );
	}

	public function test_cbp_code_with_dollar_sign() {
		$t = $this->get_transformer();
		$html = '<div class="wp-block-kevinbatdorf-code-block-pro">'
			. '<textarea class="code-block-pro-copy-button-textarea" tabindex="-1" aria-hidden="true" readonly>old</textarea>'
			. '</div>';
		$result = $t->auto_transform_html(
			'kevinbatdorf/code-block-pro',
			array( 'code' => '$var = \'hello\';' ),
			$html
		);
		$this->assertNotNull( $result );
		// esc_html encodes ' as &#039; — assert the dollar sign and variable name appear literally.
		$this->assertStringContainsString( '$var', $result );
		$this->assertStringNotContainsString( 'old', $result );
	}
}
