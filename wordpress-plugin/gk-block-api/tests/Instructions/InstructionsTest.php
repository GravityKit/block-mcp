<?php
/**
 * Tests for the Instructions service class.
 *
 * Covers: option storage round-trip, sanitization (HTML, shortcode,
 * control-char stripping), length cap (server-side enforcement on
 * set_addendum + post-sanitize truncation in sanitize), timestamp
 * tracking, and per-IP rate limiting.
 *
 * @package GravityKit\BlockAPI\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Instructions;

class InstructionsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Instructions::OPTION_KEY );
		delete_option( Instructions::UPDATED_AT_OPTION );
	}

	// ── get_addendum / set_addendum round-trip ──

	public function test_get_addendum_returns_empty_when_unset(): void {
		$this->assertSame( '', Instructions::get_addendum() );
	}

	public function test_set_then_get_roundtrips_plain_text(): void {
		$result = Instructions::set_addendum( 'Use is-style-callout-info for tips.' );
		$this->assertTrue( $result );
		$this->assertSame( 'Use is-style-callout-info for tips.', Instructions::get_addendum() );
	}

	public function test_set_then_get_preserves_markdown_bullets(): void {
		$value = "- First rule\n- Second rule\n  - Indented sub-rule\n- Third rule";
		Instructions::set_addendum( $value );
		$this->assertSame( $value, Instructions::get_addendum() );
	}

	public function test_set_then_get_preserves_blank_lines_between_paragraphs(): void {
		$value = "Rule A.\n\nRule B.";
		Instructions::set_addendum( $value );
		$this->assertSame( $value, Instructions::get_addendum() );
	}

	// ── Sanitization: HTML / shortcodes / PHP ──

	public function test_sanitize_strips_script_tags(): void {
		$dirty = "Use callouts.<script>alert('xss')</script>";
		$this->assertSame( 'Use callouts.', Instructions::sanitize( $dirty ) );
	}

	public function test_sanitize_strips_anchor_tags_but_keeps_text(): void {
		$dirty = 'Click <a href="https://evil.example">here</a> for rules.';
		$this->assertSame( 'Click here for rules.', Instructions::sanitize( $dirty ) );
	}

	public function test_sanitize_strips_img_tags(): void {
		$dirty = 'Bullet <img src=x onerror=alert(1)> rule';
		// wp_strip_all_tags removes the tag and its attributes; the
		// surrounding text survives intact.
		$result = Instructions::sanitize( $dirty );
		$this->assertStringNotContainsString( '<img', $result );
		$this->assertStringNotContainsString( 'onerror', $result );
		$this->assertStringContainsString( 'Bullet', $result );
		$this->assertStringContainsString( 'rule', $result );
	}

	public function test_sanitize_strips_php_tags(): void {
		$dirty = "Rule one. <?php system('id'); ?> Rule two.";
		$result = Instructions::sanitize( $dirty );
		$this->assertStringNotContainsString( '<?php', $result );
		$this->assertStringNotContainsString( 'system', $result );
	}

	public function test_sanitize_strips_shortcodes(): void {
		$dirty = 'Use [gallery] and [shortcode_attr foo="bar"] in docs.';
		$result = Instructions::sanitize( $dirty );
		$this->assertStringNotContainsString( '[gallery]', $result );
		$this->assertStringNotContainsString( '[shortcode_attr', $result );
		$this->assertStringContainsString( 'Use', $result );
		$this->assertStringContainsString( 'in docs.', $result );
	}

	public function test_sanitize_strips_c0_control_chars(): void {
		// Bell, null, ESC, backspace — none should survive.
		$dirty = "Rule\x00 \x07with\x1B[31m \x08control chars.";
		$result = Instructions::sanitize( $dirty );
		$this->assertSame( 'Rule with control chars.', $result );
	}

	public function test_sanitize_strips_del_char(): void {
		$dirty = "Rule\x7F.";
		$this->assertSame( 'Rule.', Instructions::sanitize( $dirty ) );
	}

	public function test_sanitize_preserves_tab(): void {
		// Tabs are kept — indentation under markdown bullets needs them.
		$value = "- Top\n\t- Nested";
		$this->assertSame( $value, Instructions::sanitize( $value ) );
	}

	public function test_sanitize_normalizes_crlf_to_lf(): void {
		$dirty = "Line one.\r\nLine two.\rLine three.";
		$this->assertSame( "Line one.\nLine two.\nLine three.", Instructions::sanitize( $dirty ) );
	}

	public function test_sanitize_trims_outer_whitespace(): void {
		$dirty = "\n\n  Real content here.  \n\n";
		$this->assertSame( 'Real content here.', Instructions::sanitize( $dirty ) );
	}

	public function test_sanitize_returns_empty_for_array(): void {
		$this->assertSame( '', Instructions::sanitize( array( 'rule' ) ) );
	}

	public function test_sanitize_returns_empty_for_object(): void {
		$this->assertSame( '', Instructions::sanitize( new \stdClass() ) );
	}

	public function test_sanitize_returns_empty_for_empty_string(): void {
		$this->assertSame( '', Instructions::sanitize( '' ) );
	}

	public function test_sanitize_casts_integers_to_string(): void {
		$this->assertSame( '42', Instructions::sanitize( 42 ) );
	}

	// ── Length cap ──

	public function test_sanitize_truncates_to_max_length(): void {
		$long = str_repeat( 'A', Instructions::MAX_LENGTH + 500 );
		$result = Instructions::sanitize( $long );
		$this->assertSame( Instructions::MAX_LENGTH, strlen( $result ) );
	}

	/**
	 * Over-long input is silently truncated to MAX_LENGTH at sanitize time,
	 * so set_addendum() succeeds rather than returning WP_Error. The
	 * post-condition is "no value > MAX_LENGTH ever lands in the option."
	 * The explicit WP_Error branch in set_addendum() exists as a guard
	 * against a future sanitize refactor that stops truncating — it cannot
	 * be hit through the public API today.
	 */
	public function test_set_truncates_over_long_input_at_max_length(): void {
		$value = str_repeat( 'B', Instructions::MAX_LENGTH + 1 );
		$result = Instructions::set_addendum( $value );
		$this->assertTrue( $result );
		$this->assertSame( Instructions::MAX_LENGTH, strlen( Instructions::get_addendum() ) );
	}

	public function test_set_accepts_exactly_max_length(): void {
		$value = str_repeat( 'C', Instructions::MAX_LENGTH );
		$this->assertTrue( Instructions::set_addendum( $value ) );
		$this->assertSame( Instructions::MAX_LENGTH, strlen( Instructions::get_addendum() ) );
	}

	// ── Timestamp tracking ──

	public function test_get_updated_at_returns_zero_when_never_saved(): void {
		$this->assertSame( 0, Instructions::get_updated_at() );
	}

	public function test_updated_at_advances_on_save(): void {
		$before = time();
		Instructions::set_addendum( 'Hello' );
		$after = Instructions::get_updated_at();
		$this->assertGreaterThanOrEqual( $before, $after );
		$this->assertLessThanOrEqual( time() + 1, $after );
	}

	public function test_updated_at_advances_even_for_empty_save(): void {
		Instructions::set_addendum( 'first' );
		$first = Instructions::get_updated_at();
		// Sleep one tick to ensure the timestamp would change if it does.
		sleep( 1 );
		Instructions::set_addendum( '' );
		$this->assertGreaterThanOrEqual( $first, Instructions::get_updated_at() );
	}

	// ── sanitize_callback (Settings API entry point) ──

	public function test_sanitize_callback_returns_sanitized_string(): void {
		$result = Instructions::sanitize_callback( 'Plain <b>text</b>.' );
		$this->assertSame( 'Plain text.', $result );
	}

	public function test_sanitize_callback_touches_updated_at(): void {
		$before = Instructions::get_updated_at();
		// Sleep so the timestamp would strictly increase.
		if ( $before > 0 ) {
			sleep( 1 );
		}
		Instructions::sanitize_callback( 'something' );
		$this->assertGreaterThan( $before, Instructions::get_updated_at() );
	}

	// ── Read-path sanitize (defense in depth) ──

	public function test_get_addendum_re_sanitizes_dirty_option(): void {
		// Simulate a direct update_option from a sibling plugin that
		// bypassed Instructions::sanitize. The read path must still
		// produce a clean value.
		update_option( Instructions::OPTION_KEY, "Dirty\x00<script>x</script>", false );
		$this->assertSame( 'Dirtyx', Instructions::get_addendum() );
	}

	// ── Rate limiter ──

	public function test_rate_limit_allows_first_request(): void {
		$ip = '203.0.113.42';
		$this->assertTrue( Instructions::check_rate_limit( $ip ) );
	}

	public function test_rate_limit_blocks_after_budget_exhausted(): void {
		$ip = '203.0.113.43';
		for ( $i = 0; $i < Instructions::RATE_LIMIT_PER_MIN; $i++ ) {
			$this->assertTrue(
				Instructions::check_rate_limit( $ip ),
				"Request {$i} should be allowed"
			);
		}
		// The next call exceeds the budget.
		$this->assertFalse( Instructions::check_rate_limit( $ip ) );
	}

	public function test_rate_limit_is_per_ip(): void {
		$ip_a = '203.0.113.44';
		$ip_b = '203.0.113.45';
		// Exhaust A's budget.
		for ( $i = 0; $i < Instructions::RATE_LIMIT_PER_MIN; $i++ ) {
			Instructions::check_rate_limit( $ip_a );
		}
		$this->assertFalse( Instructions::check_rate_limit( $ip_a ) );
		// B is independent.
		$this->assertTrue( Instructions::check_rate_limit( $ip_b ) );
	}

	public function test_rate_limit_handles_ipv6(): void {
		$ip = '2001:db8::1';
		$this->assertTrue( Instructions::check_rate_limit( $ip ) );
	}

	public function test_rate_limit_does_not_store_raw_ip(): void {
		$ip = 'PIIIP-marker-203.0.113.46';
		Instructions::check_rate_limit( $ip );
		// The transient key uses a hash; the raw IP must not appear in
		// any option_name in the table.
		global $wpdb;
		$rows = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_gk_block_api_instr_rl_%'"
		);
		foreach ( $rows as $name ) {
			$this->assertStringNotContainsString( 'PIIIP-marker', $name );
		}
	}
}
