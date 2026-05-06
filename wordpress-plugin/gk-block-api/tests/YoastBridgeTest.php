<?php
/**
 * Tests for Yoast_Bridge — Yoast SEO post-meta read/write.
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare(strict_types=1);

use GravityKit\BlockAPI\Yoast_Bridge;

require_once __DIR__ . '/bootstrap.php';

/**
 * Subclass that exposes the protected read/write methods so tests can drive
 * them directly without standing up the full WP_REST_Request stack.
 */
class Yoast_Bridge_Testable extends Yoast_Bridge {
	public function read_fields_public( $post_id ) {
		return $this->read_fields( $post_id );
	}

	public function write_fields_public( $post_id, array $fields ) {
		return $this->write_fields( $post_id, $fields );
	}
}

class YoastBridgeTest extends \PHPUnit\Framework\TestCase {

	/** @var Yoast_Bridge_Testable */
	protected $bridge;

	/** @var int */
	protected $post_id = 7700;

	protected function setUp(): void {
		parent::setUp();

		$this->bridge = new Yoast_Bridge_Testable();

		// Reset all per-test global state.
		$GLOBALS['_gk_test_posts']          = array();
		$GLOBALS['_gk_test_post_meta']      = array();
		$GLOBALS['_gk_test_terms']          = array();
		$GLOBALS['_gk_test_post_terms']    = array();
		$GLOBALS['_gk_test_next_term_id']   = 1;

		// Seed a post the bridge can read against.
		$post                = new \stdClass();
		$post->ID            = $this->post_id;
		$post->post_type     = 'post';
		$post->post_status   = 'publish';
		$post->post_title    = 'Yoast Test Post';
		$post->post_content  = '';
		$GLOBALS['_gk_test_posts'][ $this->post_id ] = $post;
	}

	// ── read_fields ────────────────────────────────────────────────

