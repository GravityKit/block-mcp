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
 *     and leaves no Application Passwords on the deleted user ID.
 *
 *  2. Multisite: provisioning an agent on a non-main sub-site, then calling
 *     Agent_Provisioner::purge() under that blog's context, removes the sub-site
 *     agent user and its Application Passwords and reassigns its posts.
 *     Tagged ms-required; runs under tests/phpunit/multisite.xml.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Agent_Provisioner;

/**
 * End-to-end teardown contracts for the agent service account.
 *
 * The single-site test requires uninstall.php (which defines WP_UNINSTALL_PLUGIN
 * and global functions and runs the full teardown), so it is isolated in its own
 * process via a method-level @runInSeparateProcess. The multisite test exercises
 * Agent_Provisioner::purge() in-process and must NOT be process-isolated: a child
 * process re-installing the multisite test environment over the parent's open
 * SQLite database deadlocks. That is why @runInSeparateProcess lives on the
 * single-site method only, not on the class.
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
		remove_filter( 'gk/block-mcp/agent/remove-on-uninstall', '__return_false' );
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
		remove_role( Agent_Provisioner::ROLE );
	}

	/**
	 * Requiring uninstall.php after provisioning an agent must remove the
	 * agent user, delete its option, remove the role, and revoke all
	 * Application Passwords.
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

		// Seed the UI-managed options and the inventory cache so we can assert
		// uninstall.php's per-blog option/transient sweep actually removes them
		// (gk_block_api_uninstall_blog deletes the preferences + allowlist options
		// and the gk_block_inventory transient).
		update_option( 'gk_block_api_preferences', array( 'namespace_scores' => array( 'core' => 90 ) ) );
		update_option( 'gk_block_api_post_types_allowlist', array( 'post', 'page' ) );
		update_option( \GravityKit\BlockMCP\Block_Abilities::ENABLED_OPTION, '0' );
		set_transient( 'gk_block_inventory', array( 'seeded' => true ), HOUR_IN_SECONDS );

		// [LC1] Plant a single-use exchange-code transient — it holds a live
		// plaintext app password and must not survive uninstall.
		$xchg_key = 'gk_block_api_xchg_' . hash( 'sha256', 'e2e-test-code' );
		set_transient( $xchg_key, array( 'site' => 'https://x', 'user' => 'block-mcp', 'password' => 'live-secret' ), 120 );

		// Define the constant uninstall.php requires before it will execute.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'gk-block-mcp/gk-block-mcp.php' );
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
		// The sweep is a raw $wpdb DELETE (it bypasses the object cache), so assert
		// the underlying option row is gone rather than via get_transient().
		global $wpdb;
		$xchg_row = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", '_transient_' . $xchg_key )
		);
		$this->assertNull(
			$xchg_row,
			'[LC1] exchange-code transients (holding a live password) must be swept by uninstall.php'
		);

		$after = WP_Application_Passwords::get_user_application_passwords( $agent_id );
		$this->assertEmpty(
			$after,
			'All Application Passwords for the agent user ID must be revoked after uninstall.php'
		);

		// The UI-managed options and the inventory cache seeded above must be gone.
		$this->assertFalse(
			get_option( 'gk_block_api_preferences', false ),
			'gk_block_api_preferences option must be deleted after uninstall.php'
		);
		$this->assertFalse(
			get_option( 'gk_block_api_post_types_allowlist', false ),
			'gk_block_api_post_types_allowlist option must be deleted after uninstall.php'
		);
		$this->assertFalse(
			get_transient( 'gk_block_inventory' ),
			'gk_block_inventory transient must be deleted after uninstall.php'
		);
		$this->assertFalse(
			get_option( \GravityKit\BlockMCP\Block_Abilities::ENABLED_OPTION, false ),
			'gk_block_api_abilities_enabled option must be deleted after uninstall.php'
		);
	}

	/**
	 * On multisite, Agent_Provisioner::purge() run in a sub-site's context must
	 * remove that sub-site's agent user and its Application Passwords, delete the
	 * agent-id option, and reassign any agent-authored posts to an administrator
	 * rather than deleting them.
	 *
	 * The post-reassignment contract: purge() issues a $wpdb->update on wp_posts
	 * before calling wpmu_delete_user(). Without that update, wpmu_delete_user()
	 * would delete the agent's posts network-wide. This inserts a post authored
	 * by the agent, runs purge(), and asserts the post still exists with a
	 * different post_author.
	 *
	 * uninstall.php's per-blog option/transient sweep is covered single-site by
	 * the end-to-end test above; this test pins the multisite-specific half —
	 * purge() under switch_to_blog(). It runs in-process (see the class docblock
	 * for why it must not be process-isolated) and follows the same
	 * create-switch-assert-restore shape as the passing AgentProvisionerTest
	 * multisite test: no explicit blog deletion, restore_current_blog() in
	 * finally, transaction rollback handles cleanup.
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
		try {
			// A real sub-site always has an administrator; purge() reassigns the
			// agent's content to the first one. A factory-created blog has none
			// (user_id defaults to 0), so create one here or the reassign target
			// resolves to 0 and content is left orphaned on the deleted agent.
			$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$this->assertIsInt( $admin_id, 'sub-site must have an administrator to receive reassigned content' );

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

			// purge() must (a) reassign the post and (b) delete the agent user,
			// all while the blog context is active.
			Agent_Provisioner::purge();

			$this->assertFalse(
				get_user_by( 'id', $agent_id ),
				'Sub-site agent user must not exist after per-blog purge()'
			);
			$this->assertFalse(
				(bool) get_option( 'gk_block_api_agent_user_id' ),
				'gk_block_api_agent_user_id option must be deleted after per-blog purge()'
			);

			$after = WP_Application_Passwords::get_user_application_passwords( $agent_id );
			$this->assertEmpty(
				$after,
				'All Application Passwords for the sub-site agent user must be revoked after per-blog purge()'
			);

			// Post must still exist and its author must have been reassigned away
			// from the (now-deleted) agent account. purge() reassigns via a raw
			// $wpdb->update (it bypasses the object cache), so drop the cached
			// post before reading it back or get_post() returns the stale author.
			clean_post_cache( $post_id );
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
			$this->assertSame(
				$admin_id,
				(int) $post->post_author,
				'post_author must be reassigned to the sub-site administrator'
			);
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * On multisite, purge() must reassign the agent's posts on EVERY blog before
	 * the network-wide user deletion — not just the blog it is invoked from.
	 *
	 * wpmu_delete_user() deletes the user's authored posts across the entire
	 * network. The agent user is network-global, so a post it authored on blog B
	 * is destroyed when purge() runs from blog A unless blog B's posts are
	 * reassigned first. Before the fix, purge() reassigned only the current
	 * blog, so cross-blog agent content was silently deleted. This provisions
	 * the agent and authors a post on a second sub-site, runs purge() from the
	 * first sub-site, and asserts the OTHER blog's post survived with a
	 * reassigned author.
	 *
	 * @group ms-required
	 */
	public function test_multisite_purge_reassigns_posts_on_all_blogs_before_delete() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test. Run with WP_TESTS_MULTISITE=1.' );
		}

		$blog_a = self::factory()->blog->create();
		$blog_b = self::factory()->blog->create();
		$this->assertIsInt( $blog_a );
		$this->assertIsInt( $blog_b );

		// Provision the agent from blog A; the gk_block_api_agent_user_id option
		// lands on blog A, so purge() invoked there drives the network deletion.
		switch_to_blog( $blog_a );
		$admin_a  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $agent_id );
		restore_current_blog();

		// Author a post by the agent on blog B (a DIFFERENT blog) and give blog B
		// an administrator so its content has a reassignment target.
		switch_to_blog( $blog_b );
		$admin_b = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_user_to_blog( $blog_b, $agent_id, Agent_Provisioner::ROLE );
		$post_b = wp_insert_post(
			array(
				'post_title'  => 'Agent post on the OTHER blog',
				'post_status' => 'publish',
				'post_author' => $agent_id,
			)
		);
		$this->assertIsInt( $post_b );
		$this->assertGreaterThan( 0, $post_b );
		restore_current_blog();

		// Run purge() from blog A — this triggers the network-wide wpmu_delete_user().
		switch_to_blog( $blog_a );
		try {
			Agent_Provisioner::purge();
		} finally {
			restore_current_blog();
		}

		$this->assertFalse( get_user_by( 'id', $agent_id ), 'agent user must be deleted network-wide' );

		// Blog B's post must still exist with a non-agent author — proving its
		// content was reassigned BEFORE the network deletion, not destroyed.
		switch_to_blog( $blog_b );
		try {
			clean_post_cache( $post_b );
			$post = get_post( $post_b );
			$this->assertInstanceOf( \WP_Post::class, $post, 'cross-blog agent post must survive purge()' );
			$this->assertNotSame( $agent_id, (int) $post->post_author, 'cross-blog post_author must be reassigned away from the agent' );
		} finally {
			restore_current_blog();
		}
	}
}
