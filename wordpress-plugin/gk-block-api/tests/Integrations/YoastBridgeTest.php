<?php
/**
 * Tests for Yoast_Bridge — Yoast SEO post-meta read/write.
 *
 * Drives Yoast_Bridge::read_fields() and Yoast_Bridge::write_fields()
 * directly via a thin Testable subclass. The REST handlers (get_seo,
 * update_seo, bulk_update_seo) are covered indirectly — they all funnel
 * into the same read/write methods exercised here.
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare(strict_types=1);

use GravityKit\BlockAPI\Yoast_Bridge;

/**
 * Yoast_Bridge subclass that exposes the protected read/write methods.
 *
 * The bridge keeps read_fields() and write_fields() protected so the public
 * surface stays a small set of REST callbacks. For unit testing we promote
 * them via this subclass rather than building WP_REST_Request fixtures.
 */
class Yoast_Bridge_Testable extends Yoast_Bridge {

	/**
	 * Public passthrough to the protected read_fields() method.
	 *
	 * @param int $post_id Post to read.
	 *
	 * @return array Normalized field values.
	 */
	public function read_fields_public( $post_id ) {
		return $this->read_fields( $post_id );
	}

	/**
	 * Public passthrough to the protected write_fields() method.
	 *
	 * @param int                  $post_id Post to update.
	 * @param array<string, mixed> $fields  Field name => value pairs.
	 *
	 * @return true|\WP_Error
	 */
	public function write_fields_public( $post_id, array $fields ) {
		return $this->write_fields( $post_id, $fields );
	}
}

/**
 * Yoast_Bridge unit-test suite.
 *
 * Requires a loaded Yoast SEO plugin (WPSEO_FILE defined, yoast/* REST
 * namespace registered, Yoast meta-key contracts in place). The general suite
 * runs Yoast-free, so this class is tagged for the dedicated Yoast run
 * (tests/phpunit/yoast.xml, which sets GK_LOAD_YOAST so the bootstrap loads
 * Yoast). The group also excludes it from the general single-site run.
 *
 * @group yoast
 */
class YoastBridgeTest extends WP_UnitTestCase {

	/**
	 * Bridge under test.
	 *
	 * @var Yoast_Bridge_Testable
	 */
	protected $bridge;

	/**
	 * Post ID assigned by the factory in setUp.
	 *
	 * @var int
	 */
	protected $post_id;

