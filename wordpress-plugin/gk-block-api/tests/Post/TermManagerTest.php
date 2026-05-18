<?php
/**
 * Tests for the Term_Manager class.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Term_Manager;

class TermManagerTest extends WP_UnitTestCase {

	/** @var Term_Manager */
	private $tm;

	protected function setUp(): void {
		$GLOBALS['_gk_test_terms']        = array();
		$GLOBALS['_gk_test_next_term_id'] = 1;
		$this->tm = new Term_Manager();
	}

	public function test_default_taxonomy_is_category() {
		_gk_test_make_term( 'category', 'Z-test' );
		$result = $this->tm->list_terms( array() );
		$this->assertIsArray( $result );
		$this->assertSame( 'category', $result['taxonomy'] );
		$this->assertSame( 1, $result['total'] );
		$this->assertCount( 1, $result['terms'] );
		$this->assertSame( 'Z-test', $result['terms'][0]['name'] );
	}

	public function test_invalid_taxonomy_returns_error() {
		$result = $this->tm->list_terms( array( 'taxonomy' => 'nope_xyz' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_taxonomy', $result->get_error_code() );
	}

	public function test_search_filter() {
		_gk_test_make_term( 'category', 'Documentation' );
		_gk_test_make_term( 'category', 'News' );
		$result = $this->tm->list_terms( array( 'search' => 'Doc' ) );
		$this->assertCount( 1, $result['terms'] );
		$this->assertSame( 'Documentation', $result['terms'][0]['name'] );
	}

	public function test_parent_filter() {
		$parent = _gk_test_make_term( 'category', 'Parent' );
		_gk_test_make_term( 'category', 'Child', array( 'parent' => $parent->term_id ) );
		_gk_test_make_term( 'category', 'Other' );
		$result = $this->tm->list_terms( array( 'parent' => $parent->term_id ) );
		$this->assertCount( 1, $result['terms'] );
		$this->assertSame( 'Child', $result['terms'][0]['name'] );
	}

	public function test_pagination() {
		for ( $i = 0; $i < 5; $i++ ) {
			_gk_test_make_term( 'category', 'tag-' . $i );
		}
		$page1 = $this->tm->list_terms( array( 'per_page' => 2, 'page' => 1, 'orderby' => 'name' ) );
		$page2 = $this->tm->list_terms( array( 'per_page' => 2, 'page' => 2, 'orderby' => 'name' ) );
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
		$a = _gk_test_make_term( 'category', 'A' );
		$b = _gk_test_make_term( 'category', 'B' );
		_gk_test_make_term( 'category', 'C' );
		$result = $this->tm->list_terms( array( 'include' => array( $a->term_id, $b->term_id ) ) );
		$this->assertCount( 2, $result['terms'] );
	}

	public function test_format_includes_link() {
		_gk_test_make_term( 'category', 'Linked' );
		$result = $this->tm->list_terms( array() );
		$this->assertArrayHasKey( 'link', $result['terms'][0] );
		$this->assertStringContainsString( 'linked', $result['terms'][0]['link'] );
	}

	public function test_order_desc() {
		_gk_test_make_term( 'category', 'Apple' );
		_gk_test_make_term( 'category', 'Zebra' );
		$asc  = $this->tm->list_terms( array( 'order' => 'asc' ) );
		$desc = $this->tm->list_terms( array( 'order' => 'desc' ) );
		$this->assertSame( 'Apple', $asc['terms'][0]['name'] );
		$this->assertSame( 'Zebra', $desc['terms'][0]['name'] );
	}
}
