<?php
/**
 * Agent Application Password authentication contracts.
 *
 * The agent service account authenticates every REST call through
 * WordPress Application Passwords.  Both go through the same
 * `authenticate` filter:
 *
 *   priority 20 — wp_authenticate_application_password() resolves the
 *                 agent to a WP_User when the correct app password is
 *                 supplied and the request is an API request.
 *   priority 30 — Agent_Provisioner::block_agent_login() runs next.
 *
 * Without a guard, block_agent_login()'s Path 1 (WP_User + meta flag)
 * fires on the already-resolved WP_User and replaces it with
 * WP_Error('agent_no_login'), breaking the agent's own REST auth.
 *
 * The guard must allow application-password / API authentication to pass
 * through unchanged and only block interactive (non-API) login attempts.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Agent_Provisioner;
use GravityKit\BlockMCP\App_Password_Issuer;

/**
 * Tests for the Application Password / API auth path through the agent login filter.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class AgentAuthTest extends WP_UnitTestCase {

	public function set_up(): void {
		$stale = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $stale ) {
			wp_delete_user( $stale->ID );
		}
		delete_option( 'gk_block_api_agent_user_id' );

		parent::set_up();
		remove_role( Agent_Provisioner::ROLE );
	}

	public function tear_down(): void {
		$user = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
		remove_role( Agent_Provisioner::ROLE );
		delete_option( 'gk_block_api_agent_user_id' );
		remove_all_filters( 'authenticate' );
		remove_all_filters( 'application_password_is_api_request' );
		parent::tear_down();
	}

	/**
	 * The agent's Application Password must authenticate successfully when the
	 * request is an API request (REST or XML-RPC).
	 *
	 * wp_authenticate_application_password() runs on the `authenticate` filter
	 * at priority 20.  It resolves the agent to a WP_User when the correct app
	 * password is supplied.  block_agent_login() runs at priority 30; without
	 * the API-request guard it immediately replaces that WP_User with
	 * WP_Error('agent_no_login'), cutting off the agent's own REST auth.
	 *
	 * This test exercises the full filter chain as core does:
	 *  1. Provisions the agent and mints an Application Password.
	 *  2. Gates the app-password path via the filter core uses.
	 *  3. Calls wp_authenticate() so every priority-ordered hook in the chain fires.
	 *  4. Asserts the result is the agent WP_User — not a WP_Error.
	 */
	public function test_agent_app_password_authenticates_on_api_request(): void {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id, 'Agent provisioning must succeed' );

		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$issuer = new App_Password_Issuer();
		$creds  = $issuer->issue( $agent_id, 'AgentAuthTest' );
		$this->assertIsArray( $creds, 'App password issuance must succeed' );
		$this->assertArrayHasKey( 'password', $creds );

		$plaintext = $creds['password'];

		// Simulate an API request — mirrors the gate inside
		// wp_authenticate_application_password() and our own guard.
		add_filter( 'application_password_is_api_request', '__return_true' );

		// Ensure the login-block filter is active (bootstrap loads the plugin on
		// muplugins_loaded, so it normally is; this guard keeps the test
		// self-contained if the hook order differs in isolated process mode).
		if ( ! has_filter( 'authenticate', array( 'GravityKit\BlockMCP\Agent_Provisioner', 'block_agent_login' ) ) ) {
			add_filter( 'authenticate', array( 'GravityKit\BlockMCP\Agent_Provisioner', 'block_agent_login' ), 30, 3 );
		}

		$result = wp_authenticate( Agent_Provisioner::LOGIN, $plaintext );

		$this->assertInstanceOf(
			WP_User::class,
			$result,
			sprintf(
				'Agent app-password must authenticate on an API request; got %s',
				$result instanceof WP_Error ? $result->get_error_code() : gettype( $result )
			)
		);
		$this->assertSame( $agent_id, $result->ID, 'Authenticated user must be the agent' );
	}

	/**
	 * Interactive login for the agent must be blocked when the request is NOT
	 * an API request, regardless of whether the supplied password is correct.
	 *
	 * This pins the non-API (interactive) branch of block_agent_login() and
	 * ensures the API-guard fix does not accidentally allow the agent to log
	 * in through the browser login form.
	 *
	 * In a non-API context wp_authenticate_application_password() exits early
	 * (returns null) so standard password auth runs.  block_agent_login() at
	 * priority 30 must then veto the agent regardless.
	 *
	 * A normal (non-agent) user in the same non-API context must pass through
	 * unchanged.
	 */
	public function test_agent_interactive_login_is_blocked_outside_api_request(): void {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id, 'Agent provisioning must succeed' );

		// Explicitly not an API request — the filter returns false.
		add_filter( 'application_password_is_api_request', '__return_false' );

		if ( ! has_filter( 'authenticate', array( 'GravityKit\BlockMCP\Agent_Provisioner', 'block_agent_login' ) ) ) {
			add_filter( 'authenticate', array( 'GravityKit\BlockMCP\Agent_Provisioner', 'block_agent_login' ), 30, 3 );
		}

		$result = wp_authenticate( Agent_Provisioner::LOGIN, 'any-password' );

		$this->assertInstanceOf( WP_Error::class, $result, 'Interactive login must be blocked for the agent' );
		$this->assertSame( 'agent_no_login', $result->get_error_code() );

		// A normal user must not be affected by the agent login block.
		$normal = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$this->assertSame(
			$normal,
			Agent_Provisioner::block_agent_login( $normal, $normal->user_login ),
			'block_agent_login() must return a non-agent WP_User unchanged'
		);
	}
}
