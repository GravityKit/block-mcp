<?php
/**
 * Scenario 11: auto-transform combinatorics.
 *
 * HTML_Transformer::auto_transform_html() drives 4 categories:
 *   - regex tag swaps (heading level, list ordered, group tagName)
 *   - HTML attribute transforms (url→href/src/alt, boolean attrs)
 *   - CSS inline-style transforms (height/width)
 *   - text-content regex (citation, etc.)
 *
 * Each branch is regex- or Tag_Processor-driven, both of which are
 * easy to mangle on adversarial inputs (minified HTML, comments inside
 * the target tag, the same tag nested inside itself). This battery
 * runs each branch against a small fixture table of realistic inputs
 * + a handful of adversarial inputs and asserts the transform either
 * correctly updates the OUTERMOST matching element or returns null
 * (the safety guard would fire). Either is acceptable. Mangling an
 * inner element is not.
 *
 * @package GravityKit\BlockMCP\Tests\Stress
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\HTML_Transformer;

class AutoTransformCombinatoricsTest extends WP_UnitTestCase {

	/** @var HTML_Transformer */
	private $t;

	public function set_up(): void {
		parent::set_up();
		$this->t = new HTML_Transformer();
	}

	// ── heading level swap ────────────────────────────────────────

	public function test_heading_level_swap_h2_to_h3() {
		$out = $this->t->auto_transform_html( 'core/heading', array( 'level' => 3 ), '<h2>Title</h2>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<h3', $out );
		$this->assertStringNotContainsString( '<h2', $out );
		$this->assertStringContainsString( 'Title', $out );
	}

	public function test_heading_level_swap_h1_to_h6_extremes() {
		$out = $this->t->auto_transform_html( 'core/heading', array( 'level' => 6 ), '<h1>X</h1>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<h6', $out );
		$this->assertStringNotContainsString( '<h1', $out );
	}

	public function test_heading_level_swap_preserves_attributes() {
		$out = $this->t->auto_transform_html( 'core/heading', array( 'level' => 4 ), '<h2 class="hero">Hello</h2>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( 'class="hero"', $out );
	}

	/**
	 * Outer is h2; inner content references h3 as text — the swap
	 * must target the OUTERMOST tag, not the text mention.
	 */
	public function test_heading_level_swap_does_not_mangle_unrelated_h_tags_in_content() {
		$out = $this->t->auto_transform_html( 'core/heading', array( 'level' => 5 ), '<h2>About <code>&lt;h3&gt;</code></h2>' );
		$this->assertIsString( $out );
		$this->assertStringStartsWith( '<h5', $out );
		$this->assertStringContainsString( '&lt;h3&gt;', $out, 'text-content h3 reference must survive untouched' );
	}

	// ── list ordered toggle ───────────────────────────────────────

	public function test_list_ordered_toggle_ul_to_ol() {
		$out = $this->t->auto_transform_html( 'core/list', array( 'ordered' => true ), '<ul><li>a</li></ul>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<ol', $out );
		$this->assertStringContainsString( '</ol>', $out );
		$this->assertStringNotContainsString( '<ul', $out );
	}

	public function test_list_ordered_toggle_ol_to_ul() {
		$out = $this->t->auto_transform_html( 'core/list', array( 'ordered' => false ), '<ol><li>a</li></ol>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<ul', $out );
		$this->assertStringContainsString( '</ul>', $out );
		$this->assertStringNotContainsString( '<ol', $out );
	}

	public function test_list_swap_does_not_mangle_nested_list_text() {
		// A list containing the literal text "ul" — must not touch it.
		$out = $this->t->auto_transform_html( 'core/list', array( 'ordered' => true ), '<ul><li>ul stands for unordered</li></ul>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( 'ul stands for unordered', $out );
	}

	// ── group tagName swap ────────────────────────────────────────

	public function test_group_tagname_swap_div_to_section() {
		$out = $this->t->auto_transform_html( 'core/group', array( 'tagName' => 'section' ), '<div class="wp-block-group"></div>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<section', $out );
		$this->assertStringContainsString( '</section>', $out );
	}

	public function test_group_tagname_swap_to_aside() {
		$out = $this->t->auto_transform_html( 'core/group', array( 'tagName' => 'aside' ), '<div></div>' );
		$this->assertIsString( $out );
		$this->assertStringContainsString( '<aside', $out );
	}

	// ── ambiguous / no-op inputs ──────────────────────────────────

	public function test_unchanged_attrs_returns_null_or_unchanged() {
		// No attr changes — transformer may decline (null) or echo input.
		$out = $this->t->auto_transform_html( 'core/paragraph', array(), '<p>same</p>' );
		if ( null !== $out ) {
			$this->assertStringContainsString( '<p>same</p>', $out );
		}
		$this->addToAssertionCount( 1 ); // no-op is acceptable.
	}

	public function test_unknown_block_returns_null() {
		$out = $this->t->auto_transform_html( 'unknown/block', array( 'level' => 3 ), '<x>1</x>' );
		$this->assertNull( $out, 'unknown blocks must fall through so the safety guard runs' );
	}

	public function test_unrelated_attr_change_returns_null_for_known_block() {
		// 'flubber' is not in any auto-transform branch for core/heading.
		$out = $this->t->auto_transform_html( 'core/heading', array( 'flubber' => 'green' ), '<h2>x</h2>' );
		$this->assertNull( $out, 'unrecognized attr for known block must fall through' );
	}

	// ── adversarial wrappers ──────────────────────────────────────

	/**
	 * No whitespace anywhere — exercises regex anchors / boundary
	 * detection.
	 */
	public function test_heading_swap_with_minified_input() {
		$out = $this->t->auto_transform_html( 'core/heading', array( 'level' => 3 ), '<h2 id="x"><strong>bold</strong>tail</h2>' );
		$this->assertIsString( $out );
		$this->assertStringStartsWith( '<h3', $out );
		$this->assertStringContainsString( '</h3>', $out );
		$this->assertStringContainsString( '<strong>bold</strong>', $out, 'inner markup must be preserved' );
	}

	public function test_list_swap_preserves_inner_list_items() {
		$out = $this->t->auto_transform_html(
			'core/list',
			array( 'ordered' => true ),
			'<ul class="wp-block-list"><li>a</li><li>b</li><li>c</li></ul>'
		);
		$this->assertIsString( $out );
		// Count list items.
		$this->assertSame(
			3,
			substr_count( $out, '<li>' ),
			'all three list items must survive the ul→ol swap'
		);
	}
}
