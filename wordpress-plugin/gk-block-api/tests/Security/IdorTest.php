<?php
/**
 * Insecure Direct Object Reference (IDOR) tests.
 *
 * Attempts to use the block API to read, write, or reference content the
 * current user shouldn't have access to:
 *
 *   - block refs are scoped to one post; resolving a ref from post A
 *     against post B must fail (no cross-post leakage);
 *   - update_post / update_block / mutate against a post the user can't
 *     edit must return rest_cannot_edit (and not silently succeed
 *     against a different post they CAN edit);
 *   - featured_media using an attachment owned by another user is
 *     allowed only when wp_attachment_is_image succeeds (mime check is
 *     not a capability check);
 *   - parent set to a hierarchical post type still has to honor the
 *     parent's existence — must not silently accept ID 0 or invalid IDs;
 *   - resolve_ref does not leak post existence: a ref from a fresh
 *     post against a deleted post returns post_not_found, not a more
 *     informative error that distinguishes "post never existed" from
 *     "post was deleted."
 *
 * @package GravityKit\BlockAPI\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Post_Manager;

class IdorTest extends BlockApiTestCase {

	/** @var Post_Manager */
	private $pm;

	public function set_up(): void {
		parent::set_up();
		$this->pm = new Post_Manager( $this->crud );

		// Default actor: editor (can edit any post).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
	}

	// ── refs are post-scoped ──────────────────────────────────────

	/**
	 * Two posts, each gets refs assigned. A ref that exists on post A
	 * must NOT resolve to anything on post B.
	 */
	public function test_ref_from_post_a_does_not_resolve_against_post_b() {
		$post_a = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_content' => serialize_blocks( array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerHTML'    => '<p>secret-A</p>',
					'innerContent' => array( '<p>secret-A</p>' ),
					'innerBlocks'  => array(),
				),
			) ),
		) );
		$post_b = self::factory()->post->create( array(
			'post_status'  => 'publish',
			'post_content' => serialize_blocks( array(
				array(
					'blockName'    => 'core/paragraph',
					'attrs'        => array(),
					'innerHTML'    => '<p>secret-B</p>',
					'innerContent' => array( '<p>secret-B</p>' ),
					'innerBlocks'  => array(),
				),
			) ),
		) );

		// Trigger ref assignment on A; capture the ref.
		$blocks_a = $this->crud->get_blocks( $post_a );
		$this->assertNotInstanceOf( \WP_Error::class, $blocks_a );
		$ref_a = $blocks_a[0]['ref'];
		$this->assertNotEmpty( $ref_a );

		// Try to resolve A's ref against B — must not find anything.
		$resolved = $this->crud->resolve_ref( $post_b, $ref_a );
		$this->assertInstanceOf( \WP_Error::class, $resolved, 'cross-post ref resolution must NOT succeed' );
		$this->assertSame( 'ref_stale', $resolved->get_error_code() );
	}

	public function test_resolve_ref_against_nonexistent_post_returns_404() {
		// Doesn't leak whether the post once existed and was deleted vs
		// never existed — both produce the same 404.
		$err = $this->crud->resolve_ref( 999_999, 'blk_anything' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertSame( 'post_not_found', $err->get_error_code() );
	}

	public function test_resolve_ref_against_trashed_post_still_404s() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_trash_post( $id );
		// Trashed posts return null from get_post() in default contexts,
		// so the plugin treats them as missing. Either post_not_found or
		// ref_stale is acceptable — the key invariant is no information
		// disclosure beyond "you can't access this."
		$err = $this->crud->resolve_ref( $id, 'blk_anything' );
		$this->assertInstanceOf( \WP_Error::class, $err );
		$this->assertContains(
			$err->get_error_code(),
			array( 'post_not_found', 'ref_stale' ),
			'trashed post should not distinguish itself from a deleted or never-existed post'
		);
	}

	// ── cross-user write access ───────────────────────────────────

	public function test_subscriber_cannot_update_post_via_post_manager() {
		// Editor creates a post.
		$post_id = self::factory()->post->create( array(
			'post_status' => 'publish',
			'post_title'  => 'Editor wrote this',
		) );

		// Switch to a subscriber. They have NO edit_posts cap at all.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$result = $this->pm->update_post( $post_id, array( 'title' => 'Hijacked' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );

		// Title must NOT have changed.
		$this->assertSame( 'Editor wrote this', get_post( $post_id )->post_title );
	}

	public function test_subscriber_cannot_create_post_via_post_manager() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = $this->pm->create_post( array( 'title' => 'Should fail' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
	}

	public function test_anonymous_user_cannot_create_post() {
		wp_set_current_user( 0 ); // anonymous
		$result = $this->pm->create_post( array( 'title' => 'Anonymous attempt' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
	}

	// ── parent / attachment cross-references ───────────────────────

	/**
	 * Setting `parent=99999` — a post ID that doesn't exist. Must not
	 * silently coerce to 0; that would create an orphan that escaped
	 * the validator's intent.
	 */
	public function test_create_post_with_nonexistent_parent_is_rejected() {
		$result = $this->pm->create_post( array(
			'title'     => 'X',
			'post_type' => 'page',
			'parent'    => 999_999,
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_parent', $result->get_error_code() );
	}

	/**
	 * An attachment owned by anyone — the check is mime-based, not
	 * ownership-based. We're verifying the mime gate works;
	 * image-ownership is a deliberate non-constraint (WP doesn't
	 * enforce attachment authorship for featured-image use anyway).
	 */
	public function test_create_post_with_non_image_featured_media_rejected() {
		$pdf_id = self::factory()->post->create( array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'application/pdf',
		) );
		$result = $this->pm->create_post( array(
			'title'          => 'X',
			'featured_media' => $pdf_id,
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_featured_media', $result->get_error_code() );
	}

	// ── update across posts isn't possible via the API ─────────────

	/**
	 * Two posts seeded with identical content. `update_block` against A
	 * must modify A only, never B.
	 */
	public function test_update_block_targets_only_the_named_post() {
		$content = serialize_blocks( array(
			array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerHTML'    => '<p>before</p>',
				'innerContent' => array( '<p>before</p>' ),
				'innerBlocks'  => array(),
			),
		) );
		$post_a = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => $content ) );
		$post_b = self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => $content ) );

		$result = $this->crud->update_block( $post_a, 0, array(), '<p>after</p>' );
		$this->assertNotInstanceOf( \WP_Error::class, $result );

		$this->assertStringContainsString(
			'<p>after</p>',
			(string) get_post_field( 'post_content', $post_a ),
			'update_block on A must change A'
		);
		$this->assertStringContainsString(
			'<p>before</p>',
			(string) get_post_field( 'post_content', $post_b ),
			'update_block on A must NOT change B'
		);
		$this->assertStringNotContainsString(
			'<p>after</p>',
			(string) get_post_field( 'post_content', $post_b )
		);
	}
}
