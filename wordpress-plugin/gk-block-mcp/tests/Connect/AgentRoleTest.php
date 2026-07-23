<?php
/**
 * Agent_Provisioner::register_role() — capability contracts.
 *
 * The block_mcp_agent role must carry exactly the permissions an AI agent
 * needs to manage content, and must never hold elevated capabilities that
 * would let it manage the site (manage_options), delete others' work
 * (delete_others_posts), or inject unfiltered HTML (unfiltered_html).
 *
 * The gk/block-mcp/agent/caps filter must let operators narrow or widen
 * the capability set — register_role() must honour it on a clean call.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Agent_Provisioner;
use GravityKit\BlockMCP\Template_Manager;

/**
 * Tests for Agent_Provisioner::register_role().
 */
class AgentRoleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		remove_role( Agent_Provisioner::ROLE );
	}

	public function tear_down(): void {
		remove_all_filters( 'gk/block-mcp/agent/caps' );
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
	 * When the gk/block-mcp/agent/role filter returns a non-canonical slug,
	 * register_role() must return that slug and must NOT create or modify the
	 * block_mcp_agent role — the operator is responsible for ensuring the
	 * custom role exists.
	 *
	 * This covers the delegation path: a site that routes the agent into the
	 * built-in 'editor' role (or any other custom role) via the filter must not
	 * have a redundant block_mcp_agent role created alongside it.
	 */
	public function test_role_slug_filter_returns_custom_slug_without_creating_canonical_role() {
		add_filter( 'gk/block-mcp/agent/role', static fn() => 'editor' );

		$returned_slug = Agent_Provisioner::register_role();

		remove_all_filters( 'gk/block-mcp/agent/role' );

		$this->assertSame( 'editor', $returned_slug, 'register_role() must return the slug the filter resolves' );
		$this->assertNull( get_role( Agent_Provisioner::ROLE ), 'block_mcp_agent role must not be created when the filter returns a custom slug' );
	}

	/**
	 * The gk/block-mcp/agent/caps filter must override the default capability
	 * set when register_role() is called. A narrow filter that omits
	 * upload_files must produce a role without that capability.
	 */
	public function test_caps_filter_overrides_default_capabilities() {
		add_filter(
			'gk/block-mcp/agent/caps',
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

	/**
	 * A custom post type with its own capability_type (weDocs-style `docs`) must
	 * be editable by the agent. register_role() derives the role's caps from each
	 * show_in_rest post type's capability object, so the mapped edit/publish
	 * primitives (edit_docs, edit_others_docs, edit_published_docs, publish_docs)
	 * are granted — not just `read`. Pins the BMCP-5 fix where a static post+page
	 * cap set left show_in_rest CPTs read-only despite "all public types allowed".
	 *
	 * delete_docs is NOT granted — the agent never hard-deletes.
	 */
	public function test_register_role_grants_edit_caps_for_custom_capability_cpt() {
		register_post_type(
			'doc',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => 'doc',
				'map_meta_cap'    => true,
			)
		);

		Agent_Provisioner::register_role();
		$role = get_role( Agent_Provisioner::ROLE );

		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( 'edit_docs' ), 'agent must be able to edit docs' );
		$this->assertTrue( $role->has_cap( 'edit_others_docs' ), "agent must be able to edit others' docs" );
		$this->assertTrue( $role->has_cap( 'edit_published_docs' ), 'agent must be able to edit published docs' );
		$this->assertTrue( $role->has_cap( 'publish_docs' ), 'agent must be able to publish docs' );
		$this->assertFalse( $role->has_cap( 'delete_docs' ), 'agent must NOT be able to delete docs' );

		unregister_post_type( 'doc' );
	}

	/**
	 * The WeDocs scenario: the role is created first (e.g. at activation, before
	 * the CPT exists), then a show_in_rest CPT appears later. A subsequent
	 * register_role() (it runs on init priority 99) must ADD the CPT's edit caps
	 * to the already-existing role — otherwise the type stays 403 ("enabled in
	 * settings but still can't edit"). Pins the additive re-assert path.
	 */
	public function test_register_role_reasserts_caps_for_cpt_registered_after_role() {
		// Role created before the CPT exists (mirrors activation).
		Agent_Provisioner::register_role();
		$this->assertFalse(
			get_role( Agent_Provisioner::ROLE )->has_cap( 'edit_others_docs' ),
			'precondition: no docs caps before the CPT is registered'
		);

		// CPT appears later (e.g. weDocs loads on a subsequent request).
		register_post_type(
			'doc',
			array(
				'public'          => true,
				'show_in_rest'    => true,
				'capability_type' => 'doc',
				'map_meta_cap'    => true,
			)
		);

		// Next register_role() re-asserts onto the existing role.
		Agent_Provisioner::register_role();
		$this->assertTrue(
			get_role( Agent_Provisioner::ROLE )->has_cap( 'edit_others_docs' ),
			'caps must be re-asserted for a CPT registered after the role already existed'
		);

		unregister_post_type( 'doc' );
	}

	/**
	 * The public gk/block-mcp/agent/caps filter can unset the
	 * TEMPLATE_EDIT_CAP entry entirely (not merely set it false), and
	 * register_role() must treat that the same as an explicit false —
	 * revoking the cap from an existing role — without emitting a PHP
	 * warning for the missing array key.
	 */
	public function test_register_role_revokes_template_edit_cap_when_filter_unsets_key() {
		update_option( Template_Manager::ALLOW_TEMPLATE_EDITS_OPTION, '1' );
		Agent_Provisioner::register_role();
		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertTrue( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ), 'setup: cap must be granted before the filter unsets it' );

		$filter = static function ( $caps ) {
			unset( $caps[ Agent_Provisioner::TEMPLATE_EDIT_CAP ] );
			return $caps;
		};
		add_filter( 'gk/block-mcp/agent/caps', $filter );

		Agent_Provisioner::register_role();

		remove_filter( 'gk/block-mcp/agent/caps', $filter );

		$role = get_role( Agent_Provisioner::ROLE );
		$this->assertNotNull( $role );
		$this->assertFalse( $role->has_cap( Agent_Provisioner::TEMPLATE_EDIT_CAP ), 'an unset cap-map key must revoke the cap, the safe default for a security-gating capability' );
	}
}
