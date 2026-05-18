<?php
/**
 * Smoke test confirming the real-WP harness is wired up correctly.
 * Once Phase 1 is green, this file goes away.
 */

class SmokeTest extends WP_UnitTestCase {

	public function test_wp_loaded(): void {
		$this->assertTrue( defined( 'ABSPATH' ) );
		$this->assertTrue( function_exists( 'wp_insert_post' ) );
		$this->assertTrue( function_exists( 'serialize_blocks' ) );
		$this->assertTrue( function_exists( 'parse_blocks' ) );
	}

	public function test_factory_creates_real_post(): void {
		$id = self::factory()->post->create( array(
			'post_title'   => 'Hello',
			'post_content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->',
		) );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );

		$post = get_post( $id );
		$this->assertSame( 'Hello', $post->post_title );

		$blocks = parse_blocks( $post->post_content );
		$this->assertCount( 1, $blocks );
		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertSame( '<p>Hi</p>', trim( $blocks[0]['innerHTML'] ) );
	}

	public function test_plugin_classes_autoload(): void {
		$this->assertTrue( class_exists( '\GravityKit\BlockAPI\Block_CRUD' ) );
		$this->assertTrue( class_exists( '\GravityKit\BlockAPI\Post_Manager' ) );
	}
}
