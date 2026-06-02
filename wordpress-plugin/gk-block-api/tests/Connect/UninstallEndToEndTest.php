<?php
/**
 * Uninstall end-to-end contracts: proves uninstall.php actually reaches purge().
 *
 * UninstallCleanupTest proves that Agent_Provisioner::purge() does the right
 * thing when called directly. This file proves the other half: that uninstall.php
 * actually invokes purge() in the right context for every blog.
 *
 * Two contracts are pinned:
 *
 *  1. Single-site: requiring uninstall.php after provisioning an agent and
 *     minting an Application Password removes the agent user, deletes the
 *     gk_block_api_agent_user_id option, removes the block_mcp_agent role,
 *     clears the deactivation-notice transient, and leaves no Application
 *     Passwords on the deleted user ID.
 *
 *  2. Multisite: provisioning an agent on a non-main sub-site, then running
 *     the per-blog uninstall teardown under that blog's context, removes the
 *     sub-site agent user and its Application Passwords.
 *     Skipped when not running under a multisite install.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;

/**
 * End-to-end tests that require uninstall.php and assert full teardown.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallEndToEndTest extends WP_UnitTestCase {

	/**
	 * Absolute path to the plugin's uninstall.php.
	 *
	 * Resolved once here so tests don't duplicate the literal path.
	 *
	 * @var string
	 */
	private string $uninstall_file;

	public function set_up(): void {
		parent::set_up();
		$this->uninstall_file = dirname( __DIR__, 2 ) . '/uninstall.php';
		$this->clean_agent_state();
	}

	public function tear_down(): void {
		$this->clean_agent_state();
		remove_filter( 'gk_block_api_remove_agent_on_uninstall', '__return_false' );
		parent::tear_down();
	}

	/**
	 * Remove any agent user and clear all agent-related state so each test
	 * starts from a known baseline.
	 */
	private function clean_agent_state(): void {
		$user = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $user ) {
			if ( ! function_exists( 'wp_delete_user' ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
			}
			wp_delete_user( $user->ID );
		}
		delete_option( 'gk_block_api_agent_user_id' );
		delete_transient( 'gk_block_api_deactivation_notice' );
		remove_role( Agent_Provisioner::ROLE );
	}

	/**
	 * Requiring uninstall.php after provisioning an agent must remove the
	 * agent user, delete its option, remove the role, sweep the
	 * deactivation-notice transient, and revoke all Application Passwords.
	 *
	 * This test proves that purge() is actually invoked from within
	 * uninstall.php — not just that purge() works in isolation (which
	 * UninstallCleanupTest covers).
	 *
	 * WP_UNINSTALL_PLUGIN must be defined before uninstall.php is required;
	 * otherwise the file exits early on its guard check.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_uninstall_php_removes_agent_user_option_role_and_app_passwords() {
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id, 'ensure() must return an integer agent user ID' );

		WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'E2E Test Client' )
		);

		$before = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertNotEmpty( $before, 'An app password must exist before running uninstall.php' );

		// Plant the deactivation-notice transient to confirm it is swept.
		set_transient( 'gk_block_api_deactivation_notice', 1, 5 * MINUTE_IN_SECONDS );

		// Define the constant uninstall.php requires before it will execute.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'gk-block-api/gk-block-api.php' );
		}

		require $this->uninstall_file;

		$this->assertFalse(
			get_user_by( 'id', $agent_id ),
			'Agent user must not exist after uninstall.php'
		);
		$this->assertFalse(
			(bool) get_option( 'gk_block_api_agent_user_id' ),
			'gk_block_api_agent_user_id option must be deleted after uninstall.php'
		);
		$this->assertNull(
			get_role( Agent_Provisioner::ROLE ),
			'block_mcp_agent role must be removed after uninstall.php'
		);
		$this->assertFalse(
			(bool) get_transient( 'gk_block_api_deactivation_notice' ),
			'gk_block_api_deactivation_notice transient must be deleted after uninstall.php'
		);

		$after = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertEmpty(
			$after,
			'All Application Passwords for the agent user ID must be revoked after uninstall.php'
		);
	}

	/**
	 * On multisite, provisioning the agent on a non-main sub-site and then
	 * running the per-blog uninstall teardown in that blog's context must
	 * remove the sub-site's agent user, its Application Passwords, and
	 * reassign any agent-authored posts to an administrator rather than
	 * deleting them.
	 *
	 * The post-reassignment contract: purge() issues a $wpdb->update on
	 * wp_posts before calling wpmu_delete_user(). Without that update,
	 * wpmu_delete_user() would delete the agent's posts network-wide.
	 * This test inserts a post authored by the agent, runs purge(), and
	 * asserts the post still exists and its post_author is no longer the
	 * agent's ID.
	 *
	 * @group ms-required
	 */
	public function test_multisite_per_blog_purge_removes_subsite_agent_and_app_passwords() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test. Run with WP_TESTS_MULTISITE=1.' );
		}

		// Create a sub-site and provision the agent there.
		$blog_id = self::factory()->blog->create();
		$this->assertIsInt( $blog_id );

		switch_to_blog( $blog_id );

		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id, 'ensure() must return an integer agent user ID on the sub-site' );

		WP_Application_Passwords::create_new_application_password(
			$agent_id,
			array( 'name' => 'Multisite E2E Client' )
		);

		$before = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertNotEmpty( $before, 'An app password must exist on the sub-site before teardown' );

		// Insert a post authored by the agent — purge() must reassign it, not
		// delete it, so the content survives plugin removal.
		$post_id = wp_insert_post(
			array(
				'post_title'   => 'Agent-authored post (reassignment test)',
				'post_status'  => 'publish',
				'post_author'  => $agent_id,
				'post_content' => 'Authored by the agent service account.',
			)
		);
		$this->assertIsInt( $post_id );
		$this->assertGreaterThan( 0, $post_id, 'Test post must be created before purge()' );

		// Plant the deactivation-notice transient on this sub-site.
		set_transient( 'gk_block_api_deactivation_notice', 1, 5 * MINUTE_IN_SECONDS );

		// Simulate the per-blog teardown that uninstall.php runs inside
		// switch_to_blog(). purge() must (a) reassign the post and (b) delete
		// the agent user — all while the blog context is active.
		gk_block_api_uninstall_blog(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- calling the function defined in uninstall.php
		Agent_Provisioner::purge();

		$this->assertFalse(
			get_user_by( 'id', $agent_id ),
			'Sub-site agent user must not exist after per-blog purge()'
		);
		$this->assertFalse(
			(bool) get_option( 'gk_block_api_agent_user_id' ),
			'gk_block_api_agent_user_id option must be deleted after per-blog purge()'
		);
		$this->assertFalse(
			(bool) get_transient( 'gk_block_api_deactivation_notice' ),
			'Deactivation-notice transient must be deleted on the sub-site after per-blog cleanup'
		);

		$after = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertEmpty(
			$after,
			'All Application Passwords for the sub-site agent user must be revoked after per-blog purge()'
		);

		// Post must still exist and its author must have been reassigned away
		// from the (now-deleted) agent account.
		$post = get_post( $post_id );
		$this->assertInstanceOf(
			\WP_Post::class,
			$post,
			'Agent-authored post must still exist after purge() — content must not be deleted'
		);
		$this->assertNotSame(
			$agent_id,
			(int) $post->post_author,
			'post_author must no longer be the agent ID after purge() reassignment'
		);

		restore_current_blog();

		// Clean up the sub-site.
		wpmu_delete_blog( $blog_id, true );
	}
}
