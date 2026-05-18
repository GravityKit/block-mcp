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
use GravityKit\BlockAPI\Block_CRUD;
use GravityKit\BlockAPI\Block_Inventory;
use GravityKit\BlockAPI\Block_Safety;
use GravityKit\BlockAPI\HTML_Transformer;

class PostManagerTest extends WP_UnitTestCase {

	/** @var Post_Manager */
	private $pm;

	public function set_up(): void {
		parent::set_up();
		// Default test actor: an editor (has edit_posts + publish_posts).
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// Register a few core blocks for tests that pass `blocks` input.
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'core/heading', 'core/paragraph', 'ugb/heading' ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$preferences = new Preferences();
		$block_crud  = new Block_CRUD( $preferences, new Block_Safety(), new HTML_Transformer(), new Block_Inventory() );
		$this->pm    = new Post_Manager( $block_crud );
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
		// Contributor has edit_posts but not publish_posts.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$result = $this->pm->create_post( array( 'title' => 'X', 'status' => 'publish' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
	}

	// ── create_post: capability denial ───────────────────────────────

	public function test_create_post_denied_when_create_cap_missing() {
		// Subscriber has no edit_posts cap.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
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
		$content = (string) get_post_field( 'post_content', $result['id'] );
		$this->assertNotEmpty( $content );
		// With real WP grammar the saved content is the canonical block-comment
		// markup, not the in-memory shape.
		$this->assertStringContainsString( '<!-- wp:heading', $content );
	}

	// ── create_post: innerHTML round-trip regression ─────────────────