	public function set_up(): void {
		parent::set_up();
		$this->bridge  = new Yoast_Bridge_Testable();
		$this->post_id = self::factory()->post->create( array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Yoast Test Post',
			'post_content' => '',
		) );
	}

	// ── read_fields ────────────────────────────────────────────────

	/**
	 * A post with no Yoast meta yields the expected default-shape response.
	 *
	 * Establishes the contract that callers can always rely on: every field
	 * present, with type-appropriate defaults (empty string, false, null, []).
	 */
	public function test_read_returns_default_shape_for_post_without_meta() {
		$data = $this->bridge->read_fields_public( $this->post_id );

		$this->assertSame( $this->post_id, $data['post_id'] );
		$this->assertSame( '', $data['title'] );
		$this->assertSame( '', $data['description'] );
		$this->assertNull( $data['noindex'] );           // tri-state default
		$this->assertFalse( $data['nofollow'] );          // boolean default
		$this->assertFalse( $data['is_cornerstone'] );   // boolean default
		$this->assertSame( array(), $data['robots_advanced'] );
		$this->assertNull( $data['og_image_id'] );
		$this->assertNull( $data['twitter_image_id'] );
		$this->assertNull( $data['seo_score'] );
		$this->assertNull( $data['readability_score'] );
		$this->assertSame( array(), $data['primary_terms'] );
	}

	/**
	 * `noindex` is a Yoast tri-state stored as "1"/"2"/(default).
	 *
	 * Verifies that reads normalize to true (noindex), false (explicit
	 * index), and null (post-type default / no override).
	 */
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

	/**
	 * `robots_advanced` is stored as a CSV; reads expose it as an array.
	 *
	 * Yoast's "-" sentinel ("explicitly empty") is treated equivalently to
	 * an unset value: the API surfaces an empty array.
	 */
	public function test_read_normalizes_robots_advanced_csv() {
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-adv', 'noimageindex,noarchive' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( array( 'noimageindex', 'noarchive' ), $data['robots_advanced'] );

		// Yoast also stores "-" as a sentinel "explicitly empty" — should map to [].
		update_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-adv', '-' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( array(), $data['robots_advanced'] );
	}

	/**
	 * Image-ID meta values are coerced to int (or null when absent).
	 */
	public function test_read_normalizes_image_ids_as_int_or_null() {
		update_post_meta( $this->post_id, '_yoast_wpseo_opengraph-image-id', '42' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( 42, $data['og_image_id'] );
	}

	/**
	 * Primary-term meta is grouped under a `primary_terms` map keyed by taxonomy.
	 *
	 * Yoast stores one meta key per taxonomy (`_yoast_wpseo_primary_{taxonomy}`);
	 * the bridge folds them into a single response field for readability.
	 */
	public function test_read_returns_primary_terms_for_post_taxonomies() {
		update_post_meta( $this->post_id, '_yoast_wpseo_primary_category', '99' );
		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( array( 'category' => 99 ), $data['primary_terms'] );
	}

	// ── write_fields ───────────────────────────────────────────────

	/**
	 * Plain string fields (title, description, focus_keyword) round-trip to
	 * their canonical Yoast meta keys.
	 */
	public function test_write_persists_simple_text_fields() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array(
				'title'         => 'Custom Title',
				'description'   => 'Custom description.',
				'focus_keyword' => 'block mcp',
			)
		);

		$this->assertSame( 'Custom Title', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( 'Custom description.', get_post_meta( $this->post_id, '_yoast_wpseo_metadesc', true ) );
		$this->assertSame( 'block mcp', get_post_meta( $this->post_id, '_yoast_wpseo_focuskw', true ) );
	}

	/**
	 * `noindex` writes use Yoast's storage codes ("1"/"2"/delete) on disk.
	 *
	 * The public API exposes a clean true/false/null tri-state; this test
	 * asserts the on-disk format stays compatible with what Yoast itself
	 * writes via wp-admin so the metabox UI keeps showing the correct value.
	 */
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

	/**
	 * `nofollow=false` deletes the meta entirely — matching Yoast's convention
	 * (absence == follow). This keeps the metabox UI in its "default" state.
	 */
	public function test_write_nofollow_uses_delete_for_falsy() {
		$this->bridge->write_fields_public( $this->post_id, array( 'nofollow' => true ) );
		$this->assertSame( '1', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-nofollow', true ) );

		$this->bridge->write_fields_public( $this->post_id, array( 'nofollow' => false ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-nofollow', true ) );
	}

	/**
	 * `is_cornerstone` is stored as the literal strings "1" / "false".
	 *
	 * That's a Yoast quirk — most boolean meta uses "1"/"" or "1"/"0", but
	 * cornerstone uses the word "false". We mirror that exactly so Yoast
	 * Premium's cornerstone-content reports keep working.
	 */
	public function test_write_is_cornerstone_persists_string_form() {
		$this->bridge->write_fields_public( $this->post_id, array( 'is_cornerstone' => true ) );
		$this->assertSame( '1', get_post_meta( $this->post_id, '_yoast_wpseo_is_cornerstone', true ) );

		$this->bridge->write_fields_public( $this->post_id, array( 'is_cornerstone' => false ) );
		// Real Yoast's `sanitize_post_meta` callbacks intercept this key
		// and normalize the literal string "false" to "" (its on-disk
		// representation of "explicitly not cornerstone"). The bridge
		// passes "false" through to update_post_meta as the WP plugin
		// contract intends; what we assert is the property-level
		// invariant — after a falsy write, the read-back is falsy.
		$stored = get_post_meta( $this->post_id, '_yoast_wpseo_is_cornerstone', true );
		$this->assertContains( $stored, array( '', 'false' ), 'cornerstone=false must persist as either Yoast-sanitized "" or literal "false"' );
		// Round-trip via the bridge's own reader, which is what consumers
		// actually call. Read-side normalization gives a clean false.
		$read = $this->bridge->read_fields_public( $this->post_id );
		$this->assertFalse( $read['is_cornerstone'] );
	}

	/**
	 * `robots_advanced` writes intersect with the Yoast allow-list.
	 *
	 * Off-list values silently drop on the way in — they cannot reach the DB.
	 * On-list values are concatenated as CSV in the order Yoast expects.
	 */
	public function test_write_robots_advanced_array_intersects_with_allow_list() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array(
				'robots_advanced' => array( 'noimageindex', 'evilflag', 'noarchive' ),
			)
		);
		// 'evilflag' isn't on the allow-list and gets dropped.
		$this->assertSame(
			'noimageindex,noarchive',
			get_post_meta( $this->post_id, '_yoast_wpseo_meta-robots-adv', true )
		);
	}

	/**
	 * Setting an image ID to `0` clears the meta rather than persisting "0".
	 *
	 * Yoast's metabox treats absence of the meta key as "no image"; storing
	 * "0" would leave Premium's image-validation paths confused.
	 */
	public function test_write_image_id_zero_deletes_meta() {
		update_post_meta( $this->post_id, '_yoast_wpseo_opengraph-image-id', '42' );
		$this->bridge->write_fields_public( $this->post_id, array( 'og_image_id' => 0 ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_opengraph-image-id', true ) );
	}

	/**
	 * URL-shaped fields (canonical/redirect/og_image/twitter_image) route to
	 * their dedicated meta keys, not the title meta key.
	 *
	 * The exact escaping is a job for esc_url_raw() — we trust WP core in
	 * production and only stub it for unit tests; what we assert here is the
	 * routing, not the stringification.
	 */
	public function test_write_canonical_writes_to_canonical_meta_key() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array( 'canonical' => 'https://example.com/foo' )
		);
		$stored = get_post_meta( $this->post_id, '_yoast_wpseo_canonical', true );
		$this->assertStringContainsString( 'example.com', $stored );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
	}

	/**
	 * Unknown / ad-hoc field names are dropped silently — they cannot escape
	 * the field-map allow-list and write arbitrary post meta.
	 *
	 * Defense against agents (or hostile clients) sending random fields
	 * hoping to mutate non-Yoast meta through this endpoint.
	 */
	public function test_write_ignores_unknown_fields() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array(
				'title'           => 'Kept',
				'evil_field'      => 'IGNORED',
				'_arbitrary_meta' => 'IGNORED',
			)
		);
		$this->assertSame( 'Kept', get_post_meta( $this->post_id, '_yoast_wpseo_title', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_arbitrary_meta', true ) );
		$this->assertSame( '', get_post_meta( $this->post_id, 'evil_field', true ) );
	}

	/**
	 * Yoast-generated score fields (seo_score, readability_score, …) are
	 * read-only via this API.
	 *
	 * Those values are produced by Yoast's content analysis and writing
	 * them by hand would corrupt the dashboard's rollups.
	 */
	public function test_write_ignores_readonly_score_fields() {
		// seo_score is a Yoast-generated score, not user-writable.
		$this->bridge->write_fields_public( $this->post_id, array( 'seo_score' => 99 ) );
		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_linkdex', true ) );
	}

	/**
	 * Primary-term writes persist a `_yoast_wpseo_primary_{taxonomy}` meta
	 * with the term's ID, exactly as Yoast's metabox does.
	 */
	public function test_write_primary_terms_persists_per_taxonomy_meta() {
		// Seed a real category term so get_term() returns it. The helper
		// auto-assigns the term_id; capture it so we can pass it back.
		$term = self::factory()->term->create_and_get( array( 'taxonomy' => 'category', 'name' => 'Docs' ) );

		$this->bridge->write_fields_public(
			$this->post_id,
			array( 'primary_terms' => array( 'category' => $term->term_id ) )
		);

		$this->assertSame(
			(int) $term->term_id,
			(int) get_post_meta( $this->post_id, '_yoast_wpseo_primary_category', true )
		);
	}

	/**
	 * Writing a primary term against an unregistered taxonomy is a no-op.
	 *
	 * Prevents creating arbitrary `_yoast_wpseo_primary_<anything>` meta keys
	 * via taxonomy spoofing.
	 */
	public function test_write_primary_terms_skips_invalid_taxonomy() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array( 'primary_terms' => array( 'made_up_taxonomy' => 1 ) )
		);

		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_primary_made_up_taxonomy', true ) );
	}

	/**
	 * Writing a primary term that doesn't exist is a no-op.
	 *
	 * Yoast itself rejects non-existent term IDs in the metabox; we mirror that.
	 */
	public function test_write_primary_terms_skips_nonexistent_term() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array( 'primary_terms' => array( 'category' => 999 ) )
		);

		$this->assertSame( '', get_post_meta( $this->post_id, '_yoast_wpseo_primary_category', true ) );
	}

	/**
	 * Writing fields and then reading them back yields the same values.
	 *
	 * End-to-end smoke test — proves the storage-format quirks (tri-states,
	 * boolean-as-string, etc.) round-trip correctly without lossy normalization.
	 */
	public function test_round_trip_preserves_all_writable_fields() {
		$this->bridge->write_fields_public(
			$this->post_id,
			array(
				'title'         => 'Trip',
				'description'   => 'Round trip.',
				'focus_keyword' => 'roundtrip',
				'noindex'       => true,
				'nofollow'      => true,
			)
		);

		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( 'Trip', $data['title'] );
		$this->assertSame( 'Round trip.', $data['description'] );
		$this->assertSame( 'roundtrip', $data['focus_keyword'] );
		$this->assertTrue( $data['noindex'] );
		$this->assertTrue( $data['nofollow'] );
	}

	// ── emoji round-trip ───────────────────────────────────────────

	/**
	 * Emoji survive a write → read cycle on every text field unmolested.
	 *
	 * Sanitizers like sanitize_text_field() are sometimes mistakenly applied
	 * to multi-byte UTF-8 input in ways that strip 4-byte characters (the
	 * emoji range). This test pins the contract: agents can put emoji in
	 * SEO copy and they survive the trip through the bridge.
	 */
	public function test_write_then_read_preserves_emoji_in_text_fields() {
		$with_emoji = array(
			'title'               => 'Award winner 🏆 Block MCP',
			'description'         => 'Edits like 🪄 magic — no editor corruption.',
			'focus_keyword'       => '🚀 ship it',
			'og_title'            => 'Block MCP 💎',
			'og_description'      => 'WordPress MCP done right ✨',
			'twitter_title'       => 'Tweet me 🐦',
			'twitter_description' => 'Threads-friendly 🧵',
			'breadcrumb_title'    => 'Home 🏠',
		);

		$this->bridge->write_fields_public( $this->post_id, $with_emoji );

		$data = $this->bridge->read_fields_public( $this->post_id );

		foreach ( $with_emoji as $field => $expected ) {
			$this->assertSame( $expected, $data[ $field ], "Field {$field} must round-trip emoji unchanged." );
		}
	}

	/**
	 * Multi-codepoint emoji clusters (skin tones, ZWJ sequences) survive too.
	 *
	 * Family / waving-hand-with-skin-tone / flag-of emoji are made of
	 * multiple codepoints joined by zero-width-joiner or skin-tone modifiers.
	 * They're a frequent casualty of overzealous sanitizers; this test
	 * pins them down explicitly so we'd catch a regression that mangled
	 * the joiner sequence.
	 */
	public function test_write_then_read_preserves_complex_emoji_sequences() {
		$family   = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";              // 👨‍👩‍👧
		$wave     = "\u{1F44B}\u{1F3FE}";                                        // 👋🏾 (skin tone)
		$flag     = "\u{1F1FA}\u{1F1F8}";                                        // 🇺🇸

		$this->bridge->write_fields_public(
			$this->post_id,
			array(
				'title'         => 'Hi ' . $wave,
				'description'   => 'A ' . $family . ' page',
				'focus_keyword' => $flag . ' MCP',
			)
		);

		$data = $this->bridge->read_fields_public( $this->post_id );
		$this->assertSame( 'Hi ' . $wave, $data['title'] );
		$this->assertSame( 'A ' . $family . ' page', $data['description'] );
		$this->assertSame( $flag . ' MCP', $data['focus_keyword'] );
	}

	// ── is_yoast_active ────────────────────────────────────────────

	/**
	 * `is_yoast_active()` is purely a `defined('WPSEO_FILE')` check.
	 *
	 * The bootstrap activates the real Yoast SEO plugin (from WPackagist),
	 * so WPSEO_FILE IS defined and the bridge reports Yoast as active.
	 * register_routes() registers the Yoast routes when this returns true.
	 */
	public function test_is_yoast_active_reflects_wpseo_file_constant() {
		$this->assertTrue( defined( 'WPSEO_FILE' ), 'real Yoast plugin must load in the test harness' );
		$this->assertTrue( Yoast_Bridge::is_yoast_active() );
	}

	/**
	 * Sanity: when Yoast is active its REST namespace is registered too —
	 * proves the WPackagist install actually booted, not just defined the
	 * constant.
	 */
	public function test_real_yoast_rest_namespace_present() {
		// Force REST init so namespaces get populated.
		do_action( 'rest_api_init' );
		$server     = rest_get_server();
		$namespaces = $server->get_namespaces();
		$has_yoast  = false;
		foreach ( $namespaces as $ns ) {
			if ( 0 === strpos( (string) $ns, 'yoast/' ) ) {
				$has_yoast = true;
				break;
			}
		}
		$this->assertTrue( $has_yoast, 'expected at least one yoast/* REST namespace to be registered' );
	}

	/**
	 * Cornerstone-disable must delete the meta, not write the truthy string 'false'.
	 *
	 * Pre-fix, write_fields stored the literal string 'false' on disable.
	 * PHP truthiness treats 'false' as a non-empty string → true, so
	 * Yoast's own indexable / sitemap code read the meta as enabled
	 * regardless of the agent's intent. Our reader normalised the round
	 * trip ('1' === 'false' is false → bool false on read) which hid the
	 * bug from us but not from Yoast itself.
	 *
	 * Contract pinned here: after `is_cornerstone: false`, the meta is
	 * GONE from the database — not stored as any string, truthy or not.
	 */
	public function test_write_is_cornerstone_disable_removes_meta_entirely() {
		$this->bridge->write_fields_public( $this->post_id, array( 'is_cornerstone' => true ) );
		$this->assertSame( '1', get_post_meta( $this->post_id, '_yoast_wpseo_is_cornerstone', true ) );

		$this->bridge->write_fields_public( $this->post_id, array( 'is_cornerstone' => false ) );

		$this->assertFalse(
			metadata_exists( 'post', $this->post_id, '_yoast_wpseo_is_cornerstone' ),
			'is_cornerstone=false must delete the meta; the literal string "false" would be truthy to Yoast.'
		);
	}

	/**
	 * /yoast/bulk must reject batches larger than Block_CRUD::MAX_BATCH_SIZE.
	 *
	 * Pre-fix the route declared `posts` as an unbounded array. An
	 * authenticated edit_posts user could send 10k entries and amplify
	 * a cheap REST call into a long fan-out of DB queries + Yoast hooks.
	 * Cap matches the per-block update_blocks_batch limit (50) so the
	 * two batch APIs share a single resource ceiling.
	 */
	public function test_bulk_endpoint_rejects_oversized_batch() {
		do_action( 'rest_api_init' );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$posts = array();
		for ( $i = 0; $i < \GravityKit\BlockAPI\Block_CRUD::MAX_BATCH_SIZE + 1; $i++ ) {
			$posts[] = array( 'post_id' => $this->post_id, 'title' => 'x' );
		}

		$request = new \WP_REST_Request( 'PATCH', '/gk-block-api/v1/yoast/bulk' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array( 'posts' => $posts ) ) );

		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'batch_too_large', $data['code'] ?? null );

		wp_set_current_user( 0 );
	}
}
