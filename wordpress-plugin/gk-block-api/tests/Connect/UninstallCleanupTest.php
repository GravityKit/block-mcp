<?php
/**
 * Agent_Provisioner::purge() — uninstall and credential-cleanup contracts.
 *
 * purge() is the authoritative teardown path for the service-account user.
 * Every code path in uninstall.php that removes agent state is ultimately
 * rooted here. The contracts pinned by this suite:
 *
 *  - purge() revokes all Application Passwords and deletes the agent user
 *    when the gk_block_api_remove_agent_on_uninstall filter returns true
 *    (the default). After purge() the agent user must not exist, the
 *    gk_block_api_agent_user_id option must be gone, and no app passwords
 *    must remain on that user ID.
 *
 *  - purge() is gated behind apply_filters('gk_block_api_remove_agent_on_uninstall', true).
 *    When the filter returns false the agent user, its credentials, and the
 *    option must all remain intact — an operator who opted to keep the
 *    account must not see it silently wiped.
 *
 *  - purge() verifies the _gk_block_api_agent meta flag before deleting any
 *    user. A normal user whose ID happens to be stored in the option must
 *    never be deleted; without this guard a misconfigured option could
 *    erase a real admin account.
 *
 *  - purge() is idempotent: calling it when no agent exists must not raise
 *    errors or trigger PHP warnings.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;

/**
 * Tests for Agent_Provisioner::purge().
 */
class UninstallCleanupTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->clean_agent_state();
	}

	public function tear_down(): void {
		$this->clean_agent_state();
		remove_filter( 'gk_block_api_remove_agent_on_uninstall', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Remove any agent user left over from a prior test and clear all
	 * agent-related options/roles so each test starts from a clean slate.
	 */
	private function clean_agent_state(): void {
		$user = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $user ) {
			// wp_delete_user requires wp-admin/includes/user.php outside admin context.
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $user->ID );
		}
		delete_option( 'gk_block_api_agent_user_id' );
		remove_role( Agent_Provisioner::ROLE );
	}

	/**
	 * purge() must revoke every Application Password on the agent user and
	 * then delete the user entirely.
	 *
	 * After a successful purge:
	 *  - get_user_by('id', $agent_id) returns false (user gone).
	 *  - The gk_block_api_agent_user_id option is deleted.
	 *  - The block_mcp_agent role is removed.
	 *  - WP_Application_Passwords::get_user_application_passwords() returns
	 *    an empty array for the (now-deleted) user ID.
	 */
	public function test_purge_revokes_app_passwords_and_deletes_agent_user() {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id, 'ensure() must return an integer agent user ID' );

		WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Test Client' )
		);

		$before = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertNotEmpty( $before, 'An app password must exist before purge()' );

		Agent_Provisioner::purge();

		$this->assertFalse(
			get_user_by( 'id', $agent_id ),
			'Agent user must not exist after purge()'
		);
		$this->assertFalse(
			(bool) get_option( 'gk_block_api_agent_user_id' ),
			'gk_block_api_agent_user_id option must be deleted after purge()'
		);
		$this->assertNull(
			get_role( Agent_Provisioner::ROLE ),
			'block_mcp_agent role must be removed after purge()'
		);

		$after = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertEmpty(
			$after,
			'All app passwords for the agent user ID must be gone after purge()'
		);
	}

	/**
	 * When the gk_block_api_remove_agent_on_uninstall filter returns false,
	 * purge() must be a no-op: the agent user, its option, and its role must
	 * all remain intact.
	 *
	 * This allows operators to keep the service account across plugin
	 * reinstalls — for instance when they manage credentials outside the
	 * plugin's own UI.
	 */
	public function test_purge_respects_disable_filter() {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id );

		add_filter( 'gk_block_api_remove_agent_on_uninstall', '__return_false' );

		Agent_Provisioner::purge();

		$this->assertInstanceOf(
			WP_User::class,
			get_user_by( 'id', $agent_id ),
			'Agent user must still exist when the disable filter returns false'
		);
		$this->assertSame(
			$agent_id,
			(int) get_option( 'gk_block_api_agent_user_id' ),
			'gk_block_api_agent_user_id option must remain when the disable filter returns false'
		);

		remove_filter( 'gk_block_api_remove_agent_on_uninstall', '__return_false' );
	}

	/**
	 * purge() must verify the _gk_block_api_agent meta flag before deleting
	 * any user.
	 *
	 * If a normal user's ID is stored in gk_block_api_agent_user_id but that
	 * user does not carry the meta flag, purge() must leave the user
	 * untouched. Without this guard, a stale or misconfigured option could
	 * cause purge() to erase a real site admin account.
	 */
	public function test_purge_never_deletes_a_nonagent_user() {
		$normal_user_id = self::factory()->user->create(
			array( 'role' => 'editor' )
		);

		// Plant the normal user's ID in the agent option without the meta flag.
		update_option( 'gk_block_api_agent_user_id', $normal_user_id, false );

		Agent_Provisioner::purge();

		$this->assertInstanceOf(
			WP_User::class,
			get_user_by( 'id', $normal_user_id ),
			'purge() must not delete a user that lacks the _gk_block_api_agent meta flag'
		);
	}

	/**
	 * purge() must reassign agent-authored posts to another user rather than
	 * deleting them.
	 *
	 * When an agent account is removed, any content it authored must survive.
	 * Passing no reassign argument to wp_delete_user() causes WordPress to
	 * delete every post the user authored. purge() resolves a reassign target
	 * (via the gk_block_api_agent_reassign_to filter, falling back to the
	 * first administrator) and passes it to wp_delete_user() so authored
	 * content is preserved under a different owner.
	 */
	public function test_purge_reassigns_agent_authored_posts_rather_than_deleting_them() {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id );

		$post_id = wp_insert_post(
			array(
				'post_title'  => 'Agent-authored post',
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_author' => $agent_id,
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id );

		Agent_Provisioner::purge();

		$post = get_post( $post_id );
		$this->assertNotNull( $post, 'Agent-authored post must still exist after purge()' );
		$this->assertNotSame( $agent_id, (int) $post->post_author, 'Post author must have been reassigned away from the deleted agent' );
	}

	/**
	 * Calling purge() when no agent has ever been provisioned (no option, no
	 * user) must complete silently without errors, warnings, or fatals.
	 *
	 * Idempotency is essential: uninstall.php may run in environments where
	 * the activation hook never fired, or after a previous incomplete
	 * uninstall already cleared partial state.
	 */
	public function test_purge_is_idempotent() {
		// No agent provisioned — option absent, no user.
		delete_option( 'gk_block_api_agent_user_id' );

		// Two consecutive calls must not throw or emit notices.
		Agent_Provisioner::purge();
		Agent_Provisioner::purge();

		// If we reach this assertion, no fatal or uncaught exception occurred.
		$this->assertTrue( true, 'purge() must be callable repeatedly with no agent present' );
	}
}
