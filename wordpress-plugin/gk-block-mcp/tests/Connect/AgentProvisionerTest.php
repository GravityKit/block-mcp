<?php
/**
 * Agent_Provisioner: user-creation and idempotency contracts.
 *
 * The provisioner is responsible for creating or locating the dedicated
 * service-account user that owns the credentials an AI client uses.
 * Key contracts pinned here:
 *
 *  - ensure() creates exactly one user with login 'block-mcp', assigns the
 *    minimal block_mcp_agent role (not Editor), and marks the user with the
 *    _gk_block_api_agent meta flag.
 *  - ensure() is idempotent: a second call returns the same ID and does not
 *    duplicate the user.
 *  - ensure() returns WP_Error('agent_login_taken') when the desired login
 *    already belongs to a non-agent user, rather than silently adopting it.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Agent_Provisioner;
use GravityKit\BlockMCP\Template_Manager;

/**
 * Tests for Agent_Provisioner::ensure().
 */
class AgentProvisionerTest extends WP_UnitTestCase {

	public function set_up(): void {
		// Clear any agent user a previous aborted run may have left behind
		// before WP_UnitTestCase opens its per-test transaction — otherwise a
		// stale 'block-mcp' user pushes a test onto the wrong code branch.
		$stale = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $stale ) {
			wp_delete_user( $stale->ID );
		}
		delete_option( 'gk_block_api_agent_user_id' );

