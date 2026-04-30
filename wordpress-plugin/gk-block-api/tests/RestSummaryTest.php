<?php
/**
 * Tests for REST_Controller's private summary + outline helpers.
 *
 * Uses reflection to exercise:
 *   - build_blocks_summary()
 *   - walk_blocks_for_summary()
 *   - extract_outline()
 *   - walk_blocks_for_outline()
 *
 * These methods are private because they are implementation details of
 * the `/posts/{id}/blocks` endpoint. Testing them directly avoids the
 * need for a full WP_REST_Request / WP_REST_Response stub chain.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\REST_Controller;
use GravityKit\BlockAPI\Block_Registry;
use GravityKit\BlockAPI\Pattern_Manager;
use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Mutator;
use GravityKit\BlockAPI\Preferences;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Post_Manager;
use GravityKit\BlockAPI\Term_Manager;
use GravityKit\BlockAPI\Media_Manager;

class RestSummaryTest extends \PHPUnit\Framework\TestCase {

	/** @var REST_Controller */
	private $controller;

	protected function setUp(): void {
		$preferences     = new Preferences();
		$safety          = new Block_Safety();
		$transformer     = new HTML_Transformer();
		$block_inventory = new Block_Inventory();
		$crud            = new Block_CRUD( $preferences, $safety, $transformer, $block_inventory );
		$mutator         = new Block_Mutator( $crud, $preferences, $safety, $transformer );
		$registry        = new Block_Registry( $preferences, $block_inventory );
		$patterns        = new Pattern_Manager( $preferences );

		$this->controller = new REST_Controller(
			$registry,
			$patterns,
			$crud,
			$block_inventory,
			$mutator,
			new Post_Manager( $crud ),
			new Term_Manager(),
			new Media_Manager(),
			$preferences
		);
	}

	/**
	 * Invoke a private method on the controller via reflection.
	 *
	 * @param string $method_name Method to call.
	 * @param array  $args        Arguments.
	 *
	 * @return mixed
	 */
	private function callPrivate( string $method_name, array $args ) {
		$reflection = new \ReflectionClass( REST_Controller::class );
		$method     = $reflection->getMethod( $method_name );
		// PHP 8.1+ allows ReflectionMethod::invokeArgs on private methods
		// without setAccessible; setAccessible is deprecated.
		return $method->invokeArgs( $this->controller, $args );
	}

	// ── build_blocks_summary ───────────────────────────────────────

	public function test_summary_counts_total_blocks() {
		$blocks = array(
			array( 'name' => 'core/heading',   'path' => array( 0 ), 'attributes' => array( 'level' => 2 ) ),
			array( 'name' => 'core/paragraph', 'path' => array( 1 ) ),
			array( 'name' => 'core/heading',   'path' => array( 2 ), 'attributes' => array( 'level' => 3 ) ),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertEquals( 3, $summary['total_blocks'] );
		$this->assertEquals( 3, $summary['top_level_blocks'] );
		$this->assertEquals( 0, $summary['max_path_depth'] );
	}

	public function test_summary_block_types_counted_and_sorted_descending() {
		$blocks = array(
			array( 'name' => 'core/paragraph', 'path' => array( 0 ) ),
			array( 'name' => 'core/heading',   'path' => array( 1 ) ),
			array( 'name' => 'core/paragraph', 'path' => array( 2 ) ),
			array( 'name' => 'core/paragraph', 'path' => array( 3 ) ),
			array( 'name' => 'core/heading',   'path' => array( 4 ) ),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertEquals( 3, $summary['block_types']['core/paragraph'] );
		$this->assertEquals( 2, $summary['block_types']['core/heading'] );

		// Verify descending sort: paragraphs (3) should come before headings (2).
		$keys = array_keys( $summary['block_types'] );
		$this->assertEquals( 'core/paragraph', $keys[0] );
		$this->assertEquals( 'core/heading',   $keys[1] );
	}

	public function test_summary_sections_extracts_metadata_name_and_path() {
		$blocks = array(
			array(
				'name'    => 'core/group',
				'path'    => array( 0 ),
				'section' => 'Hero',
			),
			array(
				'name'    => 'core/group',
				'path'    => array( 1 ),
				'section' => 'Features',
			),
			array( 'name' => 'core/paragraph', 'path' => array( 2 ) ),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertCount( 2, $summary['sections'] );
		$this->assertEquals( 'Hero',     $summary['sections'][0]['name'] );
		$this->assertEquals( array( 0 ), $summary['sections'][0]['path'] );
		$this->assertEquals( 'Features', $summary['sections'][1]['name'] );
		$this->assertEquals( array( 1 ), $summary['sections'][1]['path'] );
	}

	public function test_summary_headings_extracts_level_text_path() {
		$blocks = array(
			array(
				'name'         => 'core/heading',
				'path'         => array( 0 ),
				'attributes'   => array( 'level' => 2 ),
				'text_preview' => 'Welcome',
			),
			array(
				'name'         => 'core/heading',
				'path'         => array( 3 ),
				'attributes'   => array( 'level' => 3 ),
				'text_preview' => 'Subtitle',
			),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertCount( 2, $summary['headings'] );
		$this->assertEquals( 2, $summary['headings'][0]['level'] );
		$this->assertEquals( 'Welcome', $summary['headings'][0]['text'] );
		$this->assertEquals( array( 0 ), $summary['headings'][0]['path'] );
		$this->assertEquals( 3, $summary['headings'][1]['level'] );
		$this->assertEquals( 'Subtitle', $summary['headings'][1]['text'] );
	}

	public function test_summary_heading_defaults_to_level_2() {
		// Heading without an explicit `level` attribute should default to h2.
		$blocks = array(
			array(
				'name' => 'core/heading',
				'path' => array( 0 ),
			),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );
		$this->assertEquals( 2, $summary['headings'][0]['level'] );
	}

	public function test_summary_legacy_blocks_aggregates_by_namespace_and_name() {
		$blocks = array(
			array( 'name' => 'core/paragraph',     'path' => array( 0 ) ),
			array( 'name' => 'stackable/heading',  'path' => array( 1 ) ),
			array( 'name' => 'stackable/heading',  'path' => array( 2 ) ),
			array( 'name' => 'ugb/text',           'path' => array( 3 ) ),
			array( 'name' => 'jetpack/contact',    'path' => array( 4 ) ),
			array( 'name' => 'gravityforms/form',  'path' => array( 5 ) ), // not legacy
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertEquals( 4, $summary['legacy_blocks']['total'] );
		$this->assertEquals( 2, $summary['legacy_blocks']['by_namespace']['stackable'] );
		$this->assertEquals( 1, $summary['legacy_blocks']['by_namespace']['ugb'] );
		$this->assertEquals( 1, $summary['legacy_blocks']['by_namespace']['jetpack'] );
		$this->assertEquals( 2, $summary['legacy_blocks']['by_block_name']['stackable/heading'] );
		$this->assertEquals( 1, $summary['legacy_blocks']['by_block_name']['ugb/text'] );
		$this->assertEquals( 1, $summary['legacy_blocks']['by_block_name']['jetpack/contact'] );
		$this->assertArrayNotHasKey( 'core/paragraph', $summary['legacy_blocks']['by_block_name'] );
		$this->assertArrayNotHasKey( 'gravityforms/form', $summary['legacy_blocks']['by_block_name'] );
		// Paths only present when explicitly opted in.
		$this->assertArrayNotHasKey( 'paths', $summary['legacy_blocks'] );
	}

	public function test_summary_legacy_blocks_paths_opt_in() {
		$blocks = array(
			array( 'name' => 'stackable/heading',  'path' => array( 0 ) ),
			array( 'name' => 'ugb/text',           'path' => array( 1 ) ),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks, true ) );

		$this->assertArrayHasKey( 'paths', $summary['legacy_blocks'] );
		$this->assertCount( 2, $summary['legacy_blocks']['paths'] );
		$this->assertEquals( 'stackable/heading', $summary['legacy_blocks']['paths'][0]['name'] );
		$this->assertEquals( array( 0 ), $summary['legacy_blocks']['paths'][0]['path'] );
	}

	public function test_summary_max_path_depth_tracks_deepest_nesting() {
		$blocks = array(
			array(
				'name'        => 'core/group',
				'path'        => array( 0 ),
				'innerBlocks' => array(
					array(
						'name'        => 'core/columns',
						'path'        => array( 0, 0 ),
						'innerBlocks' => array(
							array(
								'name'        => 'core/column',
								'path'        => array( 0, 0, 0 ),
								'innerBlocks' => array(
									array( 'name' => 'core/paragraph', 'path' => array( 0, 0, 0, 0 ) ),
								),
							),
						),
					),
				),
			),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertEquals( 4, $summary['total_blocks'] );
		$this->assertEquals( 1, $summary['top_level_blocks'] );
		// Depth: group=0, columns=1, column=2, paragraph=3 — deepest depth visited = 3.
		$this->assertEquals( 3, $summary['max_path_depth'] );
	}

	public function test_summary_recurses_into_inner_blocks() {
		$blocks = array(
			array(
				'name'        => 'core/group',
				'path'        => array( 0 ),
				'innerBlocks' => array(
					array( 'name' => 'core/heading',   'path' => array( 0, 0 ), 'attributes' => array( 'level' => 2 ) ),
					array( 'name' => 'core/paragraph', 'path' => array( 0, 1 ) ),
				),
			),
		);
		$summary = $this->callPrivate( 'build_blocks_summary', array( $blocks ) );

		$this->assertEquals( 3, $summary['total_blocks'] );
		$this->assertEquals( 1, $summary['top_level_blocks'] );
		$this->assertEquals( 1, $summary['block_types']['core/group'] );
		$this->assertEquals( 1, $summary['block_types']['core/heading'] );
		$this->assertEquals( 1, $summary['block_types']['core/paragraph'] );
		$this->assertCount( 1, $summary['headings'] );
	}

	public function test_summary_empty_blocks_returns_zero_counts() {
		$summary = $this->callPrivate( 'build_blocks_summary', array( array() ) );
		$this->assertEquals( 0, $summary['total_blocks'] );
		$this->assertEquals( 0, $summary['top_level_blocks'] );
		$this->assertEquals( array(), $summary['block_types'] );
		$this->assertEquals( array(), $summary['sections'] );
		$this->assertEquals( array(), $summary['headings'] );
		// Clean pages have no legacy_blocks key — omitted to keep responses lean.
		$this->assertArrayNotHasKey( 'legacy_blocks', $summary );
		$this->assertEquals( 0, $summary['max_path_depth'] );
	}

	// ── extract_outline / walk_blocks_for_outline ──────────────────

	public function test_outline_extracts_headings() {
		$blocks = array(
			array( 'name' => 'core/paragraph', 'path' => array( 0 ) ),
			array(
				'name'         => 'core/heading',
				'path'         => array( 1 ),
				'attributes'   => array( 'level' => 2 ),
				'text_preview' => 'First',
			),
			array(
				'name'         => 'core/heading',
				'path'         => array( 2 ),
				'attributes'   => array( 'level' => 3 ),
				'text_preview' => 'Second',
			),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );

		// Two headings, no sections.
		$this->assertCount( 2, $outline );
		$this->assertEquals( 'heading', $outline[0]['type'] );
		$this->assertEquals( 'First', $outline[0]['text'] );
		$this->assertEquals( 2, $outline[0]['level'] );
		$this->assertEquals( array( 1 ), $outline[0]['path'] );
		$this->assertEquals( 'heading', $outline[1]['type'] );
		$this->assertEquals( 'Second', $outline[1]['text'] );
		$this->assertEquals( 3, $outline[1]['level'] );
	}

	public function test_outline_extracts_sections() {
		$blocks = array(
			array(
				'name'    => 'core/group',
				'path'    => array( 0 ),
				'section' => 'Hero Section',
			),
			array(
				'name'    => 'core/group',
				'path'    => array( 1 ),
				'section' => 'Features Block',
			),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );

		$this->assertCount( 2, $outline );
		$this->assertEquals( 'section', $outline[0]['type'] );
		$this->assertEquals( 'Hero Section', $outline[0]['section_name'] );
		$this->assertEquals( 'core/group', $outline[0]['block_name'] );
		$this->assertEquals( array( 0 ), $outline[0]['path'] );
		$this->assertEquals( 'section', $outline[1]['type'] );
		$this->assertEquals( 'Features Block', $outline[1]['section_name'] );
	}

	public function test_outline_preserves_document_order() {
		$blocks = array(
			array(
				'name'         => 'core/heading',
				'path'         => array( 0 ),
				'attributes'   => array( 'level' => 2 ),
				'text_preview' => 'A',
			),
			array(
				'name'    => 'core/group',
				'path'    => array( 1 ),
				'section' => 'Middle',
			),
			array(
				'name'         => 'core/heading',
				'path'         => array( 2 ),
				'attributes'   => array( 'level' => 2 ),
				'text_preview' => 'B',
			),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );

		$this->assertCount( 3, $outline );
		$this->assertEquals( 'A',      $outline[0]['text'] );
		$this->assertEquals( 'Middle', $outline[1]['section_name'] );
		$this->assertEquals( 'B',      $outline[2]['text'] );
	}

	public function test_outline_includes_nested_headings() {
		$blocks = array(
			array(
				'name'        => 'core/group',
				'path'        => array( 0 ),
				'innerBlocks' => array(
					array(
						'name'        => 'core/columns',
						'path'        => array( 0, 0 ),
						'innerBlocks' => array(
							array(
								'name'         => 'core/heading',
								'path'         => array( 0, 0, 0 ),
								'attributes'   => array( 'level' => 3 ),
								'text_preview' => 'Deeply nested',
							),
						),
					),
				),
			),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );

		$this->assertCount( 1, $outline );
		$this->assertEquals( 'heading', $outline[0]['type'] );
		$this->assertEquals( 'Deeply nested', $outline[0]['text'] );
		$this->assertEquals( array( 0, 0, 0 ), $outline[0]['path'] );
	}

	public function test_outline_block_with_section_and_heading_emits_both() {
		// A group that also has a name should produce a section entry;
		// a heading sibling produces a heading entry. Both are ordered
		// as encountered during tree walk.
		$blocks = array(
			array(
				'name'        => 'core/group',
				'path'        => array( 0 ),
				'section'     => 'Intro',
				'innerBlocks' => array(
					array(
						'name'         => 'core/heading',
						'path'         => array( 0, 0 ),
						'attributes'   => array( 'level' => 2 ),
						'text_preview' => 'Title',
					),
				),
			),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );

		$this->assertCount( 2, $outline );
		// Section comes first (emitted before recursing into innerBlocks).
		$this->assertEquals( 'section', $outline[0]['type'] );
		$this->assertEquals( 'Intro', $outline[0]['section_name'] );
		$this->assertEquals( 'heading', $outline[1]['type'] );
		$this->assertEquals( 'Title', $outline[1]['text'] );
	}

	public function test_outline_empty_when_no_headings_or_sections() {
		$blocks = array(
			array( 'name' => 'core/paragraph', 'path' => array( 0 ) ),
			array( 'name' => 'core/image',     'path' => array( 1 ) ),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );
		$this->assertEquals( array(), $outline );
	}

	public function test_outline_heading_defaults_to_level_2() {
		$blocks = array(
			array(
				'name'         => 'core/heading',
				'path'         => array( 0 ),
				'text_preview' => 'No level set',
			),
		);
		$outline = $this->callPrivate( 'extract_outline', array( $blocks ) );
		$this->assertEquals( 2, $outline[0]['level'] );
	}
}
