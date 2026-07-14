<?php
/**
 * Tests for Rank_Math_Bridge — Rank Math post-meta read/write.
 *
 * Rank Math itself is not installed in the test environment, so these exercise
 * the bridge's own logic: field mapping, direct meta read/write (with the
 * fallback sanitizer), robots array handling, and empty-value clearing. The
 * native-ability delegation path is covered separately where wp_has_ability
 * is available.
 *
 * @package GravityKit\BlockMCP
 */

namespace GravityKit\BlockMCP\Tests\Integrations;

use WP_UnitTestCase;
use GravityKit\BlockMCP\Rank_Math_Bridge;

/**
 * Rank_Math_Bridge test suite.
 */
class RankMathBridgeTest extends WP_UnitTestCase {

	/**
	 * Bridge subclass exposing the protected read/write methods.
	 *
	 * @var object
	 */
	private $bridge;

	/**
	 * Set up an anonymous testable bridge.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->bridge = new class() extends Rank_Math_Bridge {
			/**
			 * Expose read_fields.
			 *
			 * @param int $post_id Post ID.
			 * @return array
			 */
			public function read( $post_id ) {
				return $this->read_fields( $post_id );
			}

			/**
			 * Expose write_fields.
			 *
			 * @param int   $post_id Post ID.
			 * @param array $fields  Fields.
			 * @return mixed
			 */
			public function write( $post_id, array $fields ) {
				return $this->write_fields( $post_id, $fields );
			}
		};
	}

	/**
	 * write_fields stores each field under its rank_math_* meta key, and
	 * read_fields reads them back.
	 */
	public function test_write_then_read_round_trips_scalar_fields() {
		$post_id = self::factory()->post->create();

		$this->bridge->write(
			$post_id,
			array(
				'title'         => 'Custom SEO Title',
				'description'   => 'Custom meta description.',
				'focus_keyword' => 'block editor',
				'canonical'     => 'https://example.com/canonical/',
			)
		);

		$this->assertSame( 'Custom SEO Title', get_post_meta( $post_id, 'rank_math_title', true ) );
		$this->assertSame( 'Custom meta description.', get_post_meta( $post_id, 'rank_math_description', true ) );
		$this->assertSame( 'block editor', get_post_meta( $post_id, 'rank_math_focus_keyword', true ) );
		$this->assertSame( 'https://example.com/canonical/', get_post_meta( $post_id, 'rank_math_canonical_url', true ) );

		$read = $this->bridge->read( $post_id );
		$this->assertSame( $post_id, $read['post_id'] );
		$this->assertSame( 'Custom SEO Title', $read['title'] );
		$this->assertSame( 'https://example.com/canonical/', $read['canonical'] );
	}

	/**
	 * og_/twitter_ fields map to their facebook_/twitter_ meta keys.
	 */
	public function test_social_fields_map_to_correct_meta_keys() {
		$post_id = self::factory()->post->create();

		$this->bridge->write(
			$post_id,
			array(
				'og_title'            => 'OG Title',
				'og_description'      => 'OG Desc',
				'twitter_title'       => 'TW Title',
				'twitter_description' => 'TW Desc',
			)
		);

		$this->assertSame( 'OG Title', get_post_meta( $post_id, 'rank_math_facebook_title', true ) );
		$this->assertSame( 'OG Desc', get_post_meta( $post_id, 'rank_math_facebook_description', true ) );
		$this->assertSame( 'TW Title', get_post_meta( $post_id, 'rank_math_twitter_title', true ) );
		$this->assertSame( 'TW Desc', get_post_meta( $post_id, 'rank_math_twitter_description', true ) );
	}

	/**
	 * robots is stored and returned as an array of directive strings.
	 */
	public function test_robots_is_stored_and_read_as_array() {
		$post_id = self::factory()->post->create();

		$this->bridge->write( $post_id, array( 'robots' => array( 'noindex', 'nofollow' ) ) );

		$read = $this->bridge->read( $post_id );
		$this->assertIsArray( $read['robots'] );
		$this->assertContains( 'noindex', $read['robots'] );
		$this->assertContains( 'nofollow', $read['robots'] );
	}

	/**
	 * An empty value clears the meta rather than storing an empty string.
	 */
	public function test_empty_value_clears_meta() {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, 'rank_math_title', 'Existing' );

		$this->bridge->write( $post_id, array( 'title' => '' ) );

		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_title', true ) );
		$this->assertEmpty( get_post_meta( $post_id, 'rank_math_title', true ) );
	}

	/**
	 * read_fields exposes seo_score as a read-only integer/null and never lets
	 * it be written through write_fields.
	 */
	public function test_seo_score_is_read_only() {
		$post_id = self::factory()->post->create();

		// Attempt to write the read-only field.
		$this->bridge->write( $post_id, array( 'seo_score' => 99 ) );

		// It is not persisted under the score meta key by the bridge.
		$this->assertSame( '', get_post_meta( $post_id, 'rank_math_seo_score', true ) );

		// When set out-of-band, read_fields surfaces it as an int.
		update_post_meta( $post_id, 'rank_math_seo_score', '82' );
		$read = $this->bridge->read( $post_id );
		$this->assertSame( 82, $read['seo_score'] );
	}

	/**
	 * Rank Math is not installed in the test environment, so the active check
	 * is false and route registration is a no-op.
	 */
	public function test_is_rank_math_active_false_without_plugin() {
		$this->assertFalse( Rank_Math_Bridge::is_rank_math_active() );
	}
}
