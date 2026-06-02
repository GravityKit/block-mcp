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
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;

/**
 * Tests for Agent_Provisioner::ensure().
 */
class AgentProvisionerTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_role( Agent_Provisioner::ROLE );
	}

	public function tear_down(): void {
		// Remove any agent user created during the test.
		$user = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $user ) {
			wp_delete_user( $user->ID );
		}
		remove_role( Agent_Provisioner::ROLE );
		delete_option( 'gk_block_api_agent_user_id' );
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
		$this->assertCount( 1, get_users( array( 'login' => Agent_Provisioner::LOGIN ) ) );
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
		self::factory()->user->create( array( 'user_login' => Agent_Provisioner::LOGIN, 'role' => 'subscriber' ) );
		$result = ( new Agent_Provisioner() )->ensure();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'agent_login_taken', $result->get_error_code() );
	}
}
