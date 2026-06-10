<?php
/**
 * Connect_Page credential sealing + short-lived storage: exhaustive coverage.
 *
 * The connect flow mints a WordPress Application Password and must hold it
 * briefly between two HTTP requests (the admin's browser Approve, then the
 * connector's exchange POST) without leaving a recoverable plaintext at rest.
 * Two primitives back that:
 *
 *   - seal_secret() / unseal_secret(): AES-256-GCM authenticated encryption with
 *     an HKDF-derived key (domain-separated from any other wp_salt('auth') use),
 *     a fresh 96-bit IV per call, and a 128-bit tag. A value WITHOUT the seal
 *     marker is REJECTED on a seal-capable host (closing an inject-unsealed
 *     tampering vector); it is accepted as plaintext only where sealing is
 *     unavailable.
 *   - put_record() / take_record() / gc_records(): a non-autoloaded wp_options
 *     store with an embedded expiry — deliberately NOT a transient, so the
 *     browser->connector handoff survives object-cache / multi-server topologies
 *     (transients live in the object cache, where a per-server cache makes the
 *     connector miss the value and an LRU cache can evict it before the TTL).
 *
 * These tests pin: round-trip across edge inputs, sealed-format invariants, IV
 * uniqueness, every tamper/truncation/wrong-key rejection path, the
 * reject-unsealed-on-capable-host contract, single-use replay protection,
 * expiry enforcement, autoload='no', the GC sweep (and that its throttle marker
 * is never swept), and the end-to-end store/redeem exchange contract.
 *
 * The test host has openssl + aes-256-gcm, so can_seal() is true throughout;
 * the openssl-unavailable plaintext-passthrough branch cannot be exercised here
 * (it would require disabling the extension) and is asserted by inspection.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Connect_Page;

/**
 * @covers \GravityKit\BlockMCP\Connect_Page
 */
class CredentialSealTest extends WP_UnitTestCase {

	/**
	 * Connect_Page instance under test.
	 *
	 * @var Connect_Page
	 */
	private $page;

	public function set_up() {
		parent::set_up();
		$this->page = new Connect_Page();
	}

	/**
	 * Invoke a protected/private Connect_Page method by name.
	 *
	 * setAccessible() is a no-op on PHP 8.1+ but keeps the 7.4 floor green.
	 *
	 * @param  string $method Method name.
	 * @param  array  $args   Positional args.
	 * @return mixed
	 */
	private function call( string $method, array $args = array() ) {
		$ref = new \ReflectionMethod( Connect_Page::class, $method );
		$ref->setAccessible( true );
		return $ref->invokeArgs( $this->page, $args );
	}

	private function seal( $plaintext ) {
		return $this->call( 'seal_secret', array( $plaintext ) );
	}

	private function unseal( $sealed ) {
		return $this->call( 'unseal_secret', array( $sealed ) );
	}

	// ──────────────────────────────────────────────────────────────────────
	// seal_secret() / unseal_secret() — round-trip + format.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * A typical Application Password round-trips through seal -> unseal.
	 */
	public function test_round_trips_a_typical_password() {
		$secret = 'abcd EFGH 1234 wxyz 7890 KLMN';
		$this->assertSame( $secret, $this->unseal( $this->seal( $secret ) ) );
	}

