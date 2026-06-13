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
 *  - The gk/block-mcp/credential/seal-mode filter in 'paste' mode causes
 *    prepare_installer() to omit the password from the manifest default and
 *    return the plaintext separately.
 *  - render_section() in the 'ready' state outputs all six client radio cards
 *    with the correct values, Claude Desktop checked by default.
 *  - render_section() with ?setup=<client> shows the secret-free command
 *    artifact for that client; no password field is rendered.
 *  - render_section() with ?gk_authorize renders the Approve screen.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Agent_Provisioner;
use GravityKit\BlockMCP\Connect_Page;
use GravityKit\BlockMCP\Connections;

/**
 * Tests for Connect_Page.
 *
 * @covers \GravityKit\BlockMCP\Connect_Page
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
		remove_all_filters( 'gk/block-mcp/credential/seal-mode' );
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
	 * The connection label must show the friendly client name, not the slug.
	 *
	 * The browser-Approve path (handle_authorize) hands provision_credentials the
	 * raw client slug straight from the request ("claude-code"), so the minted
	 * Application Password name — which is what the "Client" column renders — must
	 * resolve through client_label() to "Claude Code". Pre-fix the label read
	 * "Block MCP — claude-code"; this pins the resolution so the slug never leaks
	 * into the connections list.
	 */
	public function test_provision_credentials_label_resolves_slug_to_friendly_name() {
		$page = new Connect_Page();
		$page->provision_credentials( 'claude-code', 'agent' );

		$agent     = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$passwords = WP_Application_Passwords::get_user_application_passwords( $agent->ID );
		$this->assertCount( 1, $passwords );

		$name = $passwords[0]['name'];
		$this->assertStringContainsString( 'Claude Code', $name, 'label must use the friendly client name' );
		$this->assertStringNotContainsString( 'claude-code', $name, 'label must not leak the raw client slug' );
	}

	/**
	 * Default identity ('agent') mints on the dedicated agent account and records
	 * the host + approver for the audit trail (no byline state — that subsystem was
	 * removed with the agent_as_me identity).
	 */
	public function test_provision_credentials_agent_mints_on_agent() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$page  = new Connect_Page();
		$creds = $page->provision_credentials( 'Claude Code', 'agent' );

		$this->assertIsArray( $creds );
		$this->assertSame( Agent_Provisioner::LOGIN, $creds['user'] );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$meta  = \GravityKit\BlockMCP\Connections::get_meta( $creds['uuid'] );
		$this->assertSame( (int) $agent->ID, (int) $meta['user_id'] );
		$this->assertSame( $admin, (int) $meta['created_by'] );
	}

	/**
	 * The removed 'agent_as_me' identity — and any other unrecognised value — falls
	 * back to the dedicated agent account, never the approving user.
	 */
	public function test_provision_credentials_unknown_identity_falls_back_to_agent() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$page  = new Connect_Page();
		$creds = $page->provision_credentials( 'Cursor', 'agent_as_me' );

		$this->assertIsArray( $creds );
		$this->assertSame( Agent_Provisioner::LOGIN, $creds['user'], 'unknown identity must mint on the agent, not the user' );
		$this->assertCount( 0, WP_Application_Passwords::get_user_application_passwords( $admin ), 'no credential may be minted on the approving user' );
	}

	/**
	 * 'self' (use my own account) mints the credential on the approving user — not
	 * the agent — so the AI app acts with that person's full capabilities. The
	 * agent is not provisioned on this path. Pins the higher-blast-radius contract.
	 */
	public function test_provision_credentials_self_mints_on_current_user() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$page  = new Connect_Page();
		$creds = $page->provision_credentials( 'Cursor', 'self' );

		$this->assertIsArray( $creds );
		$admin_user = get_user_by( 'id', $admin );
		$this->assertSame( $admin_user->user_login, $creds['user'], 'self mints on the approving user' );

		// The credential lives on the human, and the agent was never provisioned.
		$this->assertCount( 1, WP_Application_Passwords::get_user_application_passwords( $admin ) );
		$this->assertFalse( get_user_by( 'login', Agent_Provisioner::LOGIN ), 'self path must not provision the agent' );

		$meta = \GravityKit\BlockMCP\Connections::get_meta( $creds['uuid'] );
		$this->assertSame( $admin, (int) $meta['user_id'] );
		$this->assertSame( $admin, (int) $meta['created_by'] );
	}

	/**
	 * The gk/block-mcp/identity/allow-self filter (false) clamps a 'self' request
	 * back to the dedicated agent account, so an operator can forbid full-account
	 * credentials. No credential may be minted on the approving user.
	 */
	public function test_self_identity_disabled_by_filter_clamps_to_agent() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		add_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );
		$page  = new Connect_Page();
		$creds = $page->provision_credentials( 'Cursor', 'self' );
		remove_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );

		$this->assertIsArray( $creds );
		$this->assertSame( Agent_Provisioner::LOGIN, $creds['user'], 'self must clamp to the agent when the filter forbids it' );
		$this->assertCount( 0, WP_Application_Passwords::get_user_application_passwords( $admin ), 'no credential may be minted on the user when self is disabled' );
	}

	/**
	 * On single-site, a connection's Application Password label is exactly
	 * "Block MCP — <client>" with no site suffix.
	 *
	 * Pins that connection_label()'s multisite site-discriminator does not leak
	 * into the single-site label — the common path stays unchanged.
	 */
	public function test_connection_label_has_no_site_suffix_on_single_site() {
		if ( is_multisite() ) {
			$this->markTestSkipped( 'Single-site label contract; the multisite suffix is covered separately.' );
		}

		$page = new Connect_Page();
		$page->provision_credentials( 'Claude Desktop' );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent );
		$passwords = WP_Application_Passwords::get_user_application_passwords( $agent->ID );
		$this->assertCount( 1, $passwords );
		$this->assertSame(
			'Block MCP — Claude Desktop',
			$passwords[0]['name'],
			'single-site connection label must carry no site suffix'
		);
	}

	/**
	 * On multisite, the agent user and its Application Passwords are
	 * network-global, so a connection minted on one sub-site appears in every
	 * blog's list. connection_label() appends the originating site's address so
	 * the two are distinguishable. This provisions on the main blog and on a fresh
	 * sub-site against the SAME network agent, then asserts both connections are
	 * listed and each label carries its own site address — proving the list is
	 * honestly network-wide while still attributing each connection to its origin.
	 *
	 * @group ms-required
	 */
	public function test_connection_label_includes_site_address_on_multisite() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test. Run with WP_TESTS_MULTISITE=1.' );
		}

		$page = new Connect_Page();

		// Main-blog connection.
		$main_creds = $page->provision_credentials( 'Claude Desktop' );
		$this->assertIsArray( $main_creds );
		$main_site = (string) preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent );

		// Sub-site connection on the same network agent.
		$blog_id = self::factory()->blog->create();
		$this->assertIsInt( $blog_id );

		switch_to_blog( $blog_id );
		$sub_site = (string) preg_replace( '#^https?://#', '', untrailingslashit( home_url() ) );
		try {
			$sub_creds = $page->provision_credentials( 'Cursor' );
			$this->assertIsArray( $sub_creds );
		} finally {
			restore_current_blog();
		}

		// The site addresses must differ, or the discriminator is pointless.
		$this->assertNotSame( $main_site, $sub_site, 'main and sub-site addresses must differ' );

		// Both connections live on the one network-global agent and are both listed.
		$names = wp_list_pluck( ( new Connections() )->list( $agent->ID ), 'name', 'uuid' );
		$this->assertArrayHasKey( $main_creds['uuid'], $names );
		$this->assertArrayHasKey( $sub_creds['uuid'], $names );
		$this->assertSame( 'Block MCP — Claude Desktop (' . $main_site . ')', $names[ $main_creds['uuid'] ] );
		$this->assertSame( 'Block MCP — Cursor (' . $sub_site . ')', $names[ $sub_creds['uuid'] ] );
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
	 * Application Password and redirect a single-use exchange CODE (not the
	 * credential itself) to the callback URL, with the state echoed back.
	 *
	 * [WP-F3] The site-wide password must NOT appear in the redirect URL — it is
	 * delivered out-of-band via the exchange endpoint. This pins that the
	 * redirect carries code + state and no password/user/site.
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
		$this->assertNotEmpty( $qs['code'], 'redirect must carry a single-use exchange code' );
		$this->assertSame( 'test-state-token', $qs['state'], 'redirect must echo back the state token' );
		$this->assertArrayNotHasKey( 'password', $qs, 'redirect must NOT carry the password (delivered via exchange)' );
		$this->assertArrayNotHasKey( 'user', $qs, 'redirect must NOT carry the user' );
		$this->assertArrayNotHasKey( 'site', $qs, 'redirect must NOT carry the site' );

		// Exactly one Application Password must have been minted.
		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent );
		$passwords = WP_Application_Passwords::get_user_application_passwords( $agent->ID );
		$this->assertCount( 1, $passwords, 'exactly one Application Password must be minted' );
	}

	/**
	 * The browser-Approve fetch path returns the code as JSON and emits NO
	 * loopback address anywhere in the response.
	 *
	 * A server-issued redirect to the loopback callback (Location:
	 * http://127.0.0.1:...) reads as SSRF/open-redirect to origin WAF/RASP
	 * layers, which block the response and silently break Connect. The fix
	 * returns the code as JSON when the request is marked gk_xhr and lets the
	 * browser navigate client-side. This pins all three halves: no server
	 * redirect is issued, the single-use code + state are delivered, and the
	 * loopback host appears nowhere in the response body. Revert the JSON branch
	 * and the handler redirects instead — the wp_redirect guard trips and this
	 * goes red.
	 */
	public function test_handle_authorize_xhr_returns_code_as_json_without_loopback_address() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'http://127.0.0.1:51791/cb';
		$_POST['state']       = 'xhr-state-token';
		$_POST['client']      = 'block-mcp';
		$_POST['gk_xhr']      = '1';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$redirected = false;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$redirected ) {
				$redirected = true;
				throw new \RuntimeException( 'must_not_redirect: ' . (string) $location );
			}
		);

		try {
			$raw = $this->capture_authorize_output( new Connect_Page() );
		} finally {
			remove_all_filters( 'wp_redirect' );
			unset(
				$_POST['action'],
				$_POST['callback'],
				$_POST['state'],
				$_POST['client'],
				$_POST['gk_xhr'],
				$_POST['_wpnonce'],
				$_REQUEST['_wpnonce']
			);
		}

		$this->assertFalse( $redirected, 'the XHR path must NOT issue a server redirect' );
		$this->assertStringNotContainsString( '127.0.0.1', $raw, 'no loopback address may appear in the response body' );

		$json = (array) json_decode( $raw, true );
		$this->assertTrue( $json['success'], 'the XHR path returns a success envelope' );
		$this->assertNotEmpty( $json['data']['code'], 'the response carries the single-use exchange code' );
		$this->assertSame( 'xhr-state-token', $json['data']['state'], 'the response echoes the state token' );
		$this->assertArrayNotHasKey( 'password', $json['data'], 'the response must NOT carry the password' );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent );
		$this->assertCount(
			1,
			WP_Application_Passwords::get_user_application_passwords( $agent->ID ),
			'exactly one Application Password must be minted'
		);
	}

	/**
	 * Invoke handle_authorize() and return the raw body it emits.
	 *
	 * Mirrors capture_exchange_json(): wp_send_json_* calls die() in a non-AJAX
	 * request, which would kill the test process; flip wp_doing_ajax true so it
	 * routes through wp_die() instead, and install a throwing wp_die handler so
	 * the echoed body can be captured.
	 *
	 * @param  Connect_Page $page Page whose handle_authorize() to invoke.
	 * @return string Raw response body.
	 */
	private function capture_authorize_output( Connect_Page $page ): string {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'throwing_die_handler' ) );

		ob_start();
		try {
			$page->handle_authorize();
		} catch ( \WPDieException $e ) {
			unset( $e );
		} finally {
			$out = (string) ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', array( $this, 'throwing_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return $out;
	}

	/**
	 * handle_authorize() must rawurlencode() code/state before add_query_arg().
	 *
	 * add_query_arg() does NOT encode the new values it adds — it expects the
	 * caller to pre-encode them (it only re-encodes params already present in
	 * the URL). Passing a query-significant character (&) raw would corrupt the
	 * redirect query string: '&' starts a new parameter, so the state would be
	 * truncated and the connector's CSRF state comparison would fail. This pins
	 * that a state containing '&' round-trips intact through the redirect — a
	 * guard against "simplifying away" the rawurlencode() wrapper.
	 */
	public function test_handle_authorize_encodes_query_significant_chars_in_state() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		// '&' is query-significant: without rawurlencode() it would split the
		// state into two params and the round-trip would lose everything after it.
		$raw_state = 'a&b=evil';

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'http://127.0.0.1:51793/cb';
		$_POST['state']       = $raw_state;
		$_POST['client']      = 'block-mcp';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

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
		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertNotNull( $captured_redirect, 'handle_authorize() must call wp_redirect()' );

		$parts = wp_parse_url( $captured_redirect );
		parse_str( $parts['query'], $qs );

		$this->assertSame( $raw_state, $qs['state'], 'state with a query-significant char must round-trip intact' );
	}

	/**
	 * [WP-F3] The minted password must never appear in the redirect URL string.
	 *
	 * Belt-and-suspenders for the credential-in-URL finding: even if a future
	 * change re-added a credential param, the raw password string must not be
	 * present anywhere in the redirect location. We capture the redirect and the
	 * minted password (read back from the agent) and assert the URL excludes it.
	 */
	public function test_handle_authorize_redirect_never_contains_the_password() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'http://127.0.0.1:51792/cb';
		$_POST['state']       = 'state-xyz';
		$_POST['client']      = 'block-mcp';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

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
		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		// Redeem the code from the redirect to learn the password that was stored,
		// then assert that password never appeared in the redirect URL itself.
		$parts = wp_parse_url( $captured_redirect );
		parse_str( $parts['query'], $qs );

		$page   = new Connect_Page();
		$redeem = new \ReflectionMethod( Connect_Page::class, 'redeem_exchange_code' );
		$redeem->setAccessible( true );
		$creds = $redeem->invoke( $page, rawurldecode( $qs['code'] ) );

		$this->assertIsArray( $creds, 'the exchange code must redeem to a credential set' );
		$this->assertNotEmpty( $creds['password'], 'redeemed credentials must include the password' );
		$this->assertStringNotContainsString(
			$creds['password'],
			(string) $captured_redirect,
			'the minted password must never appear in the redirect URL'
		);
	}

	/**
	 * handle_authorize() with identity=self mints the Application Password on the
	 * APPROVING admin — not the dedicated agent — so the AI app acts with that
	 * person's full capabilities.
	 *
	 * This drives the real browser-Approve handler (nonce + manage_options +
	 * loopback callback + redirect) with $_POST['identity']='self', the path the
	 * Approve screen's "Your own account" radio submits. It pins the higher-blast-
	 * radius end-to-end seam: exactly one credential lands on the current admin and
	 * the block-mcp service account is never provisioned. The provision-level
	 * equivalent (test_provision_credentials_self_mints_on_current_user) does not
	 * exercise the HTTP handler that reads and forwards the identity field.
	 */
	public function test_handle_authorize_self_mints_on_approving_user() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'http://127.0.0.1:51793/cb';
		$_POST['state']       = 'self-state-token';
		$_POST['client']      = 'block-mcp';
		$_POST['identity']    = 'self';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

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
		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'], $_POST['identity'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertNotNull( $captured_redirect, 'handle_authorize() must call wp_redirect()' );

		// The credential lives on the approving admin — exactly one — and the agent
		// service account was never provisioned on the self path.
		$this->assertCount(
			1,
			WP_Application_Passwords::get_user_application_passwords( $admin_id ),
			'identity=self must mint exactly one Application Password on the approving user'
		);
		$this->assertFalse(
			get_user_by( 'login', Agent_Provisioner::LOGIN ),
			'identity=self must not provision the dedicated agent service account'
		);
	}

	/**
	 * handle_authorize() with identity=self is clamped back to the dedicated agent
	 * when gk/block-mcp/identity/allow-self returns false.
	 *
	 * An operator (or managed host) can forbid full-account credentials with the
	 * filter. With it disabled, a self request from the Approve handler must mint on
	 * the limited block-mcp agent instead — never on the approving admin — so the
	 * server-side clamp holds even if a client POSTs identity=self directly. Pins
	 * the clamp at the HTTP boundary, not just inside provision_credentials().
	 */
	public function test_handle_authorize_self_clamped_to_agent_when_filter_disabled() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'http://127.0.0.1:51794/cb';
		$_POST['state']       = 'clamp-state-token';
		$_POST['client']      = 'block-mcp';
		$_POST['identity']    = 'self';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		add_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );

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
		remove_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );
		unset( $_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'], $_POST['identity'], $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );

		$this->assertNotNull( $captured_redirect, 'handle_authorize() must call wp_redirect()' );

		// The clamp moved the mint to the agent: the agent exists with exactly one
		// credential, and the approving admin holds none.
		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		$this->assertInstanceOf( WP_User::class, $agent, 'the clamp must provision the dedicated agent' );
		$this->assertCount(
			1,
			WP_Application_Passwords::get_user_application_passwords( $agent->ID ),
			'the clamped credential must be minted on the agent'
		);
		$this->assertCount(
			0,
			WP_Application_Passwords::get_user_application_passwords( $admin_id ),
			'no credential may be minted on the approving user when self is disabled'
		);
	}

	// ──────────────────────────────────────────────────────────────────────
	// [WP-F3] Single-use credential exchange code.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * store_exchange_code() then redeem_exchange_code() round-trips the creds.
	 *
	 * The connector receives only the code on the loopback callback, then POSTs
	 * it to the exchange endpoint to retrieve the credential set once.
	 */
	public function test_exchange_code_round_trips_credentials() {
		$page  = new Connect_Page();
		$store = new \ReflectionMethod( Connect_Page::class, 'store_exchange_code' );
		$store->setAccessible( true );
		$redeem = new \ReflectionMethod( Connect_Page::class, 'redeem_exchange_code' );
		$redeem->setAccessible( true );

		$code = $store->invoke(
			$page,
			array(
				'url'      => 'https://example.com',
				'user'     => 'block-mcp',
				'password' => 'minted secret 9876',
				'uuid'     => 'abc',
			)
		);

		$this->assertNotEmpty( $code, 'store_exchange_code() must return a non-empty code' );

		$creds = $redeem->invoke( $page, $code );
		$this->assertSame( 'https://example.com', $creds['site'] );
		$this->assertSame( 'block-mcp', $creds['user'] );
		$this->assertSame( 'minted secret 9876', $creds['password'] );
	}

	/**
	 * An exchange code must be single-use: a second redeem returns null.
	 *
	 * Pins the replay guard — once the connector has exchanged the code, it is
	 * deleted and cannot be redeemed again.
	 */
	public function test_exchange_code_is_single_use() {
		$page  = new Connect_Page();
		$store = new \ReflectionMethod( Connect_Page::class, 'store_exchange_code' );
		$store->setAccessible( true );
		$redeem = new \ReflectionMethod( Connect_Page::class, 'redeem_exchange_code' );
		$redeem->setAccessible( true );

		$code = $store->invoke(
			$page,
			array(
				'url'      => 'https://example.com',
				'user'     => 'block-mcp',
				'password' => 'one time only',
				'uuid'     => 'abc',
			)
		);

		$first = $redeem->invoke( $page, $code );
		$this->assertIsArray( $first, 'first redeem must succeed' );

		$second = $redeem->invoke( $page, $code );
		$this->assertNull( $second, 'a redeemed code must not be reusable' );
	}

	/**
	 * redeem_exchange_code() returns null for an unknown or empty code.
	 */
	public function test_exchange_code_rejects_unknown_code() {
		$page   = new Connect_Page();
		$redeem = new \ReflectionMethod( Connect_Page::class, 'redeem_exchange_code' );
		$redeem->setAccessible( true );

		$this->assertNull( $redeem->invoke( $page, 'never-issued-code' ), 'unknown code must redeem to null' );
		$this->assertNull( $redeem->invoke( $page, '' ), 'empty code must redeem to null' );
	}

	/**
	 * register() must wire the exchange handler on both the logged-in and the
	 * nopriv admin-post hooks — the connector is an unauthenticated local
	 * process, so the code itself is the bearer credential.
	 */
	public function test_register_wires_exchange_handler_for_nopriv() {
		$page = new Connect_Page();
		$page->register();

		$this->assertNotFalse(
			has_action( 'admin_post_nopriv_' . Connect_Page::ACTION_EXCHANGE, array( $page, 'handle_exchange' ) ),
			'exchange handler must be wired on the nopriv admin-post hook'
		);
		$this->assertNotFalse(
			has_action( 'admin_post_' . Connect_Page::ACTION_EXCHANGE, array( $page, 'handle_exchange' ) ),
			'exchange handler must be wired on the logged-in admin-post hook'
		);
	}

	/**
	 * The public handle_exchange() seam — the actual nopriv POST handler the
	 * connector hits — must return the stored credential exactly once as JSON
	 * success, then reject a replay of the same code with JSON error.
	 *
	 * The store/redeem helpers are unit-tested above via reflection; this drives
	 * the real $_POST → redeem → wp_send_json path end-to-end so the bearer-code
	 * contract is pinned at the HTTP boundary, not just the internal seam.
	 */
	public function test_handle_exchange_returns_credentials_once_then_rejects_replay() {
		$page  = new Connect_Page();
		$store = new \ReflectionMethod( Connect_Page::class, 'store_exchange_code' );
		$store->setAccessible( true );
		$code = $store->invoke(
			$page,
			array( 'url' => 'https://example.com', 'user' => 'block-mcp', 'password' => 'live-secret' )
		);

		$_POST['code'] = $code;
		try {
			$first = $this->capture_exchange_json( $page );
			$this->assertTrue( $first['success'], 'first exchange must succeed' );
			$this->assertSame( 'live-secret', $first['data']['password'], 'first exchange returns the stored password once' );
			$this->assertSame( 'https://example.com', $first['data']['site'] );

			$second = $this->capture_exchange_json( $page );
			$this->assertFalse( $second['success'], 'a replay of the consumed code must be rejected' );
		} finally {
			unset( $_POST['code'] );
		}
	}

	/**
	 * Invoke handle_exchange() and decode the JSON it emits.
	 *
	 * wp_send_json_* echoes the body and then terminates the request. In a normal
	 * (non-AJAX) request it calls die() outright, which would kill the test
	 * process; flip wp_doing_ajax true so it routes through wp_die() instead, and
	 * install a wp_die handler that throws so the echo can be captured. This is
	 * the same shape WP_Ajax_UnitTestCase uses for JSON handlers.
	 *
	 * @param  Connect_Page $page Page whose handle_exchange() to invoke.
	 * @return array Decoded JSON response.
	 */
	private function capture_exchange_json( Connect_Page $page ): array {
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'throwing_die_handler' ) );

		ob_start();
		try {
			$page->handle_exchange();
		} catch ( \WPDieException $e ) {
			// Expected: wp_send_json_* echoes the body then wp_die()s.
			unset( $e );
		} finally {
			$out = (string) ob_get_clean();
			remove_filter( 'wp_die_ajax_handler', array( $this, 'throwing_die_handler' ) );
			remove_filter( 'wp_doing_ajax', '__return_true' );
		}

		return (array) json_decode( $out, true );
	}

	/**
	 * wp_die handler that throws instead of terminating, so a wp_send_json call
	 * can be exercised under test. Returned as the 'wp_die_ajax_handler' callable.
	 *
	 * @return callable
	 */
	public function throwing_die_handler(): callable {
		return static function ( $message = '' ): void {
			throw new \WPDieException( is_scalar( $message ) ? (string) $message : '' );
		};
	}

	/**
	 * End-to-end JOIN: the code handle_authorize() redirects must redeem at the
	 * real handle_exchange() endpoint and yield the live minted credential.
	 *
	 * Gap closed: the two real handlers were previously tested only in isolation —
	 * handle_authorize() asserted a code was emitted in its redirect, and
	 * handle_exchange() was fed a reflection-minted code. Nothing proved the
	 * authorize redirect's code is actually redeemable by the exchange handler, so
	 * a key/format drift between the two (or the transient->wp_options storage
	 * migration) could pass. This drives the FULL path: mint + redirect via the
	 * real authorize handler, extract the redirected code, POST that exact code to
	 * the real exchange handler, and assert it returns the agent's credential.
	 */
	public function test_authorize_to_exchange_round_trips_end_to_end() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$nonce = wp_create_nonce( Connect_Page::ACTION_AUTHORIZE );

		$_POST['action']      = Connect_Page::ACTION_AUTHORIZE;
		$_POST['callback']    = 'http://127.0.0.1:51791/cb';
		$_POST['state']       = 'join-state-token';
		$_POST['client']      = 'block-mcp';
		$_POST['_wpnonce']    = $nonce;
		$_REQUEST['_wpnonce'] = $nonce;

		$captured_redirect = null;
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$captured_redirect ) {
				$captured_redirect = $location;
				throw new \RuntimeException( 'redirect_captured' );
			}
		);

		$page = new Connect_Page();
		try {
			$page->handle_authorize();
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirect_captured', $e->getMessage() );
		}
		remove_all_filters( 'wp_redirect' );

		$this->assertNotNull( $captured_redirect, 'handle_authorize() must redirect' );

		// Extract the single-use code the REAL authorize handler redirected with.
		parse_str( (string) wp_parse_url( $captured_redirect, PHP_URL_QUERY ), $qs );
		$this->assertNotEmpty( $qs['code'], 'authorize must redirect a single-use code' );

		// Feed THAT exact code to the REAL exchange handler.
		$_POST['code'] = $qs['code'];
		try {
			$exchanged = $this->capture_exchange_json( $page );
		} finally {
			unset(
				$_POST['action'], $_POST['callback'], $_POST['state'], $_POST['client'],
				$_POST['_wpnonce'], $_REQUEST['_wpnonce'], $_POST['code']
			);
		}

		$this->assertTrue( $exchanged['success'], 'the authorize-issued code must redeem at the exchange endpoint' );
		$this->assertSame( untrailingslashit( home_url() ), $exchanged['data']['site'], 'exchange returns the site' );
		$this->assertSame( Agent_Provisioner::LOGIN, $exchanged['data']['user'], 'exchange returns the agent login' );
		$this->assertNotEmpty( $exchanged['data']['password'], 'exchange returns the live minted password' );

		// And it is genuinely single-use: replaying the same code now fails.
		$_POST['code'] = $qs['code'];
		try {
			$replay = $this->capture_exchange_json( $page );
		} finally {
			unset( $_POST['code'] );
		}
		$this->assertFalse( $replay['success'], 'the code must not redeem twice' );
	}

	/**
	 * The connector's REST exchange route redeems a valid code once (returning the
	 * credential envelope) and rejects a replay.
	 *
	 * This exercises the ACTUAL transport the connector uses: REST, not
	 * admin-post.php. admin-post.php is routinely 30x'd before the handler runs by
	 * canonical/SSL redirects, the Redirection plugin, and security plugins on real
	 * sites (it surfaced as a hard "fetch failed" in the field); REST routes escape
	 * those. The route is registered by init_rest_api() on rest_api_init.
	 */
	public function test_rest_exchange_route_redeems_once_then_rejects_replay() {
		do_action( 'rest_api_init' );

		$page  = new Connect_Page();
		$store = new \ReflectionMethod( Connect_Page::class, 'store_exchange_code' );
		$store->setAccessible( true );
		$code  = $store->invoke( $page, array( 'url' => 'https://example.com', 'user' => 'block-mcp', 'password' => 'rest-secret' ) );

		$make_request = static function ( $value ) {
			$req = new \WP_REST_Request( 'POST', '/gk-block-api/v1/connect/exchange' );
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( array( 'code' => $value ) ) );
			return $req;
		};

		$res  = rest_get_server()->dispatch( $make_request( $code ) );
		$data = $res->get_data();
		$this->assertSame( 200, $res->get_status(), 'a valid code must redeem at the REST route' );
		$this->assertTrue( $data['success'] );
		$this->assertSame( 'rest-secret', $data['data']['password'], 'the REST route returns the live minted password once' );
		$this->assertSame( 'block-mcp', $data['data']['user'] );
		$this->assertSame( 'https://example.com', $data['data']['site'] );

		$replay = rest_get_server()->dispatch( $make_request( $code ) );
		$this->assertSame( 400, $replay->get_status(), 'a replayed code must be rejected (single-use)' );
	}

	/**
	 * The "Invalid or expired code." error must route through the gk-block-mcp
	 * text domain so it is translatable — both on the nopriv JSON handler and
	 * the REST route.
	 *
	 * The message was a bare string literal; a non-English site would always
	 * show English. This installs a gettext override for the gk-block-mcp domain
	 * and asserts the translated text comes back from both code paths, proving
	 * the strings are now wrapped in __( …, 'gk-block-mcp' ).
	 */
	public function test_invalid_code_message_is_localized() {
		$translated = 'CÓDIGO INVÁLIDO';

		$filter = static function ( $translation, $text, $domain ) use ( $translated ) {
			if ( 'gk-block-mcp' === $domain && 'Invalid or expired code.' === $text ) {
				return $translated;
			}
			return $translation;
		};
		add_filter( 'gettext', $filter, 10, 3 );

		try {
			// Path 1: the nopriv JSON handler.
			$page          = new Connect_Page();
			$_POST['code'] = 'never-issued';
			$json          = $this->capture_exchange_json( $page );
			unset( $_POST['code'] );
			$this->assertFalse( $json['success'], 'an unknown code must be rejected' );
			$this->assertSame( $translated, $json['data']['message'], 'the JSON error message must be translatable' );

			// Path 2: the REST route.
			do_action( 'rest_api_init' );
			$req = new \WP_REST_Request( 'POST', '/gk-block-api/v1/connect/exchange' );
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( (string) wp_json_encode( array( 'code' => 'never-issued' ) ) );
			$res = rest_get_server()->dispatch( $req );
			$this->assertSame( 400, $res->get_status() );
			$this->assertSame( $translated, $res->as_error()->get_error_message(), 'the REST error message must be translatable' );
		} finally {
			remove_filter( 'gettext', $filter, 10 );
		}
	}

	/**
	 * The REST exchange route is reachable WITHOUT authentication — the single-use
	 * code is the only credential — and 400s an unknown code.
	 */
	public function test_rest_exchange_route_is_public_and_rejects_unknown_code() {
		do_action( 'rest_api_init' );
		wp_set_current_user( 0 );

		$req = new \WP_REST_Request( 'POST', '/gk-block-api/v1/connect/exchange' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( (string) wp_json_encode( array( 'code' => 'never-issued-code' ) ) );

		$res = rest_get_server()->dispatch( $req );
		$this->assertSame( 400, $res->get_status(), 'an unknown code must 400 (the route is reachable while logged out)' );
	}

	/**
	 * The generated .mcpb bundle embeds the plaintext Application Password, so it
	 * must not linger on disk. unlink_temp_bundle() — called from the streaming
	 * finally AND a shutdown function (the browser-abort case) — must delete the
	 * bundle, and a second call on the already-removed path must be a harmless
	 * no-op so both callers can fire for the same file. Empty / non-existent
	 * paths must also be safe no-ops.
	 */
	public function test_unlink_temp_bundle_removes_credential_file_and_double_unlink_is_safe() {
		$unlink = new \ReflectionMethod( Connect_Page::class, 'unlink_temp_bundle' );
		$unlink->setAccessible( true );

		$path = wp_tempnam( 'gk-block-mcp-mcpb-test' );
		file_put_contents( $path, 'PK fake-mcpb-with-embedded-credential' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertFileExists( $path, 'precondition: the bundle exists before cleanup' );

		$unlink->invoke( null, $path );
		$this->assertFileDoesNotExist( $path, 'the credential-bearing bundle must be deleted' );

		// Abort case: the finally and the shutdown function both fire for the same
		// path. A second unlink of the already-removed file must not error.
		$unlink->invoke( null, $path );
		$this->assertFileDoesNotExist( $path );

		// Empty and never-existed paths are harmless no-ops.
		$unlink->invoke( null, '' );
		$unlink->invoke( null, $path . '-never-existed' );
		$this->assertTrue( true, 'no-op cleanup paths must not throw' );
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
	 * When the gk/block-mcp/credential/seal-mode filter returns 'paste', the
	 * built bundle must carry an empty string for wordpress_app_password.default
	 * (so the installer does not pre-fill the field) and the return array must
	 * carry the plaintext password separately for the UI to display once.
	 *
	 * This mode lets security-conscious operators avoid embedding the plaintext
	 * credential in the downloadable file at the cost of requiring a manual copy.
	 */
	public function test_secret_at_rest_filter_paste_mode_omits_password_default() {
		add_filter(
			'gk/block-mcp/credential/seal-mode',
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
	 * When GK_BLOCK_MCP_FORCE_PASTE_SECRET is defined and true (no filter), the
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
		define( 'GK_BLOCK_MCP_FORCE_PASTE_SECRET', true );

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
	// render_section() — output contracts.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * render_section() in the 'ready' state must output per-client next-steps blocks
	 * for all six slugs and all six client-picker radio cards with stable slug values,
	 * with Claude Desktop checked by default.
	 *
	 * Radio values are the stable, translation-proof slugs defined by the
	 * CLIENT_* constants. Labels (e.g. "Claude Desktop app") are display-only
	 * and appear as card text — they must also be present but must NOT be used
	 * as the radio `value`.
	 *
	 * Per-client next-steps blocks are all in the DOM tagged with data-client.
	 * Exactly one block is visible by default (claude-desktop); the other five
	 * carry style="display:none;" and aria-hidden="true".
	 *
	 * The Claude Desktop block must contain the .mcpb download steps
	 * ("After you download", "claude.ai/download"). Non-desktop blocks must
	 * contain the Generate-setup-config / Approve / "No password to copy" copy
	 * and must NOT contain the .mcpb download steps.
	 *
	 * The six required slugs: CLIENT_CLAUDE_DESKTOP, CLIENT_CLAUDE_CODE,
	 * CLIENT_CURSOR, CLIENT_AI_PROMPT, CLIENT_OTHER.
	 * The "Let my AI set it up" card must carry the is-ai class.
	 *
	 * Wrapping markup uses <div class="gk-block-mcp-connect"> + <div class="gk-block-mcp-connect__card">
	 * so all CSS selectors are scoped.
	 */
	/**
	 * The picker JS must defer initialization until the DOM is ready.
	 *
	 * Regression: the inline <script> was emitted before the radio inputs in
	 * the document, so its querySelectorAll('input[name="client"]') returned an
	 * empty NodeList and the IIFE bailed at `if ( ! radios.length ) return;`.
	 * That silently killed ALL picker interactivity — the submit-button label,
	 * the "other" note, and the per-client next-steps toggle never updated on
	 * selection (only the CSS :has() selection ring kept working). The fix wraps
	 * the body in init() behind a DOMContentLoaded gate so element/script order
	 * can't break it. This pins that the deferred-init guard is present.
	 */
	public function test_render_section_picker_script_defers_init_until_dom_ready() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'function init()', $html, 'Picker JS must define a deferred init().' );
		$this->assertStringContainsString( 'DOMContentLoaded', $html, 'Picker JS must defer init until the DOM is ready so it runs after the radios exist.' );
	}

	/**
	 * render_section() in the ready (HTTPS-capable) state renders the full client
	 * picker — all six radio-card clients plus their per-client next-steps blocks.
	 *
	 * The Connect tab's whole UX hinges on this markup, so this pins the contract
	 * that defines a working picker:
	 *  - The scoped wrapper/card containers exist (so the CSS applies).
	 *  - The picker is radio cards keyed by name="client", NOT a <select>.
	 *  - Each of the six clients exposes a STABLE SLUG as its radio value
	 *    (claude-desktop / claude-code / cursor / ai-prompt /
	 *    other) — never a translated label, which would break handle_connect()'s
	 *    slug matching once the admin UI is localized — alongside its human label.
	 *  - Claude Desktop is the default-checked client and its next-steps block is
	 *    the only one visible; the other five carry display:none + aria-hidden so
	 *    exactly one set of instructions shows before any JS runs.
	 *  - The default block carries the .mcpb download steps.
	 *
	 * Failure mode: a regression that swaps slugs for labels, drops a client card,
	 * reveals more than one next-steps block, or degrades to a <select> would all
	 * surface here.
	 */
	public function test_render_section_shows_next_steps_and_all_six_picker_cards_ready_state() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		// Wrapper class and card container must be present for scoped CSS.
		$this->assertStringContainsString( 'class="gk-block-mcp-connect"', $html, 'Outer wrapper must carry class gk-block-mcp-connect' );
		$this->assertStringContainsString( 'gk-block-mcp-connect__card', $html, 'Card container must be present' );

		// Radio inputs must be present with name="client" (not a <select>).
		$this->assertStringContainsString( 'type="radio"', $html, 'Radio inputs must be present' );
		$this->assertStringContainsString( 'name="client"', $html, 'Radio inputs must carry name="client"' );

		// All five radio values must be stable slugs — NOT translated labels.
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"', $html, 'claude-desktop slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CLAUDE_CODE . '"', $html, 'claude-code slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_CURSOR . '"', $html, 'cursor slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_AI_PROMPT . '"', $html, 'ai-prompt slug must be the radio value' );
		$this->assertStringContainsString( 'value="' . Connect_Page::CLIENT_OTHER . '"', $html, 'other slug must be the radio value' );

		// Human-facing card labels (display-only) must also appear as card text.
		$this->assertStringContainsString( 'Claude Desktop app', $html, 'Claude Desktop card label must be present as card text' );
		$this->assertStringContainsString( 'Claude Code', $html, 'Claude Code card label must be present as card text' );
		$this->assertStringContainsString( 'Cursor', $html, 'Cursor card label must be present as card text' );
		$this->assertStringContainsString( 'Let my AI set it up', $html, '"Let my AI" card label must be present as card text' );
		$this->assertStringContainsString( 'Configure it myself', $html, '"Configure it myself" card label must be present as card text' );

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

		// ── Per-client next-steps blocks ──────────────────────────────────────

		// All five data-client blocks must be in the DOM.
		$slugs = array(
			Connect_Page::CLIENT_CLAUDE_DESKTOP,
			Connect_Page::CLIENT_CLAUDE_CODE,
			Connect_Page::CLIENT_CURSOR,
			Connect_Page::CLIENT_AI_PROMPT,
			Connect_Page::CLIENT_OTHER,
		);
		foreach ( $slugs as $slug ) {
			$this->assertStringContainsString(
				'data-client="' . $slug . '"',
				$html,
				"Per-client next-steps block must exist for slug: {$slug}"
			);
		}

		// Exactly one block — claude-desktop — must be visible by default;
		// the other four must carry display:none and aria-hidden="true".
		$this->assertStringNotContainsString(
			'data-client="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '" style="display:none;"',
			$html,
			'The claude-desktop next-steps block must be visible by default (no display:none)'
		);
		$this->assertStringNotContainsString(
			'data-client="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"  aria-hidden="true"',
			$html,
			'The claude-desktop next-steps block must not carry aria-hidden="true" by default'
		);

		$hidden_slugs = array(
			Connect_Page::CLIENT_CLAUDE_CODE,
			Connect_Page::CLIENT_CURSOR,
			Connect_Page::CLIENT_AI_PROMPT,
			Connect_Page::CLIENT_OTHER,
		);
		foreach ( $hidden_slugs as $slug ) {
			$this->assertMatchesRegularExpression(
				'/data-client="' . preg_quote( $slug, '/' ) . '"[^>]*style="display:none;"/',
				$html,
				"Non-default next-steps block must carry display:none for slug: {$slug}"
			);
			$this->assertMatchesRegularExpression(
				'/data-client="' . preg_quote( $slug, '/' ) . '"[^>]*aria-hidden="true"/',
				$html,
				"Non-default next-steps block must carry aria-hidden=\"true\" for slug: {$slug}"
			);
		}

		// The claude-desktop block must contain the .mcpb download steps.
		$this->assertStringContainsString( 'After you download', $html, 'Claude Desktop next-steps block must contain "After you download" heading' );
		$this->assertStringContainsString( 'claude.ai/download', $html, 'Claude Desktop next-steps block must contain the claude.ai/download link' );
		$this->assertStringContainsString( 'mcpb', $html, 'Claude Desktop next-steps block must reference the .mcpb file' );

		// The claude-code block must show the command-setup artifact + Approve step
		// and reassure no password is needed — and must NOT carry the .mcpb steps.
		$this->assertStringContainsString(
			'Claude Code setup',
			$html,
			'claude-code next-steps block must show the "Claude Code setup" artifact card'
		);
		$this->assertStringContainsString(
			'Approve',
			$html,
			'CLI-client next-steps block must mention the Approve step'
		);
		$this->assertStringContainsString(
			'never on this page',
			$html,
			'the command-line flow must reassure the password never appears on the page'
		);
	}

	/**
	 * render_section() in the 'connected' state must output the Active
	 * connections list (the connected indicator) with a Disconnect control for
	 * each active connection, in addition to the after-download guidance and
	 * client picker.
	 *
	 * This pins the P0 deliverable for the post-connection state: both the
	 * connections list and the revoke affordance must be rendered when at least
	 * one credential is live.
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

		$this->assertStringContainsString( 'Active connections', $html, 'the active connections list must be present in the connected state' );
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
	 * All client panels now render in the DOM (client-side switching), so the
	 * canonical-key contract is observable via pre-selection: ?setup=<slug>
	 * pre-selects that client's radio, while an old label value is not recognised
	 * and falls back to the default (Claude Desktop).
	 */
	public function test_render_section_setup_slug_renders_artifact_label_does_not() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( 'gk_block_api_agent_user_id' );

		$checked_re = static function ( string $slug ) {
			$q = preg_quote( $slug, '/' );
			return '/value="' . $q . '"[^>]*checked|checked[^>]*value="' . $q . '"/';
		};

		// Slug is canonical: ?setup=<slug> pre-selects that client.
		$_GET['setup'] = Connect_Page::CLIENT_CLAUDE_CODE; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		( new Connect_Page() )->render_section();
		$html_slug = ob_get_clean();

		unset( $_GET['setup'] );

		$this->assertStringContainsString( 'npx -y @gravitykit/block-mcp connect', $html_slug, 'the npx connect artifact must render' );
		$this->assertStringContainsString( '--client ' . Connect_Page::CLIENT_CLAUDE_CODE, $html_slug, 'the artifact must carry the --client slug flag' );
		$this->assertMatchesRegularExpression( $checked_re( Connect_Page::CLIENT_CLAUDE_CODE ), $html_slug, '?setup=<slug> must pre-select that client' );

		// An old label value is NOT canonical: unrecognised, so the picker falls
		// back to the default (Claude Desktop) and does not pre-select claude-code.
		$_GET['setup'] = 'Claude Code'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		( new Connect_Page() )->render_section();
		$html_label = ob_get_clean();

		unset( $_GET['setup'] );

		$this->assertDoesNotMatchRegularExpression( $checked_re( Connect_Page::CLIENT_CLAUDE_CODE ), $html_label, 'an old label value must NOT pre-select claude-code' );
		$this->assertMatchesRegularExpression( $checked_re( Connect_Page::CLIENT_CLAUDE_DESKTOP ), $html_label, 'an unrecognised ?setup must fall back to the default Claude Desktop' );
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
		$this->assertStringNotContainsString( 'Copy password', $html, 'No "Copy password" button must be present' );

		// The command artifact itself must be secret-free. The env-var NAME may
		// appear elsewhere only as the "Configure it myself" manual-config
		// placeholder (a <pre>, not a textarea), so scope the secret check to the
		// artifact textareas — the thing the user actually copies.
		preg_match_all( '/<textarea[^>]*gk-block-mcp-connect__artifact-textarea[^>]*>(.*?)<\/textarea>/s', $html, $matches );
		$this->assertNotEmpty( $matches[1], 'at least one artifact textarea must render' );
		foreach ( $matches[1] as $artifact_body ) {
			$this->assertStringNotContainsString( 'WORDPRESS_APP_PASSWORD', $artifact_body, 'the command artifact must be secret-free' );
		}
	}

	/**
	 * render_section() with ?gk_authorize set must render the Approve screen,
	 * not the normal connect UI.
	 *
	 * The Approve screen must include the plain-language heading, an Approve
	 * submit button, and a Cancel link. The normal client-picker form must NOT
	 * appear.
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

		$this->assertStringContainsString( 'Allow your AI app to connect', $html, 'Plain-language authorize heading must be present' );
		$this->assertStringContainsString( 'Approve', $html, 'Approve button must be present' );
		$this->assertStringContainsString( 'Cancel', $html, 'Cancel link must be present' );
		$this->assertStringContainsString( Connect_Page::ACTION_AUTHORIZE, $html, 'Form action must reference the authorize action' );

		// The normal client picker must NOT appear in authorize mode.
		$this->assertStringNotContainsString( 'Connect an AI Assistant', $html, 'Normal connect UI heading must NOT appear in authorize mode' );
		$this->assertStringNotContainsString( 'value="' . Connect_Page::CLIENT_CLAUDE_DESKTOP . '"', $html, 'Client picker radios must NOT appear in authorize mode' );
	}

	/**
	 * The Approve screen offers the higher-risk "Your own account" option with its
	 * acknowledgment gate, and the removed agent_as_me option must be gone.
	 */
	public function test_authorize_screen_offers_self_with_acknowledgment_gate() {
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

		$this->assertStringContainsString( 'value="self"', $html, 'the self identity option must be offered' );
		$this->assertStringContainsString( 'name="self_ack"', $html, 'the self acknowledgment checkbox must be present' );
		$this->assertStringNotContainsString( 'value="agent_as_me"', $html, 'the removed agent_as_me option must NOT appear' );
	}

	/**
	 * The gk/block-mcp/identity/allow-self filter (false) removes the self option
	 * and its acknowledgment gate from the Approve screen entirely, while the rest
	 * of the screen still renders.
	 */
	public function test_authorize_screen_omits_self_when_filter_disables_it() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['gk_authorize'] = '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET['callback']     = 'http://127.0.0.1:9999/cb';
		$_GET['state']        = 'tok123';
		$_GET['client']       = 'block-mcp';

		add_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );
		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();
		remove_filter( 'gk/block-mcp/identity/allow-self', '__return_false' );

		unset( $_GET['gk_authorize'], $_GET['callback'], $_GET['state'], $_GET['client'] );

		$this->assertStringContainsString( 'Allow your AI app to connect', $html, 'the Approve screen must still render' );
		$this->assertStringNotContainsString( 'value="self"', $html, 'the self option must be hidden when the filter forbids it' );
		$this->assertStringNotContainsString( 'name="self_ack"', $html, 'the acknowledgment gate must be hidden when self is forbidden' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// [WP-F2] .mcpb temp-bundle cleanup survives a client-aborted download.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * The download handler must schedule an abort-safe cleanup of the bundle.
	 *
	 * In prefill mode the streamed .mcpb embeds the plaintext Application
	 * Password. Cleanup was a streaming `finally { wp_delete_file() }`, but a
	 * browser abort mid-readfile() (with ignore_user_abort false) terminates
	 * the script before the finally runs, stranding the credential-bearing
	 * bundle in the temp dir. The fix registers a shutdown function — which
	 * fires on user-abort termination — to unlink the bundle. This pins that
	 * the streaming path wires register_shutdown_function so the abort case is
	 * covered; without it the temp file survives the abort.
	 */
	public function test_download_handler_registers_shutdown_cleanup_for_bundle() {
		$ref  = new \ReflectionMethod( Connect_Page::class, 'handle_connect' );
		$lines = file( $ref->getFileName() );
		$body  = implode(
			'',
			array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 )
		);

		$this->assertStringContainsString(
			'register_shutdown_function',
			$body,
			'The .mcpb download must register a shutdown cleanup so a client abort cannot strand the credential-bearing temp file.'
		);
	}

	/**
	 * unlink_temp_bundle() must delete an existing credential-bearing bundle.
	 *
	 * This is the callback registered both in the streaming finally and as the
	 * shutdown function; it must remove the temp file so the plaintext
	 * credential does not persist on disk.
	 */
	public function test_unlink_temp_bundle_deletes_existing_file() {
		$tmp = wp_tempnam( 'gk-mcpb-cleanup' );
		file_put_contents( $tmp, 'credential-bearing-bundle' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$this->assertFileExists( $tmp );

		$method = new \ReflectionMethod( Connect_Page::class, 'unlink_temp_bundle' );
		$method->setAccessible( true );
		$method->invoke( null, $tmp );

		$this->assertFileDoesNotExist( $tmp );
	}

	/**
	 * unlink_temp_bundle() must be a harmless no-op for an already-gone file.
	 *
	 * The streaming finally and the shutdown function can both fire for the
	 * same bundle (success path), so a double unlink — or a path the OS already
	 * swept — must not raise.
	 */
	public function test_unlink_temp_bundle_no_ops_on_missing_file() {
		$method = new \ReflectionMethod( Connect_Page::class, 'unlink_temp_bundle' );
		$method->setAccessible( true );
		$method->invoke( null, '/nonexistent/gk-block-mcp-missing.mcpb' );

		$this->assertTrue( true, 'unlink_temp_bundle must not raise on a missing path.' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Beginner-usability fixes (Sarah persona review).
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * [F3] In ?setup=<client> mode, only the selected client's next-steps panel
	 * shows — never the default Claude Desktop panel alongside the artifact.
	 *
	 * Regression: the post-submit reload to ?setup=claude-code rendered the
	 * Claude Code command artifact AND re-rendered the picker with the radio
	 * reset to claude-desktop, so the claude-desktop ".mcpb / After you
	 * download" panel appeared below the Claude Code terminal command — two
	 * contradictory instruction sets on one screen. The fix preselects the setup
	 * client so the radio, the is-selected card, and the default-visible panel
	 * all match the artifact.
	 */
	public function test_render_section_setup_mode_preselects_client_and_shows_single_panel() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		delete_option( 'gk_block_api_agent_user_id' );

		$_GET['setup'] = Connect_Page::CLIENT_CLAUDE_CODE;

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		unset( $_GET['setup'] );

		// The selected client's radio is checked; Claude Desktop is not.
		$this->assertSame( 1, preg_match( '/value="claude-code"\s*checked=/', $html ), 'claude-code radio must be checked in setup mode' );
		$this->assertSame( 0, preg_match( '/value="claude-desktop"\s*checked=/', $html ), 'claude-desktop radio must NOT be checked in setup mode' );

		// The Claude Desktop "After you download" panel must be hidden; the
		// claude-code panel must be the visible one (one selection => one panel).
		$this->assertSame( 1, preg_match( '/data-client="claude-desktop"[^>]*style="display:none;"/', $html ), 'claude-desktop next-steps must be hidden in setup=claude-code mode' );
		$this->assertSame( 0, preg_match( '/data-client="claude-code"[^>]*style="display:none;"/', $html ), 'claude-code next-steps must be visible in setup=claude-code mode' );
	}

	/**
	 * [F6][F7] The 'Something else / I'm not sure' path gives real guidance, not a
	 * dead end, and the old 'coming soon' note is no longer duplicated.
	 *
	 * Regression: the uncertain-beginner card showed only 'Browser-based setup is
	 * coming soon...' — and that sentence rendered twice (an inline note in the
	 * fieldset plus a boxed callout), reading like a glitch. The fix replaces the
	 * dead end with a decision helper that routes the user to the right app and
	 * removes the duplicate inline note.
	 */
	public function test_render_section_other_path_gives_guidance_not_dead_end() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		// F7: the duplicate inline note element is gone and the dead-end copy is
		// no longer present anywhere.
		$this->assertStringNotContainsString( 'gk-block-mcp-other-note', $html, 'the duplicate inline other-note element must be removed' );
		$this->assertStringNotContainsString( 'coming soon', $html, 'the coming-soon dead-end copy must be replaced with guidance' );

		// F6: real guidance that routes the uncertain user to a working path.
		$this->assertStringContainsString( 'Not sure what a terminal is', $html, 'the other path must offer a decision helper' );
	}

	/**
	 * [F4] The Claude Desktop step explains what a .mcpb file is, so a beginner
	 * who's never seen the extension isn't stranded.
	 */
	public function test_render_section_glosses_mcpb_file() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Claude Desktop setup file', $html, 'the .mcpb step must explain what the file is in plain words' );
	}

	/**
	 * [F5] The command-line (Claude Code / Cursor) path gives a
	 * non-developer a plain-language escape hatch instead of a bare npx command.
	 */
	public function test_render_section_cli_path_offers_escape_hatch() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Not sure what a terminal', $html, 'the command-line path must offer a non-developer escape hatch' );
	}

	/**
	 * [F8] A persistent 'View the setup guide' help link appears on the Connect
	 * flow so a stuck beginner has somewhere to go besides email or Google.
	 */
	public function test_render_section_has_persistent_help_link() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		delete_option( 'gk_block_api_agent_user_id' );

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'View the setup guide', $html, 'a persistent help link must be present on the Connect flow' );
	}

	/**
	 * [F8] The browser-Approve screen also carries the help link, since a user
	 * can get stuck there too.
	 */
	public function test_authorize_screen_has_help_link() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['gk_authorize'] = '1';
		$_GET['callback']     = 'http://127.0.0.1:51999/cb';
		$_GET['state']        = 'demo';
		$_GET['client']       = 'claude-code';

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		unset( $_GET['gk_authorize'], $_GET['callback'], $_GET['state'], $_GET['client'] );

		$this->assertStringContainsString( 'View the setup guide', $html, 'the authorize screen must also carry the help link' );
	}

	/**
	 * [F11] The Approve screen explains, in plain language, what the user is
	 * allowing — not 'Block MCP agent / credential / local application' jargon.
	 */
	public function test_authorize_screen_uses_plain_language() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$_GET['gk_authorize'] = '1';
		$_GET['callback']     = 'http://127.0.0.1:51999/cb';
		$_GET['state']        = 'demo';
		$_GET['client']       = 'claude-code';

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		unset( $_GET['gk_authorize'], $_GET['callback'], $_GET['state'], $_GET['client'] );

		// Plain language present.
		$this->assertStringContainsString( 'permission to create and edit content', $html, 'must say what permission is granted in plain words' );
		$this->assertStringContainsString( 'remove this access anytime', $html, 'must reassure the access is revocable' );

		// Old jargon phrasing gone.
		$this->assertStringNotContainsString( 'as the Block MCP agent', $html, 'the "Block MCP agent" jargon phrasing must be replaced' );
		$this->assertStringNotContainsString( 'sends a credential to the local app', $html, 'the "credential / local app" jargon phrasing must be replaced' );
	}

	/**
	 * Account-creation wording is conditional on the agent already existing.
	 *
	 * The "Block MCP" account persists once provisioned (it survives revoking
	 * every connection), so "connecting creates a new account" and "Download
	 * installer & create MCP user" are only accurate on the very first connect.
	 * After the account exists the onboarding notice switches to present tense
	 * and the Desktop button drops the "create MCP user" clause. This pins both
	 * states so the copy can't regress to always-claims-creation.
	 */
	public function test_connect_section_creation_copy_is_conditional_on_agent_existing() {
		// Before the agent exists: creation language is shown.
		ob_start();
		( new Connect_Page() )->render_section();
		$before = ob_get_clean();
		$this->assertStringContainsString( 'Connecting creates a new user account', $before );
		$this->assertStringContainsString( 'create MCP user', $before, 'the Desktop button names account creation on first run' );

		// Provision the agent — no connection needed; the account itself persists.
		( new Agent_Provisioner() )->ensure();
		$this->assertNotFalse( get_user_by( 'login', Agent_Provisioner::LOGIN ), 'agent must exist for the second render' );

		ob_start();
		( new Connect_Page() )->render_section();
		$after = ob_get_clean();
		$this->assertStringContainsString( 'The AI uses a dedicated', $after, 'present-tense copy once the account exists' );
		$this->assertStringNotContainsString( 'Connecting creates a new user account', $after, 'must not claim creation once the account exists' );
		$this->assertStringNotContainsString( 'create MCP user', $after, 'the Desktop button drops the creation clause once the account exists' );
	}

	/**
	 * The Active connections list has a dedicated "Account" column, separate from
	 * "Approved by".
	 *
	 * For an agent-hosted connection the Account column names the dedicated
	 * "Block MCP" account as a "Limited account"; the approver's name lives only in
	 * the "Approved by" column. The old conflated "Shown as author" subtext and the
	 * byline "Posts as <name>" line were removed with the agent_as_me identity.
	 */
	public function test_active_connections_account_column_names_the_connection_type() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator', 'display_name' => 'Screenshot Admin' ) );
		wp_set_current_user( $admin_id );

		$agent_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		update_option( 'gk_block_api_agent_user_id', $agent_id );

		$created = \WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Block MCP — Claude Code' )
		);
		\GravityKit\BlockMCP\Connections::record_meta(
			$created[1]['uuid'],
			array( 'user_id' => $agent_id, 'created_by' => $admin_id )
		);

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		// Account column header + the agent-account identity label.
		$this->assertStringContainsString( 'Account', $html, 'the Account column header must be present' );
		$this->assertStringContainsString( 'Limited account', $html, 'the Account column must label the agent connection as a limited account' );
		// Approved-by audit shows the approver name.
		$this->assertStringContainsString( 'Screenshot Admin', $html );
		// The removed byline subtexts must be gone.
		$this->assertStringNotContainsString( 'Shown as author', $html );
		$this->assertStringNotContainsString( 'Posts as', $html, 'the removed byline subtext must not appear' );
	}

	/**
	 * The "Need help?" link must point at the published doc's canonical URL.
	 *
	 * BetterDocs permalinks include the category segment, so the original
	 * category-less URL (/docs/connect-ai-assistant/) 404s on the live site —
	 * the flow would ship a dead help link. Pins the canonical
	 * /docs/block-mcp/connect-ai-assistant/ location.
	 */
	public function test_help_url_points_at_canonical_doc_location() {
		$help_url = new \ReflectionMethod( Connect_Page::class, 'help_url' );
		$help_url->setAccessible( true );

		$url = $help_url->invoke( new Connect_Page() );

		$this->assertSame( 'https://www.gravitykit.com/docs/block-mcp/connect-ai-assistant/', $url );
	}

}
