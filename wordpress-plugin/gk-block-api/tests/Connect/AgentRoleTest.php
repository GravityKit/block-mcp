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
	 *
	 * publish_pages is required for publishing pages via create_post — without
	 * it, POST /posts with post_type:page and status:publish returns 403.
	 * manage_categories is a delete-class capability that the role must not
	 * hold; term assignment on editable posts flows through assign_terms which
	 * is derived from edit_posts.
	 */
	public function test_register_role_creates_minimal_capabilities() {
		Agent_Provisioner::register_role();
		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'edit_others_posts' ) );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertTrue( $role->has_cap( 'publish_pages' ), 'publish_pages must be granted so the agent can publish pages' );
		$this->assertFalse( $role->has_cap( 'delete_others_posts' ) );
		$this->assertFalse( $role->has_cap( 'unfiltered_html' ) );
		$this->assertFalse( $role->has_cap( 'manage_options' ) );
		$this->assertFalse( $role->has_cap( 'manage_categories' ), 'manage_categories must not be granted — it is a delete-class cap the agent does not need' );
	}

	/**
	 * When the gk_block_api_agent_role filter returns a non-canonical slug,
	 * register_role() must return that slug and must NOT create or modify the
	 * block_mcp_agent role — the operator is responsible for ensuring the
	 * custom role exists.
	 *
	 * This covers the delegation path: a site that routes the agent into the
	 * built-in 'editor' role (or any other custom role) via the filter must not
	 * have a redundant block_mcp_agent role created alongside it.
	 */
	public function test_role_slug_filter_returns_custom_slug_without_creating_canonical_role() {
		add_filter( 'gk_block_api_agent_role', static fn() => 'editor' );

		$returned_slug = Agent_Provisioner::register_role();

		remove_all_filters( 'gk_block_api_agent_role' );

		$this->assertSame( 'editor', $returned_slug, 'register_role() must return the slug the filter resolves' );
		$this->assertNull( get_role( Agent_Provisioner::ROLE ), 'block_mcp_agent role must not be created when the filter returns a custom slug' );
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
