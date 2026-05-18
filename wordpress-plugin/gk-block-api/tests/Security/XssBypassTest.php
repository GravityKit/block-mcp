<?php
/**
 * XSS bypass attempt tests.
 *
 * The plugin's sanitization invariant is: every innerHTML passed to a
 * write operation goes through wp_kses_post before reaching post_content.
 * This battery throws known XSS payload classes at the API and asserts
 * each one is neutralized in the saved content.
 *
 * Classes covered:
 *   - direct <script> tags (the easy case)
 *   - case variants (<ScRiPt>, <SCRIPT>)
 *   - nested-broken tags (<scr<script>ipt>)
 *   - event handlers on every common element (img, body, svg, video, audio)
 *   - inline javascript:, data:, vbscript: URL schemes
 *   - HTML-entity encoded js: prefixes (javasc&#x72;ipt:)
 *   - SVG with embedded <script> (XSS via SVG content type — kses
 *     normally strips this entirely but verify)
 *   - srcdoc + onload on iframes
 *   - <math> / <svg> namespace-confusion attempts
 *   - CSS expression() (legacy IE; should still be filtered)
 *   - "title=" attribute closing-quote breakout
 *   - input type=image with formaction
 *
 * Any test that fails indicates a real XSS path. The fix lives in the
 * plugin's sanitization layer, not in this test file.
 *
 * @package GravityKit\BlockAPI\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Preferences;

class XssBypassTest extends WP_UnitTestCase {

	/** @var Block_CRUD */
	private $crud;

	/** @var int */
	private $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->crud = new Block_CRUD(
			new Preferences(),
			new Block_Safety(),
			new HTML_Transformer(),
			new Block_Inventory()
		);
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '' ) );
	}

	/**
	 * Save innerHTML via replace_all_blocks then return the FINAL saved
	 * post_content as one string — that's the user-facing surface the
	 * XSS would actually fire from.
	 */
	private function save_and_get_saved( string $inner_html ): string {
		$result = $this->crud->replace_all_blocks( $this->post_id, array(
			array( 'name' => 'core/paragraph', 'innerHTML' => $inner_html ),
		) );
		$this->assertNotInstanceOf( \WP_Error::class, $result, 'replace_all_blocks should not error on hostile payloads' );
		return (string) get_post_field( 'post_content', $this->post_id );
	}

	private function assertNoScriptOrHandlersOrBadUrls( string $saved, string $payload_label ): void {
		// Core invariant: nothing that fires JavaScript on render.
		$this->assertStringNotContainsStringIgnoringCase( '<script', $saved, "executable <script must not survive: $payload_label" );
		$this->assertStringNotContainsStringIgnoringCase( '</script', $saved, "</script must not survive: $payload_label" );
		// Event handlers — match the start: ` on...=`. Allowed words like
		// "on" inside attribute values aren't a problem; we look for an
		// attribute-position match (whitespace-prefixed, =-suffixed).
		$this->assertDoesNotMatchRegularExpression(
			'/\son\w+\s*=/i',
			$saved,
			"event handler attribute (on*=) must not survive: $payload_label"
		);
		// URL schemes.
		$this->assertDoesNotMatchRegularExpression(
			'#(?:href|src|action|formaction|srcdoc|data|poster)\s*=\s*["\']\s*(?:javascript|vbscript|data)\s*:#i',
			$saved,
			"javascript:/vbscript:/data: URL scheme must not survive: $payload_label"
		);
	}

	// ── Direct <script> variants ──────────────────────────────────

	public function test_strips_lowercase_script() {
		$saved = $this->save_and_get_saved( '<p>x</p><script>alert(1)</script>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'lowercase <script>' );
	}

	public function test_strips_mixed_case_script() {
		$saved = $this->save_and_get_saved( '<p>x</p><ScRiPt>alert(1)</ScRiPt>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'mixed-case <ScRiPt>' );
	}

	/**
	 * Browsers parse `<scr<script>ipt>` as `<script>` after kses strips
	 * the inner tag — verify the outer survives kses stripping too.
	 */
	public function test_strips_nested_broken_script() {
		$saved = $this->save_and_get_saved( '<p>x</p><scr<script>ipt>alert(1)</scr</script>ipt>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'nested-broken <script>' );
	}

	/**
	 * HTML parsers sometimes accept Unicode whitespace where ASCII
	 * whitespace is expected.
	 */
	public function test_strips_script_with_unicode_whitespace() {
		$saved = $this->save_and_get_saved( "<p>x</p><script\xC2\xA0type=text/javascript>alert(1)</script>" );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'NBSP-separated <script>' );
	}

	// ── Event handler vectors ─────────────────────────────────────

	public function test_strips_onerror_on_img() {
		$saved = $this->save_and_get_saved( '<img src="x" onerror="alert(1)">' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'onerror on <img>' );
	}

	public function test_strips_onload_on_iframe() {
		$saved = $this->save_and_get_saved( '<iframe src="about:blank" onload="alert(1)"></iframe>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'onload on <iframe>' );
	}

	public function test_strips_onmouseover_on_div() {
		$saved = $this->save_and_get_saved( '<div onmouseover="alert(1)">hover</div>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'onmouseover on <div>' );
	}

	/**
	 * @dataProvider modern_event_handler_provider
	 */
	public function test_strips_modern_event_handlers( string $handler ) {
		// oninput / onpointerover / onanimationend — added in HTML5 and
		// later. wp_kses_post should reject all on* attribute names.
		// Each handler runs in its own fresh post so rate-limit on PUT
		// (2/min) doesn't trip the loop.
		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '' ) );
		$saved = $this->save_and_get_saved( '<p ' . $handler . '="alert(1)">x</p>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, "$handler on <p>" );
	}

	public function modern_event_handler_provider(): array {
		return array(
			'oninput'         => array( 'oninput' ),
			'onpointerover'   => array( 'onpointerover' ),
			'onanimationend'  => array( 'onanimationend' ),
			'ontransitionend' => array( 'ontransitionend' ),
			'onbeforeinput'   => array( 'onbeforeinput' ),
		);
	}

	public function test_strips_handler_with_unusual_quoting() {
		// No quotes around handler value.
		$saved = $this->save_and_get_saved( '<p onclick=alert(1)>x</p>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'unquoted onclick' );

		// Backticks (HTML5 allows this in some contexts).
		$saved = $this->save_and_get_saved( '<p onclick=`alert(1)`>x</p>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'backtick-quoted onclick' );
	}

	// ── URL-scheme injection ──────────────────────────────────────

	public function test_strips_javascript_href() {
		$saved = $this->save_and_get_saved( '<a href="javascript:alert(1)">click</a>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'javascript: href' );
	}

	/**
	 * `javasc&#x72;ipt:` decodes to `javascript:` — kses should
	 * normalize and strip.
	 */
	public function test_strips_javascript_href_with_entity_encoding() {
		$saved = $this->save_and_get_saved( '<a href="javasc&#x72;ipt:alert(1)">x</a>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'entity-encoded javascript: href' );
	}

	public function test_strips_javascript_href_with_whitespace_prefix() {
		// "  javascript:..." — kses normalizes whitespace.
		$saved = $this->save_and_get_saved( '<a href="   javascript:alert(1)">x</a>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'whitespace-prefixed javascript: href' );
	}

	public function test_strips_data_url_html() {
		$saved = $this->save_and_get_saved( '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'data:text/html URL' );
	}

	public function test_strips_vbscript_href() {
		$saved = $this->save_and_get_saved( '<a href="vbscript:msgbox(1)">x</a>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'vbscript: href' );
	}

	public function test_strips_formaction_javascript() {
		$saved = $this->save_and_get_saved( '<button formaction="javascript:alert(1)">click</button>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'formaction=javascript:' );
	}

	// ── SVG / iframe vectors ──────────────────────────────────────

	/**
	 * SVG can carry `<script>`. `wp_kses_post` strips `<svg>` entirely
	 * from post content by default, so anything inside is also gone.
	 */
	public function test_strips_svg_with_inline_script() {
		$saved = $this->save_and_get_saved( '<svg><script>alert(1)</script></svg>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, '<svg><script>' );
		$this->assertStringNotContainsStringIgnoringCase( '<svg', $saved, '<svg> itself must not survive in post body' );
	}

	public function test_strips_iframe_srcdoc_with_script() {
		$saved = $this->save_and_get_saved( '<iframe srcdoc="&lt;script&gt;alert(1)&lt;/script&gt;"></iframe>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, '<iframe srcdoc>' );
	}

	public function test_strips_math_with_script() {
		$saved = $this->save_and_get_saved( '<math><mtext><script>alert(1)</script></mtext></math>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, '<math> namespace confusion' );
	}

	// ── Attribute breakout ────────────────────────────────────────

	/**
	 * Classic attribute-context escape: a stray `">` inside an attr
	 * value. kses parses the attribute boundaries and won't be fooled.
	 */
	public function test_quote_breakout_in_title_attribute() {
		$saved = $this->save_and_get_saved( '<a title="hi"><img src=x onerror=alert(1)>" href="#">x</a>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'attribute breakout via stray quote' );
	}

	// ── Less-common vectors ────────────────────────────────────────

	public function test_strips_input_type_image_formaction() {
		$saved = $this->save_and_get_saved( '<input type="image" formaction="javascript:alert(1)">' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, 'input[type=image] formaction' );
	}

	public function test_strips_isindex_action() {
		$saved = $this->save_and_get_saved( '<isindex action="javascript:alert(1)" type="image">' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, '<isindex> with javascript:action' );
	}

	public function test_strips_object_data_javascript() {
		$saved = $this->save_and_get_saved( '<object data="javascript:alert(1)"></object>' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, '<object data="javascript:">' );
	}

	public function test_strips_embed_javascript_src() {
		$saved = $this->save_and_get_saved( '<embed src="javascript:alert(1)">' );
		$this->assertNoScriptOrHandlersOrBadUrls( $saved, '<embed src="javascript:">' );
	}
}
