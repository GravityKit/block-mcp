<?php
/**
 * Real MCP transport behavior for the dedicated gk-block-mcp server.
 *
 * Runs only under tests/phpunit/adapter.xml (the real WordPress MCP Adapter is
 * loaded). Drives the adapter's ToolsHandler directly — the tools/list and
 * tools/call path an MCP client reaches over HTTP — to pin the error taxonomy
 * (protocol error vs. execution isError result) and a real write round-trip.
 * The general suite can't reach this because the adapter class is absent there.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;

/**
 * @group mcp-adapter
 */
class McpTransportTest extends RestControllerTestCase {

	/**
	 * Enable abilities and act as an administrator so tool permission checks pass
	 * and the transport reaches execution.
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Build (once per process) and return the dedicated gk-block-mcp server.
	 *
	 * @return \WP\MCP\Core\McpServer
	 */
	private function server() {
		$adapter = \WP\MCP\Core\McpAdapter::instance();
		$adapter->init();
		return $adapter->get_server( 'gk-block-mcp' );
	}

	/**
	 * @param \WP\MCP\Core\McpServer $server Server under test.
	 * @return \WP\MCP\Handlers\Tools\ToolsHandler
	 */
	private function handler( $server ) {
		return new \WP\MCP\Handlers\Tools\ToolsHandler( $server );
	}

	/**
	 * tools/list over the transport lists every registrable tool.
	 */
	public function test_tools_list_returns_every_tool() {
		$server = $this->server();
		$this->assertNotNull( $server );

		$result = $this->handler( $server )->list_tools()->toArray();

		$this->assertArrayHasKey( 'tools', $result );
		$this->assertCount( count( $server->get_tools() ), $result['tools'] );
	}

	/**
	 * An unknown tool name is a protocol error (JSONRPCErrorResponse), not a
	 * fatal and not an execution result.
	 */
	public function test_call_tool_unknown_tool_is_protocol_error() {
		$result = $this->handler( $this->server() )->call_tool(
			array( 'name' => 'gk-block-mcp-does-not-exist' )
		);

		$this->assertInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
	}

	/**
	 * A non-object `arguments` is rejected as invalid params before any tool runs.
	 */
	public function test_call_tool_non_object_arguments_is_protocol_error() {
		$result = $this->handler( $this->server() )->call_tool(
			array( 'name' => 'gk-block-mcp-get-page-blocks', 'arguments' => 'not-an-object' )
		);

		$this->assertInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
	}

	/**
	 * A missing tool name is a protocol error.
	 */
	public function test_call_tool_missing_name_is_protocol_error() {
		$result = $this->handler( $this->server() )->call_tool( array() );

		$this->assertInstanceOf( \WP\McpSchema\Common\JsonRpc\DTO\JSONRPCErrorResponse::class, $result );
	}

	/**
	 * A handler WP_Error (a real failure inside the tool) surfaces as an
	 * execution result with isError:true — an LLM-visible tool error, not a
	 * protocol crash — and the request survives.
	 */
	public function test_call_tool_handler_error_is_execution_error_result() {
		$result = $this->handler( $this->server() )->call_tool(
			array(
				'name'      => 'gk-block-mcp-get-page-blocks',
				'arguments' => array( 'post_id' => 999999 ),
			)
		);

		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\DTO\CallToolResult::class, $result );
		$this->assertTrue( $result->getIsError() );
	}

	/**
	 * A real write driven end-to-end through tools/call persists, and the result
	 * is a non-error execution result.
	 */
	public function test_call_tool_write_round_trip_persists() {
		$server  = $this->server();
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Before</p>' ) ) );

		$result = $this->handler( $server )->call_tool(
			array(
				'name'      => 'gk-block-mcp-update-block',
				'arguments' => array(
					'post_id'    => $post_id,
					'flat_index' => 0,
					'innerHTML'  => '<p>After via MCP</p>',
				),
			)
		);

		$this->assertInstanceOf( \WP\McpSchema\Server\Tools\DTO\CallToolResult::class, $result );
		$this->assertNotTrue( $result->getIsError(), 'a successful call must not be an error result' );
		$this->assertStringContainsString( '<p>After via MCP</p>', $this->block_tree( $post_id )[0]['innerHTML'] );
	}

	/**
	 * Build a flat core/paragraph block in WP-internal shape for make_block_post().
	 *
	 * @param string $html Paragraph innerHTML.
	 * @return array<string, mixed>
	 */
	private function paragraph( string $html ): array {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}
}