	public function test_read_returns_default_shape_for_post_without_meta() {
		$data = $this->bridge->read_fields_public( $this->post_id );

		$this->assertSame( $this->post_id, $data['post_id'] );
		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['description'] );
		$this->assertNull( $data['noindex'] );          // tri-state default
		$this->assertFalse( $data['nofollow'] );         // boolean default
		$this->assertFalse( $data['is_cornerstone'] );  // boolean default
		$this->assertSame( array(), $data['robots_advanced'] );
		$this->assertNull( $data['og_image_id'] );
		$this->assertNull( $data['twitter_image_id'] );
		$this->assertNull( $data['seo_score'] );
		$this->assertNull( $data['readability_score'] );
		$this->assertSame( array(), $data['primary_terms'] );
	}

	public function test_read_normalizes_noindex_tristate() {
		// "1" => true (noindex)
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-noindex', '1' );
		$this->assertTrue( $this->bridge->read_fields_public( $this->post_id )['noindex'] );

		// "2" => false (explicit index)
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-noindex', '2' );
		$this->assertFalse( $this->bridge->read_fields_public( $this->post_id )['noindex'] );

		// Anything else => null (default)
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-noindex', '0' );
		$this->assertNull( $this->bridge->read_fields_public( $this->post_id )['noindex'] );
	}

	public function test_read_normalizes_robots_advanced_csv() {
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-adv', 'noimageindex,noarchive' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( array( 'noimageindex', 'noarchive' ), $data['robots_advanced'] );

		// Yoast also stores "-" as a sentinel "explicitly empty" — should map to [].
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-adv', '-' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( array(), $data['robots_advanced'] );
	}

	public function test_read_normalizes_image_ids_as_int_or_null() {
		update_post_meta( $this->post_id, '_yoast_wpseo_opengraph-image-id', '42' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( 42, $data['og_image_id'] );
	}

	public function test_read_returns_primary_terms_for_post_taxonomies() {
		update_post_meta( $this->post_id, '_yoast_wpseo_primary_category', '99' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( array( 'category' => 99 ), $data['primary_terms'] );
	}

	// ── write_fields ───────────────────────────────────────────────

	public function test_write_persists_simple_text_fields() {
		$this->bridge->write_fields_public( $this->post_id, array(
			'title'         => 'Custom Title',
			'description'   => 'Custom description.',
			'focus_keyword' => 'block mcp',
		) );

		$this->assertSame( 'Custom Title', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Custom description.', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'block mcp', get_post_meta( $this->post_id, '_yoast_wpseo_focuskw', true ) );
	}

	public function test_write_noindex_tristate_uses_yoast_storage_codes() {
		// true => "1"
		$this->bridge->write_fields_public( $this->post_id, array( 'noindex' => true ) );
		$this->assertSame( '1', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-noindex', true ) );

		// false => "2"
		$this->bridge->write_fields_public( $this->post_id, array( 'noindex' => false ) );
		$this->assertSame( '2', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-noindex', true ) );

		// null => meta deleted (default)
		$this->bridge->write_fields_public( $this->post_id, array( 'noindex' => null ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-noindex', true ) );
	}

	public function test_write_nofollow_uses_delete_for_falsy() {
		$this->bridge->write_fields_public( $this->post_id, array( 'nofollow' => true ) );
		$this->assertSame( '1', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-nofollow', true ) );

		$this->bridge->write_fields_public( $this->post_id, array( 'nofollow' => false ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-nofollow', true ) );
	}

	public function test_write_is_cornerstone_persists_string_form() {
		$this->bridge->write_fields_public( $this->post_id, array( 'is_cornerstone' => true ) );
		$this->assertSame( '1', get_post_meta( $this->post_id, '_yoast_wpseo_is_cornerstone', true ) );

		$this->bridge->write_fields_public( $this->post_id, array( 'is_cornerstone' => false ) );
		$this->assertSame( 'false', get_post_meta( $this->post_id, '_yoast_wpseo_is_cornerstone', true ) );
	}

	public function test_write_robots_advanced_array_intersects_with_allow_list() {
		$this->bridge->write_fields_public( $this->post_id, array(
			'robots_advanced' => array( 'noimageindex', 'evilflag', 'noarchive' ),
		) );
		// 'evilflag' isn't on the allow-list and gets dropped.
		$this->assertSame(
			'noimageindex,noarchive',
			get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-adv', true )
		);
	}

	public function test_write_image_id_zero_deletes_meta() {
		update_post_meta( $this->post_id, '_yoast_wpseo_opengraph-image-id', '42' );
		$this->bridge->write_fields_public( $this->post_id, array( 'og_image_id' => 0 ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_opengraph-image-id', true ) );
	}

	public function test_write_canonical_writes_to_canonical_meta_key() {
		// We don't assert the exact escaping (that's the stubbed esc_url_raw's
		// job in tests, the real one's job in prod) — just that the field
		// routes to the canonical meta key, not the title meta key.
		$this->bridge->write_fields_public( $this->post_id, array(
			'canonical' => 'https://example.com/foo',
		) );
		$stored = get_post_meta( $this->post_id, '_yoast_wpseo_canonical', true );
		$this->assertStringContainsString( 'example.com', $stored );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
	}

	public function test_write_ignores_unknown_fields() {
		$this->bridge->write_fields_public( $this->post_id, array(
			'title'           => 'Kept',
			'evil_field'      => 'IGNORED',
			'_arbitrary_meta' => 'IGNORED',
		) );
		$this->assertSame( 'Kept', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_arbitrary_meta', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, 'evil_field', true ) );
	}

	public function test_write_ignores_readonly_score_fields() {
		// seo_score is a Yoast-generated score, not user-writable.
		$this->bridge->write_fields_public( $this->post_id, array(
			'seo_score' => 99,
		) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_linkdex', true ) );
	}

	public function test_write_primary_terms_persists_per_taxonomy_meta() {
		// Seed a real category term so get_term() returns it. The helper
		// auto-assigns the term_id; capture it so we can pass it back.
		$term = _gk_test_make_term( 'category', 'Docs' );

		$this->bridge->write_fields_public( $this->post_id, array(
			'primary_terms' => array( 'category' => $term->term_id ),
		) );

		$this->assertSame(
			(int) $term->term_id,
			(int) get_post_meta( $this->post_id, '_yoast_wpseo_primary_category', true )
		);
	}

	public function test_write_primary_terms_skips_invalid_taxonomy() {
		$this->bridge->write_fields_public( $this->post_id, array(
			'primary_terms' => array( 'made_up_taxonomy' => 1 ),
		) );

		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_primary_made_up_taxonomy', true ) );
	}

	public function test_write_primary_terms_skips_nonexistent_term() {
		$this->bridge->write_fields_public( $this->post_id, array(
			'primary_terms' => array( 'category' => 999 ),
		) );

		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_primary_category', true ) );
	}

	public function test_round_trip_preserves_all_writable_fields() {
		$this->bridge->write_fields_public( $this->post_id, array(
			'title'         => 'Trip',
			'description'   => 'Round trip.',
			'focus_keyword' => 'roundtrip',
			'noindex'       => true,
			'nofollow'      => true,
		) );

		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( 'Trip', $data['title'] );
		$this->assertSame( 'Round trip.', $data['description'] );
		$this->assertSame( 'roundtrip', $data['focus_keyword'] );
		$this->assertTrue( $data['noindex'] );
		$this->assertTrue( $data['nofollow'] );
	}

	// ── is_yoast_active ────────────────────────────────────────────

	public function test_is_yoast_active_reflects_wpseo_file_constant() {
		// In the test bootstrap, WPSEO_FILE isn't defined, so the bridge
		// should report Yoast as inactive.
		$this->assertFalse( Yoast_Bridge::is_yoast_active() );
	}
}
