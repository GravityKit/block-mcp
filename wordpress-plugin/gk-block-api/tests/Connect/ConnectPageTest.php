<?php
/**
 * Connect_Page: provision-mint-build, connection-state, artifact, and render contracts.
 *
 * The Connect_Page orchestrates the full connect flow: provisioning the agent
 * service account, minting an Application Password, and either building a
 * pre-filled .mcpb bundle (Claude Desktop) or producing a ready-to-paste
 * setup artifact (Claude Code, Cursor, ChatGPT Desktop, ai-prompt).
 *
 * These tests pin the testable seams — provision_credentials(),
 * prepare_installer(), setup_artifact(), connection_state(), and
 * render_section() — without exercising the HTTP-streaming or admin-menu
 * registration paths, which have no testable surface in the unit harness.
 *
 * Contracts pinned here:
 *
 *  - provision_credentials() provisions the agent user, mints exactly one
 *    Application Password, and returns url/user/password/uuid.
 *  - provision_credentials() returns WP_Error when a non-agent user owns
 *    the block-mcp login (propagated from Agent_Provisioner::ensure()).
 *  - prepare_installer() calls provision_credentials() and builds the .mcpb
 *    from the returned creds; the .mcpb path is unchanged.
 *  - setup_artifact() returns correct bash/json/text bodies for each client.
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
	// setup_artifact() — per-client bodies.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * setup_artifact() for 'Claude Code' must return a bash snippet containing
	 * `claude mcp add`, the site URL, and WORDPRESS_APP_PASSWORD with the
	 * placeholder — never the real password.
	 *
	 * The real secret must not appear in the artifact body because it would land
	 * in shell history when the user pastes the command into a terminal. It is
	 * surfaced separately by render_artifact_card() in a dedicated password field.
	 */
	public function test_setup_artifact_claude_code_contains_placeholder_not_password() {
		$page  = new Connect_Page();
		$creds = array(
			'url'      => 'https://example.com',
			'user'     => 'block-mcp',
			'password' => 'testpass123',
			'uuid'     => 'test-uuid',
		);

		$artifact = $page->setup_artifact( 'Claude Code', $creds );

		$this->assertSame( 'bash', $artifact['language'] );
		// The body is raw (not HTML-escaped); esc_textarea() is applied at render time.
		$this->assertStringContainsString( 'claude mcp add', $artifact['body'], 'body must contain claude mcp add command' );
		$this->assertStringContainsString( 'https://example.com', $artifact['body'], 'body must contain the site URL' );
		$this->assertStringContainsString( 'WORDPRESS_APP_PASSWORD', $artifact['body'], 'body must contain WORDPRESS_APP_PASSWORD' );
		$this->assertStringContainsString( '@gravitykit/block-mcp', $artifact['body'], 'body must reference the npm package' );
		$this->assertStringContainsString( Connect_Page::PW_PLACEHOLDER, $artifact['body'], 'body must contain the placeholder' );
		$this->assertStringNotContainsString( 'testpass123', $artifact['body'], 'body must NOT contain the real password' );
	}

	/**
	 * setup_artifact() for 'Cursor' must return valid JSON containing mcpServers
	 * with a block-mcp entry carrying the site URL and user, but using the
	 * placeholder for WORDPRESS_APP_PASSWORD — never the real password.
	 *
	 * Embedding the real secret in the JSON snippet would expose it if the user
	 * pastes the snippet into an AI chat or commits the config file to source control.
	 */
	public function test_setup_artifact_cursor_is_valid_json_with_placeholder_not_password() {
		$page  = new Connect_Page();
		$creds = array(
			'url'      => 'https://example.com',
			'user'     => 'block-mcp',
			'password' => 'cursorpass',
			'uuid'     => 'test-uuid',
		);

		$artifact = $page->setup_artifact( 'Cursor', $creds );

		$this->assertSame( 'json', $artifact['language'] );

		// The body is raw JSON (not HTML-escaped); decode directly.
		$decoded = json_decode( $artifact['body'], true );
		$this->assertNotNull( $decoded, 'body must be valid JSON' );
		$this->assertArrayHasKey( 'mcpServers', $decoded );
		$this->assertArrayHasKey( 'block-mcp', $decoded['mcpServers'] );

		$server = $decoded['mcpServers']['block-mcp'];
		$this->assertSame( 'https://example.com', $server['env']['WORDPRESS_URL'] );
		$this->assertSame( Connect_Page::PW_PLACEHOLDER, $server['env']['WORDPRESS_APP_PASSWORD'], 'WORDPRESS_APP_PASSWORD must be the placeholder, not the real secret' );
		$this->assertStringNotContainsString( 'cursorpass', $artifact['body'], 'body must NOT contain the real password' );
	}

	/**
	 * setup_artifact() for 'ChatGPT Desktop' must return valid JSON containing
	 * mcpServers with a block-mcp entry using the placeholder for
	 * WORDPRESS_APP_PASSWORD — same contract as Cursor.
	 *
	 * The snippet lands in a config file the user may share or version-control,
	 * so the real secret must never appear there.
	 */
	public function test_setup_artifact_chatgpt_desktop_is_valid_json_with_placeholder_not_password() {
		$page  = new Connect_Page();
		$creds = array(
			'url'      => 'https://example.com',
			'user'     => 'block-mcp',
			'password' => 'chatgptpass',
			'uuid'     => 'test-uuid',
		);

		$artifact = $page->setup_artifact( 'ChatGPT Desktop', $creds );

		$this->assertSame( 'json', $artifact['language'] );

		// The body is raw JSON (not HTML-escaped); decode directly.
		$decoded = json_decode( $artifact['body'], true );
		$this->assertNotNull( $decoded, 'body must be valid JSON' );
		$this->assertArrayHasKey( 'mcpServers', $decoded );
		$this->assertArrayHasKey( 'block-mcp', $decoded['mcpServers'] );

		$server = $decoded['mcpServers']['block-mcp'];
		$this->assertSame( 'https://example.com', $server['env']['WORDPRESS_URL'] );
		$this->assertSame( Connect_Page::PW_PLACEHOLDER, $server['env']['WORDPRESS_APP_PASSWORD'], 'WORDPRESS_APP_PASSWORD must be the placeholder, not the real secret' );
		$this->assertStringNotContainsString( 'chatgptpass', $artifact['body'], 'body must NOT contain the real password' );
	}

	/**
	 * setup_artifact() for 'ai-prompt' must return a plain-text body containing
	 * the site URL, WORDPRESS_APP_PASSWORD, and the "block-mcp" server name with
	 * the placeholder — never the real password.
	 *
	 * The prompt is pasted into an AI chat transcript. Including the real secret
	 * there would expose it to the AI provider's servers and the chat history.
	 */
	public function test_setup_artifact_ai_prompt_contains_placeholder_not_password() {
		$page  = new Connect_Page();
		$creds = array(
			'url'      => 'https://example.com',
			'user'     => 'block-mcp',
			'password' => 'promptpass',
			'uuid'     => 'test-uuid',
		);

		$artifact = $page->setup_artifact( 'ai-prompt', $creds );

		// The body is raw (not HTML-escaped); esc_textarea() is applied at render time.
		$this->assertSame( 'text', $artifact['language'] );
		$this->assertStringContainsString( 'https://example.com', $artifact['body'], 'body must contain the site URL' );
		$this->assertStringContainsString( 'WORDPRESS_APP_PASSWORD', $artifact['body'], 'body must contain WORDPRESS_APP_PASSWORD' );
		$this->assertStringContainsString( 'block-mcp', $artifact['body'], 'body must reference the block-mcp server name' );
		$this->assertStringContainsString( Connect_Page::PW_PLACEHOLDER, $artifact['body'], 'body must contain the placeholder' );
		$this->assertStringNotContainsString( 'promptpass', $artifact['body'], 'body must NOT contain the real password' );
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
	 * guidance block and all six client-picker radio cards with correct values,
	 * with Claude Desktop checked by default.
	 *
	 * The six required cards are: Claude Desktop, Claude Code, Cursor,
	 * ChatGPT Desktop, ai-prompt, and other. The "Let my AI set it up" card
	 * must carry the is-ai class so it is visually prominent. The Claude Desktop
	 * radio must be checked by default.
	 *
	 * The modern block-editor restyling wraps all output in <div class="gk-connect">
	 * and nests the content inside <div class="gk-connect__card"> so all CSS
	 * selectors are scoped and the card container is present.
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

		// All six client values must be present.
		$this->assertStringContainsString( 'value="Claude Desktop"', $html, 'Claude Desktop radio value must be present' );
		$this->assertStringContainsString( 'value="Claude Code"', $html, 'Claude Code radio value must be present' );
		$this->assertStringContainsString( 'value="Cursor"', $html, 'Cursor radio value must be present' );
		$this->assertStringContainsString( 'value="ChatGPT Desktop"', $html, 'ChatGPT Desktop radio value must be present' );
		$this->assertStringContainsString( 'value="ai-prompt"', $html, '"ai-prompt" radio value must be present' );
		$this->assertStringContainsString( 'value="other"', $html, '"other" radio value must be present' );

		// Card labels must be present.
		$this->assertStringContainsString( 'Claude Desktop app', $html, 'Claude Desktop card label must be present' );
		$this->assertStringContainsString( 'Claude Code', $html, 'Claude Code card label must be present' );
		$this->assertStringContainsString( 'Cursor', $html, 'Cursor card label must be present' );
		$this->assertStringContainsString( 'ChatGPT Desktop', $html, 'ChatGPT Desktop card label must be present' );
		$this->assertStringContainsString( 'Let my AI set it up', $html, '"Let my AI" card label must be present' );
		$this->assertStringContainsString( 'Something else', $html, '"Something else" card label must be present' );

		// The "Let my AI" card must carry the is-ai accent class.
		$this->assertStringContainsString( 'is-ai', $html, '"Let my AI" card must carry is-ai class' );

		// The Claude Desktop radio must be checked by default.
		$this->assertMatchesRegularExpression(
			'/value="Claude Desktop"[^>]*checked|checked[^>]*value="Claude Desktop"/',
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
	 * render_section() after a non-.mcpb connect must surface the real password
	 * ONLY in the dedicated password field, never embedded in the artifact textarea.
	 *
	 * The artifact textarea uses PW_PLACEHOLDER so copying it into a terminal or
	 * AI chat does not leak the secret into shell history or a chat transcript.
	 * The actual one-time password appears in a separate readonly input so the
	 * user substitutes it as a deliberate step.
	 *
	 * This test simulates the post-redirect render by writing the transient
	 * directly, then asserting:
	 *  - the artifact textarea contains the placeholder, not the real password.
	 *  - the real password appears in the separate password input (value="…").
	 *  - the "Copy password" button is present.
	 *  - the "shown once" notice text is present.
	 */
	public function test_render_section_artifact_card_shows_password_separately_not_in_artifact() {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_option( 'gk_block_api_agent_user_id' );

		// Simulate the transient written by handle_connect() after provisioning.
		$fake_password = 'S3cr3tPassw0rd!';
		$transient_key = Connect_Page::PASTE_TRANSIENT_PREFIX . $admin_id;
		set_transient(
			$transient_key,
			array(
				'client' => 'Claude Code',
				'creds'  => array(
					'url'      => 'https://example.com',
					'user'     => 'block-mcp',
					'password' => $fake_password,
					'uuid'     => 'fake-uuid',
				),
			),
			5 * MINUTE_IN_SECONDS
		);

		ob_start();
		( new Connect_Page() )->render_section();
		$html = ob_get_clean();

		// The body is escaped via esc_textarea() at render time, so '<' becomes '&lt;'.
		$this->assertStringContainsString(
			esc_textarea( Connect_Page::PW_PLACEHOLDER ),
			$html,
			'Artifact textarea must contain PW_PLACEHOLDER (HTML-encoded by esc_textarea)'
		);

		// The real password must appear in the dedicated password input field.
		$this->assertStringContainsString(
			'value="' . esc_attr( $fake_password ) . '"',
			$html,
			'Real password must appear in the dedicated password input'
		);

		// The "Copy password" button must be present.
		$this->assertStringContainsString( 'Copy password', $html, '"Copy password" button must be present' );

		// The "shown once" notice must be present.
		$this->assertStringContainsString( 'shown once', $html, '"Shown once" notice must be present' );

		// The real password must NOT appear inside the artifact textarea value.
		// We detect this by checking that the password does not appear between the
		// opening <textarea … > tag and the closing </textarea> tag.
		$textarea_start = strpos( $html, 'gk-connect__artifact-textarea' );
		$textarea_end   = strpos( $html, '</textarea>', $textarea_start );
		$textarea_body  = substr( $html, $textarea_start, $textarea_end - $textarea_start );
		$this->assertStringNotContainsString(
			$fake_password,
			$textarea_body,
			'Real password must NOT appear inside the artifact textarea'
		);
	}
}
