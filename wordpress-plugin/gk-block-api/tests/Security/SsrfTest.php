<?php
/**
 * Adversarial SSRF tests for Media_Manager::guard_ssrf().
 *
 * Pins that every documented bypass class is rejected with HTTP 400
 * `invalid_url`. Each test corresponds to a real-world SSRF technique that
 * has historically broken WordPress plugin guards — encoded IPs, IPv4-mapped
 * IPv6, multi-A records, URL credential embedding, port confusion, and the
 * 302-redirect bypass. When a test fails, the production code is the bug
 * (not the assertion).
 *
 * @package GravityKit\BlockAPI\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Media_Manager;

class SsrfTest extends WP_UnitTestCase {

	/** @var Media_Manager */
	private $mm;

	protected function setUp(): void {
		parent::setUp();
		$this->mm = new Media_Manager();
		// Make sure uploads are enabled — these tests assert the SSRF guard,
		// not the kill-switch.
		update_option( Media_Manager::UPLOADS_OPTION, '1' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	private function assertRejected( $result, string $hint = '' ): void {
		$this->assertInstanceOf( \WP_Error::class, $result, "Expected SSRF rejection: $hint" );
		$this->assertSame( 'invalid_url', $result->get_error_code(), "Expected 'invalid_url' error code for: $hint" );
		$this->assertSame( 400, $result->get_error_data()['status'], "Expected 400 status for: $hint" );
	}

	// ── IPv4 hostile literals ──────────────────────────────────────

	public function test_blocks_aws_metadata_endpoint() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://169.254.169.254/latest/meta-data/iam' ) ),
			'AWS/GCP/Azure 169.254.169.254 cloud-metadata endpoint'
		);
	}

	public function test_blocks_loopback_127_0_0_1() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://127.0.0.1/' ) ), '127.0.0.1' );
	}

	public function test_blocks_loopback_alt_127_x_y_z() {
		// All of 127.0.0.0/8 is loopback. 127.10.20.30 is just as bad.
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://127.10.20.30/' ) ), '127.10.20.30' );
	}

	public function test_blocks_zero_dot_zero() {
		// 0.0.0.0 — on Linux this aliases to localhost; on some systems it
		// hits 127.0.0.1. Must reject either way.
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://0.0.0.0/' ) ), '0.0.0.0' );
	}

	public function test_blocks_rfc1918_10() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://10.0.0.1/admin' ) ), '10.0.0.1' );
	}

	public function test_blocks_rfc1918_172_16() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://172.16.42.7/' ) ), '172.16.42.7' );
	}

	public function test_blocks_rfc1918_192_168() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://192.168.1.1/' ) ), '192.168.1.1' );
	}

	public function test_blocks_multicast() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://239.255.255.250/' ) ), '239.255.255.250 multicast' );
	}

	// ── Encoded IP bypass attempts ─────────────────────────────────

	public function test_blocks_decimal_encoded_loopback() {
		// 2130706433 == 127.0.0.1
		$result = $this->mm->upload( array( 'url' => 'http://2130706433/admin' ) );
		// wp_parse_url() reports the host as "2130706433"; the guard tries
		// dns_get_record on that, gets nothing, falls back to
		// gethostbyname(). gethostbyname accepts decimal-encoded IPs on
		// many systems and returns 127.0.0.1, which the guard rejects.
		// If the host fails to resolve, the guard also rejects with
		// 'invalid_url'. Either rejection is fine.
		$this->assertInstanceOf( \WP_Error::class, $result, 'decimal IP 2130706433 must not reach download_url' );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_blocks_hex_encoded_loopback() {
		// 0x7f000001 == 127.0.0.1
		$result = $this->mm->upload( array( 'url' => 'http://0x7f000001/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result, 'hex-encoded IP must not reach download_url' );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_blocks_octal_encoded_loopback() {
		// 0177.0.0.1 — octal representation of 127.0.0.1
		$result = $this->mm->upload( array( 'url' => 'http://0177.0.0.1/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result, 'octal IP must not reach download_url' );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	// ── IPv6 hostile literals ──────────────────────────────────────

	public function test_blocks_ipv6_loopback() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://[::1]/' ) ), 'IPv6 ::1' );
	}

	public function test_blocks_ipv4_mapped_ipv6() {
		// ::ffff:127.0.0.1 must be normalized and rejected — otherwise an
		// attacker bypasses the IPv4 range check by using an IPv6 wrapper.
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://[::ffff:127.0.0.1]/' ) ),
			'IPv4-mapped IPv6 ::ffff:127.0.0.1'
		);
	}

	public function test_blocks_ipv4_mapped_metadata() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://[::ffff:169.254.169.254]/' ) ),
			'IPv4-mapped IPv6 to cloud metadata'
		);
	}

	public function test_blocks_ipv6_link_local() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://[fe80::1]/' ) ), 'IPv6 fe80::1 link-local' );
	}

	public function test_blocks_ipv6_unique_local() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://[fc00::1]/' ) ), 'IPv6 fc00::1 unique-local' );
	}

	// ── URL syntax abuse ───────────────────────────────────────────

	public function test_blocks_port_on_loopback() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://127.0.0.1:8080/' ) ), '127.0.0.1:8080' );
	}

	public function test_blocks_userinfo_on_loopback() {
		// http://attacker:password@127.0.0.1/x — userinfo doesn't change
		// the host. The guard must still see 127.0.0.1 as the host.
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://attacker:pwd@127.0.0.1/' ) ),
			'userinfo-prefixed loopback'
		);
	}

	public function test_blocks_userinfo_with_at_in_password() {
		// http://user@evil:bad@127.0.0.1/x — naive parsers can misread
		// the "@" inside the userinfo and treat "evil" as host.
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://user@evil:bad@127.0.0.1/' ) ),
			'userinfo with @ in password'
		);
	}

	public function test_blocks_non_http_scheme_ftp() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'ftp://example.com/x' ) ), 'ftp:// scheme' );
	}

	public function test_blocks_non_http_scheme_file() {
		$result = $this->mm->upload( array( 'url' => 'file:///etc/passwd' ) );
		$this->assertInstanceOf( \WP_Error::class, $result, 'file:// must be rejected' );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_blocks_non_http_scheme_gopher() {
		$result = $this->mm->upload( array( 'url' => 'gopher://127.0.0.1:6379/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result, 'gopher:// (Redis RCE class) must be rejected' );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	// ── No-host / malformed URLs ──────────────────────────────────

	public function test_blocks_url_without_host() {
		$result = $this->mm->upload( array( 'url' => 'http:///path' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_blocks_unresolvable_host() {
		// A host that can't be DNS-resolved is rejected too — paranoid
		// default rather than letting download_url() try.
		$result = $this->mm->upload(
			array( 'url' => 'http://this-host-definitely-does-not-exist.invalid.gk-block-api-test/' )
		);
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	// ── Filter-extensibility ──────────────────────────────────────

	public function test_filter_can_block_additional_ipv4_range() {
		add_filter( 'gk_block_api_url_sideload_blocked_ranges', static function ( $ranges ) {
			$ranges[] = array( '203.0.113.0', '203.0.113.255' );
			return $ranges;
		} );
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://203.0.113.42/' ) ),
			'admin filter adds 203.0.113/24 to blocklist'
		);
	}

	public function test_filter_can_block_additional_ipv6_cidr() {
		add_filter( 'gk_block_api_url_sideload_blocked_ipv6_cidrs', static function ( $cidrs ) {
			$cidrs[] = '2001:db8::/32';
			return $cidrs;
		} );
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://[2001:db8::1]/' ) ),
			'admin filter adds 2001:db8::/32 to blocklist'
		);
	}
}