	public function test_create_post_leaf_block_innerhtml_becomes_inner_content() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Hello world</p>' ),
			),
		) );
		$this->assertIsArray( $result );

		$blocks = $this->stored_blocks( $result['id'] );

		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertSame( '<p>Hello world</p>', $blocks[0]['innerHTML'] );
		$this->assertSame( array( '<p>Hello world</p>' ), $blocks[0]['innerContent'] );
		$this->assertSame( array(), $blocks[0]['innerBlocks'] );
	}

	/**
	 * Read the stored block tree for the given post ID via real WP — the
	 * post_content round-trips through serialize_blocks() at save time and
	 * parse_blocks() here, exactly as a production caller would see.
	 */
	private function stored_blocks( int $post_id ): array {
		return parse_blocks( (string) get_post_field( 'post_content', $post_id ) );
	}

	public function test_create_post_container_inner_blocks_recursed_and_split() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array(
					'name'        => 'core/heading',
					'attributes'  => array( 'level' => 2 ),
					'innerHTML'   => '<h2>Heading</h2>',
				),
				array(
					'name'        => 'core/paragraph',
					'innerHTML'   => '<ul class="wp-block-list"></ul>',
					'innerBlocks' => array(
						array( 'name' => 'core/paragraph', 'innerHTML' => '<p>A</p>' ),
						array( 'name' => 'core/paragraph', 'innerHTML' => '<p>B</p>' ),
					),
				),
			),
		) );
		$this->assertIsArray( $result );

		$blocks = $this->stored_blocks( $result['id'] );
		$this->assertCount( 2, $blocks );

		// Container block: children are normalized to WP internal shape (blockName,
		// not name) and the wrapper HTML is split into opening/null-per-child/closing.
		$container = $blocks[1];
		$this->assertCount( 2, $container['innerBlocks'] );
		$this->assertSame( 'core/paragraph', $container['innerBlocks'][0]['blockName'] );
		$this->assertSame( '<p>A</p>', $container['innerBlocks'][0]['innerHTML'] );
		$this->assertSame( 'core/paragraph', $container['innerBlocks'][1]['blockName'] );

		$this->assertSame(
			array( '<ul class="wp-block-list">', null, null, '</ul>' ),
			$container['innerContent'],
			'Container innerContent must interleave a null placeholder per child.'
		);
		// At save time the container's innerHTML is '' (the wrapper lives in
		// innerContent slices). When parse_blocks reads it back, WordPress'
		// parser reconstructs innerHTML by concatenating the non-null pieces
		// — so the post-round-trip value is the wrapper concatenation. Both
		// are valid; this test pins the round-trip shape, which is what real
		// callers actually observe.
		$this->assertSame( '<ul class="wp-block-list"></ul>', $container['innerHTML'] );
	}

	public function test_create_post_deeply_nested_inner_blocks_normalized() {
		// Register the blocks needed for this nesting (idempotent under
		// real WP, which auto-registers core blocks at init).
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'core/group', 'core/list', 'core/list-item' ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array(
					'name'        => 'core/group',
					'innerHTML'   => '<div class="wp-block-group"></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/list',
							'innerHTML'   => '<ul></ul>',
							'innerBlocks' => array(
								array( 'name' => 'core/list-item', 'innerHTML' => '<li>One</li>' ),
							),
						),
					),
				),
			),
		) );
		$this->assertIsArray( $result );

		$blocks = $this->stored_blocks( $result['id'] );

		// Depth 0 — group with one child.
		$this->assertSame( 'core/group', $blocks[0]['blockName'] );
		$this->assertCount( 1, $blocks[0]['innerBlocks'] );
		// Depth 1 — list with one child.
		$list = $blocks[0]['innerBlocks'][0];
		$this->assertSame( 'core/list', $list['blockName'] );
		$this->assertCount( 1, $list['innerBlocks'] );
		// Depth 2 — list-item leaf.
		$item = $list['innerBlocks'][0];
		$this->assertSame( 'core/list-item', $item['blockName'] );
		$this->assertSame( '<li>One</li>', $item['innerHTML'] );
		$this->assertSame( array( '<li>One</li>' ), $item['innerContent'] );
	}

	// ── create_post: XSS sanitization across the tree ────────────────

	public function test_create_post_strips_script_tag_from_leaf_inner_html() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p>Hi</p><script>alert(1)</script>' ),
			),
		) );
		$blocks = $this->stored_blocks( $result['id'] );

		// Real wp_kses_post strips the <script> tag markers but leaves the
		// text content ("alert(1)") as inert text. Assert the security
		// invariant: no executable <script tag survives at any storage layer.
		$this->assertStringNotContainsStringIgnoringCase( '<script', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $blocks[0]['innerContent'][0] );
		$this->assertStringNotContainsStringIgnoringCase( '<script', (string) get_post_field( 'post_content', $result['id'] ) );
	}

	public function test_create_post_strips_event_handler_attribute() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p><img src="x" onerror="alert(1)"></p>' ),
			),
		) );
		$blocks = $this->stored_blocks( $result['id'] );

		$this->assertStringNotContainsStringIgnoringCase( 'onerror', $blocks[0]['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( 'alert(1)', $blocks[0]['innerHTML'] );
		// The benign <img> tag itself should survive.
		$this->assertStringContainsString( '<img src="x"', $blocks[0]['innerHTML'] );
	}

	public function test_create_post_neutralizes_javascript_url() {
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array( 'name' => 'core/paragraph', 'innerHTML' => '<p><a href="javascript:alert(1)">x</a></p>' ),
			),
		) );
		$blocks = $this->stored_blocks( $result['id'] );

		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $blocks[0]['innerHTML'] );
		$this->assertStringContainsString( '<a ', $blocks[0]['innerHTML'], 'The <a> wrapper survives; only the javascript: URL is neutralized.' );
	}

	public function test_create_post_strips_xss_in_container_split_innerhtml() {
		// Regression guard for the strpos-based wrapper split in
		// normalize_block_def_for_insert. kses runs before the split, so even
		// if the wrapper carries a <script>, the executable tag markers must
		// not resurface in innerContent's opening or closing slice.
		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array(
					'name'        => 'core/paragraph',
					'innerHTML'   => '<script>alert(1)</script><div class="wp-block-group"></div>',
					'innerBlocks' => array(
						array( 'name' => 'core/paragraph', 'innerHTML' => '<p>child</p>' ),
					),
				),
			),
		) );
		$container = $this->stored_blocks( $result['id'] )[0];

		foreach ( $container['innerContent'] as $piece ) {
			if ( null === $piece ) {
				continue;
			}
			$this->assertStringNotContainsStringIgnoringCase( '<script', $piece );
		}
	}

	public function test_create_post_strips_xss_from_nested_inner_blocks() {
		$registry = \WP_Block_Type_Registry::get_instance();
		foreach ( array( 'core/group', 'core/list', 'core/list-item' ) as $name ) {
			if ( ! $registry->is_registered( $name ) ) {
				$registry->register( $name );
			}
		}

		$result = $this->pm->create_post( array(
			'title'  => 'X',
			'blocks' => array(
				array(
					'name'        => 'core/group',
					'innerHTML'   => '<div></div>',
					'innerBlocks' => array(
						array(
							'name'        => 'core/list',
							'innerHTML'   => '<ul></ul>',
							'innerBlocks' => array(
								array(
									'name'      => 'core/list-item',
									'innerHTML' => '<li onclick="alert(1)">deep <script>alert(2)</script></li>',
								),
							),
						),
					),
				),
			),
		) );
		$leaf = $this->stored_blocks( $result['id'] )[0]['innerBlocks'][0]['innerBlocks'][0];

		// Executable surface gone: no <script> tag, no on*= handler, and the
		// onclick="alert(1)" attribute value is removed with the attribute.
		// The text "alert(2)" inside the stripped <script> survives as inert
		// text, matching real wp_kses behavior.
		$this->assertStringNotContainsStringIgnoringCase( 'onclick', $leaf['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( '<script', $leaf['innerHTML'] );
		$this->assertStringNotContainsStringIgnoringCase( 'alert(1)', $leaf['innerHTML'] );
		$this->assertStringContainsString( 'deep', $leaf['innerHTML'] );
		$this->assertSame( $leaf['innerHTML'], $leaf['innerContent'][0] );
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
		// Delegated to Block_CRUD::validate_block_def, which uses 'invalid_block'.
		$this->assertSame( 'invalid_block', $result->get_error_code() );
	}

	// ── create_post: terms ───────────────────────────────────────────

	public function test_create_post_with_categories() {
		$cat = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Docs' ) );
		$result = $this->pm->create_post( array(
			'title'      => 'X',
			'categories' => array( $cat->term_id ),
		) );
		$this->assertIsArray( $result );
		$assigned = wp_get_object_terms( $result['id'], 'category', array( 'fields' => 'ids' ) );
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
		register_taxonomy( 'doc_section', 'post', array( 'hierarchical' => true ) );
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'doc_section', 'name' => 'Setup' ) );
		$result = $this->pm->create_post( array(
			'title' => 'X',
			'terms' => array( 'doc_section' => array( $term->term_id ) ),
		) );
		$this->assertIsArray( $result );
		$this->assertSame(
			array( $term->term_id ),
			wp_get_object_terms( $result['id'], 'doc_section', array( 'fields' => 'ids' ) )
		);
	}

	public function test_create_post_rejects_taxonomy_not_registered_for_type() {
		register_taxonomy( 'doc_section', 'page', array( 'hierarchical' => true ) );
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'doc_section', 'name' => 'Setup' ) );
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
		$this->assertSame( $parent, get_post( $result['id'] )->post_parent );
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
		// wp_attachment_is_image() reads _wp_attached_file; seed it so the
		// validator's primary check passes (rather than falling back to MIME).
		update_post_meta( $id, '_wp_attached_file', 'pic.png' );
		$result = $this->pm->create_post( array( 'title' => 'X', 'featured_media' => $id ) );
		$this->assertIsArray( $result );
		$this->assertSame( $id, (int) get_post_meta( $result['id'], '_thumbnail_id', true ) );
	}

	// ── update_post: missing post / cap ──────────────────────────────

	public function test_update_post_404_for_missing() {
		$result = $this->pm->update_post( 99999, array( 'title' => 'X' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'post_not_found', $result->get_error_code() );
	}

	public function test_update_post_denied_when_edit_cap_missing() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		// Subscriber cannot edit posts authored by someone else.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$result = $this->pm->update_post( $id, array( 'title' => 'New' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
	}

	// ── update_post: title / status ──────────────────────────────────

	public function test_update_post_changes_title() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Old', 'post_status' => 'draft' ) );
		$result = $this->pm->update_post( $id, array( 'title' => 'New' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'New', get_post( $id )->post_title );
	}

	public function test_update_post_publish_transitions() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'publish' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['transitioned_to_publish'] );
		$this->assertSame( 'publish', get_post( $id )->post_status );
	}

	public function test_update_post_publish_requires_cap() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'X' ) );
		// Author can edit but author cannot publish posts they didn't create.
		// Use contributor: edit_posts yes, publish_posts no.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'publish' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		// Contributor can't edit others' posts either, so the failure may be
		// either rest_cannot_publish or rest_cannot_edit — both are valid
		// authorization denials for this scenario.
		$this->assertContains(
			$result->get_error_code(),
			array( 'rest_cannot_publish', 'rest_cannot_edit' )
		);
	}

	public function test_update_post_to_trash() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'trash', get_post( $id )->post_status );
	}

	public function test_update_post_untrash() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		wp_trash_post( $id );
		$this->assertSame( 'trash', get_post( $id )->post_status );

		$result = $this->pm->update_post( $id, array( 'status' => 'draft' ) );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['untrashed'] );
		$this->assertSame( 'draft', get_post( $id )->post_status );
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
		$this->assertSame( $parent, get_post( $child )->post_parent );
	}

	// ── update_post: featured_media ──────────────────────────────────

	public function test_update_post_clears_featured_media_with_zero() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		update_post_meta( $id, '_thumbnail_id', 999 );
		$result = $this->pm->update_post( $id, array( 'featured_media' => 0 ) );
		$this->assertIsArray( $result );
		// After clear, the meta is removed (or empty) — real WP delete_post_meta
		// causes get_post_meta to return ''.
		$this->assertEmpty( get_post_meta( $id, '_thumbnail_id', true ) );
	}

	// ── update_post: terms ───────────────────────────────────────────

	public function test_update_post_assigns_categories() {
		$id  = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		$cat = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Tutorials' ) );
		$result = $this->pm->update_post( $id, array( 'categories' => array( $cat->term_id ) ) );
		$this->assertIsArray( $result );
		$this->assertSame(
			array( $cat->term_id ),
			wp_get_object_terms( $id, 'category', array( 'fields' => 'ids' ) )
		);
	}

	// ── update_post: mixed trash payload guard ───────────────────────

	public function test_update_post_rejects_status_trash_with_title() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash', 'title' => 'New' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'mixed_trash_payload', $result->get_error_code() );
		// Post must NOT have been trashed or renamed.
		$post = get_post( $id );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'X', $post->post_title );
	}

	public function test_update_post_status_trash_alone_is_allowed() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'trash', get_post( $id )->post_status );
	}

	// ── future status requires future date ───────────────────────────

	public function test_create_post_status_future_requires_future_date() {
		$result = $this->pm->create_post( array( 'title' => 'X', 'status' => 'future' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );

		$result_past = $this->pm->create_post( array(
			'title'  => 'X',
			'status' => 'future',
			'date'   => '2000-01-01 00:00:00',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result_past );
		$this->assertSame( 'invalid_status', $result_past->get_error_code() );

		$future = gmdate( 'Y-m-d H:i:s', time() + 86400 );
		$result_ok = $this->pm->create_post( array(
			'title'  => 'X',
			'status' => 'future',
			'date'   => $future,
		) );
		$this->assertIsArray( $result_ok );
		$this->assertSame( 'future', $result_ok['status'] );
	}

	// ── post-types allow-list option override ────────────────────────

	public function test_post_types_allowlist_option_restricts_types() {
		// With override set to ['post'] only, page should be rejected.
		update_option( Post_Manager::POST_TYPES_ALLOWLIST_OPTION, array( 'post' ) );
		$result = $this->pm->create_post( array( 'title' => 'X', 'post_type' => 'page' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		// Cleanup.
		update_option( Post_Manager::POST_TYPES_ALLOWLIST_OPTION, false );
	}

	// ── update_post rate limit ───────────────────────────────────────

	public function test_update_post_respects_rate_limit() {
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		// Pre-fill the writes bucket past the 10/min limit.
		$now = time();
		set_transient(
			'gk_block_api_rate_' . $id,
			array(
				'writes' => array(
					$now, $now, $now, $now, $now, $now, $now, $now, $now, $now,
				),
				'puts'   => array(),
			),
			120
		);
		$result = $this->pm->update_post( $id, array( 'title' => 'New' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
	}
}
