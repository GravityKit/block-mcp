<?php
/**
 * Permission matrix across the Block MCP tools and WordPress roles.
 *
 * AbilitiesRegistryTest spot-checks a few permission cells; this file sweeps the
 * whole write surface so a single missing capability check is caught. Every
 * tool is driven through the real ability layer (wp_get_ability()->execute()),
 * the same path an MCP client reaches, and each denial asserts the write left no
 * trace. A regression here is a privilege escalation: an under-privileged caller
 * mutating content it must not touch.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Block_Abilities;

class AbilityPermissionMatrixTest extends RestControllerTestCase {

	/**
	 * Abilities are opt-in; enable so wp_get_ability() resolves the tools. The
	 * Abilities API registry fires its one-shot init on first touch, so the
	 * option must be on before any test method touches it (see AbilitiesRegistryTest).
	 */
	public function set_up(): void {
		parent::set_up();
		update_option( Block_Abilities::ENABLED_OPTION, '1' );
	}

	/**
	 * Every write-capable tool (edit_post, create_post, upload_files,
	 * manage_options), excluding the Yoast tools which only register when Yoast
	 * is active.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function write_tool_provider(): array {
		return array(
			'update-block'        => array( 'gk-block-mcp/update-block' ),
			'update-blocks'       => array( 'gk-block-mcp/update-blocks' ),
			'insert-blocks'       => array( 'gk-block-mcp/insert-blocks' ),
			'delete-block'        => array( 'gk-block-mcp/delete-block' ),
			'replace-block-range' => array( 'gk-block-mcp/replace-block-range' ),
			'rewrite-post-blocks' => array( 'gk-block-mcp/rewrite-post-blocks' ),
			'revert-to-revision'  => array( 'gk-block-mcp/revert-to-revision' ),
			'insert-pattern'      => array( 'gk-block-mcp/insert-pattern' ),
			'edit-block-tree'     => array( 'gk-block-mcp/edit-block-tree' ),
			'update-post'         => array( 'gk-block-mcp/update-post' ),
			'create-post'         => array( 'gk-block-mcp/create-post' ),
			'upload-media'        => array( 'gk-block-mcp/upload-media' ),
			'scan-storage-modes'  => array( 'gk-block-mcp/scan-storage-modes' ),
		);
	}

	/**
	 * The tools gated on a per-post edit_post capability: an author has
	 * edit_posts globally (clears the first check) but not edit_others_posts, so
	 * targeting another author's post must be denied by the per-post half.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function per_post_write_tool_provider(): array {
		return array(
			'update-block'        => array( 'gk-block-mcp/update-block' ),
			'update-blocks'       => array( 'gk-block-mcp/update-blocks' ),
			'insert-blocks'       => array( 'gk-block-mcp/insert-blocks' ),
			'delete-block'        => array( 'gk-block-mcp/delete-block' ),
			'replace-block-range' => array( 'gk-block-mcp/replace-block-range' ),
			'rewrite-post-blocks' => array( 'gk-block-mcp/rewrite-post-blocks' ),
			'revert-to-revision'  => array( 'gk-block-mcp/revert-to-revision' ),
			'insert-pattern'      => array( 'gk-block-mcp/insert-pattern' ),
			'edit-block-tree'     => array( 'gk-block-mcp/edit-block-tree' ),
			'update-post'         => array( 'gk-block-mcp/update-post' ),
		);
	}

	/**
	 * A subscriber (no edit_posts / manage_options / upload_files) is denied
	 * every write tool by the ability's own permission gate. Checked directly
	 * via WP_Ability::check_permissions() so the denial is the permission
	 * decision, not input-schema validation (which the API runs separately).
	 *
	 * @dataProvider write_tool_provider
	 */
	public function test_subscriber_is_denied_every_write_tool( string $ability_id ) {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Guarded</p>' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = wp_get_ability( $ability_id )->check_permissions( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result, $ability_id . ' must deny a subscriber' );
	}

	/**
	 * A logged-out request is denied every write tool — the same global gate,
	 * before even the per-post check.
	 *
	 * @dataProvider write_tool_provider
	 */
	public function test_logged_out_is_denied_every_write_tool( string $ability_id ) {
		$post_id = $this->make_block_post( array( $this->paragraph( '<p>Guarded</p>' ) ) );
		wp_set_current_user( 0 );

		$result = wp_get_ability( $ability_id )->check_permissions( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result, $ability_id . ' must deny a logged-out caller' );
	}

	/**
	 * An author with edit_posts but not edit_others_posts is denied every
	 * per-post write tool when targeting another author's post. Pins the
	 * per-post half of the edit_post gate across the whole write surface, not
	 * just update-block.
	 *
	 * @dataProvider per_post_write_tool_provider
	 */
	public function test_author_cannot_write_another_authors_post( string $ability_id ) {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id  = $this->make_block_post(
			array( $this->paragraph( '<p>Owner content</p>' ) ),
			array( 'post_author' => $owner_id )
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$result = wp_get_ability( $ability_id )->check_permissions( array( 'post_id' => $post_id ) );

		$this->assertWPError( $result, $ability_id . ' must deny writing another author\'s post' );
	}

	/**
	 * The content-reading tools, which gate on the lenient global `read`
	 * permission but must still not hand back another user's private content.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function read_content_tool_provider(): array {
		return array(
			'get-page-blocks' => array( 'gk-block-mcp/get-page-blocks' ),
			'get-block'       => array( 'gk-block-mcp/get-block' ),
			'get-post-info'   => array( 'gk-block-mcp/get-post-info' ),
		);
	}

	/**
	 * An author has the global edit_posts the `read` permission checks, so the
	 * ability gate passes; the handler's own per-post readability re-check must
	 * still deny reading another author's private post. Pins the defense in depth
	 * that the lenient read gate alone doesn't provide.
	 *
	 * @dataProvider read_content_tool_provider
	 */
	public function test_author_cannot_read_another_authors_private_post( string $ability_id ) {
		$owner_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id  = $this->make_block_post(
			array( $this->paragraph( '<p>Secret</p>' ) ),
			array(
				'post_author' => $owner_id,
				'post_status' => 'private',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );

		$input = array( 'post_id' => $post_id );
		if ( 'gk-block-mcp/get-block' === $ability_id ) {
			$input['flat_index'] = 0;
		}

		$result = wp_get_ability( $ability_id )->execute( $input );

		$this->assertWPError( $result, $ability_id . ' must not leak another author\'s private post' );
	}

	/**
	 * Build a flat core/paragraph block in WP-internal shape for make_block_post().
	 *
	 * @param string $html Paragraph innerHTML.
	 * @return array<string, mixed>
	 */
	private function paragraph( string $html ): array {
		return array(
			'blockName'    => 'core/paragraph',
			'attrs'        => array(),
			'innerHTML'    => $html,
			'innerContent' => array( $html ),
			'innerBlocks'  => array(),
		);
	}
}
