<?php
/**
 * Regressions for empty() mishandling the literal string "0".
 *
 * empty('0') is true, so a guard written as empty($string) treats a value of
 * "0" as absent. Two contracts this pins:
 *
 *  1. create_post accepts a post titled "0" when the post has content (WordPress
 *     core accepts a "0" title on its own).
 *
 *  2. The block builders keep a leaf whose innerHTML is "0" in innerContent.
 *     WordPress core's serialize_block still voids a bare "0"
 *     (get_comment_delimited_block_content() uses empty($block_content)), which a
 *     plugin cannot override, so this pins the builder's in-memory shape, not a
 *     full serialize round-trip.
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Post_Manager;
use GravityKit\BlockMCP\Block_CRUD;

class EmptyZeroValueRegressionTest extends BlockApiTestCase {

	/** @var Post_Manager */
	private $pm;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->pm = new Post_Manager( $this->crud );
	}

	/**
	 * A post titled "0" is created when the post has content (so WP core doesn't
	 * reject it as all-empty).
	 */
	public function test_create_post_allows_title_of_literal_zero() {
		$result = $this->pm->create_post( array(
			'title'  => '0',
			'blocks' => array( array( 'name' => 'core/paragraph', 'innerHTML' => '<p>body</p>' ) ),
		) );

		$this->assertNotWPError( $result );
		$this->assertSame( '0', get_post_field( 'post_title', $result['id'] ) );
	}

	/**
	 * The block builders keep a leaf's "0" innerHTML in innerContent. Pins both
	 * build paths (insert via build_block_from_def, create via
	 * normalize_block_def_for_insert).
	 */
	public function test_builders_keep_zero_innerhtml_in_inner_content() {
		$rw = new ReflectionProperty( Block_CRUD::class, 'writer' );
		$rw->setAccessible( true );
		$writer   = $rw->getValue( $this->crud );
		$warnings = array();
		$built    = $writer->build_block_from_def( array( 'name' => 'core/paragraph', 'innerHTML' => '0' ), $warnings );
		$this->assertSame( array( '0' ), $built['innerContent'], 'build_block_from_def must keep "0"' );

		$rm = new ReflectionMethod( Post_Manager::class, 'normalize_block_def_for_insert' );
		$rm->setAccessible( true );
		$normalized = $rm->invoke( $this->pm, array( 'name' => 'core/paragraph', 'innerHTML' => '0' ) );
		$this->assertSame( array( '0' ), $normalized['innerContent'], 'normalize_block_def_for_insert must keep "0"' );
	}
}
