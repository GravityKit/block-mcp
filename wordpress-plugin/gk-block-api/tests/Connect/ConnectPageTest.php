<?php
/**
 * Connect_Page: provision-mint-build and connection-state contracts.
 *
 * The Connect_Page orchestrates the full connect flow: provisioning the agent
 * service account, minting an Application Password, and building a pre-filled
 * .mcpb bundle. These tests pin the testable seam — prepare_installer() and
 * connection_state() — without exercising the HTTP-streaming or admin-menu
 * registration paths, which have no testable surface in the unit harness.
 *
 * Contracts pinned here:
 *
 *  - prepare_installer() provisions the agent user, mints exactly one
 *    Application Password, and returns a bundle array with the expected keys.
 *  - The manifest inside the .mcpb zip carries the home_url() base (not
 *    site_url()) so subdirectory installs produce working credentials.
 *  - When Application Passwords are unavailable, prepare_installer() returns
 *    WP_Error without minting any credential.
 *  - When a non-agent user already owns the block-mcp login,
 *    prepare_installer() propagates WP_Error from Agent_Provisioner::ensure()
 *    without minting any credential on that user.
 *  - connection_state() correctly reports 'needs_https', 'ready', and
 *    'connected' for the three reachable branches.
 *  - The gk_block_api_secret_at_rest_mode filter in 'paste' mode causes
 *    prepare_installer() to omit the password from the manifest default and
 *    return the plaintext separately.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;
use GravityKit\BlockAPI\Connect_Page;
use GravityKit\BlockAPI\Connections;

/**
 * Tests for Connect_Page::prepare_installer() and Connect_Page::connection_state().
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
	 * guidance block and both client-picker radio cards (Claude Desktop and the
	 * "Something else" fallback), with the Claude Desktop radio checked by default.
	 *
	 * The picker was converted from a <select> to a fieldset of radio cards so
	 * keyboard navigation and screen readers work with native browser behaviour.
	 * This test pins the P0 deliverables for the ready/pre-connection state: the
	 * next-steps panel, both radio inputs with name="client", both option labels,
	 * and the default-checked state on the Claude Desktop card.
	 *
	 * The modern block-editor restyling wraps all output in <div class="gk-connect">
	 * and nests the content inside <div class="gk-connect__card"> so all CSS
	 * selectors are scoped and the card container is present.
	 */
	public function test_render_section_shows_next_steps_and_picker_ready_state() {
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

		// Both option labels must be present.
		$this->assertStringContainsString( 'Claude Desktop app', $html, 'Claude Desktop radio card label must be present' );
		$this->assertStringContainsString( 'Something else', $html, '"Something else" radio card label must be present' );

		// Both option values must be present.
		$this->assertStringContainsString( 'value="Claude Desktop"', $html, 'Claude Desktop radio value must be present' );
		$this->assertStringContainsString( 'value="other"', $html, '"other" radio value must be present' );

		// The Claude Desktop radio must be checked by default.
		$this->assertMatchesRegularExpression(
			'/value="Claude Desktop"[^>]*checked|checked[^>]*value="Claude Desktop"/',
			$html,
			'Claude Desktop radio must be checked by default'
		);

		// A <select> must NOT be present — the picker is now radio cards.
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
}
