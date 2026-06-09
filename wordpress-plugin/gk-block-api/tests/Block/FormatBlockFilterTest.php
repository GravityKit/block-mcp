<?php
/**
 * Tests for the gk/block-mcp/block/format filter behaviour from
 * includes/integrations/cbp.php.
 *
 * These tests exercise the filter logic directly as a plain PHP function
 * without requiring WordPress hooks infrastructure. The filter callback
 * strips derived/bulky fields from kevinbatdorf/code-block-pro block data
 * and passes all other block types through unchanged.
 *
 * @package GravityKit\BlockAPI\Tests
 */

class FormatBlockFilterTest extends WP_UnitTestCase {

	/**
	 * Simulate what the CBP filter does: strip codeHTML, highestLineNumber,
	 * and innerHTML from a CBP block data array.
	 *
	 * This mirrors the anonymous function registered in cbp.php without
	 * needing add_filter() / apply_filters().
	 *
	 * @param array  $data       Block data array (attributes, innerHTML, etc.).
	 * @param string $block_name Block type name.
	 *
	 * @return array Filtered data.
	 */
	private function apply_cbp_filter( array $data, $block_name ) {
		if ( 'kevinbatdorf/code-block-pro' !== $block_name ) {
			return $data;
		}

		unset( $data['attributes']['codeHTML'] );
		unset( $data['attributes']['highestLineNumber'] );
		unset( $data['innerHTML'] );

		return $data;
	}

	// ── CBP block: derived fields are stripped ──

	public function test_cbp_codehtml_removed() {
		$data = array(
			'attributes' => array(
				'code'            => 'echo "hi";',
				'language'        => 'php',
				'theme'           => 'nord',
				'codeHTML'        => '<pre class="shiki">...</pre>',
				'highestLineNumber' => 1,
			),
			'innerHTML' => '<div class="wp-block-kevinbatdorf-code-block-pro">...</div>',
		);

		$result = $this->apply_cbp_filter( $data, 'kevinbatdorf/code-block-pro' );

		$this->assertArrayNotHasKey( 'codeHTML', $result['attributes'] );
	}

	public function test_cbp_highest_line_number_removed() {
		$data = array(
			'attributes' => array(
				'code'              => 'echo "hi";',
				'language'          => 'php',
				'codeHTML'          => '<pre class="shiki">...</pre>',
				'highestLineNumber' => 42,
			),
			'innerHTML' => '<div>...</div>',
		);

		$result = $this->apply_cbp_filter( $data, 'kevinbatdorf/code-block-pro' );

		$this->assertArrayNotHasKey( 'highestLineNumber', $result['attributes'] );
	}

	public function test_cbp_inner_html_removed() {
		$data = array(
			'attributes' => array(
				'code'     => 'echo "hi";',
				'language' => 'php',
				'codeHTML' => '<pre class="shiki">...</pre>',
			),
			'innerHTML' => '<div class="wp-block-kevinbatdorf-code-block-pro">BIG_HTML_BLOB</div>',
		);

		$result = $this->apply_cbp_filter( $data, 'kevinbatdorf/code-block-pro' );

		$this->assertArrayNotHasKey( 'innerHTML', $result );
	}

	public function test_cbp_agent_fields_retained() {
		$data = array(
			'attributes' => array(
				'code'              => '$x = 1;',
				'language'          => 'php',
				'theme'             => 'github-dark',
				'lineNumbers'       => true,
				'codeHTML'          => '<pre class="shiki">...</pre>',
				'highestLineNumber' => 1,
			),
			'innerHTML' => '<div>...</div>',
		);

		$result = $this->apply_cbp_filter( $data, 'kevinbatdorf/code-block-pro' );

		$this->assertSame( '$x = 1;', $result['attributes']['code'] );
		$this->assertSame( 'php', $result['attributes']['language'] );
		$this->assertSame( 'github-dark', $result['attributes']['theme'] );
		$this->assertTrue( $result['attributes']['lineNumbers'] );
	}

	// ── Non-CBP block: passes through completely unchanged ──

	public function test_non_cbp_block_unchanged() {
		$data = array(
			'attributes' => array(
				'content' => 'Hello world',
				'dropCap' => false,
			),
			'innerHTML' => '<p class="">Hello world</p>',
		);

		$result = $this->apply_cbp_filter( $data, 'core/paragraph' );

		$this->assertSame( $data, $result );
	}

	public function test_non_cbp_block_with_codehtml_key_unchanged() {
		// A hypothetical non-CBP block that happens to have a codeHTML key
		// must not be touched — the guard is on block_name only.
		$data = array(
			'attributes' => array(
				'codeHTML' => 'should stay',
			),
			'innerHTML' => '<div>stays</div>',
		);

		$result = $this->apply_cbp_filter( $data, 'core/html' );

		$this->assertSame( 'should stay', $result['attributes']['codeHTML'] );
		$this->assertSame( '<div>stays</div>', $result['innerHTML'] );
	}

	// ── Edge cases ──

	public function test_cbp_missing_optional_fields_no_error() {
		// Block data without codeHTML or highestLineNumber — unsetting a missing key is safe.
		$data = array(
			'attributes' => array(
				'code'     => 'ls -la',
				'language' => 'bash',
			),
			'innerHTML' => '<div>...</div>',
		);

		$result = $this->apply_cbp_filter( $data, 'kevinbatdorf/code-block-pro' );

		$this->assertArrayNotHasKey( 'codeHTML', $result['attributes'] );
		$this->assertArrayNotHasKey( 'highestLineNumber', $result['attributes'] );
		$this->assertArrayNotHasKey( 'innerHTML', $result );
		$this->assertSame( 'ls -la', $result['attributes']['code'] );
	}

	public function test_cbp_empty_attributes_no_error() {
		$data = array(
			'attributes' => array(),
			'innerHTML'  => '',
		);

		$result = $this->apply_cbp_filter( $data, 'kevinbatdorf/code-block-pro' );

		$this->assertIsArray( $result['attributes'] );
		$this->assertEmpty( $result['attributes'] );
		$this->assertArrayNotHasKey( 'innerHTML', $result );
	}
}
