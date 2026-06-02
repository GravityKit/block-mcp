<?php
/**
 * End-to-end agent REST capability tests.
 *
 * These tests prove that the block_mcp_agent role carries every capability
 * required to actually USE the gk-block-api/v1 REST surface as the provisioned
 * agent identity.  They dispatch real WP_REST_Request objects to the live REST
 * server and assert both the HTTP status code and the persisted side-effect.
 *
 * Earlier test classes (AgentRoleTest, AgentProvisionerTest) exercise the role
 * definition in isolation; AgentAuthTest covers the Application Password /
 * authenticate-filter chain.  This file covers the gap those tests left: a
 * role that looks correct when inspected statically can still return 403 if a
 * capability derivation inside Post_Manager or WP's own meta-cap resolver
 * maps to something the role does not hold.
 *
 * Design principles
 * -----------------
 *  - Each test provisions the agent fresh, sets it as the current user via
 *    wp_set_current_user(), then dispatches a WP_REST_Request.  Auth wiring
 *    (Application Password + the authenticate-filter guard) is covered by
 *    AgentAuthTest; here we isolate role / capability sufficiency only.
 *  - Assertions are specific: HTTP status code AND a persisted database effect.
 *    A test that passes with no real capability check is not a useful test.
 *  - Tests are written so that temporarily removing a specific capability from
 *    Agent_Provisioner::register_role() causes the corresponding test to fail
 *    with a 403 response.  See the mutation-check results in the commit message.
 *
 * @package GravityKit\BlockAPI\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Agent_Provisioner;

/**
 * End-to-end tests asserting the agent role is sufficient for every REST
 * operation the agent identity is expected to perform.
 *
 * A failure here means the role is missing a capability the REST surface
 * requires — not merely that the capability definition looks wrong in
 * isolation.
 */
class AgentRestCapabilityTest extends RestControllerTestCase {

	/**
	 * ID of the provisioned agent user created in set_up().
	 *
	 * @var int
	 */
	private $agent_id;

	/**
	 * ID of an admin user whose posts the agent edits in tests.
	 *
	 * @var int
	 */
	private $admin_id;

	public function set_up(): void {
		$stale = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $stale ) {
			wp_delete_user( $stale->ID );
		}
		delete_option( 'gk_block_api_agent_user_id' );

		parent::set_up();

		remove_role( Agent_Provisioner::ROLE );
		Agent_Provisioner::register_role();

