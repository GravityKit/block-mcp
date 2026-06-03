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
	 * build() must return WP_Error with code 'mcpb_server_missing' when the
	 * server bundle path does not exist or is not readable.
	 *
	 * Without this guard, ZipArchive::addFile() silently returns false for a
	 * missing file, producing a manifest-only zip that streams as a successful
	 * download. Claude Desktop then fails to launch `node server/index.cjs`
	 * with no user-visible error — a silent dead-end that defeats the
	 * one-click install promise.
	 */
	public function test_build_returns_wp_error_when_server_bundle_missing() {
		$result = ( new MCPB_Generator() )->build( $this->creds(), '/nonexistent/path/index.cjs' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'mcpb_server_missing', $result->get_error_code() );
	}

	/**
	 * build() must return WP_Error('mcpb_tempfile_failed') when the temporary
	 * file cannot be created.
	 *
	 * wp_tempnam() can return false on systems where the temp directory is
	 * missing, full, or has restricted permissions. Without the guard the
	 * code would pass false to ZipArchive::open(), producing a PHP warning
	 * and an indeterminate error code instead of a usable WP_Error.
	 */
	public function test_build_returns_wp_error_when_tempfile_fails() {
		$generator = new class() extends MCPB_Generator {
			protected function make_temp_path() {
				return false;
			}
		};

		$server_fixture = wp_tempnam( 'srv' );
		file_put_contents( $server_fixture, "#!/usr/bin/env node\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $generator->build( $this->creds(), $server_fixture );

		wp_delete_file( $server_fixture );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcpb_tempfile_failed', $result->get_error_code() );
	}

	/**
	 * build() must delete the temp file created by make_temp_path() when
	 * ZipArchive::open() fails, leaving no orphaned file on disk.
	 *
	 * Before the fix, the zip-open failure path returned early without
	 * calling wp_delete_file(), leaking the empty temp file. The test
	 * subclass injects a path inside a non-existent directory so open()
	 * always fails, then asserts the path is gone after build() returns.
	 */
	public function test_build_cleans_up_temp_file_on_zip_open_failure() {
		$bogus_dir  = sys_get_temp_dir() . '/gk_nonexistent_' . uniqid( '', true );
		$bogus_path = $bogus_dir . '/block-mcp.mcpb';

		$generator = new class( $bogus_path ) extends MCPB_Generator {
			/** @var string */
			private $injected_path;

			public function __construct( string $path ) {
				$this->injected_path = $path;
			}

			protected function make_temp_path() {
				return $this->injected_path;
			}
		};

		$server_fixture = wp_tempnam( 'srv' );
		file_put_contents( $server_fixture, "#!/usr/bin/env node\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$result = $generator->build( $this->creds(), $server_fixture );

		wp_delete_file( $server_fixture );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcpb_zip_open_failed', $result->get_error_code() );
		$this->assertFileDoesNotExist( $bogus_path, 'build() must not leave a temp file behind on zip-open failure' );
	}

	/**
	 * build() must produce a valid zip archive containing manifest.json
	 * (with a per-site name) and the MCP server binary at the path
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
		// creds() uses https://example.com → block-mcp-example.
		$this->assertSame( 'block-mcp-example', $manifest['name'] );
		$this->assertNotFalse( $zip->locateName( 'server/index.cjs' ) );
		$zip->close();

		unlink( $path );
		unlink( $server_fixture );
	}

	/**
	 * The manifest name must be derived from the site host so each site installs
	 * as a DISTINCT Claude Desktop extension that coexists.
	 *
	 * Claude Desktop identifies an installed extension by its manifest `name`.
	 * A fixed name ('block-mcp') meant a second site's .mcpb replaced the first.
	 * The name now mirrors the connector's server name — block-mcp-<host-label>
	 * (www stripped, lowercased) — so www.gravitykit.com, dev.test, and
	 * gkclone.orb.local become three different extensions.
	 */
	public function test_manifest_name_is_derived_per_site() {
		$gen = new MCPB_Generator();

		$name = static function ( $url ) use ( $gen ) {
			$m = $gen->manifest(
				array(
					'url'      => $url,
					'user'     => 'block-mcp',
					'password' => 'pw',
					'client'   => 'Claude Desktop app',
				)
			);
			return $m['name'];
		};

		$this->assertSame( 'block-mcp-gravitykit', $name( 'https://www.gravitykit.com' ) );
		$this->assertSame( 'block-mcp-dev', $name( 'https://dev.test' ) );
		$this->assertSame( 'block-mcp-gkclone', $name( 'https://gkclone.orb.local' ) );

		// The whole point: distinct sites → distinct extension names.
		$names = array(
			$name( 'https://www.gravitykit.com' ),
			$name( 'https://dev.test' ),
			$name( 'https://gkclone.orb.local' ),
		);
		$this->assertCount( 3, array_unique( $names ), 'each site must yield a unique manifest name' );
	}

	/**
	 * The manifest name falls back to 'block-mcp' when the URL has no host.
	 */
	public function test_manifest_name_falls_back_when_url_has_no_host() {
		$m = ( new MCPB_Generator() )->manifest(
			array(
				'url'      => 'not-a-url',
				'user'     => 'block-mcp',
				'password' => 'pw',
				'client'   => 'Claude Desktop app',
			)
		);
		$this->assertSame( 'block-mcp', $m['name'] );
	}

	/**
	 * The display_name shows the site host so a multi-site Claude Desktop
	 * extension list distinguishes each connection at a glance.
	 */
	public function test_manifest_display_name_shows_the_site_host() {
		$m = ( new MCPB_Generator() )->manifest(
			array(
				'url'      => 'https://www.gravitykit.com',
				'user'     => 'block-mcp',
				'password' => 'pw',
				'client'   => 'Claude Desktop app',
			)
		);
		$this->assertStringContainsString( 'www.gravitykit.com', $m['display_name'] );
	}
}
