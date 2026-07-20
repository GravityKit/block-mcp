<?php
/**
 * Registration lifecycle at the adapter seam.
 *
 * The enable/disable gate and the one-shot registry are covered in
 * AbilitiesRegistryTest. This pins the adapter-boundary contract: a server may
 * only be created during the adapter's mcp_adapter_init action, so calling the
 * plugin's registration outside it registers nothing rather than mis-wiring.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;
use function GravityKit\BlockMCP\get_abilities_registry;

class AbilityLifecycleTest extends RestControllerTestCase {

	/**
	 * Enable abilities before the one-shot init.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
	}

	/**
	 * The adapter forbids create_server outside its mcp_adapter_init action.
	 * Calling register_mcp_server() directly (not during the action) must trip
	 * that guard and register no server, rather than silently mis-registering.
	 *
	 * @group mcp-adapter
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_register_mcp_server_outside_action_is_rejected() {
		$this->assertTrue( class_exists( '\WP\MCP\Core\McpAdapter' ), 'the adapter run must load the adapter' );

		$registry = get_abilities_registry();
		$this->assertNotNull( $registry );

		$this->setExpectedIncorrectUsage( 'create_server' );
		$registry->register_mcp_server();

		$this->assertNull(
			\WP\MCP\Core\McpAdapter::instance()->get_server( 'gk-block-mcp' ),
			'no gk-block-mcp server may be created outside mcp_adapter_init'
		);
	}
}