		$this->agent_id = ( new Agent_Provisioner() )->ensure();
		$this->assertIsInt( $this->agent_id, 'Agent provisioning must succeed in set_up()' );

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		// Fire rest_api_init so the plugin bootstrap registers all routes on the
		// global REST server — mirrors the pattern used in PostVisibilityTest.
		do_action( 'rest_api_init' );
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );

		$agent = get_user_by( 'login', Agent_Provisioner::LOGIN );
		if ( $agent ) {
			wp_delete_user( $agent->ID );
		}
		remove_role( Agent_Provisioner::ROLE );
		delete_option( 'gk_block_api_agent_user_id' );

		parent::tear_down();
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Dispatch a request through the real REST server and return the response.
	 *
	 * @param \WP_REST_Request $request Fully configured request.
	 *
	 * @return \WP_REST_Response
	 */
	private function dispatch( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_get_server()->dispatch( $request );
	}

	// =========================================================================
	// Tests
	// =========================================================================

	/**
	 * The agent must be able to update a block on a post authored by a different
	 * user (the admin).
	 *
	 * This pins the edit_others_posts + edit_published_posts capability pair.
	 * Without edit_others_posts the PATCH endpoint returns 403 because
	 * check_post_edit_permission() maps to the edit_post meta-cap which requires
	 * edit_others_posts when the post author is different from the current user.
	 *
	 * The test confirms both the HTTP 200 status and that the changed text was
	 * actually persisted to the database, ruling out a false-positive where the
	 * cap check is bypassed without a real write occurring.
	 */
	public function test_agent_can_edit_a_block_on_another_users_post(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_author'  => $this->admin_id,
				'post_title'   => 'Admin post for agent capability test',
				'post_content' => '<!-- wp:paragraph --><p>Original text</p><!-- /wp:paragraph -->',
			)
		);

		wp_set_current_user( $this->agent_id );

		$request = new \WP_REST_Request( 'PATCH', '/gk-block-api/v1/posts/' . $post_id . '/blocks/0' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'innerHTML' => '<p>Agent-updated text</p>',
				)
			)
		);

		$response = $this->dispatch( $request );

		$this->assertNotContains(
			$response->get_status(),
			array( 401, 403 ),
			sprintf(
				'Agent must not receive a 401/403 when editing another user\'s published post; got %d. Role must include edit_others_posts + edit_published_posts.',
				$response->get_status()
			)
		);
		$this->assertSame(
			200,
			$response->get_status(),
			'PATCH /posts/{id}/blocks/{index} must return 200 for the agent on another user\'s post.'
		);

		$saved = get_post_field( 'post_content', $post_id );
		$this->assertStringContainsString(
			'Agent-updated text',
			(string) $saved,
			'The block change must be persisted to the database, not just acknowledged.'
		);
	}

	/**
	 * The agent must be able to create and immediately publish a page.
	 *
	 * This pins the publish_pages capability.  For the page post type WordPress
	 * maps the publish_posts meta-cap to the literal capability publish_pages.
	 * Without it, Post_Manager::create_post() returns WP_Error('rest_cannot_publish')
	 * which the REST controller surfaces as HTTP 403.
	 *
	 * The test also verifies the persisted post_status is 'publish', ruling out
	 * a scenario where the create succeeds as a draft despite the payload
	 * requesting publish status.
	 *
	 * The gk_block_api_post_types_allowlist option is temporarily set to include
	 * 'page' to ensure the allow-list gate does not interfere with what is
	 * effectively a default-allowed post type.
	 */
	public function test_agent_can_create_and_publish_a_page(): void {
		update_option( 'gk_block_api_post_types_allowlist', array( 'page' ) );

		wp_set_current_user( $this->agent_id );

		$request = new \WP_REST_Request( 'POST', '/gk-block-api/v1/posts' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'post_type' => 'page',
					'status'    => 'publish',
					'title'     => 'Agent capability test page',
				)
			)
		);

		$response = $this->dispatch( $request );

		delete_option( 'gk_block_api_post_types_allowlist' );

		$data = $response->get_data();
		$code = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';

		$this->assertNotSame(
			403,
			$response->get_status(),
			sprintf(
				'Agent must not receive 403 on POST /posts with post_type:page + status:publish. Error code: %s — role must include publish_pages.',
				$code
			)
		);
		$this->assertNotSame(
			'rest_cannot_publish',
			$code,
			'rest_cannot_publish error code indicates the publish_pages capability is missing from the agent role.'
		);

		$this->assertSame(
			200,
			$response->get_status(),
			'POST /posts with status:publish must return 200 for the agent.'
		);

		$created_id = is_array( $data ) ? ( (int) ( $data['id'] ?? 0 ) ) : 0;
		$this->assertGreaterThan( 0, $created_id, 'Response must include the created post ID.' );

		$persisted_status = get_post_field( 'post_status', $created_id );
		$this->assertSame(
			'publish',
			$persisted_status,
			'The page must be persisted with post_status=publish, not silently downgraded to draft.'
		);
	}

	/**
	 * The agent must pass the upload_files permission gate on the media route.
	 *
	 * Asserting the permission_callback directly avoids filesystem / multipart
	 * flakiness while still verifying the capability check the media endpoint
	 * relies on.  A real upload would pass through the same gate.
	 *
	 * check_upload_permissions() in REST_Controller returns a WP_Error when the
	 * current user lacks upload_files, and true otherwise.  This test sets the
	 * agent as the current user before invoking it and expects true.
	 */
	public function test_agent_upload_permission_callback_returns_true(): void {
		wp_set_current_user( $this->agent_id );

		$result = $this->controller->check_upload_permissions();

		$this->assertTrue(
			$result,
			'check_upload_permissions() must return true for the agent — role must include upload_files.'
		);
		$this->assertNotInstanceOf(
			\WP_Error::class,
			$result,
			'Agent must not receive a WP_Error from check_upload_permissions(); upload_files capability may be missing.'
		);
	}
}
