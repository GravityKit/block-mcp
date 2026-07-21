<?php
/**
 * Input-schema validation across the tools, driven as an authorized editor so a
 * rejection is the schema check, not a permission denial.
 *
 * An MCP client relies on the declared inputSchema: a missing required argument
 * or a wrong-typed one must be rejected cleanly (a WP_Error the client can
 * surface), never executed with a coerced or absent value that mutates the
 * wrong thing.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;

class AbilityInputValidationTest extends RestControllerTestCase {

	/**
	 * Enable abilities before the one-shot init; act as an editor so validation,
	 * not permission, is what rejects.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	/**
	 * Each case omits one required argument; the ability must reject before it
	 * runs. [ability_id, input-missing-a-required-field].
	 *
	 * @return array<string, array{0:string,1:array<string,mixed>}>
	 */
	public function missing_required_provider(): array {
		return array(
			'get-pattern no pattern_id'        => array( 'gk-block-mcp/get-pattern', array() ),
			'update-blocks no updates'         => array( 'gk-block-mcp/update-blocks', array( 'post_id' => 1 ) ),
			'replace-block-range no count'     => array( 'gk-block-mcp/replace-block-range', array( 'post_id' => 1, 'start' => 0, 'blocks' => array() ) ),
			'revert-to-revision no revision'   => array( 'gk-block-mcp/revert-to-revision', array( 'post_id' => 1 ) ),
			'insert-pattern no pattern_id'     => array( 'gk-block-mcp/insert-pattern', array( 'post_id' => 1 ) ),
			'insert-blocks no blocks'          => array( 'gk-block-mcp/insert-blocks', array( 'post_id' => 1 ) ),
			'rewrite-post-blocks no blocks'    => array( 'gk-block-mcp/rewrite-post-blocks', array( 'post_id' => 1 ) ),
		);
	}

	/**
	 * A required argument omitted is rejected before execution.
	 *
	 * @dataProvider missing_required_provider
	 */
	public function test_missing_required_argument_is_rejected( string $ability_id, array $input ) {
		$result = wp_get_ability( $ability_id )->execute( $input );

		$this->assertWPError( $result, $ability_id . ' must reject input missing a required field' );
	}

	/**
	 * Each case supplies a wrong-typed argument that must be rejected, never
	 * silently coerced into a mutation. [ability_id, input-with-wrong-type].
	 *
	 * @return array<string, array{0:string,1:array<string,mixed>}>
	 */
	public function wrong_type_provider(): array {
		return array(
			'update-blocks updates string'     => array( 'gk-block-mcp/update-blocks', array( 'post_id' => 1, 'updates' => 'nope' ) ),
			'insert-blocks blocks string'      => array( 'gk-block-mcp/insert-blocks', array( 'post_id' => 1, 'blocks' => 'nope' ) ),
			'replace-range start string'       => array( 'gk-block-mcp/replace-block-range', array( 'post_id' => 1, 'start' => 'zero', 'count' => 1, 'blocks' => array() ) ),
			'update-block post_id non-numeric' => array( 'gk-block-mcp/update-block', array( 'post_id' => 'not-a-number', 'flat_index' => 0, 'innerHTML' => '<p>x</p>' ) ),
		);
	}

	/**
	 * A wrong-typed argument is rejected rather than coerced into a mutation.
	 *
	 * @dataProvider wrong_type_provider
	 */
	public function test_wrong_typed_argument_is_rejected( string $ability_id, array $input ) {
		$result = wp_get_ability( $ability_id )->execute( $input );

		$this->assertWPError( $result, $ability_id . ' must reject a wrong-typed argument' );
	}
}
