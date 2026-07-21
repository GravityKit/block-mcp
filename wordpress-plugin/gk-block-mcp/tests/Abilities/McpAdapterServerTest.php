<?php
/**
 * MCP Adapter server-registration tests.
 *
 * These run only under tests/phpunit/adapter.xml, which loads the real
 * WordPress MCP Adapter (the wordpress/mcp-adapter dev dependency) via
 * GK_LOAD_MCP_ADAPTER. They exercise Abilities_Registry::register_mcp_server()
 * against the actual adapter — the create_server path the general suite can't
 * reach because the adapter class is absent there.
 *
 * The MCP Adapter only permits create_server() during its mcp_adapter_init
 * action, which its own init() (hooked on rest_api_init) fires. So each test
 * drives the real rest_api_init -> mcp_adapter_init sequence rather than calling
 * register_mcp_server() directly. The adapter caches its singleton and fires
 * mcp_adapter_init once per process, so the enabled/disabled cases run in
 * separate processes to each start from a pristine adapter.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;
use function GravityKit\BlockMCP\get_abilities_registry;

/**
 * @group mcp-adapter
 */
class McpAdapterServerTest extends RestControllerTestCase {

	/**
	 * The dedicated run must actually load the MCP Adapter, or every assertion
	 * below is vacuous. Pins the harness contract: adapter.xml sets
	 * GK_LOAD_MCP_ADAPTER and the bootstrap loads the plugin.
	 */
	public function test_adapter_is_loaded_for_this_run() {
		$this->assertTrue(
			class_exists( '\WP\MCP\Core\McpAdapter' ),
			'tests/phpunit/adapter.xml must load wordpress/mcp-adapter (GK_LOAD_MCP_ADAPTER=1).'
		);
	}

	/**
	 * With abilities enabled and the adapter present, the mcp_adapter_init flow
	 * registers a dedicated "gk-block-mcp" server that lists one tool per
	 * registrable ability — the full Block MCP tool surface, matching the npm
	 * server, not the adapter's generic discover/execute default server.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_enabled_registers_dedicated_server_with_every_tool() {
		update_option( Block_Abilities::ENABLED_OPTION, '1' );

		$registry = get_abilities_registry();
		$this->assertNotNull( $registry, 'the registry builds when abilities are enabled' );

		// Fire the real init path: the adapter hooks its init() on rest_api_init,
		// and init() fires mcp_adapter_init, during which the plugin's handler
		// calls create_server().
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$adapter->init();

		$server = $adapter->get_server( 'gk-block-mcp' );
		$this->assertNotNull( $server, 'a dedicated gk-block-mcp MCP server is registered' );
		$this->assertSame( 'Block MCP', $server->get_server_name() );
		$this->assertSame( GK_BLOCK_MCP_VERSION, $server->get_server_version() );

		$expected = count( $registry->get_ability_ids() );
		$this->assertGreaterThan( 20, $expected, 'sanity: the manifest yields the full tool set' );
		$this->assertCount(
			$expected,
			$server->get_tools(),
			'the server exposes exactly one tool per registrable ability'
		);
	}

	/**
	 * The server tool ids stay in lockstep with the registered ability ids:
	 * every registrable ability is exposed as a tool, and no extra tool appears.
	 * A drift here means an MCP client sees a different tool set than the
	 * Abilities REST API and the npm server.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_server_tools_match_registered_ability_ids() {
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		$registry = get_abilities_registry();
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$adapter->init();

		$server = $adapter->get_server( 'gk-block-mcp' );
		$this->assertNotNull( $server );

		// get_tools() is keyed by the MCP tool name, which the adapter derives
		// from the ability id by replacing '/' with '-' (McpNameSanitizer).
		$tool_names = array_keys( $server->get_tools() );
		sort( $tool_names );

		$expected = array_map(
			static function ( $id ) {
				return str_replace( '/', '-', $id );
			},
			$registry->get_ability_ids()
		);
		sort( $expected );

		$this->assertSame( $expected, $tool_names, 'each registrable ability is exposed as exactly one MCP tool (id with / becomes -)' );
	}

	/**
	 * With abilities disabled, the plugin registers no dedicated server even
	 * though the adapter is present: get_abilities_registry() returns null at the
	 * mcp_adapter_init seam, so create_server() is never called for gk-block-mcp.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_disabled_registers_no_dedicated_server() {
		delete_option( Block_Abilities::ENABLED_OPTION );

		\WP\MCP\Core\McpAdapter::instance()->init();

		$this->assertNull(
			\WP\MCP\Core\McpAdapter::instance()->get_server( 'gk-block-mcp' ),
			'no gk-block-mcp server is registered when abilities are disabled'
		);
	}
}
