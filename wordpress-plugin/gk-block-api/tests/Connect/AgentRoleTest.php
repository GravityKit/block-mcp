<?php
/**
 * Agent_Provisioner::register_role() — capability contracts.
 *
 * The block_mcp_agent role must carry exactly the permissions an AI agent
 * needs to manage content, and must never hold elevated capabilities that
 * would let it manage the site (manage_options), delete others' work
 * (delete_others_posts), or inject unfiltered HTML (unfiltered_html).
 *
 * The gk_block_api_agent_caps filter must let operators narrow or widen
 * the capability set — register_role() must honour it on a clean call.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;

/**
 * Tests for Agent_Provisioner::register_role().
 */
class AgentRoleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_role( Agent_Provisioner::ROLE );
	}

	public function tear_down(): void {
		remove_all_filters( 'gk_block_api_agent_caps' );
		remove_role( Agent_Provisioner::ROLE );
		parent::tear_down();
	}

	/**
	 * The default capability set must include the content-editing permissions
	 * the agent requires and must exclude privileged capabilities.
	 */
	public function test_register_role_creates_minimal_capabilities() {
		Agent_Provisioner::register_role();
		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'edit_others_posts' ) );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertFalse( $role->has_cap( 'delete_others_posts' ) );
		$this->assertFalse( $role->has_cap( 'unfiltered_html' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
	}

	/**
	 * The gk_block_api_agent_caps filter must override the default capability
	 * set when register_role() is called. A narrow filter that omits
	 * upload_files must produce a role without that capability.
	 */
	public function test_caps_filter_overrides_default_capabilities() {
		add_filter(
			'gk_block_api_agent_caps',
			static function () {
				return array( 'read' => true, 'edit_posts' => true );
			}
		);
		// Ensure a clean re-register under the filter.
		remove_role( Agent_Provisioner::ROLE );
		Agent_Provisioner::register_role();
		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'edit_posts' ) );
		$this->assertFalse( $role->has_cap( 'upload_files' ) );
	}
}
