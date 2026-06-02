<?php
/**
 * Connect_Page: provision-mint-build, connection-state, artifact, authorize, and render contracts.
 *
 * The Connect_Page orchestrates the full connect flow: provisioning the agent
 * service account, minting an Application Password, and either building a
 * pre-filled .mcpb bundle (Claude Desktop) or emitting a secret-free
 * `npx connect` command that drives the browser-Approve handshake.
 *
 * These tests pin the testable seams — provision_credentials(),
 * prepare_installer(), setup_artifact(), is_loopback_callback(),
 * handle_authorize(), connection_state(), and render_section() — without
 * exercising the HTTP-streaming or admin-menu registration paths.
 *
 * Contracts pinned here:
 *
 *  - provision_credentials() provisions the agent user, mints exactly one
 *    Application Password, and returns url/user/password/uuid.
 *  - provision_credentials() returns WP_Error when a non-agent user owns
 *    the block-mcp login (propagated from Agent_Provisioner::ensure()).
 *  - prepare_installer() calls provision_credentials() and builds the .mcpb
 *    from the returned creds; the .mcpb path is unchanged.
 *  - setup_artifact() returns secret-free npx-connect commands for each
 *    non-Desktop client; bodies must NOT contain any password or
 *    WORDPRESS_APP_PASSWORD.
 *  - is_loopback_callback() accepts only loopback http:// URLs with an
 *    explicit port and no userinfo; rejects everything else.
 *  - handle_authorize() mints exactly one credential and redirects it to the
 *    loopback callback; rejects non-loopback callbacks; rejects missing caps.
 *  - The manifest inside the .mcpb zip carries the home_url() base (not
 *    site_url()) so subdirectory installs produce working credentials.
 *  - When Application Passwords are unavailable, prepare_installer() returns
 *    WP_Error without minting any credential.
 *  - When a non-agent user already owns the block-mcp login,
 *    prepare_installer() propagates WP_Error without minting any credential.
 *  - connection_state() correctly reports 'needs_https', 'ready', and
 *    'connected' for the three reachable branches.
 *  - The gk_block_api_secret_at_rest_mode filter in 'paste' mode causes
 *    prepare_installer() to omit the password from the manifest default and
 *    return the plaintext separately.
 *  - render_section() in the 'ready' state outputs all six client radio cards
 *    with the correct values, Claude Desktop checked by default.
 *  - render_section() with ?setup=<client> shows the secret-free command
 *    artifact for that client; no password field is rendered.
 *  - render_section() with ?gk_authorize renders the Approve screen.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;
use GravityKit\BlockAPI\Connect_Page;
use GravityKit\BlockAPI\Connections;

/**
 * Tests for Connect_Page.
 *
 * @covers \GravityKit\BlockAPI\Connect_Page
 */
class ConnectPageTest extends WP_UnitTestCase {

	/**
	 * Path to the server fixture used by tests that call prepare_installer().
	 *
	 * Created fresh in set_up(), removed in tear_down().
	 *
	 * @var string
	 */
	private $fixture_server;

	public function set_up() {
		parent::set_up();
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$this->fixture_server = wp_tempnam( 'mcp-server' );
		file_put_contents( $this->fixture_server, "#!/usr/bin/env node\n// fixture server\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	public function tear_down() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		remove_filter( 'wp_is_application_passwords_available', '__return_false' );
		remove_all_filters( 'gk_block_api_secret_at_rest_mode' );
		remove_all_filters( 'home_url' );
		remove_all_filters( 'wp_redirect' );
		remove_all_filters( 'wp_die_handler' );

		// Clean up superglobals set by authorize / setup render tests.
		unset( $_GET['gk_authorize'], $_GET['setup'], $_GET['callback'], $_GET['state'], $_GET['client'] );
		unset( $_REQUEST['_wpnonce'] );

		if ( $this->fixture_server && file_exists( $this->fixture_server ) ) {
			wp_delete_file( $this->fixture_server );
		}

		delete_option( 'gk_block_api_agent_user_id' );

		parent::tear_down();
	}

	// ──────────────────────────────────────────────────────────────────────
	// provision_credentials() — new shared seam.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * provision_credentials() must provision the agent user, mint exactly one
	 * Application Password, and return an array with url/user/password/uuid.
	 *
	 * The url must be untrailed home_url(), not site_url(), so that subdirectory
	 * installs produce working REST credentials.
	 */
	public function test_provision_credentials_returns_url_user_password_uuid() {
		$page  = new Connect_Page();
		$creds = $page->provision_credentials( 'Claude Code' );

		$this->assertIsArray( $creds, 'provision_credentials() must return an array on success' );
		$this->assertArrayHasKey( 'url', $creds );
		$this->assertArrayHasKey( 'user', $creds );
		$this->assertArrayHasKey( 'password', $creds );
		$this->assertArrayHasKey( 'uuid', $creds );

		$this->assertSame( untrailingslashit( home_url() ), $creds['url'], 'url must be untrailed home_url()' );
		$this->assertSame( Agent_Provisioner::LOGIN, $creds['user'], 'user must be the agent login' );
		$this->assertNotEmpty( $creds['password'], 'password must be non-empty' );
		$this->assertNotEmpty( $creds['uuid'], 'uuid must be non-empty' );

		// Exactly one Application Password must have been minted.
		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent );
		$passwords = WP_Application_Passwords::get_user_application_passwords( $agent->ID );
		$this->assertCount( 1, $passwords );
	}

