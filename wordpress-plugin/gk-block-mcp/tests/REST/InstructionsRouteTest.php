<?php
/**
 * Integration tests for `GET /gk-block-api/v1/instructions`.
 *
 * Pins the public-by-design read endpoint that serves the per-site MCP
 * serverInfo addendum (BLOCK-19). Covers: response shape, cache headers,
 * unauthenticated access, rate-limit enforcement, defense-in-depth
 * re-sanitization, and the timestamp contract.
 *
 * @package GravityKit\BlockMCP\Tests
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Instructions;

class InstructionsRouteTest extends RestControllerTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Instructions::OPTION_KEY );
		delete_option( Instructions::UPDATED_AT_OPTION );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
	}

	public function tear_down(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tear_down();
	}

	private function make_request(): \WP_REST_Request {
		return new \WP_REST_Request( 'GET', '/gk-block-api/v1/instructions' );
	}

	// ── Response shape ────────────────────────────────────────────────

	/**
	 * Empty addendum returns shape `{ addendum:"", length:0, max_length, updated_at:0 }`.
	 * Empty addendum is NOT 404 — clients should not have to special-case
	 * the missing-vs-empty distinction. updated_at=0 communicates "never
	 * set" via the data itself.
	 */
	public function test_empty_state_returns_full_shape(): void {
		$response = $this->controller->get_instructions( $this->make_request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( '', $data['addendum'] );
		$this->assertSame( 0, $data['length'] );
		$this->assertSame( Instructions::MAX_LENGTH, $data['max_length'] );
		$this->assertSame( 0, $data['updated_at'] );
	}

	/**
	 * Saved addendum is round-tripped verbatim through the endpoint and
	 * length reflects the post-sanitize byte length.
	 */
	public function test_stored_addendum_is_returned(): void {
		Instructions::set_addendum( "Use is-style-callout-info for tips.\nFirst H2 is Overview." );

		$response = $this->controller->get_instructions( $this->make_request() );
		$data     = $response->get_data();

		$this->assertSame(
			"Use is-style-callout-info for tips.\nFirst H2 is Overview.",
			$data['addendum']
		);
		$this->assertSame( strlen( $data['addendum'] ), $data['length'] );
		$this->assertGreaterThan( 0, $data['updated_at'] );
	}

	// ── Auth posture ──────────────────────────────────────────────────

	/**
	 * Endpoint is public by design — anonymous users (no `wp_set_current_user`)
	 * receive a 200 with the addendum. The MCP server fetches this BEFORE
	 * any auth-gated tool call; gating it would break the handshake.
	 */
	public function test_unauthenticated_request_succeeds(): void {
		// Default test environment has no logged-in user.
		wp_set_current_user( 0 );
		Instructions::set_addendum( 'public payload' );

		$response = $this->controller->get_instructions( $this->make_request() );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'public payload', $response->get_data()['addendum'] );
	}

	// ── Cache header ──────────────────────────────────────────────────

	/**
	 * `Cache-Control: public, max-age=60` is set on every response. Short
	 * TTL keeps admin edits fast to land while still letting reverse
	 * proxies / surrogates cache.
	 */
	public function test_sets_short_public_cache_control(): void {
		$response = $this->controller->get_instructions( $this->make_request() );

		$headers = $response->get_headers();
		$this->assertArrayHasKey( 'Cache-Control', $headers );
		$this->assertSame( 'public, max-age=60', $headers['Cache-Control'] );
	}

	// ── Defense in depth: read-time re-sanitize ───────────────────────

	/**
	 * Direct `update_option` writes that bypass `Instructions::sanitize`
	 * (sibling plugin, database restore from older schema) must NOT reach
	 * the wire. The read path re-sanitizes as belt-and-braces.
	 */
	public function test_dirty_option_is_resanitized_on_read(): void {
		update_option( Instructions::OPTION_KEY, "Hello\x00<script>nope</script>", false );

		$response = $this->controller->get_instructions( $this->make_request() );
		$data     = $response->get_data();

		// wp_strip_all_tags removes <script> tags AND their content (not
		// just the opening/closing markers); the \x00 control byte is then
		// stripped by the C0 pass. Final payload is the leading text only.
		$this->assertSame( 'Hello', $data['addendum'] );
	}

	// ── Rate limiting ─────────────────────────────────────────────────

	/**
	 * After RATE_LIMIT_PER_MIN requests within the sliding window, the
	 * next request from the same IP returns a 429-equivalent WP_Error.
	 * Independent IPs are unaffected.
	 */
	public function test_returns_429_when_per_ip_budget_exhausted(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		// Exhaust the budget.
		for ( $i = 0; $i < Instructions::RATE_LIMIT_PER_MIN; $i++ ) {
			$ok = $this->controller->get_instructions( $this->make_request() );
			$this->assertInstanceOf( \WP_REST_Response::class, $ok, "Request {$i} should be allowed" );
		}

		// Next call exceeds.
		$result = $this->controller->get_instructions( $this->make_request() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertSame( 429, $data['status'] );
	}

	/**
	 * Rate limit is scoped per-IP. Exhausting one IP's budget does not
	 * lock out another. Defends against a noisy neighbour scenario.
	 */
	public function test_rate_limit_does_not_leak_across_ips(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.8';
		for ( $i = 0; $i < Instructions::RATE_LIMIT_PER_MIN; $i++ ) {
			$this->controller->get_instructions( $this->make_request() );
		}
		// Exhausted.
		$blocked = $this->controller->get_instructions( $this->make_request() );
		$this->assertInstanceOf( \WP_Error::class, $blocked );

		// Different IP — fresh budget.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.9';
		$ok = $this->controller->get_instructions( $this->make_request() );
		$this->assertInstanceOf( \WP_REST_Response::class, $ok );
	}

	// ── Timestamp contract ────────────────────────────────────────────

	/**
	 * `updated_at` strictly increases (or stays equal) across saves and
	 * advances even when the addendum is cleared. Lets clients detect
	 * "explicitly cleared" vs "never set".
	 */
	public function test_updated_at_advances_on_clear(): void {
		Instructions::set_addendum( 'first value' );
		$first = $this->controller->get_instructions( $this->make_request() )->get_data()['updated_at'];
		$this->assertGreaterThan( 0, $first );

		sleep( 1 );
		Instructions::set_addendum( '' );

		$second = $this->controller->get_instructions( $this->make_request() )->get_data()['updated_at'];
		$this->assertGreaterThanOrEqual( $first, $second );
	}
}
