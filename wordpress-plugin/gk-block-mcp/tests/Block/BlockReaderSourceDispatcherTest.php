<?php
/**
 * Tests for Block_Reader's method-per-source dispatcher split.
 *
 * Verifies that the four sources we support — attribute, html, rich-text,
 * text — each have their own resolver method that can be called in
 * isolation. Lifted from vip-block-data-api's content-parser.php pattern
 * where every block.json source is a dedicated method, keeping the
 * dispatcher open-closed for new sources (e.g. query).
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Reader;

class BlockReaderSourceDispatcherTest extends BlockApiTestCase {

	/** @var Block_Reader */
	private $reader;

	public function set_up(): void {
		parent::set_up();
		$ref = new ReflectionProperty( Block_CRUD::class, 'reader' );
		$ref->setAccessible( true );
		$this->reader = $ref->getValue( $this->crud );
	}

	/**
	 * Call a private method on Block_Reader via reflection.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Args.
	 * @return mixed
	 */
	private function call_private( string $method, array $args ) {
		$reflection = new ReflectionMethod( Block_Reader::class, $method );
		$reflection->setAccessible( true );
		return $reflection->invokeArgs( $this->reader, $args );
	}

	// ── source_attribute reads an HTML attribute via Tag_Processor ────────

	public function test_source_attribute_reads_dom_attribute() {
		$html = '<figure><img src="https://example.com/x.png" alt="hello"/></figure>';
		$def  = array( 'selector' => 'img', 'attribute' => 'src' );

		$result = $this->call_private( 'source_attribute', array( $def, $html ) );

		$this->assertSame( 'https://example.com/x.png', $result );
	}

	public function test_source_attribute_returns_null_when_selector_missing() {
		$result = $this->call_private(
			'source_attribute',
			array( array( 'attribute' => 'src' ), '<img src="x"/>' )
		);
		$this->assertNull( $result );
	}

	public function test_source_attribute_returns_null_when_attribute_missing() {
		$result = $this->call_private(
			'source_attribute',
			array( array( 'selector' => 'img' ), '<img src="x"/>' )
		);
		$this->assertNull( $result );
	}

	public function test_source_attribute_returns_null_when_element_not_found() {
		$result = $this->call_private(
			'source_attribute',
			array(
				array( 'selector' => 'img', 'attribute' => 'src' ),
				'<p>no image here</p>',
			)
		);
		$this->assertNull( $result );
	}

	// ── source_html extracts inner HTML between matched tags ──────────────

	public function test_source_html_extracts_inner_html() {
		$html = '<h1 class="x"><strong>bold</strong> word</h1>';
		$def  = array( 'selector' => 'h1' );

		$result = $this->call_private( 'source_html', array( $def, $html ) );

		$this->assertSame( '<strong>bold</strong> word', $result );
	}

	public function test_source_html_returns_null_when_selector_missing() {
		$result = $this->call_private( 'source_html', array( array(), '<h1>x</h1>' ) );
		$this->assertNull( $result );
	}

	public function test_source_html_returns_null_when_no_match() {
		$result = $this->call_private(
			'source_html',
			array( array( 'selector' => 'h1' ), '<p>only paragraph</p>' )
		);
		$this->assertNull( $result );
	}

	// ── source_rich_text — same surface as source_html for now, distinct seam

	public function test_source_rich_text_extracts_inner_html() {
		$html = '<p>some <em>italic</em> text</p>';
		$def  = array( 'selector' => 'p' );

		$result = $this->call_private( 'source_rich_text', array( $def, $html ) );

		$this->assertSame( 'some <em>italic</em> text', $result );
	}

	public function test_source_rich_text_returns_null_when_selector_missing() {
		$result = $this->call_private( 'source_rich_text', array( array(), '<p>x</p>' ) );
		$this->assertNull( $result );
	}

	// ── source_text strips HTML tags from the matched inner HTML ──────────

	public function test_source_text_strips_html() {
		$html = '<h2><strong>Heading</strong> with <em>emphasis</em></h2>';
		$def  = array( 'selector' => 'h2' );

		$result = $this->call_private( 'source_text', array( $def, $html ) );

		$this->assertSame( 'Heading with emphasis', $result );
	}

	public function test_source_text_returns_null_when_no_match() {
		$result = $this->call_private(
			'source_text',
			array( array( 'selector' => 'h2' ), '<p>no heading</p>' )
		);
		$this->assertNull( $result );
	}

	// ── integration: dispatcher routes each source to the right resolver ──

	public function test_extract_sourced_attributes_dispatches_each_source() {
		// Register a synthetic block with one attr per supported source.
		$block_name = 'test/dispatch-' . uniqid();
		register_block_type(
			$block_name,
			array(
				'attributes' => array(
					'href'    => array( 'type' => 'string', 'source' => 'attribute', 'selector' => 'a', 'attribute' => 'href' ),
					'content' => array( 'type' => 'string', 'source' => 'html', 'selector' => 'h2' ),
					'subtitle' => array( 'type' => 'string', 'source' => 'rich-text', 'selector' => 'em' ),
					'plain'   => array( 'type' => 'string', 'source' => 'text', 'selector' => 'span' ),
				),
			)
		);

		$inner_html = '<h2><strong>Title</strong></h2><a href="https://gk.test/x">link</a><em>sub</em><span><b>plain</b> text</span>';

		$result = $this->call_private(
			'extract_sourced_attributes',
			array( $block_name, array(), $inner_html )
		);

		$this->assertSame( 'https://gk.test/x', $result['href'] );
		$this->assertSame( '<strong>Title</strong>', $result['content'] );
		$this->assertSame( 'sub', $result['subtitle'] );
		$this->assertSame( 'plain text', $result['plain'] );

		unregister_block_type( $block_name );
	}

	// ── delimiter values still win when present ───────────────────────────

	public function test_dispatcher_delimiter_attrs_win_over_dom_extraction() {
		$block_name = 'test/delim-wins-' . uniqid();
		register_block_type(
			$block_name,
			array(
				'attributes' => array(
					'content' => array( 'type' => 'string', 'source' => 'html', 'selector' => 'h2' ),
				),
			)
		);

		$inner_html = '<h2>from DOM</h2>';
		$parsed     = array( 'content' => 'from delimiter' );

		$result = $this->call_private(
			'extract_sourced_attributes',
			array( $block_name, $parsed, $inner_html )
		);

		$this->assertSame(
			'from delimiter',
			$result['content'],
			'Delimiter-parsed attrs must win over DOM-extracted ones.'
		);

		unregister_block_type( $block_name );
	}

	// ── query and meta sources are skipped (documented limitation) ────────

	public function test_dispatcher_skips_query_and_meta_sources() {
		$block_name = 'test/skip-' . uniqid();
		register_block_type(
			$block_name,
			array(
				'attributes' => array(
					'rows'   => array( 'type' => 'array', 'source' => 'query', 'selector' => 'tr', 'query' => array() ),
					'legacy' => array( 'type' => 'string', 'source' => 'meta', 'meta' => 'legacy_key' ),
				),
			)
		);

		$result = $this->call_private(
			'extract_sourced_attributes',
			array( $block_name, array(), '<table><tr><td>x</td></tr></table>' )
		);

		$this->assertArrayNotHasKey( 'rows', $result );
		$this->assertArrayNotHasKey( 'legacy', $result );

		unregister_block_type( $block_name );
	}
}