	/**
	 * Edge-shaped secrets round-trip: multibyte/emoji, quotes, newlines, a value
	 * that itself contains the seal prefix, and base64-looking content. A binary-
	 * safe AEAD must not corrupt or mis-handle any of these.
	 *
	 * @dataProvider edge_secret_provider
	 *
	 * @param string $secret Input to seal then unseal.
	 */
	public function test_round_trips_edge_inputs( string $secret ) {
		$this->assertSame( $secret, $this->unseal( $this->seal( $secret ) ) );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public function edge_secret_provider(): array {
		return array(
			'multibyte + emoji'   => array( 'pâsswörd—✓🔐 «секрет»' ),
			'quotes + backslash'  => array( 'a"b\'c\\d`e' ),
			'newlines + tabs'     => array( "line1\nline2\tcol" ),
			'contains seal prefix' => array( Connect_Page::SEAL_PREFIX . 'looks-sealed-but-isnt' ),
			'base64-looking'      => array( 'YWJjZGVmZ2hpamtsbW5vcA==' ),
			'long 4KB secret'     => array( str_repeat( 'X9_', 1400 ) ),
			'single char'         => array( 'x' ),
		);
	}

	/**
	 * A sealed value carries the marker, is valid base64, decodes to at least
	 * IV+TAG bytes, and never contains the plaintext.
	 */
	public function test_sealed_output_format_and_no_plaintext_leak() {
		$secret = 'top-secret-value-123';
		$sealed = $this->seal( $secret );

		$this->assertStringStartsWith( Connect_Page::SEAL_PREFIX, $sealed );
		$this->assertStringNotContainsString( $secret, $sealed, 'plaintext must not appear in the sealed blob' );

		$raw = base64_decode( substr( $sealed, strlen( Connect_Page::SEAL_PREFIX ) ), true );
		$this->assertNotFalse( $raw, 'payload after the prefix must be valid base64' );
		$this->assertGreaterThanOrEqual(
			Connect_Page::SEAL_IV_LEN + Connect_Page::SEAL_TAG_LEN,
			strlen( $raw ),
			'sealed payload must hold at least the IV + tag'
		);
	}

	/**
	 * A fresh IV is used per call: sealing the same input twice yields different
	 * ciphertexts (no IV reuse), and both still unseal to the original.
	 */
	public function test_uses_fresh_iv_each_call() {
		$secret = 'same-input-twice';
		$a      = $this->seal( $secret );
		$b      = $this->seal( $secret );

		$this->assertNotSame( $a, $b, 'two seals of the same input must differ (fresh IV)' );
		$this->assertSame( $secret, $this->unseal( $a ) );
		$this->assertSame( $secret, $this->unseal( $b ) );
	}

	/**
	 * The empty string is a no-op for sealing (nothing to protect). On a
	 * seal-capable host, unsealing a non-prefixed value (including '') returns
	 * null per the reject-unsealed contract.
	 */
	public function test_empty_string_is_not_sealed() {
		$this->assertSame( '', $this->seal( '' ) );
		$this->assertNull( $this->unseal( '' ) );
	}

	// ──────────────────────────────────────────────────────────────────────
	// unseal_secret() — rejection paths.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * On a seal-capable host, a value WITHOUT the seal marker is rejected (null),
	 * not trusted as plaintext.
	 *
	 * Regression: unseal_secret() used to pass any non-prefixed value through
	 * unchanged, letting an attacker who can write the store inject an unsealed
	 * credential. On a host that can seal, this code would never have written
	 * plaintext, so a missing marker is anomalous and must be refused.
	 */
	public function test_rejects_unsealed_value_on_seal_capable_host() {
		$this->assertNull( $this->unseal( 'just-some-plaintext' ) );
		$this->assertNull( $this->unseal( 'your application password' ) );
	}

	/**
	 * Non-string input is rejected.
	 */
	public function test_rejects_non_string_input() {
		$this->assertNull( $this->unseal( null ) );
		$this->assertNull( $this->unseal( 12345 ) );
		$this->assertNull( $this->unseal( array( 'x' ) ) );
	}

	/**
	 * A sealed token shorter than IV+TAG is rejected.
	 */
	public function test_rejects_truncated_token() {
		$short = Connect_Page::SEAL_PREFIX . base64_encode( random_bytes( 10 ) );
		$this->assertNull( $this->unseal( $short ) );
	}

	/**
	 * A sealed token whose payload is not valid base64 is rejected.
	 */
	public function test_rejects_invalid_base64() {
		$this->assertNull( $this->unseal( Connect_Page::SEAL_PREFIX . '!!! not base64 !!!' ) );
	}

	/**
	 * Flipping any byte of the IV, tag, or ciphertext fails GCM authentication, so
	 * unseal returns null rather than a corrupt/forged plaintext.
	 *
	 * @dataProvider tamper_offset_provider
	 *
	 * @param int $offset Byte offset to corrupt within the raw IV|tag|cipher blob.
	 */
	public function test_rejects_tampered_token( int $offset ) {
		$sealed = $this->seal( 'authentic-secret-value' );
		$raw    = base64_decode( substr( $sealed, strlen( Connect_Page::SEAL_PREFIX ) ), true );

		$raw[ $offset ] = ( "\x00" === $raw[ $offset ] ) ? "\x01" : ( $raw[ $offset ] ^ "\xff" );
		$tampered       = Connect_Page::SEAL_PREFIX . base64_encode( $raw );

		$this->assertNull( $this->unseal( $tampered ) );
	}

	/**
	 * @return array<string, array{0:int}>
	 */
	public function tamper_offset_provider(): array {
		return array(
			'IV byte'         => array( 0 ),
			'tag byte'        => array( Connect_Page::SEAL_IV_LEN + 1 ),
			'ciphertext byte' => array( Connect_Page::SEAL_IV_LEN + Connect_Page::SEAL_TAG_LEN ),
		);
	}

	/**
	 * A correctly-formatted token sealed under a DIFFERENT key fails to
	 * authenticate and returns null. This is the salt-rotation / wrong-site case:
	 * the current host's key can never decrypt it.
	 */
	public function test_rejects_value_sealed_under_a_different_key() {
		$wrong_key = random_bytes( 32 );
		$iv        = random_bytes( Connect_Page::SEAL_IV_LEN );
		$tag       = '';
		$cipher    = openssl_encrypt( 'secret', 'aes-256-gcm', $wrong_key, OPENSSL_RAW_DATA, $iv, $tag, '', Connect_Page::SEAL_TAG_LEN );
		$blob      = Connect_Page::SEAL_PREFIX . base64_encode( $iv . $tag . $cipher );

		$this->assertNull( $this->unseal( $blob ) );
	}

	// ──────────────────────────────────────────────────────────────────────
	// put_record() / take_record() — wp_options storage primitive.
	// ──────────────────────────────────────────────────────────────────────

	private function put( string $key, array $value, int $ttl ) {
		return $this->call( 'put_record', array( $key, $value, $ttl ) );
	}

	private function take( string $key ) {
		return $this->call( 'take_record', array( $key ) );
	}

	/**
	 * A record round-trips put -> take, with the internal expires_at stripped.
	 */
	public function test_put_take_round_trips_a_record() {
		$key = Connect_Page::EXCHANGE_OPTION_PREFIX . 'rt';
		$this->put( $key, array( 'a' => 1, 'b' => 'two' ), 120 );

		$got = $this->take( $key );
		$this->assertSame( array( 'a' => 1, 'b' => 'two' ), $got );
		$this->assertArrayNotHasKey( 'expires_at', (array) $got );
	}

	/**
	 * The record is stored in wp_options with autoload='no', so short-lived
	 * secret blobs never enter the autoloaded options cache.
	 */
	public function test_record_is_stored_non_autoloaded() {
		global $wpdb;
		$key = Connect_Page::EXCHANGE_OPTION_PREFIX . 'autoload';
		$this->put( $key, array( 'x' => 1 ), 120 );

		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		// WP 6.6+ normalises autoload='no' to the 'off' column value; older WP uses
		// 'no'. Assert the record is NOT in any autoloaded state, not a literal.
		$this->assertNotContains(
			$autoload,
			array( 'yes', 'on', 'auto', 'auto-on' ),
			"short-lived credential records must NOT be autoloaded (autoload = '{$autoload}')"
		);
	}

	/**
	 * take_record() is single-use: the second take of the same key returns null
	 * (the row is consumed on first take). This is the replay guard.
	 */
	public function test_take_is_single_use() {
		$key = Connect_Page::EXCHANGE_OPTION_PREFIX . 'single';
		$this->put( $key, array( 'v' => 'once' ), 120 );

		$this->assertSame( array( 'v' => 'once' ), $this->take( $key ) );
		$this->assertNull( $this->take( $key ), 'second take must find nothing (single-use)' );
	}

	/**
	 * take_record() of a never-written key returns null.
	 */
	public function test_take_missing_key_returns_null() {
		$this->assertNull( $this->take( Connect_Page::EXCHANGE_OPTION_PREFIX . 'never-written' ) );
	}

	/**
	 * An expired record returns null (the embedded expires_at replaces transient
	 * auto-expiry).
	 */
	public function test_take_expired_record_returns_null() {
		$key = Connect_Page::EXCHANGE_OPTION_PREFIX . 'expired';
		$this->put( $key, array( 'v' => 'stale' ), 120 );

		// Back-date the embedded expiry into the past.
		$record               = get_option( $key );
		$record['expires_at'] = time() - 10;
		update_option( $key, $record, false );

		$this->assertNull( $this->take( $key ) );
	}

	// ──────────────────────────────────────────────────────────────────────
	// gc_records() — opportunistic sweep.
	// ──────────────────────────────────────────────────────────────────────

	/**
	 * Forced GC removes expired exchange/paste records and keeps live ones.
	 */
	public function test_gc_removes_expired_keeps_live() {
		$live    = Connect_Page::EXCHANGE_OPTION_PREFIX . 'live';
		$expired = Connect_Page::PASTE_OPTION_PREFIX . 'dead';

		$this->put( $live, array( 'v' => 'keep' ), 120 );
		$this->put( $expired, array( 'v' => 'drop' ), 120 );
		$rec               = get_option( $expired );
		$rec['expires_at'] = time() - 1;
		update_option( $expired, $rec, false );

		$this->page->gc_records( true );

		$this->assertFalse( get_option( $expired, false ), 'expired record must be swept' );
		$this->assertIsArray( get_option( $live ), 'live record must survive GC' );
	}

	/**
	 * The GC throttle marker must survive the sweep.
	 *
	 * Regression guard: the marker key must live OUTSIDE the swept prefixes — if
	 * it started with the exchange prefix, gc_records() would delete its own
	 * throttle marker every run and never throttle.
	 */
	public function test_gc_does_not_sweep_its_own_marker() {
		$this->page->gc_records( true );
		$this->assertNotFalse( get_option( 'gk_block_api_cred_gc_at', false ), 'the GC marker must not be swept' );
	}

	// ──────────────────────────────────────────────────────────────────────
	// store_exchange_code() / redeem_exchange_code() — end-to-end.
	// ──────────────────────────────────────────────────────────────────────

	private function store( array $creds ) {
		return $this->call( 'store_exchange_code', array( $creds ) );
	}

	private function redeem( $code ) {
		return $this->call( 'redeem_exchange_code', array( $code ) );
	}

	/**
	 * @return array{0:string,1:string,2:string} site, user, password fixture.
	 */
	private function creds(): array {
		return array(
			'url'      => 'https://example.com',
			'user'     => 'block-mcp',
			'password' => 'plain-secret-pw-123',
		);
	}

	/**
	 * The minted password is sealed at rest (never plaintext in wp_options), the
	 * non-secret site/user stay readable, and redemption returns the original.
	 */
	public function test_store_seals_password_at_rest_and_round_trips() {
		$code = $this->store( $this->creds() );
		$this->assertNotEmpty( $code );

		$stored = get_option( Connect_Page::EXCHANGE_OPTION_PREFIX . hash( 'sha256', $code ) );
		$this->assertIsArray( $stored );
		$this->assertStringStartsWith( Connect_Page::SEAL_PREFIX, $stored['password'], 'stored password must be sealed' );
		$this->assertStringNotContainsString( 'plain-secret-pw-123', $stored['password'] );
		$this->assertSame( 'https://example.com', $stored['site'] );
		$this->assertSame( 'block-mcp', $stored['user'] );

		$redeemed = $this->redeem( $code );
		$this->assertSame( 'plain-secret-pw-123', $redeemed['password'] );
		$this->assertSame( 'https://example.com', $redeemed['site'] );
		$this->assertSame( 'block-mcp', $redeemed['user'] );
	}

	/**
	 * Redemption is single-use: a replay of the same code returns null.
	 */
	public function test_redeem_is_single_use() {
		$code = $this->store( $this->creds() );
		$this->assertIsArray( $this->redeem( $code ) );
		$this->assertNull( $this->redeem( $code ), 'replaying the code must return null' );
	}

	/**
	 * An unknown / empty code returns null.
	 */
	public function test_redeem_unknown_or_empty_code_returns_null() {
		$this->assertNull( $this->redeem( 'never-issued-code' ) );
		$this->assertNull( $this->redeem( '' ) );
	}

	/**
	 * Tampering with the sealed password in storage makes redemption reject the
	 * whole credential set (null), not hand back a corrupt password.
	 */
	public function test_redeem_rejects_tampered_stored_password() {
		$code = $this->store( $this->creds() );
		$key  = Connect_Page::EXCHANGE_OPTION_PREFIX . hash( 'sha256', $code );

		$stored          = get_option( $key );
		$sealed          = $stored['password'];
		$pos             = strlen( Connect_Page::SEAL_PREFIX ) + 4;
		$sealed[ $pos ]  = ( 'A' === $sealed[ $pos ] ) ? 'B' : 'A';
		$stored['password'] = $sealed;
		update_option( $key, $stored, false );

		$this->assertNull( $this->redeem( $code ) );
	}

	/**
	 * An expired exchange record returns null on redemption.
	 */
	public function test_redeem_expired_record_returns_null() {
		$code = $this->store( $this->creds() );
		$key  = Connect_Page::EXCHANGE_OPTION_PREFIX . hash( 'sha256', $code );

		$stored               = get_option( $key );
		$stored['expires_at'] = time() - 5;
		update_option( $key, $stored, false );

		$this->assertNull( $this->redeem( $code ) );
	}
}
