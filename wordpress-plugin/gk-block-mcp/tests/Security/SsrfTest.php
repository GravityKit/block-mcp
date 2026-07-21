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
 * @package GravityKit\BlockMCP\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Media_Manager;

class SsrfTest extends WP_UnitTestCase {

	/** @var Media_Manager */
	private $mm;

	public function set_up(): void {
		parent::set_up();
		$this->mm = new Media_Manager();
		// Make sure uploads are enabled — these tests assert the SSRF guard,
		// not the kill-switch.
		update_option( Media_Manager::UPLOADS_OPTION, '1' );
	}

	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
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

	/**
	 * `0.0.0.0` — on Linux this aliases to localhost; on some systems
	 * it hits `127.0.0.1`. Must reject either way.
	 */
	public function test_blocks_zero_dot_zero() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://0.0.0.0/' ) ), '0.0.0.0' );
	}

	public function test_blocks_rfc1918_10() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://10.0.0.1/admin' ) ), '10.0.0.1' );
	}

	public function test_blocks_cgn_100_64() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'http://100.64.0.1/' ) ), '100.64.0.1 carrier-grade NAT (RFC6598)' );
	}

	/**
	 * The fetch is pinned to the exact IP(s) guard_ssrf vetted via CURLOPT_RESOLVE,
	 * so download_url()'s independent second DNS lookup can't be rebound to a
	 * private IP. This pins the resolve-entry construction (host:port:ips).
	 */
	public function test_curl_resolve_entries_pin_vetted_ips_to_host_and_port() {
		$ref = new \ReflectionMethod( \GravityKit\BlockMCP\Media_Manager::class, 'curl_resolve_entries' );
		$ref->setAccessible( true );

		$https = $ref->invoke( $this->mm, 'https://cdn.example/img.png', array( 'host' => 'cdn.example', 'ipv4' => array( '93.184.216.34' ), 'ipv6' => array() ) );
		$this->assertSame( array( 'cdn.example:443:93.184.216.34' ), $https, 'https pins to port 443 and the vetted IPv4' );

		$http = $ref->invoke( $this->mm, 'http://cdn.example/img.png', array( 'host' => 'cdn.example', 'ipv4' => array( '1.2.3.4' ), 'ipv6' => array( '2001:db8::1' ) ) );
		$this->assertSame( array( 'cdn.example:80:1.2.3.4,2001:db8::1' ), $http, 'http pins to port 80 and all vetted IPs' );
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
		// 2130706433 == 127.0.0.1. wp_parse_url() reports the host as
		// "2130706433"; the guard tries dns_get_record on that, gets
		// nothing, falls back to gethostbyname(). gethostbyname accepts
		// decimal-encoded IPs on many systems and returns 127.0.0.1,
		// which the guard rejects. If the host fails to resolve, the
		// guard also rejects with 'invalid_url'. Either rejection is fine.
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://2130706433/admin' ) ),
			'decimal-encoded loopback (2130706433)'
		);
	}

	public function test_blocks_hex_encoded_loopback() {
		// 0x7f000001 == 127.0.0.1
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://0x7f000001/' ) ),
			'hex-encoded loopback (0x7f000001)'
		);
	}

	public function test_blocks_octal_encoded_loopback() {
		// 0177.0.0.1 — octal representation of 127.0.0.1
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://0177.0.0.1/' ) ),
			'octal-encoded loopback (0177.0.0.1)'
		);
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

	/**
	 * `http://attacker:password@127.0.0.1/x` — userinfo doesn't change
	 * the host. The guard must still see `127.0.0.1` as the host.
	 */
	public function test_blocks_userinfo_on_loopback() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://attacker:pwd@127.0.0.1/' ) ),
			'userinfo-prefixed loopback'
		);
	}

	/**
	 * `http://user@evil:bad@127.0.0.1/x` — naive parsers can misread
	 * the `@` inside the userinfo and treat `evil` as host.
	 */
	public function test_blocks_userinfo_with_at_in_password() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://user@evil:bad@127.0.0.1/' ) ),
			'userinfo with @ in password'
		);
	}

	public function test_blocks_non_http_scheme_ftp() {
		$this->assertRejected( $this->mm->upload( array( 'url' => 'ftp://example.com/x' ) ), 'ftp:// scheme' );
	}

	public function test_blocks_non_http_scheme_file() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'file:///etc/passwd' ) ),
			'file:// scheme'
		);
	}

	public function test_blocks_non_http_scheme_gopher() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'gopher://127.0.0.1:6379/' ) ),
			'gopher:// (Redis RCE class)'
		);
	}

	// ── No-host / malformed URLs ──────────────────────────────────

	public function test_blocks_url_without_host() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http:///path' ) ),
			'URL with no host'
		);
	}

	/**
	 * A host that can't be DNS-resolved is rejected too — paranoid
	 * default rather than letting `download_url()` try.
	 */
	public function test_blocks_unresolvable_host() {
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://this-host-definitely-does-not-exist.invalid.gk-block-mcp-test/' ) ),
			'unresolvable host'
		);
	}

	/**
	 * The URL sideload fetch must run with HTTP redirects DISABLED.
	 *
	 * guard_ssrf() validates only the caller's URL. download_url() otherwise
	 * follows up to 5 redirects, and a 302 to 169.254.169.254 or a private host
	 * is fetched without re-validation (core's wp_http_validate_url does not
	 * block the cloud-metadata range on redirects). This asserts the transport
	 * receives redirection=0 for the fetch — teeth: without the redirect-
	 * disabling filter the value is core's default 5 and this fails.
	 */
	public function test_url_fetch_disables_http_redirects() {
		$seen_redirection = null;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args ) use ( &$seen_redirection ) {
				unset( $preempt );
				$seen_redirection = $args['redirection'];
				// Short-circuit with a benign non-image so the fetch ends here.
				return array(
					'headers'  => array(),
					'body'     => 'x',
					'response' => array( 'code' => 200, 'message' => 'OK' ),
					'cookies'  => array(),
					'filename' => $args['filename'] ?? null,
				);
			},
			10,
			3
		);

		$this->mm->upload( array( 'url' => 'https://203.0.113.5/logo.png' ) );

		$this->assertSame( 0, $seen_redirection, 'the sideload fetch must disable HTTP redirects (redirection=0)' );
	}

	// ── Filter-extensibility ──────────────────────────────────────

	public function test_filter_can_block_additional_ipv4_range() {
		add_filter( 'gk/block-mcp/media/sideload-blocked-ranges', static function ( $ranges ) {
			$ranges[] = array( '203.0.113.0', '203.0.113.255' );
			return $ranges;
		} );
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://203.0.113.42/' ) ),
			'admin filter adds 203.0.113/24 to blocklist'
		);
	}

	public function test_filter_can_block_additional_ipv6_cidr() {
		add_filter( 'gk/block-mcp/media/sideload-blocked-ipv6-cidrs', static function ( $cidrs ) {
			$cidrs[] = '2001:db8::/32';
			return $cidrs;
		} );
		$this->assertRejected(
			$this->mm->upload( array( 'url' => 'http://[2001:db8::1]/' ) ),
			'admin filter adds 2001:db8::/32 to blocklist'
		);
	}

	// ── HTTP-redirect bypass ───────────────────────────────────────

	/**
	 * Scenario: an attacker provides a URL whose initial host passes
	 * the SSRF guard (public IP) but the response is a 302 to a private
	 * IP. WP's `download_url` follows redirects by default; if the
	 * guard runs only once on the initial URL, the attacker reads
	 * internal resources.
	 *
	 * The plugin must NOT let the redirect to a reserved IP succeed.
	 * Two acceptable outcomes:
	 *
	 *  (A) redirect-following is disabled for this fetch (no follow
	 *      → no internal read);
	 *  (B) the post-redirect URL is re-validated before storage.
	 *
	 * The test pre-empts `wp_safe_remote_get` to fail any private-IP
	 * fetch: if WP would have followed to `169.254.169.254`, the
	 * `pre_http_request` hook trips the assertion.
	 */
	public function test_no_redirect_following_into_private_ip() {
		$private_url        = 'http://169.254.169.254/latest/meta-data/iam';
		$public_url         = 'https://203.0.113.5/redirect.png';
		$initial_hook_fired = false;

		// Inner guard: if the plugin EVER calls out for the private URL,
		// the redirect was followed → failing.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $private_url ) {
			unset( $args ); // Positional placeholder — signature requires it for $url.
			$this->assertNotSame(
				$private_url,
				$url,
				'plugin must NOT follow a redirect to a private/link-local IP'
			);
			return $preempt;
		}, 10, 3 );

		// Outer hook: simulate the 302-to-private response on the initial
		// public URL. Sets the `$initial_hook_fired` flag so we can later
		// verify the public-URL fetch actually ran — without this flag
		// the test could pass vacuously if download_url() rejected the
		// public URL upstream of any HTTP fetch.
		add_filter( 'pre_http_request', function ( $preempt, $args, $url ) use ( $public_url, $private_url, &$initial_hook_fired ) {
			if ( $url !== $public_url ) {
				return $preempt;
			}
			$initial_hook_fired = true;
			// Return a Location redirect to the private IP. download_url
			// uses wp_safe_remote_get which follows redirects up to 5
			// hops by default — if it follows, the assertion above fires.
			return array(
				'headers'  => array( 'location' => $private_url ),
				'body'     => '',
				'response' => array( 'code' => 302, 'message' => 'Found' ),
				'cookies'  => array(),
				'filename' => $args['filename'] ?? null,
			);
		}, 10, 3 );

		$result = $this->mm->upload( array( 'url' => $public_url ) );
		$this->assertTrue(
			$initial_hook_fired,
			'public-URL pre_http_request hook must have fired; otherwise the redirect-not-followed assertion is vacuous'
		);
		// The download attempt fails (either rejected by SSRF re-check,
		// or the response 302 wasn't followed) — either way the upload
		// returns an error, not a successful attachment.
		$this->assertInstanceOf( \WP_Error::class, $result );
	}
}
