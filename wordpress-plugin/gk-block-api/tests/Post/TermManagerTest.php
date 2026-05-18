<?php
/**
 * Tests for the Term_Manager class.
 *
 * Exercises Term_Manager::list_terms() against the real get_terms() /
 * WP_Term_Query pipeline. Uses post_tag for tests that depend on a clean
 * term count, since WordPress auto-creates a default "Uncategorized" term
 * in the category taxonomy that would otherwise pollute pagination/count
 * assertions.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Term_Manager;

class TermManagerTest extends WP_UnitTestCase {

	/** @var Term_Manager */
	private $tm;

	public function set_up(): void {
		parent::set_up();
		// Pretty permalinks so term links carry the slug.
		update_option( 'permalink_structure', '/%postname%/' );
		$this->tm = new Term_Manager();
	}

	private function make_term( string $taxonomy, string $name, array $args = array() ): \WP_Term {
		$args = array_merge( array( 'taxonomy' => $taxonomy, 'name' => $name ), $args );
		return self::factory()->term->create_and_get( $args );
	}

	public function test_default_taxonomy_is_category() {
		$this->make_term( 'category', 'Z-test' );
		$result = $this->tm->list_terms( array() );
		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['taxonomy'] );
		$names = array_column( $result['terms'], 'name' );
		$this->assertContains( 'Z-test', $names );
	}

	public function test_invalid_taxonomy_returns_error() {
		$result = $this->tm->list_terms( array( 'taxonomy' => 'nope_xyz' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	public function test_search_filter() {
		$this->make_term( 'post_tag', 'Documentation' );
		$this->make_term( 'post_tag', 'News' );
		$result = $this->tm->list_terms( array( 'taxonomy' => 'post_tag', 'search' => 'Doc' ) );
		$this->assertCount( 1, $result['terms'] );
		$this->assertSame( 'Documentation', $result['terms'][0]['name'] );
	}

	public function test_parent_filter() {
		// category is hierarchical; post_tag is not. Use category but key the
		// assertion on names so the default term doesn't matter.
		$parent = $this->make_term( 'category', 'Parent' );
		$this->make_term( 'category', 'Child', array( 'parent' => $parent->term_id ) );
		$this->make_term( 'category', 'Other' );
		$result = $this->tm->list_terms( array( 'parent' => $parent->term_id ) );
		$this->assertCount( 1, $result['terms'] );
		$this->assertSame( 'Child', $result['terms'][0]['name'] );
	}

	public function test_pagination() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->make_term( 'post_tag', 'tag-' . $i );
		}
		$page1 = $this->tm->list_terms( array( 'taxonomy' => 'post_tag', 'per_page' => 2, 'page' => 1, 'orderby' => 'name' ) );
		$page2 = $this->tm->list_terms( array( 'taxonomy' => 'post_tag', 'per_page' => 2, 'page' => 2, 'orderby' => 'name' ) );
		$this->assertCount( 2, $page1['terms'] );
		$this->assertCount( 2, $page2['terms'] );
		$this->assertNotEquals( $page1['terms'][0]['id'], $page2['terms'][0]['id'] );
		$this->assertSame( 5, $page1['total'] );
		$this->assertSame( 5, $page2['total'] );
	}

	public function test_per_page_caps_at_max() {
		$result = $this->tm->list_terms( array( 'per_page' => 9999 ) );
		$this->assertSame( Term_Manager::MAX_PER_PAGE, $result['per_page'] );
	}

	public function test_per_page_min_one() {
		$result = $this->tm->list_terms( array( 'per_page' => 0 ) );
		$this->assertSame( 1, $result['per_page'] );
	}

	public function test_include_filter() {
		$a = $this->make_term( 'post_tag', 'A' );
		$b = $this->make_term( 'post_tag', 'B' );
		$this->make_term( 'post_tag', 'C' );
		$result = $this->tm->list_terms( array( 'taxonomy' => 'post_tag', 'include' => array( $a->term_id, $b->term_id ) ) );
		$this->assertCount( 2, $result['terms'] );
	}

	public function test_format_includes_link() {
		$this->make_term( 'post_tag', 'Linked' );
		$result = $this->tm->list_terms( array( 'taxonomy' => 'post_tag' ) );
		$this->assertArrayHasKey( 'link', $result['terms'][0] );
		$this->assertStringContainsString( 'linked', $result['terms'][0]['link'] );
	}

	public function test_order_desc() {
		$this->make_term( 'post_tag', 'Apple' );
		$this->make_term( 'post_tag', 'Zebra' );
		$asc  = $this->tm->list_terms( array( 'taxonomy' => 'post_tag', 'order' => 'asc' ) );
		$desc = $this->tm->list_terms( array( 'taxonomy' => 'post_tag', 'order' => 'desc' ) );
		$this->assertSame( 'Apple', $asc['terms'][0]['name'] );
		$this->assertSame( 'Zebra', $desc['terms'][0]['name'] );
	}

	/**
	 * /terms must refuse taxonomies the site has marked private.
	 *
	 * Plugins commonly register internal-state taxonomies with
	 * `public: false` and `show_in_rest: false` (workflow status,
	 * license keys, audit tags). Pre-fix, list_terms only checked
	 * taxonomy_exists() — anyone with edit_posts could call
	 * /terms?taxonomy=<private_slug> and enumerate every term. Same
	 * gate WP's own /wp/v2/taxonomies endpoint enforces.
	 */
	public function test_list_terms_refuses_non_rest_taxonomy() {
		register_taxonomy(
			'gk_internal_only',
			'post',
			array(
				'public'       => false,
				'show_in_rest' => false,
				'label'        => 'Internal Only',
			)
		);

		wp_insert_term( 'Secret state', 'gk_internal_only' );

		$result = $this->tm->list_terms( array( 'taxonomy' => 'gk_internal_only' ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );

		unregister_taxonomy( 'gk_internal_only' );
	}

	/**
	 * `gk_block_api_allow_taxonomy_in_terms` lets admins opt a deliberately-
	 * private taxonomy back in.
	 *
	 * The agent-editing use case: a CPT with a workflow-state taxonomy
	 * (e.g. "Editorial Status") that the site explicitly hides from
	 * /wp/v2/taxonomies but wants reachable through this MCP so agents
	 * can assign statuses. The filter is the override seam.
	 */
	public function test_list_terms_filter_can_allow_private_taxonomy() {
		register_taxonomy(
			'gk_editorial_status',
			'post',
			array(
				'public'       => false,
				'show_in_rest' => false,
				'label'        => 'Editorial Status',
			)
		);
		wp_insert_term( 'In Review', 'gk_editorial_status' );

		$callback = static function ( $allow, $taxonomy ) {
			return 'gk_editorial_status' === $taxonomy ? true : $allow;
		};
		add_filter( 'gk_block_api_allow_taxonomy_in_terms', $callback, 10, 2 );

		try {
			$result = $this->tm->list_terms( array( 'taxonomy' => 'gk_editorial_status' ) );
			$this->assertIsArray( $result );
			$this->assertSame( 'gk_editorial_status', $result['taxonomy'] );
			$this->assertNotEmpty( $result['terms'] );
		} finally {
			remove_filter( 'gk_block_api_allow_taxonomy_in_terms', $callback, 10 );
			unregister_taxonomy( 'gk_editorial_status' );
		}
	}

	/**
	 * The same filter can also deny a normally-listable taxonomy. Useful
	 * for sites that want to keep, e.g., `post_tag` off the MCP surface
	 * without un-registering it from WP-admin.
	 */
	public function test_list_terms_filter_can_deny_public_taxonomy() {
		$callback = static function ( $allow, $taxonomy ) {
			return 'post_tag' === $taxonomy ? false : $allow;
		};
		add_filter( 'gk_block_api_allow_taxonomy_in_terms', $callback, 10, 2 );

		try {
			$result = $this->tm->list_terms( array( 'taxonomy' => 'post_tag' ) );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
		} finally {
			remove_filter( 'gk_block_api_allow_taxonomy_in_terms', $callback, 10 );
		}
	}
}
