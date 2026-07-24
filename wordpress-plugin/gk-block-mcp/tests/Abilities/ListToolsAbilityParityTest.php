<?php
/**
 * Behavioral REST/Abilities parity for list_block_types and list_patterns —
 * Codex review findings #4 and #5: both tools' Abilities-path executor
 * methods silently dropped an argument the REST and npm-MCP paths already
 * honored. ToolExecutorParityAuditTest is the general structural guard
 * against this bug class recurring; this file proves the specific fix
 * actually changes behavior, not just that the argument name now appears
 * somewhere in the method.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;

class ListToolsAbilityParityTest extends RestControllerTestCase {

	/**
	 * Registration defaults to opt-in (off); enable it the same way
	 * AbilitiesRegistryTest does, before any test can trigger the Abilities
	 * API's first-touch bootstrap. See that file's set_up() docblock.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * `include_supports: true` via the ability must return each block
	 * type's full `supports` object, matching what GET /block-types and the
	 * npm MCP server's list_block_types already return.
	 */
	public function test_list_block_types_ability_forwards_include_supports() {
		$without = wp_get_ability( 'gk-block-mcp/list-block-types' )->execute( array( 'search' => 'core/paragraph' ) );
		$this->assertNotWPError( $without );
		$this->assertNotEmpty( $without['block_types'] );
		$this->assertArrayNotHasKey( 'supports', $without['block_types'][0] );

		$with = wp_get_ability( 'gk-block-mcp/list-block-types' )->execute(
			array(
				'search'           => 'core/paragraph',
				'include_supports' => true,
			)
		);
		$this->assertNotWPError( $with );
		$this->assertNotEmpty( $with['block_types'] );
		$this->assertArrayHasKey( 'supports', $with['block_types'][0], 'include_supports:true must forward through to Block_Registry::get_block_types()' );
	}

	/**
	 * `category` via the ability must filter results to patterns matching
	 * that category, and the response must include the top-level
	 * `categories` vocabulary — both already true for GET /patterns and the
	 * npm MCP server's list_patterns.
	 */
	public function test_list_patterns_ability_forwards_category_filter_and_includes_categories() {
		register_block_pattern_category( 'gktest-category', array( 'label' => 'GK Test Category' ) );
		register_block_pattern(
			'gktest/category-pattern',
			array(
				'title'      => 'Category Pattern',
				'categories' => array( 'gktest-category' ),
				'content'    => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->',
			)
		);
		register_block_pattern(
			'gktest/other-pattern',
			array(
				'title'   => 'Other Pattern',
				'content' => '<!-- wp:paragraph --><p>y</p><!-- /wp:paragraph -->',
			)
		);

		$result = wp_get_ability( 'gk-block-mcp/list-patterns' )->execute( array( 'category' => 'gktest-category' ) );

		$this->assertNotWPError( $result );
		$ids = wp_list_pluck( $result['patterns'], 'id' );
		$this->assertContains( 'gktest/category-pattern', $ids );
		$this->assertNotContains( 'gktest/other-pattern', $ids, 'category filter must exclude patterns outside the requested category' );

		$this->assertArrayHasKey( 'categories', $result, 'the pattern-category vocabulary must be forwarded, not dropped' );
		$category_names = wp_list_pluck( $result['categories'], 'name' );
		$this->assertContains( 'gktest-category', $category_names );
	}
}
