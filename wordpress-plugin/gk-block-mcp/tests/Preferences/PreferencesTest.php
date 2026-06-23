<?php
/**
 * Tests for the Preferences class.
 *
 * Block scoring, tier classification, the (site-driven) replacement map, and
 * pattern scoring. The shipped defaults are opinion-free: only `core` is
 * preferred out of the box; every other namespace resolves to a neutral
 * "acceptable" score until an admin scores it, and the replacement map ships
 * empty and is authoritative (no defaults merged in).
 *
 * @package GravityKit\BlockMCP\Tests
 */

use GravityKit\BlockMCP\Preferences;

class PreferencesTest extends WP_UnitTestCase {

	// ── Block scoring: only core is preferred by default ──

	public function test_core_namespace_is_preferred() {
		$p     = new Preferences();
		$score = $p->get_block_score( 'core/paragraph' );
		$this->assertEquals( 'preferred', $score['tier'] );
		$this->assertEquals( 90, $score['score'] );
	}

	/**
	 * Every namespace without an explicit score resolves to neutral (acceptable,
	 * 50). No shipped opinion brands a third party as legacy/avoid, favors the
	 * GravityKit ecosystem, or prefers theme blocks.
	 *
	 * @dataProvider neutral_namespace_provider
	 *
	 * @param string $block_name Block to score.
	 */
	public function test_unscored_namespaces_are_neutral( $block_name ) {
		$p     = new Preferences();
		$score = $p->get_block_score( $block_name );
		$this->assertEquals( 50, $score['score'], $block_name . ' must resolve to the neutral default' );
		$this->assertEquals( 'acceptable', $score['tier'], $block_name . ' must be acceptable, not preferred/avoid/legacy' );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function neutral_namespace_provider() {
		return array(
			'theme blocks'      => array( 'filter/testimonial-wall' ),
			'gravity forms'     => array( 'gravityforms/form' ),
			'a GravityKit gk-'  => array( 'gk-gravitycharts/chart' ), // no gk-* favoritism
			'an unknown gk-'    => array( 'gk-newproduct/block' ),
			'outermost'         => array( 'outermost/icon-block' ),
			'kevinbatdorf'      => array( 'kevinbatdorf/code-block-pro' ),
			'stackable'         => array( 'stackable/heading' ),       // not branded legacy
			'ugb'               => array( 'ugb/button' ),
			'jetpack'           => array( 'jetpack/map' ),
			'an unknown plugin' => array( 'unknown-plugin/widget' ),
		);
	}

	public function test_empty_block_name() {
		$p     = new Preferences();
		$score = $p->get_block_score( '' );
		$this->assertEquals( 'acceptable', $score['tier'] );
		$this->assertEquals( 0, $score['score'] );
	}

	/**
	 * An admin override is the only path to legacy: scoring a namespace below 10
	 * marks it legacy for that site.
	 */
	public function test_admin_override_can_mark_a_namespace_legacy() {
		update_option( Preferences::OPTION_KEY, array( 'namespace_scores' => array( 'jetpack' => 0 ) ) );
		$p     = new Preferences();
		$score = $p->get_block_score( 'jetpack/map' );
		$this->assertEquals( 0, $score['score'] );
		$this->assertEquals( 'legacy', $score['tier'] );
	}

	/**
	 * core is a floor: with no stored override it always resolves to 90; an
	 * override wins.
	 */
	public function test_core_is_a_floor_default() {
		$p = new Preferences();
		$this->assertEquals( 90, $p->get_block_score( 'core/paragraph' )['score'] );

		update_option( Preferences::OPTION_KEY, array( 'namespace_scores' => array( 'core' => 40 ) ) );
		$p2 = new Preferences();
		$this->assertEquals( 40, $p2->get_block_score( 'core/paragraph' )['score'], 'an override must win over the core floor' );
	}

	// ── Replacement map: ships empty, admin-authoritative ──

	public function test_replacement_map_is_empty_by_default() {
		$p = new Preferences();
		$this->assertSame( array(), $p->get_replacement_map() );
		$this->assertNull( $p->get_replacement( 'stackable/heading' ) );
		$this->assertNull( $p->get_replacement( 'ugb/button' ) );
	}

	public function test_replacement_map_is_authoritative() {
		update_option( Preferences::OPTION_KEY, array( 'replacement_map' => array( 'foo/bar' => 'core/paragraph' ) ) );
		$p = new Preferences();
		$this->assertEquals( 'core/paragraph', $p->get_replacement( 'foo/bar' ) );
		$this->assertCount( 1, $p->get_replacement_map(), 'no shipped defaults are merged in' );
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

	// ── Legacy detection: nothing is legacy by default ──

	public function test_no_namespace_is_legacy_by_default() {
		$p = new Preferences();
		$this->assertFalse( $p->is_legacy_block( 'ugb/button' ) );
		$this->assertFalse( $p->is_legacy_block( 'stackable/heading' ) );
		$this->assertFalse( $p->is_legacy_block( 'jetpack/map' ) );
		$this->assertFalse( $p->is_legacy_block( 'core/paragraph' ) );
	}

	public function test_is_legacy_block_follows_admin_override() {
		update_option( Preferences::OPTION_KEY, array( 'namespace_scores' => array( 'ugb' => 0 ) ) );
		$p = new Preferences();
		$this->assertTrue( $p->is_legacy_block( 'ugb/button' ) );
		$this->assertFalse( $p->is_legacy_block( 'core/paragraph' ) );
	}

	// ── Pattern scoring (mechanics unchanged) ──

	public function test_pattern_score_no_legacy_recent() {
		$p     = new Preferences();
		$score = $p->get_pattern_score(
			array(
				'reference_count' => 5,
				'created'         => '2026-03-01',
				'has_legacy'      => false,
			)
		);
		// 5 refs * 5 = 25, recency 2026 = +50, no_legacy = +20 => 95.
		$this->assertEquals( 95, $score['score'] );
		$this->assertEquals( 'preferred', $score['tier'] );
	}

	public function test_pattern_score_with_legacy() {
		$p     = new Preferences();
		$score = $p->get_pattern_score(
			array(
				'reference_count' => 2,
				'created'         => '2025-01-01',
				'has_legacy'      => true,
			)
		);
		// 2 refs * 5 = 10, recency 2025 = +30, has_legacy = -100 => -60.
		$this->assertEquals( -60, $score['score'] );
		$this->assertEquals( 'legacy', $score['tier'] );
	}

	public function test_pattern_score_zero_refs_old() {
		$p     = new Preferences();
		$score = $p->get_pattern_score(
			array(
				'reference_count' => 0,
				'created'         => '2020-01-01',
				'has_legacy'      => false,
			)
		);
		// 0 refs, no recency bonus (2020 not in map), no_legacy = +20 => 20.
		$this->assertEquals( 20, $score['score'] );
	}
}
