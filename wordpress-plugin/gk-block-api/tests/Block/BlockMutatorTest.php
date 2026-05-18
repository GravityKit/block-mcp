<?php
/**
 * Tests for the Block_Mutator class.
 *
 * Tests all 9 path-based mutation operations plus path validation,
 * dry_run mode, and error paths.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Block_Mutator;
use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Preferences;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;

class BlockMutatorTest extends WP_UnitTestCase {

	/** @var Block_Mutator */
	private $mutator;

	/** @var Block_CRUD */
	private $crud;

	/** @var int */
	private $post_id;

	protected function setUp(): void {
		parent::setUp();
		// Register core blocks used in tests.
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array(
			'core/paragraph',
			'core/heading',
			'core/group',
			'core/list',
			'core/list-item',
			'core/image',
			'core/columns',
			'core/column',
			'core/block',
			'core/separator',
			'core/quote',
			'core/buttons',
			'core/button',
			// Non-core blocks for preference-tier tests.
			'stackable/heading',   // avoid tier (score 10)
			'ugb/text',            // legacy tier (score 0)
		) as $name ) {
			if ( ! $registry->get_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$preferences       = new Preferences();
		$safety            = new Block_Safety();
		$transformer       = new HTML_Transformer();
		$this->crud        = new Block_CRUD( $preferences, $safety, $transformer, new Block_Inventory() );
		$this->mutator     = new Block_Mutator( $this->crud, $preferences, $safety, $transformer );

		$this->post_id = self::factory()->post->create( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Test Post',
			'post_content' => '',
		) );
	}

	// ── Helpers ────────────────────────────────────────────────────

	/**
	 * Create or replace the test post with the given blocks array.
	 */
	private function make_post( array $blocks ): void {
		wp_update_post( array(
			'ID'           => $this->post_id,
			'post_content' => serialize_blocks( $blocks ),
		) );
	}

	/**
	 * Read the current blocks stored on the test post.
	 */
	private function current_blocks(): array {
		return parse_blocks( (string) get_post_field( 'post_content', $this->post_id ) );
	}

	/**
	 * Build a simple block.
	 */
	private function block( string $name, array $attrs = array(), string $html = '', array $children = array() ): array {
		if ( ! empty( $children ) ) {
			// Container — build innerContent with null placeholders between wrapper strings.
			$opening = $html !== '' ? $html : '<div>';
			$closing = '</div>';
			$content = array( $opening );
			foreach ( $children as $_ ) {
				$content[] = null;
			}
			$content[] = $closing;
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

	// ── Path validation ────────────────────────────────────────────

	public function test_empty_path_returns_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	public function test_non_integer_path_returns_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 'not-int' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	public function test_negative_integer_path_returns_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( -1 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	public function test_path_out_of_bounds_returns_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 5 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	public function test_path_deep_no_inner_blocks_returns_error() {
		// Path [0, 0] but block at [0] has no innerBlocks.
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0, 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	public function test_post_not_found_returns_error() {
		$result = $this->mutator->mutate( 424242, 'remove-block', array( 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'post_not_found', $result->get_error_code() );
	}

	public function test_unknown_op_returns_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'do-something-weird', array( 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_op', $result->get_error_code() );
	}

	// ── update-attrs ───────────────────────────────────────────────

	public function test_update_attrs_merges_attributes() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array( 'align' => 'left' ), '<p>A</p>' ),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array( 'attributes' => array( 'fontSize' => 'large' ) )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 'left', $saved[0]['attrs']['align'] );
		$this->assertEquals( 'large', $saved[0]['attrs']['fontSize'] );
	}

	public function test_update_attrs_missing_attributes_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'update-attrs', array( 0 ), array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_attributes', $result->get_error_code() );
	}

	public function test_update_attrs_empty_attributes_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array( 'attributes' => array() )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_attributes', $result->get_error_code() );
	}

	public function test_update_attrs_auto_transforms_heading_level() {
		// level change on core/heading uses regex (no WP_HTML_Tag_Processor needed).
		$this->make_post( array(
			$this->block( 'core/heading', array( 'level' => 2 ), '<h2>Title</h2>' ),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array( 'attributes' => array( 'level' => 4 ) )
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 4, $saved[0]['attrs']['level'] );
		$this->assertStringContainsString( '<h4', $saved[0]['innerHTML'] );
		$this->assertStringContainsString( '</h4>', $saved[0]['innerHTML'] );
	}

	public function test_update_attrs_returns_result_block() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array( 'attributes' => array( 'align' => 'center' ) )
		);
		$this->assertArrayHasKey( 'block', $result );
		$this->assertEquals( 'core/paragraph', $result['block']['name'] );
		$this->assertEquals( 'center', $result['block']['attributes']['align'] );
	}

	// ── update-html ────────────────────────────────────────────────

	public function test_update_html_replaces_inner_html() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>Old</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '<p>New</p>' )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>New</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( array( '<p>New</p>' ), $saved[0]['innerContent'] );
	}

	public function test_update_html_missing_html_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'update-html', array( 0 ), array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_html', $result->get_error_code() );
	}

	public function test_update_html_allows_empty_string() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '' )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
	}

	// ── replace-block ──────────────────────────────────────────────

	public function test_replace_block_swaps_block() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array(
				'block' => array(
					'name'       => 'core/heading',
					'attributes' => array( 'level' => 2 ),
					'innerHTML'  => '<h2>Title</h2>',
				),
			)
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 'core/heading', $saved[0]['blockName'] );
		$this->assertEquals( 2, $saved[0]['attrs']['level'] );
	}

	public function test_replace_block_missing_block_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'replace-block', array( 0 ), array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_block', $result->get_error_code() );
	}

	public function test_replace_block_missing_name_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array( 'block' => array( 'attributes' => array() ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_block', $result->get_error_code() );
	}

	public function test_replace_block_legacy_rejected() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array( 'block' => array( 'name' => 'ugb/text', 'innerHTML' => '<p>x</p>' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'legacy_block', $result->get_error_code() );
	}

	public function test_replace_block_avoid_produces_warning() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array( 'block' => array( 'name' => 'stackable/heading', 'innerHTML' => '<h2>Hi</h2>' ) )
		);
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertEquals( 'stackable/heading', $result['warnings'][0]['block'] );
	}

	public function test_replace_block_builds_inner_blocks() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array(
				'block' => array(
					'name'        => 'core/group',
					'innerHTML'   => '<div></div>',
					'innerBlocks' => array(
						array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Child</p>' ),
					),
				),
			)
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 'core/group', $saved[0]['blockName'] );
		$this->assertCount( 1, $saved[0]['innerBlocks'] );
		$this->assertEquals( 'core/paragraph', $saved[0]['innerBlocks'][0]['blockName'] );
	}

	// replace-block must recurse to ANY depth, not just 1.
	public function test_replace_block_preserves_grandchild_inner_blocks() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array(
				'block' => array(
					'name'        => 'core/columns',
					'innerHTML'   => '<div class="wp-block-columns"></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/column',
							'innerHTML'   => '<div class="wp-block-column"></div>',
							'innerBlocks' => array(
								array( 'name' => 'core/heading', 'attributes' => array( 'level' => 3 ), 'innerHTML' => '<h3>Deep</h3>' ),
								array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Body</p>' ),
							),
						),
						array(
							'name'        => 'core/column',
							'innerHTML'   => '<div class="wp-block-column"></div>',
							'innerBlocks' => array(
								array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Right</p>' ),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 'core/columns', $saved[0]['blockName'] );
		$this->assertCount( 2, $saved[0]['innerBlocks'] );
		$col1 = $saved[0]['innerBlocks'][0];
		$col2 = $saved[0]['innerBlocks'][1];
		$this->assertEquals( 'core/column', $col1['blockName'] );
		$this->assertCount( 2, $col1['innerBlocks'], 'grandchildren of column 1 must survive' );
		$this->assertEquals( 'core/heading', $col1['innerBlocks'][0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $col1['innerBlocks'][1]['blockName'] );
		$this->assertCount( 1, $col2['innerBlocks'], 'grandchildren of column 2 must survive' );
		$this->assertEquals( 'core/paragraph', $col2['innerBlocks'][0]['blockName'] );
	}

	// ── remove-block ───────────────────────────────────────────────

	public function test_remove_block_removes_and_reindexes() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
			$this->block( 'core/paragraph', array(), '<p>C</p>' ),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 1 ) );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		$this->assertEquals( '<p>A</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>C</p>', $saved[1]['innerHTML'] );
	}

	public function test_remove_block_synced_pattern_warning() {
		// Register a pattern post so the warning can look up its title.
		$pattern_id = self::factory()->post->create( array(
			'post_type'   => 'wp_block',
			'post_status' => 'publish',
			'post_title'  => 'My Pattern',
		) );

		$this->make_post( array(
			array(
				'blockName'    => 'core/block',
				'attrs'        => array( 'ref' => $pattern_id ),
				'innerHTML'    => '',
				'innerContent' => array(),
				'innerBlocks'  => array(),
			),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0 ) );
		$this->assertTrue( $result['success'] );
		$this->assertNotEmpty( $result['warnings'] );
		$this->assertStringContainsString( 'My Pattern', $result['warnings'][0]['message'] );
	}

	public function test_remove_block_nested_cleans_grandparent_inner_content() {
		// Group containing three paragraphs. Removing the middle child must
		// drop one null placeholder from the group's innerContent so that
		// innerBlocks count matches null count.
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div>', array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
				$this->block( 'core/paragraph', array(), '<p>B</p>' ),
				$this->block( 'core/paragraph', array(), '<p>C</p>' ),
			) ),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0, 1 ) );
		$this->assertTrue( $result['success'] );

		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved[0]['innerBlocks'] );

		$null_count = 0;
		foreach ( $saved[0]['innerContent'] as $piece ) {
			if ( null === $piece ) {
				$null_count++;
			}
		}
		$this->assertSame(
			count( $saved[0]['innerBlocks'] ),
			$null_count,
			'innerContent null count must match innerBlocks count after nested remove-block.'
		);
	}

	// ── wrap-in-group ──────────────────────────────────────────────

	public function test_wrap_in_group_default_wrapper() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'wrap-in-group', array( 0 ) );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( 'core/group', $saved[0]['blockName'] );
		$this->assertCount( 1, $saved[0]['innerBlocks'] );
		$this->assertEquals( 'core/paragraph', $saved[0]['innerBlocks'][0]['blockName'] );
		$this->assertStringContainsString( '<div', $saved[0]['innerHTML'] );
	}

	public function test_wrap_in_group_custom_tag_name() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'wrap-in-group',
			array( 0 ),
			array( 'wrapper' => array( 'name' => 'core/group', 'attributes' => array( 'tagName' => 'section' ) ) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertStringContainsString( '<section', $saved[0]['innerHTML'] );
		$this->assertEquals( 'section', $saved[0]['attrs']['tagName'] );
	}

	public function test_wrap_in_group_unregistered_wrapper_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'wrap-in-group',
			array( 0 ),
			array( 'wrapper' => array( 'name' => 'totally/nonexistent' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_block', $result->get_error_code() );
	}

	// ── unwrap-group ───────────────────────────────────────────────

	public function test_unwrap_group_promotes_children() {
		$this->make_post( array(
			$this->block(
				'core/group',
				array(),
				'<div></div>',
				array(
					$this->block( 'core/paragraph', array(), '<p>A</p>' ),
					$this->block( 'core/paragraph', array(), '<p>B</p>' ),
				)
			),
			$this->block( 'core/paragraph', array(), '<p>After</p>' ),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'unwrap-group', array( 0 ) );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		// Two children replaced the container + the original sibling = 3 top-level.
		$this->assertCount( 3, $saved );
		$this->assertEquals( '<p>A</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>B</p>', $saved[1]['innerHTML'] );
		$this->assertEquals( '<p>After</p>', $saved[2]['innerHTML'] );
	}

	public function test_unwrap_group_no_inner_blocks_error() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'unwrap-group', array( 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'no_inner_blocks', $result->get_error_code() );
	}

	public function test_unwrap_group_reports_children_count() {
		$this->make_post( array(
			$this->block(
				'core/group',
				array(),
				'<div></div>',
				array(
					$this->block( 'core/paragraph', array(), '<p>A</p>' ),
					$this->block( 'core/paragraph', array(), '<p>B</p>' ),
					$this->block( 'core/paragraph', array(), '<p>C</p>' ),
				)
			),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'unwrap-group', array( 0 ) );
		$this->assertEquals( 3, $result['block']['children_count'] );
	}

	// ── insert-child ───────────────────────────────────────────────

	public function test_insert_child_appends_by_default() {
		$this->make_post( array(
			$this->block(
				'core/group',
				array(),
				'<div></div>',
				array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) )
			),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array( 'block' => array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ) )
		);

		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved[0]['innerBlocks'] );
		$this->assertEquals( '<p>A</p>', $saved[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertEquals( '<p>NEW</p>', $saved[0]['innerBlocks'][1]['innerHTML'] );
	}

	public function test_insert_child_at_start() {
		$this->make_post( array(
			$this->block(
				'core/group',
				array(),
				'<div></div>',
				array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) )
			),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array(
				'block'    => array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ),
				'position' => 'start',
			)
		);

		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>NEW</p>', $saved[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertEquals( '<p>A</p>', $saved[0]['innerBlocks'][1]['innerHTML'] );
	}

	public function test_insert_child_at_numeric_position() {
		$this->make_post( array(
			$this->block(
				'core/group',
				array(),
				'<div></div>',
				array(
					$this->block( 'core/paragraph', array(), '<p>A</p>' ),
					$this->block( 'core/paragraph', array(), '<p>B</p>' ),
					$this->block( 'core/paragraph', array(), '<p>C</p>' ),
				)
			),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array(
				'block'    => array( 'name' => 'core/paragraph', 'innerHTML' => '<p>NEW</p>' ),
				'position' => 1,
			)
		);

		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>A</p>', $saved[0]['innerBlocks'][0]['innerHTML'] );
		$this->assertEquals( '<p>NEW</p>', $saved[0]['innerBlocks'][1]['innerHTML'] );
		$this->assertEquals( '<p>B</p>', $saved[0]['innerBlocks'][2]['innerHTML'] );
	}

	public function test_insert_child_at_numeric_append_position() {
		// Numeric position equal to current child count (append): the Nth null
		// does not exist, so the new placeholder must fall back to the 'end'
		// scan and land before the closing-tag string — not after it.
		$this->make_post( array(
			$this->block(
				'core/group',
				array(),
				'<div></div>',
				array(
					$this->block( 'core/paragraph', array(), '<p>A</p>' ),
					$this->block( 'core/paragraph', array(), '<p>B</p>' ),
				)
			),
		) );

		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array(
				'block'    => array( 'name' => 'core/paragraph', 'innerHTML' => '<p>END</p>' ),
				'position' => 2, // equal to current child count
			)
		);

		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 3, $saved[0]['innerBlocks'] );
		$this->assertEquals( '<p>END</p>', $saved[0]['innerBlocks'][2]['innerHTML'] );

		// The new null must appear BEFORE the closing-tag string.
		$ic      = $saved[0]['innerContent'];
		$last_ix = count( $ic ) - 1;
		$this->assertIsString( $ic[ $last_ix ], 'Closing tag must remain the last innerContent entry.' );
		$this->assertNull( $ic[ $last_ix - 1 ], 'New null placeholder must sit before the closing tag.' );
	}

	public function test_insert_child_missing_block_error() {
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			) ),
		) );
		$result = $this->mutator->mutate( $this->post_id, 'insert-child', array( 0 ), array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_block', $result->get_error_code() );
	}

	public function test_insert_child_legacy_rejected() {
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			) ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array( 'block' => array( 'name' => 'ugb/text' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'legacy_block', $result->get_error_code() );
	}

	// insert-child must build innerBlocks recursively, not drop them.
	public function test_insert_child_preserves_nested_inner_blocks() {
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>existing</p>' ),
			) ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array(
				'block' => array(
					'name'        => 'core/columns',
					'innerHTML'   => '<div class="wp-block-columns"></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/column',
							'innerHTML'   => '<div class="wp-block-column"></div>',
							'innerBlocks' => array(
								array( 'name' => 'core/heading', 'attributes' => array( 'level' => 3 ), 'innerHTML' => '<h3>Deep</h3>' ),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $result['success'] );
		$saved    = $this->current_blocks();
		$children = $saved[0]['innerBlocks'];
		$this->assertCount( 2, $children, 'group should now have its existing child + the inserted columns' );
		$inserted = $children[1];
		$this->assertEquals( 'core/columns', $inserted['blockName'] );
		$this->assertCount( 1, $inserted['innerBlocks'], 'columns must keep its column' );
		$column = $inserted['innerBlocks'][0];
		$this->assertEquals( 'core/column', $column['blockName'] );
		$this->assertCount( 1, $column['innerBlocks'], 'column must keep its grandchild heading' );
		$this->assertEquals( 'core/heading', $column['innerBlocks'][0]['blockName'] );
	}

	// Legacy blocks nested inside an otherwise-valid insert-child must hard-reject.
	public function test_insert_child_rejects_nested_legacy_block() {
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			) ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'insert-child',
			array( 0 ),
			array(
				'block' => array(
					'name'        => 'core/columns',
					'innerHTML'   => '<div></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/column',
							'innerHTML'   => '<div></div>',
							'innerBlocks' => array(
								array( 'name' => 'ugb/text' ),
							),
						),
					),
				),
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	// ── emoji round-trip ───────────────────────────────────────────

	/**
	 * Emoji in innerHTML survive an `update-html` mutation untouched.
	 *
	 * 4-byte UTF-8 (emoji) frequently get mangled by sanitizers that aren't
	 * mb-aware. parse_blocks() / serialize_blocks() are mb-safe; this test
	 * pins the contract that nothing in our code path corrupts them.
	 */
	public function test_update_html_preserves_emoji() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>plain</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '<p>Edits like 🪄 magic 🏆</p>' )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertSame( '<p>Edits like 🪄 magic 🏆</p>', $saved[0]['innerHTML'] );
	}

	/**
	 * Emoji in block attributes survive an `update-attrs` mutation untouched.
	 *
	 * Attributes are stored as JSON inside the block-comment delimiter, so
	 * the emoji has to round-trip through json_encode + serialize_blocks +
	 * parse_blocks + json_decode. PHP json_encode escapes non-ASCII by
	 * default; WordPress' serialize_blocks uses JSON_UNESCAPED_UNICODE so
	 * the on-disk markup retains the literal emoji bytes.
	 */
	public function test_update_attrs_preserves_emoji() {
		$this->make_post( array( $this->block( 'core/heading', array( 'level' => 2 ), '<h2>old</h2>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-attrs',
			array( 0 ),
			array( 'attributes' => array( 'placeholder' => 'Type here 🪄' ) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertSame( 'Type here 🪄', $saved[0]['attrs']['placeholder'] );
	}

	/**
	 * Emoji survive `replace-block`, including in nested innerBlocks.
	 *
	 * Exercises the recursive build_block_from_def() path — an emoji in a
	 * grandchild's innerHTML must come out intact at the bottom of the tree.
	 */
	public function test_replace_block_preserves_emoji_in_nested_inner_blocks() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>old</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'replace-block',
			array( 0 ),
			array(
				'block' => array(
					'name'        => 'core/group',
					'innerHTML'   => '<div></div>',
					'innerBlocks' => array(
						array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Top 🚀</p>' ),
						array(
							'name'        => 'core/columns',
							'innerHTML'   => '<div></div>',
							'innerBlocks' => array(
								array(
									'name'        => 'core/column',
									'innerHTML'   => '<div></div>',
									'innerBlocks' => array(
										array( 'name' => 'core/heading', 'attributes' => array( 'level' => 3 ), 'innerHTML' => '<h3>Deep ✨ heading</h3>' ),
									),
								),
							),
						),
					),
				),
			)
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertSame( '<p>Top 🚀</p>', $saved[0]['innerBlocks'][0]['innerHTML'] );

		$deep_heading = $saved[0]['innerBlocks'][1]['innerBlocks'][0]['innerBlocks'][0];
		$this->assertSame( 'core/heading', $deep_heading['blockName'] );
		$this->assertSame( '<h3>Deep ✨ heading</h3>', $deep_heading['innerHTML'] );
	}

	/**
	 * Emoji clusters with ZWJ / skin-tone modifiers survive innerHTML edits.
	 *
	 * Composite emoji are the most common casualty of mb-unsafe processing —
	 * a single grapheme is multiple codepoints joined by U+200D or modified
	 * by skin-tone selectors. This test pins them down explicitly.
	 */
	public function test_complex_emoji_sequences_survive_in_html() {
		$family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";  // 👨‍👩‍👧
		$wave   = "\u{1F44B}\u{1F3FE}";                            // 👋🏾

		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>plain</p>' ) ) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '<p>Hi ' . $wave . ' from the ' . $family . '</p>' )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertSame( '<p>Hi ' . $wave . ' from the ' . $family . '</p>', $saved[0]['innerHTML'] );
	}

	// ── duplicate ──────────────────────────────────────────────────

	public function test_duplicate_deep_clones_after_original() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array( 'align' => 'center' ), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'duplicate', array( 0 ) );
		$this->assertTrue( $result['success'] );

		$saved = $this->current_blocks();
		$this->assertCount( 3, $saved );
		$this->assertEquals( '<p>A</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>A</p>', $saved[1]['innerHTML'] );
		$this->assertEquals( 'center', $saved[1]['attrs']['align'] );
		$this->assertEquals( '<p>B</p>', $saved[2]['innerHTML'] );
	}

	public function test_duplicate_returns_new_path() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->mutator->mutate( $this->post_id, 'duplicate', array( 0 ) );
		$this->assertEquals( array( 1 ), $result['block']['new_path'] );
	}

	public function test_duplicate_deep_clones_nested_structure() {
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			) ),
		) );

		$result = $this->mutator->mutate( $this->post_id, 'duplicate', array( 0 ) );
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertCount( 2, $saved );
		// Both have the same nested child.
		$this->assertEquals( 'core/paragraph', $saved[0]['innerBlocks'][0]['blockName'] );
		$this->assertEquals( 'core/paragraph', $saved[1]['innerBlocks'][0]['blockName'] );
	}

	// ── move ───────────────────────────────────────────────────────

	public function test_move_missing_destination_error() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
		) );
		$result = $this->mutator->mutate( $this->post_id, 'move', array( 0 ), array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_destination', $result->get_error_code() );
	}

	public function test_move_within_same_parent_forward() {
		// Move [0] to before [2] (i.e., destination [2]): same-parent adjustment reduces dest to 1.
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
			$this->block( 'core/paragraph', array(), '<p>C</p>' ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'move',
			array( 0 ),
			array( 'destination' => array( 2 ) )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>B</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>A</p>', $saved[1]['innerHTML'] );
		$this->assertEquals( '<p>C</p>', $saved[2]['innerHTML'] );
	}

	public function test_move_count_exceeds_available_error() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'move',
			array( 0 ),
			array( 'destination' => array( 2 ), 'count' => 5 )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_count', $result->get_error_code() );
	}

	public function test_move_into_self_descendant_error() {
		// Source: [0]. Destination: [0, 0, 0] — starts with source path, so it's a descendant.
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/group', array(), '<div></div>', array(
					$this->block( 'core/paragraph', array(), '<p>A</p>' ),
				) ),
			) ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'move',
			array( 0 ),
			array( 'destination' => array( 0, 0, 0 ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_destination', $result->get_error_code() );
	}

	public function test_move_invalid_destination_segment_error() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'move',
			array( 0 ),
			array( 'destination' => array( 'bad' ) )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
	}

	public function test_move_multiple_blocks_same_parent() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
			$this->block( 'core/paragraph', array(), '<p>B</p>' ),
			$this->block( 'core/paragraph', array(), '<p>C</p>' ),
			$this->block( 'core/paragraph', array(), '<p>D</p>' ),
		) );
		// Move two blocks [0], [1] to end (destination [4] becomes [2] after adjustment).
		$result = $this->mutator->mutate(
			$this->post_id,
			'move',
			array( 0 ),
			array( 'destination' => array( 4 ), 'count' => 2 )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>C</p>', $saved[0]['innerHTML'] );
		$this->assertEquals( '<p>D</p>', $saved[1]['innerHTML'] );
		$this->assertEquals( '<p>A</p>', $saved[2]['innerHTML'] );
		$this->assertEquals( '<p>B</p>', $saved[3]['innerHTML'] );
		$this->assertEquals( 2, $result['block']['moved_count'] );
	}

	// ── dry_run ────────────────────────────────────────────────────

	public function test_dry_run_does_not_save() {
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>Original</p>' ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '<p>Modified</p>' ),
			true
		);
		$this->assertTrue( $result['success'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertNull( $result['before_revision_id'] );
		$this->assertNull( $result['revision_id'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>Original</p>', $saved[0]['innerHTML'] );
	}

	public function test_dry_run_still_validates_errors() {
		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );
		$result = $this->mutator->mutate( $this->post_id, 'update-attrs', array( 0 ), array(), true );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'missing_attributes', $result->get_error_code() );
	}

	public function test_dry_run_skips_rate_limit() {
		// Fill the rate limit bucket.
		$key = 'gk_block_api_rate_' . $this->post_id;
		$now = time();
		set_transient(
			$key,
			array(
				'writes' => array_fill( 0, Block_CRUD::RATE_LIMIT_WRITES, $now ),
				'puts'   => array(),
			),
			120
		);

		$this->make_post( array( $this->block( 'core/paragraph', array(), '<p>A</p>' ) ) );

		// Non-dry-run should fail.
		$blocked = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '<p>X</p>' )
		);
		$this->assertInstanceOf( \WP_Error::class, $blocked );
		$this->assertEquals( 'rate_limit_exceeded', $blocked->get_error_code() );

		// Dry-run should succeed.
		$ok = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0 ),
			array( 'innerHTML' => '<p>X</p>' ),
			true
		);
		$this->assertIsArray( $ok );
		$this->assertTrue( $ok['success'] );
	}

	// ── Nested path navigation ─────────────────────────────────────

	public function test_mutation_at_nested_path() {
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>Nested</p>' ),
			) ),
		) );
		$result = $this->mutator->mutate(
			$this->post_id,
			'update-html',
			array( 0, 0 ),
			array( 'innerHTML' => '<p>Changed</p>' )
		);
		$this->assertTrue( $result['success'] );
		$saved = $this->current_blocks();
		$this->assertEquals( '<p>Changed</p>', $saved[0]['innerBlocks'][0]['innerHTML'] );
	}

	// ── Enhanced error messages ────────────────────────────────────

	public function test_invalid_path_error_includes_valid_range() {
		// Target out-of-bounds at root level: single block, try path [5].
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>A</p>' ),
		) );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 5 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'valid_range', $data );
		// Single block: valid range is [0..0].
		$this->assertEquals( '[0..0]', $data['valid_range'] );
		// Error message should include the range as well.
		$this->assertStringContainsString( '[0..0]', $result->get_error_message() );
		$this->assertStringContainsString( 'outline=true', $result->get_error_message() );
	}

	public function test_invalid_path_error_includes_partial_path_on_traversal() {
		// Traversal failure: path [0, 0, 5] where [0,0] is valid container but 5 is out of bounds.
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/group', array(), '<div></div>', array(
					$this->block( 'core/paragraph', array(), '<p>Only child</p>' ),
				) ),
			) ),
		) );
		// Path [0, 7, 0] — segment 1 (index 7) is out of bounds; partial_path = [0].
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0, 7, 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'partial_path', $data );
		$this->assertEquals( array( 0 ), $data['partial_path'] );
		$this->assertArrayHasKey( 'valid_range', $data );
		// At [0]'s innerBlocks level there's one child (the inner group), so range is [0..0].
		$this->assertEquals( '[0..0]', $data['valid_range'] );
		// Message mentions outline=true guidance.
		$this->assertStringContainsString( 'outline=true', $result->get_error_message() );
	}

	public function test_path_with_no_inner_blocks_error_includes_block_name() {
		// Try to traverse into a paragraph (no inner blocks).
		$this->make_post( array(
			$this->block( 'core/paragraph', array(), '<p>Leaf</p>' ),
		) );
		// Path [0, 0] — paragraph has no innerBlocks.
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0, 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'block_name', $data );
		$this->assertEquals( 'core/paragraph', $data['block_name'] );
		$this->assertArrayHasKey( 'partial_path', $data );
		$this->assertEquals( array( 0 ), $data['partial_path'] );
		// Message names the block.
		$this->assertStringContainsString( 'core/paragraph', $result->get_error_message() );
	}

	public function test_target_out_of_bounds_error_message_includes_range() {
		// Target out of bounds (last segment) inside a valid container.
		$this->make_post( array(
			$this->block( 'core/group', array(), '<div></div>', array(
				$this->block( 'core/paragraph', array(), '<p>A</p>' ),
				$this->block( 'core/paragraph', array(), '<p>B</p>' ),
			) ),
		) );
		// Path [0, 5] — container exists, but index 5 is out of range (only 0,1 exist).
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0, 5 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertEquals( 'invalid_path', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'valid_range', $data );
		$this->assertEquals( '[0..1]', $data['valid_range'] );
		$this->assertStringContainsString( '[0..1]', $result->get_error_message() );
	}

	public function test_invalid_path_empty_parent_valid_range() {
		// No blocks in post at all — any target should fail with "(empty)" range.
		$this->make_post( array() );
		$result = $this->mutator->mutate( $this->post_id, 'remove-block', array( 0 ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'valid_range', $data );
		$this->assertEquals( '(empty)', $data['valid_range'] );
	}
}