	/**
	 * provision_credentials() must return WP_Error('agent_login_taken') when a
	 * non-agent user already owns the block-mcp login, and must not mint any
	 * Application Password on the conflicting user.
	 *
	 * This is the guard against silently adopting a human account that happens to
	 * share the service-account login name.
	 */
	public function test_provision_credentials_returns_wp_error_when_login_taken_by_nonagent() {
		$conflict_id = self::factory()->user->create(
			array(
				'user_login' => Agent_Provisioner::LOGIN,
				'role'       => 'subscriber',
			)
		);

		$page   = new Connect_Page();
		$result = $page->provision_credentials( 'Claude Code' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_login_taken', $result->get_error_code() );

		$passwords = WP_Application_Passwords::get_user_application_passwords( $conflict_id );
		$this->assertEmpty( $passwords, 'No password must be minted on the conflicting user' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// prepare_installer() — happy path.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * prepare_installer() must provision the block-mcp agent user, mint exactly
	 * one Application Password labelled 'Block MCP — <client>', and return an
	 * array containing 'path', 'filename' (ending .mcpb), and 'uuid'. The
	 * manifest inside the bundle must carry the agent's login as
	 * wordpress_user.default and home_url() as wordpress_url.default.
	 */
	public function test_prepare_installer_provisions_mints_and_builds_bundle() {
		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', $this->fixture_server );

		$this->assertIsArray( $result, 'prepare_installer() must return an array on success' );
		$this->assertArrayHasKey( 'path', $result );
		$this->assertArrayHasKey( 'filename', $result );
		$this->assertArrayHasKey( 'uuid', $result );
		$this->assertStringEndsWith( '.mcpb', $result['filename'] );

		// Agent user must exist with the correct login.
		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent, 'Agent user must be created' );

		// Exactly one Application Password labelled 'Block MCP — Claude Desktop'.
		$passwords = WP_Application_Passwords::get_user_application_passwords( $agent->ID );
		$this->assertCount( 1, $passwords );
		$this->assertSame( 'Block MCP — Claude Desktop', $passwords[0]['name'] );

		// Bundle must be a valid zip with a manifest matching the credentials.
		$this->assertFileExists( $result['path'] );
		$zip = new ZipArchive();
		$this->assertSame( true, $zip->open( $result['path'] ) );
		$raw      = $zip->getFromName( 'manifest.json' );
		$manifest = json_decode( $raw, true );
		$zip->close();

		$this->assertSame(
			Agent_Provisioner::LOGIN,
			$manifest['user_config']['wordpress_user']['default'],
			'manifest must carry agent login as wordpress_user default'
		);
		$this->assertSame(
			untrailingslashit( home_url() ),
			$manifest['user_config']['wordpress_url']['default'],
			'manifest must carry home_url() base as wordpress_url default'
		);

		wp_delete_file( $result['path'] );
	}

	/**
	 * The .mcpb manifest must use home_url() as the WordPress site URL, not
	 * site_url(). On subdirectory installs the two values differ: site_url()
	 * points at the WordPress core files, while home_url() is the public
	 * address the MCP client must connect to. Using site_url() would make
	 * every REST call 404 on subdirectory setups.
	 */
	public function test_prepare_installer_url_uses_home_not_site() {
		add_filter(
			'home_url',
			static function () {
				return 'https://example.com';
			}
		);
		add_filter(
			'site_url',
			static function () {
				return 'https://example.com/wp';
			}
		);

		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', $this->fixture_server );

		$this->assertIsArray( $result );

		$zip = new ZipArchive();
		$zip->open( $result['path'] );
		$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
		$zip->close();

		$this->assertSame(
			'https://example.com',
			$manifest['user_config']['wordpress_url']['default'],
			'manifest URL must track home_url(), not site_url()'
		);
		$this->assertNotSame(
			'https://example.com/wp',
			$manifest['user_config']['wordpress_url']['default'],
			'manifest URL must not be site_url()'
		);

		wp_delete_file( $result['path'] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// prepare_installer() — error paths.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * When Application Passwords are unavailable (e.g. a non-HTTPS install),
	 * prepare_installer() must return WP_Error without minting any credential.
	 * The agent provisioner runs before the issuer, so this also verifies that
	 * App_Password_Issuer::issue() failures are propagated correctly.
	 */
	public function test_prepare_installer_returns_wp_error_when_passwords_unavailable() {
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', $this->fixture_server );

		$this->assertInstanceOf( WP_Error::class, $result );
	}

	/**
	 * When a non-agent user already owns the block-mcp login, ensure() returns
	 * WP_Error('agent_login_taken'). prepare_installer() must propagate that
	 * error and must not mint any Application Password on the conflicting user.
	 *
	 * This guards the scenario where a human account pre-dates the plugin and
	 * happens to share the service-account login. Adopting the account silently
	 * would grant the AI access to a real user's identity and password history.
	 */
	public function test_prepare_installer_returns_wp_error_when_login_taken_by_nonagent() {
		// Create a subscriber that owns the block-mcp login but lacks the agent flag.
		$conflict_id = self::factory()->user->create(
			array(
				'user_login' => Agent_Provisioner::LOGIN,
				'role'       => 'subscriber',
			)
		);

		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', $this->fixture_server );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_login_taken', $result->get_error_code() );

		// No Application Password must have been minted on the conflicting user.
		$passwords = WP_Application_Passwords::get_user_application_passwords( $conflict_id );
		$this->assertEmpty( $passwords, 'No password must be minted on a conflicting non-agent user' );
	}

	/**
	 * prepare_installer() must propagate WP_Error from MCPB_Generator::build()
	 * without masking the error code.
	 *
	 * When the server bundle path is not readable, build() returns
	 * WP_Error('mcpb_server_missing'). The is_wp_error($path) guard in
	 * prepare_installer() must detect this and return the same WP_Error to the
	 * caller instead of treating the error object as a filesystem path.
	 */
	public function test_prepare_installer_propagates_build_wp_error() {
		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', '/nonexistent/index.cjs' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mcpb_server_missing', $result->get_error_code() );
	}

	// ──────────────────────────────────────────────────────────────────────
	// setup_artifact() — per-client secret-free command bodies.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * setup_artifact() for the 'claude-code' slug must return a bash command
	 * containing `npx -y @gravitykit/block-mcp connect`, the site URL, and
	 * `--client claude-code`. The body must NOT contain WORDPRESS_APP_PASSWORD
	 * or any password.
	 *
	 * Credentials are delivered later via the browser-Approve handshake; the
	 * artifact is purely a terminal command that triggers that flow.
	 */
	public function test_setup_artifact_claude_code_is_npx_connect_command_no_secret() {
		$page     = new Connect_Page();
		$artifact = $page->setup_artifact( 'claude-code', 'https://example.com' );

		$this->assertSame( 'bash', $artifact['language'] );
		$this->assertStringContainsString( 'npx -y @gravitykit/block-mcp connect', $artifact['body'], 'body must contain the npx connect command' );
		$this->assertStringContainsString( 'https://example.com', $artifact['body'], 'body must contain the site URL' );
		$this->assertStringContainsString( '--client claude-code', $artifact['body'], 'body must contain --client claude-code slug' );
		$this->assertStringNotContainsString( 'WORDPRESS_APP_PASSWORD', $artifact['body'], 'body must NOT contain WORDPRESS_APP_PASSWORD' );
		$this->assertStringNotContainsString( 'password', strtolower( $artifact['body'] ), 'body must NOT contain any password text' );
	}

	/**
	 * setup_artifact() for the 'cursor' slug must return a bash command containing
	 * `npx -y @gravitykit/block-mcp connect`, the site URL, and `--client cursor`.
	 * The body must NOT contain WORDPRESS_APP_PASSWORD or any password.
	 */
	public function test_setup_artifact_cursor_is_npx_connect_command_no_secret() {
		$page     = new Connect_Page();
		$artifact = $page->setup_artifact( 'cursor', 'https://example.com' );

		$this->assertSame( 'bash', $artifact['language'] );
		$this->assertStringContainsString( 'npx -y @gravitykit/block-mcp connect', $artifact['body'], 'body must contain the npx connect command' );
		$this->assertStringContainsString( 'https://example.com', $artifact['body'], 'body must contain the site URL' );
		$this->assertStringContainsString( '--client cursor', $artifact['body'], 'body must contain --client cursor slug' );
		$this->assertStringNotContainsString( 'WORDPRESS_APP_PASSWORD', $artifact['body'], 'body must NOT contain WORDPRESS_APP_PASSWORD' );
		$this->assertStringNotContainsString( 'password', strtolower( $artifact['body'] ), 'body must NOT contain any password text' );
	}

	/**
	 * setup_artifact() for the 'chatgpt-desktop' slug must return a bash command
	 * containing `npx -y @gravitykit/block-mcp connect`, the site URL, and
	 * `--client chatgpt-desktop`. The body must NOT contain WORDPRESS_APP_PASSWORD
	 * or any password.
	 */
	public function test_setup_artifact_chatgpt_desktop_is_npx_connect_command_no_secret() {
		$page     = new Connect_Page();
		$artifact = $page->setup_artifact( 'chatgpt-desktop', 'https://example.com' );

		$this->assertSame( 'bash', $artifact['language'] );
		$this->assertStringContainsString( 'npx -y @gravitykit/block-mcp connect', $artifact['body'], 'body must contain the npx connect command' );
		$this->assertStringContainsString( 'https://example.com', $artifact['body'], 'body must contain the site URL' );
		$this->assertStringContainsString( '--client chatgpt-desktop', $artifact['body'], 'body must contain --client chatgpt-desktop slug' );
		$this->assertStringNotContainsString( 'WORDPRESS_APP_PASSWORD', $artifact['body'], 'body must NOT contain WORDPRESS_APP_PASSWORD' );
		$this->assertStringNotContainsString( 'password', strtolower( $artifact['body'] ), 'body must NOT contain any password text' );
	}

	/**
	 * setup_artifact() for 'ai-prompt' must return a plain-text instruction for the
	 * AI to run the npx connect command, containing the site URL and the approve
	 * instruction. The body must NOT contain WORDPRESS_APP_PASSWORD or any password.
	 *
	 * The prompt is pasted into an AI chat transcript. No secret should ever appear
	 * there — the credential arrives via the browser-Approve handshake instead.
	 */
	public function test_setup_artifact_ai_prompt_is_npx_connect_instruction_no_secret() {
		$page     = new Connect_Page();
		$artifact = $page->setup_artifact( 'ai-prompt', 'https://example.com' );

		$this->assertSame( 'text', $artifact['language'] );
		$this->assertStringContainsString( 'npx -y @gravitykit/block-mcp connect', $artifact['body'], 'body must contain the npx connect command' );
		$this->assertStringContainsString( 'https://example.com', $artifact['body'], 'body must contain the site URL' );
		$this->assertStringContainsString( 'approve', strtolower( $artifact['body'] ), 'body must reference the approve step' );
		$this->assertStringNotContainsString( 'WORDPRESS_APP_PASSWORD', $artifact['body'], 'body must NOT contain WORDPRESS_APP_PASSWORD' );
		$this->assertStringNotContainsString( 'password', strtolower( $artifact['body'] ), 'body must NOT contain any password text' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// is_loopback_callback() — URL validation.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * is_loopback_callback() must accept loopback http:// URLs with an explicit
	 * numeric port and reject everything else.
	 *
	 * Accepted: http://127.0.0.1:<port>/path, http://localhost:<port>/callback,
	 *           http://[::1]:<port>/
	 * Rejected: https://, missing port, remote host, userinfo, file://, empty.
	 *
	 * @dataProvider loopback_callback_cases
	 *
	 * @param string $url      Candidate callback URL.
	 * @param bool   $expected Whether the URL should be accepted.
	 * @param string $message  Failure description.
	 */
	public function test_is_loopback_callback( $url, $expected, $message ) {
		$page = new Connect_Page();
		$this->assertSame( $expected, $page->is_loopback_callback( $url ), $message );
	}

	/**
	 * Data provider for test_is_loopback_callback().
	 *
	 * @return array[]
	 */
	public function loopback_callback_cases() {
		return array(
			// Accept cases.
			array( 'http://127.0.0.1:51791/cb', true, 'standard loopback IP + port must be accepted' ),
			array( 'http://localhost:8080/callback', true, 'localhost + port must be accepted' ),
			array( 'http://localhost:3000/', true, 'localhost + port + trailing slash must be accepted' ),
			array( 'http://[::1]:3000/cb', true, 'IPv6 loopback + port must be accepted' ),
			array( 'http://127.0.0.1:1/path?foo=bar', true, 'loopback + query string must be accepted' ),

			// Reject: wrong scheme.
			array( 'https://127.0.0.1:51791/cb', false, 'https:// must be rejected (loopback needs no TLS, avoids cert surprises)' ),
			array( 'file:///etc/passwd', false, 'file:// must be rejected' ),
			array( '', false, 'empty string must be rejected' ),

			// Reject: non-loopback host.
			array( 'http://evil.com/cb', false, 'remote host must be rejected' ),
			array( 'http://192.168.1.1:8080/cb', false, 'LAN IP must be rejected' ),
			array( 'http://127.0.0.1.evil.com:80/cb', false, 'lookalike host must be rejected' ),

			// Reject: missing port.
			array( 'http://127.0.0.1/cb', false, 'missing port must be rejected' ),
			array( 'http://localhost/callback', false, 'localhost without port must be rejected' ),

			// Reject: userinfo present (http://user@host style confusion).
			array( 'http://localhost@evil.com:80/cb', false, 'userinfo-style host confusion must be rejected' ),
			array( 'http://user:pass@127.0.0.1:8080/cb', false, 'explicit userinfo must be rejected' ),
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// handle_authorize() — browser-Approve handler.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * handle_authorize() with a valid loopback callback must mint exactly one
	 * Application Password and redirect the credential to the callback URL with
	 * site/user/password/state query parameters.
	 *
	 * We hook wp_redirect to capture the redirect target and throw a catchable
	 * marker so the handler's exit() does not terminate the test process.
	 */
	public function test_handle_authorize_mints_credential_and_redirects_to_loopback() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']       = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']     = 'http://127.0.0.1:51791/cb';
		$_POST['state']        = 'test-state-token';
		$_POST['client']       = 'block-mcp';
		$_POST['_wpnonce']     = $nonce;
		$_REQUEST['_wpnonce']  = $nonce; // check_admin_referer() reads $_REQUEST in CLI.

		$captured_redirect = null;

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured_redirect ) {
				$captured_redirect = $location;
				throw new \RuntimeException( 'redirect_captured' );
			}
		);

		try {
			( new Connect_Page() )->handle_authorize();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect_captured', $e->getMessage() );
		}

		remove_all_filters( 'wp_redirect' );

		// Clean up superglobals.
		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertNotNull( $captured_redirect, 'handle_authorize() must call wp_redirect()' );

		$parts = wp_parse_url( $captured_redirect );
		$this->assertSame( '127.0.0.1', $parts['host'], 'redirect must target the loopback host' );
		$this->assertSame( 51791, $parts['port'], 'redirect must target the correct loopback port' );

		parse_str( $parts['query'], $qs );
		$this->assertNotEmpty( $qs['password'], 'redirect must carry a password query param' );
		$this->assertNotEmpty( $qs['user'], 'redirect must carry a user query param' );
		$this->assertNotEmpty( $qs['site'], 'redirect must carry a site query param' );
		$this->assertSame( 'test-state-token', $qs['state'], 'redirect must echo back the state token' );

		// Exactly one Application Password must have been minted.
		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent );
		$passwords = WP_Application_Passwords::get_user_application_passwords( $agent->ID );
		$this->assertCount( 1, $passwords, 'exactly one Application Password must be minted' );
	}

	/**
	 * handle_authorize() with a non-loopback callback must reject the request
	 * with wp_die() and must NOT mint any Application Password.
	 *
	 * We set up a filter on wp_die to assert it is called, then verify no agent
	 * account was provisioned and no password was minted.
	 */
	public function test_handle_authorize_rejects_non_loopback_callback_and_does_not_mint() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'https://evil.com/steal';
		$_POST['state']       = 'anything';
		$_POST['client']      = 'block-mcp';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$wp_die_called = false;

		add_filter(
			'wp_die_handler',
			static function () use ( &$wp_die_called ) {
				return static function () use ( &$wp_die_called ) {
					$wp_die_called = true;
					throw new \RuntimeException( 'wp_die_called' );
				};
			}
		);

		try {
			( new Connect_Page() )->handle_authorize();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		remove_all_filters( 'wp_die_handler' );

		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertTrue( $wp_die_called, 'handle_authorize() must call wp_die() for a non-loopback callback' );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertFalse( $agent, 'no agent user must be created when the callback is rejected' );
	}

	/**
	 * handle_authorize() must call wp_die() with a 403 response when the current
	 * user lacks manage_options, and must not mint any credential.
	 */
	public function test_handle_authorize_rejects_user_without_manage_options() {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		// No nonce needed — the capability check fires before the nonce check.
		$_POST['action']   = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback'] = 'http://127.0.0.1:9999/cb';
		$_POST['state']    = 'x';
		$_POST['client']   = 'block-mcp';

		$die_called = false;

		add_filter(
			'wp_die_handler',
			static function () use ( &$die_called ) {
				return static function () use ( &$die_called ) {
					$die_called = true;
					throw new \RuntimeException( 'wp_die_called' );
				};
			}
		);

		try {
			( new Connect_Page() )->handle_authorize();
		} catch ( \RuntimeException $e ) {
			// Expected.
		}

		remove_all_filters( 'wp_die_handler' );

		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'] );

		$this->assertTrue( $die_called, 'handle_authorize() must wp_die() when the user lacks manage_options' );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertFalse( $agent, 'no credential must be minted for an unauthorized user' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// connection_state().
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * connection_state() must return 'needs_https' when Application Passwords
	 * are unavailable, 'ready' when they are available but no connection exists,
	 * and 'connected' once the agent user exists and has at least one Block MCP
	 * Application Password.
	 */
	public function test_connection_state_branches() {
		$page = new Connect_Page();

		// Branch 1: passwords unavailable → 'needs_https'.
		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		$this->assertSame( 'needs_https', $page->connection_state() );

		// Branch 2: passwords available, no agent/connections → 'ready'.
		remove_filter( 'wp_is_application_passwords_available', '__return_false' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		delete_option( 'gk_block_api_agent_user_id' );
		$this->assertSame( 'ready', $page->connection_state() );

		// Branch 3: passwords available + agent + at least one connection → 'connected'.
		$result = $page->prepare_installer( 'Claude Desktop', $this->fixture_server );
		$this->assertIsArray( $result, 'prepare_installer must succeed for the connected branch' );
		wp_delete_file( $result['path'] );

		$this->assertSame( 'connected', $page->connection_state() );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Secret-at-rest mode.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * When the gk_block_api_secret_at_rest_mode filter returns 'paste', the
	 * built bundle must carry an empty string for wordpress_app_password.default
	 * (so the installer does not pre-fill the field) and the return array must
	 * carry the plaintext password separately for the UI to display once.
	 *
	 * This mode lets security-conscious operators avoid embedding the plaintext
	 * credential in the downloadable file at the cost of requiring a manual copy.
	 */
	public function test_secret_at_rest_filter_paste_mode_omits_password_default() {
		add_filter(
			'gk_block_api_secret_at_rest_mode',
			static function () {
				return 'paste';
			}
		);

		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', $this->fixture_server );

		$this->assertIsArray( $result );
		$this->assertSame( 'paste', $result['mode'] );
		$this->assertNotEmpty( $result['password'], 'plaintext password must be returned in paste mode' );

		$zip = new ZipArchive();
		$zip->open( $result['path'] );
		$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
		$zip->close();

		$this->assertSame(
			'',
			$manifest['user_config']['wordpress_app_password']['default'],
			'manifest must carry empty password default in paste mode'
		);

		wp_delete_file( $result['path'] );
	}

	/**
	 * When GK_BLOCK_API_FORCE_PASTE_SECRET is defined and true (no filter), the
	 * built bundle must carry an empty password default and the return array must
	 * carry the plaintext password separately.
	 *
	 * This pins the constant-driven path independently of the filter-driven path
	 * covered by test_secret_at_rest_filter_paste_mode_omits_password_default().
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_force_paste_constant_omits_password_default() {
		define( 'GK_BLOCK_API_FORCE_PASTE_SECRET', true );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$fixture = wp_tempnam( 'mcp-server' );
		file_put_contents( $fixture, "#!/usr/bin/env node\n// fixture\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$page   = new Connect_Page();
		$result = $page->prepare_installer( 'Claude Desktop', $fixture );

		wp_delete_file( $fixture );

		$this->assertIsArray( $result, 'prepare_installer() must return an array' );
		$this->assertSame( 'paste', $result['mode'] );
		$this->assertNotEmpty( $result['password'], 'plaintext password must be returned in paste mode' );

		$zip = new ZipArchive();
		$zip->open( $result['path'] );
		$manifest = json_decode( $zip->getFromName( 'manifest.json' ), true );
		$zip->close();

		$this->assertSame(
			'',
			$manifest['user_config']['wordpress_app_password']['default'],
			'manifest must carry empty password default when FORCE_PASTE_SECRET constant is true'
		);

		wp_delete_file( $result['path'] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// do_revoke() — testable seam for the revoke handler.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * do_revoke() must delete the targeted Application Password and return true
	 * when the agent user ID is stored and the UUID matches a live credential.
	 *
	 * This pins the testable seam extracted from handle_revoke() so the
	 * credential-deletion logic can be verified without invoking the full handler
	 * (which calls exit after the redirect).
	 */
	public function test_do_revoke_removes_the_credential() {
		$agent_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_option( 'gk_block_api_agent_user_id', $agent_id );

		$created = \WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Block MCP — Claude Desktop' )
		);
		$uuid = $created[1]['uuid'];

		$page = new Connect_Page();
		$ok   = $page->do_revoke( $uuid );

		$this->assertTrue( $ok );

		$remaining = \WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertEmpty( $remaining, 'Application Password must be deleted after do_revoke()' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// gk_block_api_do_revoke_all() — testable seam for the revoke-all handler.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * The revoke-all seam must delete every Application Password on the agent
	 * user and return the count of passwords that were present.
	 *
	 * Seeding two passwords and asserting both are gone after the call confirms
	 * that delete_all_application_passwords() was invoked and that the return
	 * value accurately reflects the pre-deletion count.
	 */
	public function test_do_revoke_all_removes_every_credential() {
		$agent_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_option( 'gk_block_api_agent_user_id', $agent_id );

		\WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Block MCP — Claude Desktop' )
		);
		\WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Block MCP — Cursor' )
		);

		$count = GravityKit\BlockAPI\do_revoke_all();

		$this->assertSame( 2, $count, 'do_revoke_all() must return the number of passwords that were revoked' );

		$remaining = \WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertEmpty( $remaining, 'All Application Passwords must be gone after do_revoke_all()' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// render_section() — output contracts.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * render_section() in the 'ready' state must output the after-download
	 * guidance block and all six client-picker radio cards with stable slug
	 * values, with Claude Desktop checked by default.
	 *
	 * Radio values are the stable, translation-proof slugs defined by the
	 * CLIENT_* constants. Labels (e.g. "Claude Desktop app") are display-only
	 * and appear as card text — they must also be present but must NOT be used
	 * as the radio `value`.
	 *
	 * The six required slugs: CLIENT_CLAUDE_DESKTOP, CLIENT_CLAUDE_CODE,
	 * CLIENT_CURSOR, CLIENT_CHATGPT, CLIENT_AI_PROMPT, CLIENT_OTHER.
	 * The "Let my AI set it up" card must carry the is-ai class.
	 * The Claude Desktop radio must be checked by default.
	 *
	 * Wrapping markup uses <div class="gk-connect"> + <div class="gk-connect__card">
	 * so all CSS selectors are scoped.
	 */
	public function test_render_section_shows_next_steps_and_all_six_picker_cards_ready_state() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		// Wrapper class and card container must be present for scoped CSS.
		$this->assertStringContainsString( 'class="gk-connect"', $html, 'Outer wrapper must carry class gk-connect' );
		$this->assertStringContainsString( 'gk-connect__card', $html, 'Card container must be present' );

		$this->assertStringContainsString( 'After you download', $html, 'Next-steps panel must be present' );

		// Radio inputs must be present with name="client" (not a <select>).
		$this->assertStringContainsString( 'type="radio"', $html, 'Radio inputs must be present' );
		$this->assertStringContainsString( 'name="client"', $html, 'Radio inputs must carry name="client"' );

		// All six radio values must be stable slugs — NOT translated labels.
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"', $html, 'claude-desktop slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CLAUDE_CODE . '"', $html, 'claude-code slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CURSOR . '"', $html, 'cursor slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CHATGPT . '"', $html, 'chatgpt-desktop slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_AI_PROMPT . '"', $html, 'ai-prompt slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_OTHER . '"', $html, 'other slug must be the radio value' );

		// Human-facing card labels (display-only) must also appear as card text.
		$this->assertStringContainsString( 'Claude Desktop app', $html, 'Claude Desktop card label must be present as card text' );
		$this->assertStringContainsString( 'Claude Code', $html, 'Claude Code card label must be present as card text' );
		$this->assertStringContainsString( 'Cursor', $html, 'Cursor card label must be present as card text' );
		$this->assertStringContainsString( 'ChatGPT Desktop', $html, 'ChatGPT Desktop card label must be present as card text' );
		$this->assertStringContainsString( 'Let my AI set it up', $html, '"Let my AI" card label must be present as card text' );
		$this->assertStringContainsString( 'Something else', $html, '"Something else" card label must be present as card text' );

		// The "Let my AI" card must carry the is-ai accent class.
		$this->assertStringContainsString( 'is-ai', $html, '"Let my AI" card must carry is-ai class' );

		// The Claude Desktop radio must be checked by default.
		$this->assertMatchesRegularExpression(
			'/value="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"[^>]*checked|checked[^>]*value="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"/',
			$html,
			'Claude Desktop radio must be checked by default'
		);

		// A <select> must NOT be present — the picker is radio cards.
		$this->assertStringNotContainsString( '<select', $html, 'A <select> must not be present; picker uses radio cards' );
	}

	/**
	 * render_section() in the 'connected' state must output the connected-state
	 * markers ("You're connected") and a Disconnect control for each active
	 * connection, in addition to the after-download guidance and client picker.
	 *
	 * This pins the P0 deliverable for the post-connection state: both the
	 * connection indicator and the revoke affordance must be rendered when at
	 * least one credential is live.
	 */
	public function test_render_section_shows_connected_state_markers() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$agent_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_option( 'gk_block_api_agent_user_id', $agent_id );

		\WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Block MCP — Claude Desktop' )
		);

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( "You&#039;re connected", $html, "Connected state marker must be present" );
		$this->assertStringContainsString( 'Disconnect', $html, 'Disconnect control must be present for active connection' );
		$this->assertStringContainsString( 'After you download', $html, 'Next-steps panel must still be present in connected state' );
	}

	/**
	 * render_section() with ?setup=<label-string> must NOT render the command
	 * artifact. Only stable slug values trigger rendering.
	 *
	 * This is the regression test for the label/slug mismatch bug: when
	 * handle_connect() redirected with ?setup=Claude+Code (a label), render_section()
	 * compared it against the slug 'claude-code' — a mismatch — so the artifact
	 * card was silently skipped. The fix uses slugs as the canonical key everywhere,
	 * ensuring ?setup=<slug> always matches and ?setup=<label> never does.
	 *
	 * The test also confirms that setting $_GET['setup'] to a slug (the post-fix
	 * canonical form) DOES render the artifact, and setting it to the old label
	 * value does NOT.
	 */
	public function test_render_section_setup_slug_renders_artifact_label_does_not() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( 'gk_block_api_agent_user_id' );

		// Slug renders the artifact (the fixed behaviour).
		$_GET['setup'] = Connect_Page::CLIENT_CLAUDE_CODE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		( new Connect_Page() )->render_section();
		$html_slug = ob_get_clean();

		unset( $_GET['setup'] );

		$this->assertStringContainsString(
			'npx -y @gravitykit/block-mcp connect',
			$html_slug,
			'Artifact card must render when ?setup carries the slug'
		);
		$this->assertStringContainsString(
			'--client ' . Connect_Page::CLIENT_CLAUDE_CODE,
			$html_slug,
			'Artifact body must include the --client slug flag'
		);

		// Old label value must NOT render the artifact (was the broken behaviour).
		$_GET['setup'] = 'Claude Code'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		( new Connect_Page() )->render_section();
		$html_label = ob_get_clean();

		unset( $_GET['setup'] );

		$this->assertStringNotContainsString(
			'npx -y @gravitykit/block-mcp connect',
			$html_label,
			'Artifact card must NOT render when ?setup carries an old label value'
		);
	}

	/**
	 * render_section() with ?setup=<client-slug> must show the secret-free npx connect
	 * command for that client. No password field and no "Copy password" button must
	 * appear — the credential is delivered via the browser-Approve handshake, not
	 * shown in the UI.
	 *
	 * This test uses the canonical slug (CLIENT_CLAUDE_CODE) as handle_connect()
	 * now redirects with after the refactor. Previously, handle_connect() redirected
	 * with ?setup=Claude+Code (the label), which never matched the slug comparison
	 * in render_section() — causing the artifact card to silently disappear.
	 */
	public function test_render_section_setup_query_shows_command_artifact_no_password() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( 'gk_block_api_agent_user_id' );

		$_GET['setup'] = Connect_Page::CLIENT_CLAUDE_CODE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		unset( $_GET['setup'] );

		$this->assertStringContainsString( 'npx -y @gravitykit/block-mcp connect', $html, 'Artifact textarea must contain the npx connect command' );
		$this->assertStringContainsString( '--client ' . Connect_Page::CLIENT_CLAUDE_CODE, $html, 'Artifact must carry the --client slug' );
		$this->assertStringNotContainsString( 'WORDPRESS_APP_PASSWORD', $html, 'WORDPRESS_APP_PASSWORD must NOT appear in the output' );
		$this->assertStringNotContainsString( 'Copy password', $html, 'No "Copy password" button must be present' );
		$this->assertStringContainsString( 'No password to copy', $html, '"No password to copy" note must be present' );
	}

