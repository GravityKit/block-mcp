<?php
/**
 * Abilities API coverage for the list-binding-sources ability.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Abilities_Registry;
use GravityKit\BlockMCP\Block_Abilities;
use GravityKit\BlockMCP\Tool_Executor;
use GravityKit\BlockMCP\Yoast_Bridge;

class BindingSourcesAbilityTest extends RestControllerTestCase {

	/**
	 * Registration defaults to opt-in (off); enable it the same way
	 * AbilitiesRegistryTest does, before any test can trigger the Abilities
	 * API's first-touch bootstrap. See that file's set_up() docblock.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
	}

	/**
	 * list-binding-sources registers as an ability.
	 */
	public function test_list_binding_sources_ability_is_registered() {
		$registry = new Abilities_Registry(
			new Tool_Executor( $this->controller, new Yoast_Bridge() ),
			$this->controller
		);

		$this->assertContains( 'gk-block-mcp/list-binding-sources', $registry->get_ability_ids() );
	}

	/**
	 * Executing the ability returns the same `{sources: [...]}` shape as its
	 * REST twin (GET /binding-sources -> Block_Registry::get_binding_sources()),
	 * proving Tool_Executor actually dispatches to it instead of 400ing
	 * "Unknown Block MCP tool".
	 */
	public function test_list_binding_sources_ability_returns_sources() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$result = wp_get_ability( 'gk-block-mcp/list-binding-sources' )->execute( array() );

		$this->assertNotWPError( $result );
		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'sources', $result );
	}

	/**
	 * The read permission branch denies a subscriber (lacks the global
	 * edit_posts capability), matching every other read-permission ability
	 * (e.g. test_list_block_types_ability_denies_subscriber in
	 * AbilitiesRegistryTest) — list-binding-sources is read parity with the
	 * rest of the discovery group, not a weaker or stronger gate.
	 */
	public function test_list_binding_sources_ability_denies_subscriber() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );
		$result = wp_get_ability( 'gk-block-mcp/list-binding-sources' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}

	/**
	 * The ability is annotated readonly, matching its REST twin's
	 * check_permissions() (a read-only route) and src/tools/discovery.ts's
	 * READ_ANNOT.
	 */
	public function test_list_binding_sources_ability_is_readonly() {
		$meta = wp_get_ability( 'gk-block-mcp/list-binding-sources' )->get_meta();
		$this->assertTrue( $meta['annotations']['readonly'] );
	}
}
