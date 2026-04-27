<?php
/**
 * Tests for the Post_Manager class.
 *
 * Covers create_post and update_post: validation, error paths, status
 * transitions, term assignment, parent validation, capability checks.
 *
 * Real WordPress integration (actual post hooks, real revisions, etc.)
 * is exercised by the gkclone E2E smoke (scripts/e2e-gkclone.mjs).
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Post_Manager;
use GravityKit\BlockAPI\Preferences;

class PostManagerTest extends \PHPUnit\Framework\TestCase {

	/** @var Post_Manager */
	private $pm;

	protected function setUp(): void {
		// Reset all globals between tests.
		$GLOBALS['_gk_test_posts']         = array();
		$GLOBALS['_gk_test_post_meta']     = array();
		$GLOBALS['_gk_test_post_terms']    = array();
		$GLOBALS['_gk_test_terms']         = array();
		$GLOBALS['_gk_test_caps']          = array();
		$GLOBALS['_gk_test_user_id']       = 1;
		$GLOBALS['_gk_test_next_post_id']  = 1000;
		$GLOBALS['_gk_test_next_term_id']  = 1;

		// Register a few core blocks for tests that pass `blocks` input.
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'core/heading', 'core/paragraph', 'ugb/heading' ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$this->pm = new Post_Manager( new Preferences() );
	}

	// ── create_post: required + format ───────────────────────────────

	public function test_create_post_requires_title() {
		$result = $this->pm->create_post( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_title', $result->get_error_code() );
	}

	public function test_create_post_rejects_empty_title() {
		$result = $this->pm->create_post( array( 'title' => '' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_title', $result->get_error_code() );
	}

	public function test_create_post_with_title_only() {
		$result = $this->pm->create_post( array( 'title' => 'Hello' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['success'] );
		$this->assertSame( 'Hello', $result['title'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'post', $result['post_type'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertNotEmpty( $result['permalink'] );
		$this->assertNotEmpty( $result['edit_link'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	// ── create_post: post type allow-list ────────────────────────────

	public function test_create_post_rejects_invalid_post_type() {
		$result = $this->pm->create_post( array( 'title' => 'X', 'post_type' => 'nope_xyz' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
	}

	public function test_create_post_allows_page_type() {
		$result = $this->pm->create_post( array( 'title' => 'X', 'post_type' => 'page' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'page', $result['post_type'] );
	}

	// ── create_post: status allow-list ───────────────────────────────

	public function test_create_post_rejects_status_trash() {
		$result = $this->pm->create_post( array( 'title' => 'X', 'status' => 'trash' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	public function test_create_post_rejects_unknown_status() {
		$result = $this->pm->create_post( array( 'title' => 'X', 'status' => 'banana' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	public function test_create_post_publish_requires_publish_cap() {
		$GLOBALS['_gk_test_caps']['publish_posts'] = false;
		$result = $this->pm->create_post( array( 'title' => 'X', 'status' => 'publish' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
	}

	// ── create_post: capability denial ───────────────────────────────

	public function test_create_post_denied_when_create_cap_missing() {
		$GLOBALS['_gk_test_caps']['edit_posts'] = false;
		$result = $this->pm->create_post( array( 'title' => 'X' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
	}

	// ── create_post: content vs blocks mutex ─────────────────────────

	public function test_create_post_rejects_content_and_blocks_together() {
		$result = $this->pm->create_post( array(
			'title'   => 'X',
			'content' => 'hi',
			'blocks'  => array( array( 'name' => 'core/paragraph' ) ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mutually_exclusive', $result->get_error_code() );
	}

	public function test_create_post_with_blocks_serializes_content() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array( 'name' => 'core/heading', 'attributes' => array( 'level' => 2 ), 'innerHTML' => '<h2>Hi</h2>' ),
			),
		) );
		$this->assertIsArray( $result );
		$content = $GLOBALS['_gk_test_posts'][ $result['id'] ]->post_content;
		// serialize_blocks stub returns JSON; just verify content is non-empty.
		$this->assertNotEmpty( $content );
	}

	public function test_create_post_rejects_legacy_block() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array( array( 'name' => 'ugb/heading' ) ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'legacy_block', $result->get_error_code() );
	}

	public function test_create_post_rejects_unregistered_block() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array( array( 'name' => 'fake/notregistered' ) ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'unregistered_block', $result->get_error_code() );
	}

	// ── create_post: terms ───────────────────────────────────────────

	public function test_create_post_with_categories() {
		$cat = _gk_test_make_term( 'category', 'Docs' );
		$result = $this->pm->create_post( array(
			'title'      => 'X',
			'categories' => array( $cat->term_id ),
		) );
		$this->assertIsArray( $result );
		$assigned = $GLOBALS['_gk_test_post_terms'][ $result['id'] ]['category'];
		$this->assertSame( array( $cat->term_id ), $assigned );
	}

	public function test_create_post_rejects_unknown_category() {
		$result = $this->pm->create_post( array(
			'title'      => 'X',
			'categories' => array( 999999 ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_term', $result->get_error_code() );
	}

	public function test_create_post_with_custom_taxonomy() {
		$GLOBALS['_gk_test_taxonomies']['doc_section'] = array(
			'object_types' => array( 'post' ),
			'hierarchical' => true,
		);
		$term = _gk_test_make_term( 'doc_section', 'Setup' );
		$result = $this->pm->create_post( array(
			'title' => 'X',
			'terms' => array( 'doc_section' => array( $term->term_id ) ),
		) );
		$this->assertIsArray( $result );
		$this->assertSame( array( $term->term_id ), $GLOBALS['_gk_test_post_terms'][ $result['id'] ]['doc_section'] );
	}

	public function test_create_post_rejects_taxonomy_not_registered_for_type() {
		$GLOBALS['_gk_test_taxonomies']['doc_section'] = array(
			'object_types' => array( 'page' ), // not for 'post'
			'hierarchical' => true,
		);
		$term = _gk_test_make_term( 'doc_section', 'Setup' );
		$result = $this->pm->create_post( array(
			'title' => 'X',
			'terms' => array( 'doc_section' => array( $term->term_id ) ),
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	// ── create_post: parent validation ───────────────────────────────

	public function test_create_post_rejects_parent_on_non_hierarchical_type() {
		// 'post' is non-hierarchical; setting parent should fail.
		$other = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'P' ) );
		$result = $this->pm->create_post( array( 'title' => 'X', 'parent' => $other ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_parent', $result->get_error_code() );
	}

	public function test_create_page_with_valid_parent() {
		$parent = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Parent' ) );
		$result = $this->pm->create_post( array(
			'title'     => 'Child',
			'post_type' => 'page',
			'parent'    => $parent,
		) );
		$this->assertIsArray( $result );
		$this->assertSame( $parent, $GLOBALS['_gk_test_posts'][ $result['id'] ]->post_parent );
	}

	public function test_create_page_rejects_parent_of_wrong_type() {
		$post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'P' ) );
		$result = $this->pm->create_post( array(
			'title'     => 'Child',
			'post_type' => 'page',
			'parent'    => $post,
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_parent', $result->get_error_code() );
	}

	// ── create_post: featured_media ──────────────────────────────────

	public function test_create_post_rejects_non_image_featured_media() {
		$id = wp_insert_post( array(
			'post_type'      => 'attachment',
			'post_title'     => 'doc',
			'post_mime_type' => 'application/pdf',
		) );
		$result = $this->pm->create_post( array( 'title' => 'X', 'featured_media' => $id ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_featured_media', $result->get_error_code() );
	}

	public function test_create_post_with_image_featured_media() {
		$id = wp_insert_post( array(
			'post_type'      => 'attachment',
			'post_title'     => 'pic',
			'post_mime_type' => 'image/png',
		) );
		$result = $this->pm->create_post( array( 'title' => 'X', 'featured_media' => $id ) );
		$this->assertIsArray( $result );
		$this->assertSame( $id, $GLOBALS['_gk_test_post_meta'][ $result['id'] ]['_thumbnail_id'] );
	}

	// ── update_post: missing post / cap ──────────────────────────────

	public function test_update_post_404_for_missing() {
		$result = $this->pm->update_post( 99999, array( 'title' => 'X' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	public function test_update_post_denied_when_edit_cap_missing() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		$GLOBALS['_gk_test_caps']['edit_post'] = false;
		$result = $this->pm->update_post( $id, array( 'title' => 'New' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
	}

	// ── update_post: title / status ──────────────────────────────────

	public function test_update_post_changes_title() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Old', 'post_status' => 'draft' ) );
		$result = $this->pm->update_post( $id, array( 'title' => 'New' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'New', $GLOBALS['_gk_test_posts'][ $id ]->post_title );
	}

	public function test_update_post_publish_transitions() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'publish' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['transitioned_to_publish'] );
		$this->assertSame( 'publish', $GLOBALS['_gk_test_posts'][ $id ]->post_status );
	}

	public function test_update_post_publish_requires_cap() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'X' ) );
		$GLOBALS['_gk_test_caps']['publish_posts'] = false;
		$result = $this->pm->update_post( $id, array( 'status' => 'publish' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
	}

	public function test_update_post_to_trash() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'trash', $GLOBALS['_gk_test_posts'][ $id ]->post_status );
	}

	public function test_update_post_untrash() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		wp_trash_post( $id );
		$this->assertSame( 'trash', $GLOBALS['_gk_test_posts'][ $id ]->post_status );

		$result = $this->pm->update_post( $id, array( 'status' => 'draft' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['untrashed'] );
		$this->assertSame( 'draft', $GLOBALS['_gk_test_posts'][ $id ]->post_status );
	}

	public function test_update_post_rejects_invalid_status() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'banana' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	// ── update_post: parent ──────────────────────────────────────────

	public function test_update_post_cycle_parent_rejected() {
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'parent' => $id ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'cycle_parent', $result->get_error_code() );
	}

	public function test_update_post_changes_parent() {
		$parent = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'P' ) );
		$child  = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'C' ) );
		$result = $this->pm->update_post( $child, array( 'parent' => $parent ) );
		$this->assertIsArray( $result );
		$this->assertSame( $parent, $GLOBALS['_gk_test_posts'][ $child ]->post_parent );
	}

	// ── update_post: featured_media ──────────────────────────────────

	public function test_update_post_clears_featured_media_with_zero() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		$GLOBALS['_gk_test_post_meta'][ $id ]['_thumbnail_id'] = 999;
		$result = $this->pm->update_post( $id, array( 'featured_media' => 0 ) );
		$this->assertIsArray( $result );
		$this->assertArrayNotHasKey( '_thumbnail_id', $GLOBALS['_gk_test_post_meta'][ $id ] ?? array() );
	}

	// ── update_post: terms ───────────────────────────────────────────

	public function test_update_post_assigns_categories() {
		$id  = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		$cat = _gk_test_make_term( 'category', 'Tutorials' );
		$result = $this->pm->update_post( $id, array( 'categories' => array( $cat->term_id ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( array( $cat->term_id ), $GLOBALS['_gk_test_post_terms'][ $id ]['category'] );
	}
}