	/**
	 * render_section() with ?gk_authorize set must render the Approve screen,
	 * not the normal connect UI.
	 *
	 * The Approve screen must include the heading "Authorize a connection", an
	 * Approve submit button, and a Cancel link. The normal client-picker form
	 * must NOT appear.
	 */
	public function test_render_section_gk_authorize_shows_approve_screen() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['gk_authorize'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['callback']     = 'http://127.0.0.1:9999/cb';
		$_GET['state']        = 'tok123';
		$_GET['client']       = 'block-mcp';

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		unset( $_GET['gk_authorize'], $_GET['callback'], $_GET['state'], $_GET['client'] );

		$this->assertStringContainsString( 'Authorize a connection', $html, 'Authorize heading must be present' );
		$this->assertStringContainsString( 'Approve', $html, 'Approve button must be present' );
		$this->assertStringContainsString( 'Cancel', $html, 'Cancel link must be present' );
		$this->assertStringContainsString( Connect_Page::ACTION_AUTHORIZE, $html, 'Form action must reference the authorize action' );

		// The normal client picker must NOT appear in authorize mode.
		$this->assertStringNotContainsString( 'Connect an AI Assistant', $html, 'Normal connect UI heading must NOT appear in authorize mode' );
		$this->assertStringNotContainsString( 'value="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"', $html, 'Client picker radios must NOT appear in authorize mode' );
	}
}
