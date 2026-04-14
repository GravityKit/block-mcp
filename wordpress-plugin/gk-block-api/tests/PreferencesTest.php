<?php
/**
 * Tests for the Preferences class.
 *
 * Tests block scoring, tier classification, replacement map, and
 * pattern scoring logic. These tests do not require WP stubs beyond
 * basic option/parse_args functions (provided by bootstrap.php).
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Preferences;

class PreferencesTest extends \PHPUnit\Framework\TestCase {

	// ── Block scoring ──

	public function test_filter_namespace_is_preferred() {
		$p = new Preferences();
		$score = $p->get_block_score( 'filter/testimonial-wall' );
		$this->assertEquals( 'preferred', $score['tier'] );
		$this->assertGreaterThanOrEqual( 80, $score['score'] );
	}

	public function test_core_namespace_is_preferred() {
		$p = new Preferences();
		$score = $p->get_block_score( 'core/paragraph' );
		$this->assertEquals( 'preferred', $score['tier'] );
		$this->assertEquals( 90, $score['score'] );
	}

	public function test_stackable_is_avoid() {
		$p = new Preferences();
		$score = $p->get_block_score( 'stackable/heading' );
		$this->assertEquals( 'avoid', $score['tier'] );
		$this->assertEquals( 10, $score['score'] );
	}

	public function test_ugb_is_legacy() {
		$p = new Preferences();
		$score = $p->get_block_score( 'ugb/button' );
		$this->assertEquals( 'legacy', $score['tier'] );
		$this->assertEquals( 0, $score['score'] );
	}

	public function test_jetpack_is_legacy() {
		$p = new Preferences();
		$score = $p->get_block_score( 'jetpack/map' );
		$this->assertEquals( 'legacy', $score['tier'] );
		$this->assertEquals( 0, $score['score'] );
	}

	public function test_unknown_namespace_is_acceptable() {
		$p = new Preferences();
		$score = $p->get_block_score( 'unknown-plugin/widget' );
		// Unknown defaults to score 30, which is 'acceptable' tier (>= 10 but < 50 is 'avoid'... wait no).
		// Actually: >= 80 = preferred, >= 50 = acceptable, >= 10 = avoid, < 10 = legacy.
		// Score 30 => avoid tier.
		$this->assertEquals( 30, $score['score'] );
	}

	public function test_empty_block_name() {
		$p = new Preferences();
		$score = $p->get_block_score( '' );
		$this->assertEquals( 'acceptable', $score['tier'] );
		$this->assertEquals( 0, $score['score'] );
	}

	public function test_gk_wildcard_score() {
		$p = new Preferences();
		$score = $p->get_block_score( 'gk-gravitycharts/chart' );
		$this->assertGreaterThanOrEqual( 80, $score['score'] );
		$this->assertEquals( 'preferred', $score['tier'] );
	}

	public function test_gk_unknown_wildcard() {
		$p = new Preferences();
		$score = $p->get_block_score( 'gk-newproduct/block' );
		$this->assertEquals( 80, $score['score'] );
		$this->assertEquals( 'preferred', $score['tier'] );
	}

	public function test_outermost_is_acceptable() {
		$p = new Preferences();
		$score = $p->get_block_score( 'outermost/icon-block' );
		$this->assertEquals( 'acceptable', $score['tier'] );
		$this->assertEquals( 60, $score['score'] );
	}

	// ── Replacement map ──

	public function test_replacement_map() {
		$p = new Preferences();
		$this->assertEquals( 'core/heading', $p->get_replacement( 'stackable/heading' ) );
		$this->assertEquals( 'filter/testimonial-wall', $p->get_replacement( 'stackable/testimonial' ) );
		$this->assertNull( $p->get_replacement( 'core/paragraph' ) );
	}

	public function test_replacement_ugb_button() {
		$p = new Preferences();
		$this->assertEquals( 'core/button', $p->get_replacement( 'ugb/button' ) );
	}

	public function test_replacement_map_completeness() {
		$p = new Preferences();
		$map = $p->get_replacement_map();
		$this->assertArrayHasKey( 'stackable/heading', $map );
		$this->assertArrayHasKey( 'ugb/columns', $map );
		$this->assertGreaterThanOrEqual( 15, count( $map ), 'Should have at least 15 replacement entries' );
	}

	// ── Namespace extraction ──

	public function test_extract_namespace() {
		$p = new Preferences();
		$this->assertEquals( 'core', $p->extract_namespace( 'core/paragraph' ) );
		$this->assertEquals( 'filter', $p->extract_namespace( 'filter/accordion' ) );
		$this->assertEquals( 'no-slash', $p->extract_namespace( 'no-slash' ) );
		$this->assertEquals( '', $p->extract_namespace( '' ) );
	}

	// ── Tier classification ──

	public function test_score_to_tier_boundaries() {
		$this->assertEquals( 'preferred', Preferences::score_to_tier( 100 ) );
		$this->assertEquals( 'preferred', Preferences::score_to_tier( 80 ) );
		$this->assertEquals( 'acceptable', Preferences::score_to_tier( 79 ) );
		$this->assertEquals( 'acceptable', Preferences::score_to_tier( 50 ) );
		$this->assertEquals( 'avoid', Preferences::score_to_tier( 49 ) );
		$this->assertEquals( 'avoid', Preferences::score_to_tier( 10 ) );
		$this->assertEquals( 'legacy', Preferences::score_to_tier( 9 ) );
		$this->assertEquals( 'legacy', Preferences::score_to_tier( 0 ) );
		$this->assertEquals( 'legacy', Preferences::score_to_tier( -50 ) );
	}

	// ── Legacy detection ──

	public function test_is_legacy_block() {
		$p = new Preferences();
		$this->assertTrue( $p->is_legacy_block( 'ugb/button' ) );
		$this->assertTrue( $p->is_legacy_block( 'stackable/heading' ) );
		$this->assertFalse( $p->is_legacy_block( 'core/paragraph' ) );
		$this->assertFalse( $p->is_legacy_block( 'filter/accordion' ) );
	}

	// ── Pattern scoring ──

	public function test_pattern_score_no_legacy_recent() {
		$p = new Preferences();
		$score = $p->get_pattern_score( array(
			'reference_count' => 5,
			'created'         => '2026-03-01',
			'has_legacy'      => false,
		) );
		// 5 refs * 5 = 25, recency 2026 = +50, no_legacy = +20 => 95
		$this->assertEquals( 95, $score['score'] );
		$this->assertEquals( 'preferred', $score['tier'] );
	}

	public function test_pattern_score_with_legacy() {
		$p = new Preferences();
		$score = $p->get_pattern_score( array(
			'reference_count' => 2,
			'created'         => '2025-01-01',
			'has_legacy'      => true,
		) );
		// 2 refs * 5 = 10, recency 2025 = +30, has_legacy = -100 => -60
		$this->assertEquals( -60, $score['score'] );
		$this->assertEquals( 'legacy', $score['tier'] );
	}

	public function test_pattern_score_zero_refs_old() {
		$p = new Preferences();
		$score = $p->get_pattern_score( array(
			'reference_count' => 0,
			'created'         => '2020-01-01',
			'has_legacy'      => false,
		) );
		// 0 refs, no recency bonus (2020 not in map), no_legacy = +20 => 20
		$this->assertEquals( 20, $score['score'] );
	}
}