		parent::set_up();
		remove_role( Agent_Provisioner::ROLE );
	}

	public function tear_down(): void {
		// Remove any agent user created during the test, under the default login
		// and under any custom login a gk/block-mcp/agent/login filter test installed.
		foreach ( array( Agent_Provisioner::LOGIN, 'my-custom-agent' ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user ) {
				wp_delete_user( $user->ID );
			}
		}
		remove_role( Agent_Provisioner::ROLE );
		delete_option( 'gk_block_api_agent_user_id' );
		remove_all_filters( 'authenticate' );
		remove_all_filters( 'gk/block-mcp/agent/login' );
		parent::tear_down();
	}

	/**
	 * ensure() must create a user with the minimal block_mcp_agent role,
	 * never Editor, and stamp the _gk_block_api_agent meta flag.
	 *
	 * Capability checks verify the role definition matches the minimum
	 * required: edit_others_posts granted, delete_others_posts and
	 * manage_options explicitly absent.
	 */
	public function test_ensure_creates_user_with_minimal_role_and_meta_flag() {
		$id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $id );
		$user = get_user_by( 'id', $id );
		$this->assertSame( Agent_Provisioner::LOGIN, $user->user_login );
		$this->assertContains( Agent_Provisioner::ROLE, (array) $user->roles );
		$this->assertNotContains( 'editor', (array) $user->roles );
		$this->assertSame( '1', get_user_meta( $id, Agent_Provisioner::META_FLAG, true ) );
		$this->assertTrue( user_can( $id, 'edit_others_posts' ) );
		$this->assertFalse( user_can( $id, 'delete_others_posts' ) );
		$this->assertFalse( user_can( $id, 'manage_options' ) );
	}

	/**
	 * The agent role must never gain `edit_theme_options`.
	 *
	 * derive_capabilities() copies each `show_in_rest` post type's mapped
	 * edit/publish primitives into the role. Core's FSE post types
	 * (wp_template, wp_template_part, wp_global_styles, wp_navigation) are
	 * show_in_rest and map those very primitives to `edit_theme_options`, so an
	 * unfiltered copy silently grants the agent site-wide theme/menu/widget/
	 * customizer control — defeating the least-privilege contract. The agent
	 * must be able to edit ordinary content, but never theme options.
	 */
	public function test_agent_role_never_grants_edit_theme_options() {
		$id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $id );

		$this->assertFalse( user_can( $id, 'edit_theme_options' ), 'agent must not gain edit_theme_options via FSE post types' );
		$this->assertTrue( user_can( $id, 'edit_others_posts' ), 'ordinary content editing must remain intact' );
	}

	/**
	 * register_role() must strip a forbidden capability from an EXISTING role.
	 *
	 * A role provisioned by an earlier (vulnerable) version keeps its stored
	 * capabilities across an update — the additive re-assert never removed them,
	 * so an already-over-granted `edit_theme_options` survived the fix. Because
	 * register_role() runs on `init`, stripping the forbidden caps there lets an
	 * upgraded site self-heal on the next request without re-provisioning.
	 * Operator-added, non-forbidden caps must be left untouched.
	 */
	public function test_register_role_strips_forbidden_caps_from_existing_role() {
		// Simulate a stale role carrying the over-grant plus a legitimate cap.
		add_role(
			Agent_Provisioner::ROLE,
			'Block MCP Agent',
			array(
				'read'                  => true,
				'edit_others_posts'     => true,
				'edit_theme_options'    => true,
				'a_custom_operator_cap' => true,
			)
		);

		Agent_Provisioner::register_role();

		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( 'edit_theme_options' ), 'forbidden cap must be stripped from the existing role' );
		$this->assertTrue( $role->has_cap( 'edit_others_posts' ), 'content-editing cap must survive' );
		$this->assertTrue( $role->has_cap( 'a_custom_operator_cap' ), 'operator-added caps must not be stripped' );
	}

	/**
	 * register_role() grants TEMPLATE_EDIT_CAP on a fresh role when the
	 * gk_block_api_template_edits toggle is on — the cap that gates
	 * POST /template, computed from the toggle rather than hardcoded.
	 */
	public function test_register_role_grants_template_edit_cap_when_toggle_on() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );

		Agent_Provisioner::register_role();

		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ) );
	}

	/**
	 * register_role() must REVOKE TEMPLATE_EDIT_CAP from an existing role
	 * when the toggle is later switched off — unlike every other capability
	 * in the map, the additive re-assert loop never removes this one, so a
	 * dedicated removal branch is required or a toggled-off grant would
	 * outlive the setting that authorized it. This is the one exception to
	 * "additive only" that test_register_role_strips_forbidden_caps_from_existing_role()
	 * (above) proves still holds for everything else.
	 */
	public function test_register_role_revokes_template_edit_cap_when_toggle_off() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		Agent_Provisioner::register_role();
		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ), 'setup: cap must be granted before it can be revoked' );

		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '0' );
		Agent_Provisioner::register_role();

		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ) );
	}

	/**
	 * Calling ensure() twice must return the same user ID and not create a
	 * second user with the same login. The resolved ID must be persisted in
	 * the gk_block_api_agent_user_id option.
	 */
	public function test_ensure_is_idempotent_and_persists_agent_id_option() {
		$p      = new Agent_Provisioner();
		$first  = $p->ensure();
		$second = $p->ensure();
		$this->assertSame( $first, $second );
		$this->assertSame( $first, (int) get_option( 'gk_block_api_agent_user_id' ) );
		$this->assertCount( 1, get_users( array( 'login__in' => array( Agent_Provisioner::LOGIN ) ) ) );
	}

	/**
	 * Interactive login must be blocked for the service account regardless of
	 * the password supplied.
	 *
	 * block_agent_login() is wired to the 'authenticate' filter in
	 * gk-block-mcp.php on plugins_loaded, which fires before the test
	 * bootstrap runs individual tests but after WP_UnitTestCase::set_up().
	 * If the filter is not yet active in this environment we add it directly
	 * so the contract is testable in isolation.
	 *
	 * Two sub-contracts:
	 *  1. wp_authenticate() for the agent login returns WP_Error('agent_no_login').
	 *  2. block_agent_login() called with a normal (non-agent) WP_User returns
	 *     that user unchanged — it must not block real users.
	 */
	public function test_interactive_login_is_blocked_for_agent_and_passes_through_for_normal_users() {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id );

		// Ensure the authenticate filter hook is active in this test context.
		// The filter is registered at priority 30 with 3 accepted args in
		// gk-block-mcp.php on plugins_loaded; it is present after the bootstrap
		// loads the plugin. The check here guards test environments where the
		// bootstrap path differs.
		if ( ! has_filter( 'authenticate', array( 'GravityKit\BlockMCP\Agent_Provisioner', 'block_agent_login' ) ) ) {
			add_filter( 'authenticate', array( 'GravityKit\BlockMCP\Agent_Provisioner', 'block_agent_login' ), 30, 3 );
		}

		$result = wp_authenticate( Agent_Provisioner::LOGIN, 'whatever' );

		$this->assertInstanceOf( WP_Error::class, $result, 'wp_authenticate() must return WP_Error for the service account' );
		$this->assertSame( 'agent_no_login', $result->get_error_code(), 'Error code must be agent_no_login' );

		// Pass-through: a normal WP_User must be returned unchanged.
		$normal_user = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$this->assertSame( $normal_user, Agent_Provisioner::block_agent_login( $normal_user ), 'block_agent_login() must return a non-agent WP_User unchanged' );
	}

	/**
	 * When the desired login already exists but lacks the _gk_block_api_agent
	 * meta flag, ensure() must refuse to adopt that account — doing so would
	 * silently take over a real user's identity.
	 *
	 * The returned WP_Error code is 'agent_login_taken' so callers can give
	 * the site admin actionable guidance.
	 */
	public function test_ensure_returns_wp_error_when_login_taken_by_nonagent() {
		$conflicting_user = self::factory()->user->create( array( 'user_login' => Agent_Provisioner::LOGIN, 'role' => 'subscriber' ) );
		$this->assertGreaterThan( 0, $conflicting_user, 'precondition: the conflicting non-agent user must be created' );
		$result = ( new Agent_Provisioner() )->ensure();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_login_taken', $result->get_error_code() );
	}

	/**
	 * The gk/block-mcp/agent/login filter overrides the service-account login.
	 *
	 * ensure() resolves the login through gk/block-mcp/agent/login before locating
	 * or creating the user, so a host that already has (or wants) a 'block-mcp' user
	 * for something else can point the agent at a different login. This pins that the
	 * filtered value is what actually lands as the created user's user_login — not
	 * the LOGIN constant.
	 */
	public function test_agent_login_filter_overrides_service_account_login() {
		$custom = static function () {
			return 'my-custom-agent';
		};
		add_filter( 'gk/block-mcp/agent/login', $custom );

		$id = ( new Agent_Provisioner() )->ensure();

		remove_filter( 'gk/block-mcp/agent/login', $custom );

		$this->assertIsInt( $id );
		$user = get_user_by( 'id', $id );
		$this->assertInstanceOf( WP_User::class, $user );
		$this->assertSame( 'my-custom-agent', $user->user_login, 'ensure() must create the agent under the filtered login' );
		$this->assertFalse( get_user_by( 'login', Agent_Provisioner::LOGIN ), 'the default login must not be created when the filter overrides it' );
	}

	/**
	 * [MS1] On multisite, ensure() must grant the agent blog membership + the
	 * agent role on EVERY blog it is provisioned on — not just the first.
	 *
	 * The agent user is network-global but capabilities are per-blog, so before
	 * the fix ensure() returned early on blog B without granting caps there, and
	 * the agent's REST writes 403'd on every blog except the one it was created
	 * on. This provisions on the main blog, switches to a fresh sub-site,
	 * provisions again, and asserts the agent is a member of B and can edit_posts
	 * there.
	 *
	 * @group ms-required
	 */
	public function test_ensure_grants_role_on_each_multisite_blog() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test. Run with WP_TESTS_MULTISITE=1.' );
		}

		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id );

		$blog_id = self::factory()->blog->create();
		$this->assertIsInt( $blog_id );

		switch_to_blog( $blog_id );
		try {
			// Before the fix the agent has no membership/caps on this blog.
			$same = ( new Agent_Provisioner() )->ensure();
			$this->assertSame( $agent_id, $same, 'the same global agent user is reused on the sub-site' );

			$this->assertTrue(
				is_user_member_of_blog( $agent_id, $blog_id ),
				'agent must be a member of the sub-site'
			);
			$this->assertTrue(
				user_can( $agent_id, 'edit_posts' ),
				'agent must have edit_posts on the sub-site so REST writes work there'
			);
		} finally {
			restore_current_blog();
		}
	}

	// ──────────────────────────────────────────────────────────────────────
	// get_existing() — the create-free resolver used by the minting request.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * get_existing() returns null when the agent has not been provisioned, and
	 * must NOT create the user.
	 *
	 * This is the half of the Monarx-safe split that keeps the credential-minting
	 * request from also creating a user: get_existing() resolves an already-present
	 * agent but never calls wp_insert_user(). Pins that an absent agent yields null
	 * and zero users with the service-account login.
	 */
	public function test_get_existing_returns_null_when_agent_absent_and_creates_no_user() {
		$result = ( new Agent_Provisioner() )->get_existing();

		$this->assertNull( $result, 'get_existing() must return null when the agent is not provisioned' );
		$this->assertFalse( get_user_by( 'login', Agent_Provisioner::LOGIN ), 'get_existing() must not create the agent user' );
		$this->assertCount(
			0,
			get_users( array( 'login__in' => array( Agent_Provisioner::LOGIN ) ) ),
			'no user may be created under the service-account login'
		);
	}

	/**
	 * Once ensure() has provisioned the agent, get_existing() returns that same
	 * user ID — the minting request resolves the pre-created account.
	 */
	public function test_get_existing_returns_id_when_agent_present() {
		$created = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $created );

		$resolved = ( new Agent_Provisioner() )->get_existing();
		$this->assertSame( $created, $resolved, 'get_existing() must resolve the pre-created agent id' );
	}

	/**
	 * get_existing() must refuse to adopt a real user that happens to own the
	 * service-account login, returning WP_Error('agent_login_taken') — the same
	 * fail-closed contract as ensure(), so the minting path never mints on a
	 * human account that shares the login.
	 */
	public function test_get_existing_returns_wp_error_when_login_taken_by_nonagent() {
		$conflicting_user = self::factory()->user->create( array( 'user_login' => Agent_Provisioner::LOGIN, 'role' => 'subscriber' ) );
		$this->assertGreaterThan( 0, $conflicting_user, 'precondition: the conflicting non-agent user must be created' );

		$result = ( new Agent_Provisioner() )->get_existing();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_login_taken', $result->get_error_code() );
	}
}
