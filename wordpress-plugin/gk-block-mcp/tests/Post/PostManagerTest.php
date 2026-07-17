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
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Post_Manager;
use GravityKit\BlockMCP\Connections;
use GravityKit\BlockMCP\Preferences;
use GravityKit\BlockMCP\Block_CRUD;
use GravityKit\BlockMCP\Block_Inventory;
use GravityKit\BlockMCP\Block_Safety;
use GravityKit\BlockMCP\HTML_Transformer;

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

		// Legacy/avoid tiers are admin-configured now (opinion-free defaults); seed
		// the namespace this create_post legacy-rejection test exercises.
		update_option(
			Preferences::OPTION_KEY,
			array(
				'namespace_scores' => array(
					'ugb'       => 0,
					'jetpack'   => 0,
					'stackable' => 10,
				),
				'replacement_map'  => array(
					'ugb/text'          => 'core/paragraph',
					'stackable/heading' => 'core/heading',
				),
			)
		);

		$preferences = new Preferences();
		$block_crud  = new Block_CRUD( $preferences, new Block_Safety(), new HTML_Transformer(), new Block_Inventory() );
		$this->pm    = new Post_Manager( $block_crud );
	}

	public function tear_down(): void {
		unset( $GLOBALS['wp_rest_application_password_uuid'] );
		parent::tear_down();
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
	 * A childless block that supplies explicit innerContent nulls must not
	 * corrupt the saved content.
	 *
	 * innerContent is part of the public block-input shape. A null there is a
	 * child placeholder; serialize_block() dereferences innerBlocks[$index] for
	 * each null. When the caller passes nulls but no innerBlocks,
	 * normalize_block_def_for_insert() passed them straight through, so
	 * serialize_blocks() read past the empty innerBlocks array and emitted
	 * corrupt content (an "Undefined array key" as the block index ran off the
	 * end). The fix drops orphaned nulls; this pins that the stored content
	 * round-trips cleanly with the wrapper string intact.
	 */
	public function test_create_post_orphan_inner_content_nulls_do_not_corrupt() {
		$result = $this->pm->create_post( array(
			'title'  => 'Orphan nulls',
			'blocks' => array(
				array(
					'name'         => 'core/group',
					'innerContent' => array( '<div class="wp-block-group">', null, null, '</div>' ),
				),
			),
		) );
		$this->assertIsArray( $result );

		$content = (string) get_post_field( 'post_content', $result['id'] );
		$this->assertStringContainsString( '<div class="wp-block-group">', $content );

		// The stored tree must satisfy the null-placeholder invariant: no null
		// in innerContent without a matching child, so serialize→parse is clean.
		$blocks = $this->stored_blocks( $result['id'] );
		$this->assertCount( 1, $blocks );
		$nulls = count( array_filter( $blocks[0]['innerContent'], static function ( $p ) {
			return null === $p;
		} ) );
		$this->assertSame( count( $blocks[0]['innerBlocks'] ), $nulls );
		$this->assertSame( 0, $nulls );

		// Idempotent round-trip (would warn/differ under the bug).
		$this->assertSame( $content, serialize_blocks( $blocks ) );
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
		// Belt-and-braces: wp-phpunit::reset_taxonomies() already
		// _unregister_taxonomy()'s every non-built-in tax in set_up,
		// but if that ever stops running, the unregister here keeps
		// register_taxonomy() a clean-slate operation instead of one
		// that merges object_types via add_object_type().
		if ( taxonomy_exists( 'doc_section' ) ) {
			unregister_taxonomy( 'doc_section' );
		}
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
		if ( taxonomy_exists( 'doc_section' ) ) {
			unregister_taxonomy( 'doc_section' );
		}
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

	/**
	 * 'private' is a publish-equivalent status and must require the publish cap on
	 * create, the same as 'publish' — matching WP core's handle_status_param().
	 *
	 * The gate only checked `'publish' === $status`, so a draft-only agent (or any
	 * edit_posts-but-not-publish_posts caller) could create private posts.
	 */
	public function test_create_post_private_status_requires_publish_cap() {
		// Contributor: edit_posts yes, publish_posts no.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$result = $this->pm->create_post( array( 'title' => 'Secret', 'status' => 'private' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
	}

	/**
	 * 'future' is a publish-equivalent status (WP-Cron auto-publishes it) and must
	 * require the publish cap on update, the same as 'publish'.
	 *
	 * The contributor authors the draft so the edit check passes and the only
	 * remaining gate is the publish cap.
	 */
	public function test_update_post_future_status_requires_publish_cap() {
		$cid = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$id  = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Scheduled', 'post_author' => $cid ) );
		wp_set_current_user( $cid );
		$result = $this->pm->update_post( $id, array( 'status' => 'future', 'date' => '2099-01-01 00:00:00' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_publish', $result->get_error_code() );
	}

	/**
	 * With the trash toggle enabled, status:trash moves the post to trash.
	 *
	 * Trashing is off by default (see the gate tests below); this exercises the
	 * mechanism with the opt-in switched on.
	 */
	public function test_update_post_to_trash() {
		update_option( Post_Manager::ALLOW_TRASH_OPTION, '1' );
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
		update_option( Post_Manager::ALLOW_TRASH_OPTION, '1' );
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
		update_option( Post_Manager::ALLOW_TRASH_OPTION, '1' );
		$id = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );
		$this->assertIsArray( $result );
		$this->assertSame( 'trash', get_post( $id )->post_status );
	}

	// ── update_post: trash toggle (off by default) ───────────────────

	/**
	 * Trashing is off by default: status:trash is rejected with `trash_disabled`
	 * and the post is left untouched.
	 *
	 * The agent has no `delete_*` caps, but trashing routes through `update_post`
	 * (gated only on `edit_post`, which the agent holds), so without this
	 * application-level gate the assistant could trash content out of the box.
	 * This pins the closed-by-default contract.
	 */
	public function test_update_post_trash_disabled_by_default_rejects() {
		$id     = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Keep me' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'trash_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		// The post must NOT have been trashed.
		$this->assertSame( 'publish', get_post( $id )->post_status );
	}

	/**
	 * The authorization gate fires before the mixed-payload correctness check:
	 * when trashing is disabled, a trash+other-fields payload reports
	 * `trash_disabled`, not `mixed_trash_payload`. Don't leak payload-shape
	 * feedback for an operation that isn't permitted at all.
	 */
	public function test_update_post_trash_disabled_takes_precedence_over_mixed_payload() {
		$id     = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Keep me' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash', 'title' => 'New' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'trash_disabled', $result->get_error_code() );
		$post = get_post( $id );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 'Keep me', $post->post_title );
	}

	/**
	 * The `gk/block-mcp/post/allow-trash` filter can force trashing on even when the
	 * stored option is off — programmatic control for site owners who'd rather
	 * gate this in code than in the UI.
	 */
	public function test_trashing_enabled_filter_can_force_enable() {
		$this->assertFalse( Post_Manager::trashing_enabled() );

		$force = static function () {
			return true;
		};
		add_filter( 'gk/block-mcp/post/allow-trash', $force );

		$this->assertTrue( Post_Manager::trashing_enabled() );
		$id     = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );

		remove_filter( 'gk/block-mcp/post/allow-trash', $force );

		$this->assertIsArray( $result );
		$this->assertSame( 'trash', get_post( $id )->post_status );
	}

	/**
	 * `trashing_enabled()` tracks the stored option: '1' → true, absent → false.
	 */
	public function test_trashing_enabled_reflects_stored_option() {
		$this->assertFalse( Post_Manager::trashing_enabled() );
		update_option( Post_Manager::ALLOW_TRASH_OPTION, '1' );
		$this->assertTrue( Post_Manager::trashing_enabled() );
		update_option( Post_Manager::ALLOW_TRASH_OPTION, '0' );
		$this->assertFalse( Post_Manager::trashing_enabled() );
	}

	// ── self identity (full-caps user): plugin-level guards still apply ─

	/**
	 * The trash gate is application-level, not capability-based: it must block
	 * an administrator too.
	 *
	 * In the `self` identity the credential is minted on the approving user, so
	 * the AI app acts with that person's full caps — including `delete_*`. The
	 * documented safety contract is that the `gk/block-mcp/post/allow-trash` gate
	 * (`class-post-manager.php`) still returns `trash_disabled` (403) for that
	 * user when the toggle is off, before any cap check. Without this, a `self`
	 * admin could trash content through the Block MCP tools by default. The
	 * existing trash-disabled test runs as an editor (which lacks `delete_*`
	 * anyway, so it cannot prove the gate is independent of caps); this pins the
	 * gate-vs-caps separation with a user who DOES hold delete capabilities.
	 */
	public function test_update_post_trash_disabled_blocks_admin_self_credential() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertTrue( current_user_can( 'delete_others_posts' ), 'precondition: admin holds delete caps' );

		$id     = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Keep me' ) );
		$result = $this->pm->update_post( $id, array( 'status' => 'trash' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'trash_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
		$this->assertSame( 'publish', get_post( $id )->post_status, 'a full-caps user must not trash while the gate is off' );
	}

	/**
	 * The post-type allow-list is application-level, not capability-based: it
	 * must reject a disallowed type even for an administrator.
	 *
	 * In the `self` identity the acting user has full caps and could create any
	 * post type via core. The documented safety contract is that
	 * `Post_Manager::create_post()` rejects types outside
	 * `gk_block_api_post_types_allowlist` with `invalid_post_type`
	 * (`class-post-manager.php`) BEFORE the capability check — so the allow-list
	 * constrains a `self` admin too. The existing allow-list test runs as an
	 * editor; this pins the same gate for a user with full caps.
	 */
	public function test_create_post_allowlist_blocks_admin_self_credential() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		update_option( Post_Manager::POST_TYPES_ALLOWLIST_OPTION, array( 'post' ) );

		$result = $this->pm->create_post( array( 'title' => 'X', 'post_type' => 'page' ) );

		update_option( Post_Manager::POST_TYPES_ALLOWLIST_OPTION, false );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_post_type', $result->get_error_code() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
	}

	// ── create_post: author assignment ───────────────────────────────

	/**
	 * Connection meta never drives post_author. The byline subsystem was removed
	 * with the agent_as_me identity, so even when a credential's meta names an
	 * approving human, a created post stays authored by the acting account.
	 *
	 * Regression pin: setting the authenticated app-password UUID + recording meta
	 * for it must NOT remap authorship to the recorded human.
	 */
	public function test_create_post_does_not_remap_author_from_connection_meta() {
		$human = self::factory()->user->create( array( 'role' => 'author' ) );
		Connections::record_meta( 'uuid-meta', array( 'created_by' => $human, 'user_id' => $human ) );
		$GLOBALS['wp_rest_application_password_uuid'] = 'uuid-meta';

		$result = $this->pm->create_post( array( 'title' => 'No remap' ) );

		$this->assertIsArray( $result );
		$this->assertSame( get_current_user_id(), (int) get_post( $result['id'] )->post_author );
	}

	/**
	 * An explicit `author` argument sets post_author when the acting account may
	 * assign authorship — the create tool's own author-assignment path, kept after
	 * the connection byline was removed.
	 */
	public function test_create_post_honors_explicit_author_argument() {
		$explicit = self::factory()->user->create( array( 'role' => 'author' ) );

		$result = $this->pm->create_post( array( 'title' => 'Explicit', 'author' => $explicit ) );

		$this->assertIsArray( $result );
		$this->assertSame( $explicit, (int) get_post( $result['id'] )->post_author );
	}

	/**
	 * Assigning authorship to a DIFFERENT user is gated on edit_others_posts.
	 *
	 * The explicit-`author` branch of create_post() lets the acting account stamp
	 * post_author, but only when it may edit others' content. An author-role actor
	 * holds edit_posts (it can create its own content) yet lacks edit_others_posts,
	 * so naming a second user as the author must be refused with
	 * rest_cannot_assign_author (403) — otherwise a low-privilege actor could
	 * attribute content to anyone. This pins the denial half of the contract; the
	 * allowed half is covered by test_create_post_honors_explicit_author_argument.
	 */
	public function test_create_post_explicit_author_denied_when_actor_cannot_assign_authorship() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertTrue( current_user_can( 'edit_posts' ), 'precondition: actor can create its own posts' );
		$this->assertFalse( current_user_can( 'edit_others_posts' ), 'precondition: actor cannot assign authorship to others' );

		$other  = self::factory()->user->create( array( 'role' => 'author' ) );
		$result = $this->pm->create_post( array( 'title' => 'X', 'author' => $other ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_assign_author', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
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

	/**
	 * post_date must parse as a real datetime.
	 *
	 * Pre-fix, sanitize_text_field stripped HTML but left arbitrary text
	 * intact, so wp_insert_post stored unparseable strings verbatim and
	 * corrupted admin sort order + date-relative queries. Now: parse via
	 * strtotime, normalize via gmdate; reject unparseable input with
	 * invalid_date 400.
	 */
	public function test_create_post_rejects_unparseable_date() {
		$result = $this->pm->create_post(
			array(
				'title' => 'X',
				'date'  => 'not-a-date',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_date', $result->get_error_code() );
	}

	/**
	 * Same contract as create_post — update_post must also reject
	 * unparseable post_date strings.
	 */
	public function test_update_post_rejects_unparseable_date() {
		$id     = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'date' => 'banana-2026' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_date', $result->get_error_code() );
	}

	/**
	 * Parseable dates are normalized to MySQL datetime via gmdate.
	 *
	 * ISO 8601 input ("2030-06-15T12:00:00Z") must land on disk as
	 * "2030-06-15 12:00:00" regardless of the wire format — same
	 * shape Yoast / WP admin / SQL queries all expect.
	 */
	public function test_create_post_normalizes_iso8601_date() {
		$result = $this->pm->create_post(
			array(
				'title' => 'X',
				'date'  => '2030-06-15T12:00:00Z',
			)
		);
		$this->assertNotInstanceOf( \WP_Error::class, $result );
		$post = get_post( $result['id'] );
		$this->assertSame( '2030-06-15 12:00:00', $post->post_date_gmt );
	}

	/**
	 * comment_status must reject unknown values, not silently coerce.
	 *
	 * Pre-fix, an in_array-with-fallback-to-'closed' silently disabled
	 * comments for any typo (`opn`, `Open`, `true`) while reporting
	 * success to the caller. Now: unknown values return invalid_status
	 * 400 so the caller knows their input was wrong.
	 */
	public function test_create_post_rejects_invalid_comment_status() {
		$result = $this->pm->create_post(
			array(
				'title'          => 'X',
				'comment_status' => 'opn',
			)
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}

	/**
	 * ping_status follows the same contract as comment_status — unknown
	 * values get rejected, not silently coerced.
	 */
	public function test_update_post_rejects_invalid_ping_status() {
		$id     = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'X' ) );
		$result = $this->pm->update_post( $id, array( 'ping_status' => 'maybe' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_status', $result->get_error_code() );
	}
}
