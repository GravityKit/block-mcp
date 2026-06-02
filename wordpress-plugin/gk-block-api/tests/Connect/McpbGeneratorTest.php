<?php
/**
 * MCPB_Generator: manifest structure and zip-bundle contracts.
 *
 * The Connect feature generates a pre-configured Claude Desktop extension
 * bundle (.mcpb) — a zip containing manifest.json and the self-contained MCP
 * server binary. The manifest pre-fills user_config fields with the issued
 * credentials so Claude Desktop can present them ready-to-enable; the
 * password field carries sensitive:true so the OS keychain stores it on
 * activation.
 *
 * Contracts pinned here:
 *
 *  - manifest() returns a server block with type 'node', entry_point
 *    'server/index.cjs', and env vars that reference ${user_config.*} tokens.
 *  - manifest() prefills all three user_config fields with the supplied
 *    credentials and marks wordpress_app_password as sensitive and required.
 *  - build() writes a valid .mcpb zip that contains manifest.json (with the
 *    correct name key) and the server binary at server/index.cjs.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\MCPB_Generator;

/**
 * Tests for MCPB_Generator::manifest() and MCPB_Generator::build().
 *
 * @covers \GravityKit\BlockAPI\MCPB_Generator
 */
class McpbGeneratorTest extends WP_UnitTestCase {

	/**
	 * Sample credentials used across test cases.
	 *
	 * @return array<string,string>
	 */
	private function creds() {
		return array(
			'url'      => 'https://example.com',
			'user'     => 'block-mcp',
			'password' => 'abcd efgh ijkl mnop qrst uvwx',
			'client'   => 'Claude Desktop',
		);
	}

	/**
	 * manifest() must pre-fill all user_config defaults from the supplied
	 * credentials, mark the password field sensitive and required, and wire
	 * the server env vars to ${user_config.*} substitution tokens.
	 *
	 * Without the sensitive flag the OS keychain integration in Claude Desktop
	 * does not activate; without the ${user_config.*} tokens the server
	 * receives empty env vars at launch.
	 */
	public function test_manifest_prefills_user_config_and_marks_password_sensitive() {
		$m = ( new MCPB_Generator() )->manifest( $this->creds() );
		$this->assertSame( 'node', $m['server']['type'] );
		$this->assertSame( 'server/index.cjs', $m['server']['entry_point'] );

		$env = $m['server']['mcp_config']['env'];
		$this->assertSame( '${user_config.wordpress_url}', $env['WORDPRESS_URL'] );
		$this->assertSame( '${user_config.wordpress_app_password}', $env['WORDPRESS_APP_PASSWORD'] );

		$cfg = $m['user_config'];
		$this->assertSame( 'https://example.com', $cfg['wordpress_url']['default'] );
		$this->assertSame( 'block-mcp', $cfg['wordpress_user']['default'] );
		$this->assertSame( 'abcd efgh ijkl mnop qrst uvwx', $cfg['wordpress_app_password']['default'] );
		$this->assertTrue( $cfg['wordpress_app_password']['sensitive'] );
		$this->assertTrue( $cfg['wordpress_app_password']['required'] );
	}

	/**
	 * build() must produce a valid zip archive containing manifest.json
	 * (with name 'block-mcp') and the MCP server binary at the path
	 * server/index.cjs that Claude Desktop expects at launch.
	 *
	 * The method returns the path to the generated temp file; callers are
	 * responsible for deleting it after the download response is sent.
	 */
	public function test_build_writes_a_zip_containing_manifest_and_server() {
		$server_fixture = wp_tempnam( 'srv' );
		file_put_contents( $server_fixture, "#!/usr/bin/env node\n// fixture\n" );

		$path = ( new MCPB_Generator() )->build( $this->creds(), $server_fixture );

		$this->assertFileExists( $path );
		$zip = new ZipArchive();
		$this->assertTrue( $zip->open( $path ) === true );
		$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
		$this->assertSame( 'block-mcp', $manifest['name'] );
		$this->assertNotFalse( $zip->locateName( 'server/index.cjs' ) );
		$zip->close();

		unlink( $path );
		unlink( $server_fixture );
	}
}
