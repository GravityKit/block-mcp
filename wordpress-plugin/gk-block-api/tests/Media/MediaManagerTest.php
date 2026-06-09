<?php
/**
 * Tests for the Media_Manager class.
 *
 * Drives the three upload modes (multipart / URL sideload / base64) against
 * real WordPress: real wp_handle_upload, real media_handle_sideload, real
 * download_url. The URL path is intercepted with the pre_http_request filter
 * so no network traffic leaves CI.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Media_Manager;

class MediaManagerTest extends WP_UnitTestCase {

	/** @var Media_Manager */
	private $mm;

	public function set_up(): void {
		parent::set_up();
		$this->mm = new Media_Manager();
		$_FILES   = array();
	}

	public function tear_down(): void {
		$_FILES = array();
		// WP_UnitTestCase backs up + restores $wp_filter around each test, so
		// per-test add_filter() calls don't normally leak. The explicit
		// remove_all_filters here is belt-and-braces against the specific
		// hooks this file attaches — useful if a future base-class change
		// drops the hook-backup behavior.
		remove_all_filters( 'upload_size_limit' );
		remove_all_filters( 'gk/block-mcp/media/upload-overrides' );
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	public function test_requires_one_input_mode() {
		$result = $this->mm->upload( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'missing_file', $result->get_error_code() );
	}

	public function test_rejects_multiple_input_modes() {
		$result = $this->mm->upload( array(
			'url'         => 'https://example.com/x.png',
			'data_base64' => 'aGVsbG8=',
			'filename'    => 'x.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'multiple_inputs', $result->get_error_code() );
	}

	// ── base64 path ──

	public function test_base64_requires_filename() {
		$result = $this->mm->upload( array( 'data_base64' => base64_encode( 'png' ) ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_filename', $result->get_error_code() );
	}

	public function test_base64_rejects_invalid_data() {
		$result = $this->mm->upload( array(
			'data_base64' => '!!!not-base64!!!',
			'filename'    => 'x.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_base64', $result->get_error_code() );
	}

	public function test_base64_rejects_disallowed_mime() {
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( '<?php echo "x"; ?>' ),
			'filename'    => 'shell.php',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'disallowed_mime', $result->get_error_code() );
	}

	public function test_base64_happy_path() {
		$png    = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( $png ),
			'filename'    => 'sample.png',
			'alt_text'    => 'sample',
			'title'       => 'My Sample',
			'caption'     => 'cap',
			'description' => 'desc',
			'post_id'     => self::factory()->post->create(),
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'sample', $result['alt_text'] );
		$this->assertSame( 'My Sample', $result['title'] );
		$this->assertSame( 'cap', $result['caption'] );
		$this->assertSame( 'desc', $result['description'] );
		$this->assertGreaterThan( 0, $result['post_parent'] );
	}

	public function test_base64_enforces_max_upload_size() {
		// Cap upload size to 8 bytes via the documented filter.
		add_filter( 'upload_size_limit', static fn() => 8 );
		$png = file_get_contents( __DIR__ . '/../fixtures/sample.png' );
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( $png ),
			'filename'    => 'sample.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'file_too_large', $result->get_error_code() );
	}

	// ── multipart path ──

	public function test_multipart_happy_path() {
		$src = __DIR__ . '/../fixtures/sample.png';
		$tmp = tempnam( sys_get_temp_dir(), 'multipart' );
		copy( $src, $tmp );
		$_FILES['file'] = array(
			'name'     => 'sample.png',
			'type'     => 'image/png',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		// _wp_handle_upload() short-circuits to `is_readable()` instead of
		// `is_uploaded_file()` whenever the action is anything other than
		// the literal 'wp_handle_upload'. The plugin's
		// gk/block-mcp/media/upload-overrides filter lets us swap to the
		// sideload action so PHPUnit-staged temp files reach the rest of
		// the pipeline.
		add_filter( 'gk/block-mcp/media/upload-overrides', static function ( $overrides ) {
			$overrides['action'] = 'wp_handle_sideload';
			return $overrides;
		} );

		$result = $this->mm->upload( array( 'file_field' => 'file', 'alt_text' => 'alt' ) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'alt', $result['alt_text'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	public function test_multipart_rejects_disallowed_mime() {
		$tmp = tempnam( sys_get_temp_dir(), 'shell' );
		file_put_contents( $tmp, '<?php' );
		$_FILES['file'] = array(
			'name'     => 'shell.php',
			'type'     => 'application/x-php',
			'tmp_name' => $tmp,
			'error'    => 0,
			'size'     => filesize( $tmp ),
		);
		$result = $this->mm->upload( array( 'file_field' => 'file' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'disallowed_mime', $result->get_error_code() );
	}

	// ── url path ──

	public function test_url_rejects_invalid_scheme() {
		$result = $this->mm->upload( array( 'url' => 'ftp://example.com/x.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_url_happy_path() {
		// RFC5737 documentation IP — passes the SSRF guard's blocklist.
		// Intercept the HTTP fetch with pre_http_request so no real network
		// traffic leaves the runner.
		$src = __DIR__ . '/../fixtures/sample.png';
		$url = 'https://203.0.113.1/test.png';
		$bytes = file_get_contents( $src );

		add_filter( 'pre_http_request', static function ( $preempt, $args, $request_url ) use ( $url, $bytes ) {
			if ( $request_url !== $url ) {
				return $preempt;
			}
			// download_url() opens a tempfile and asks WP to stream the body
			// into it. If `filename` is set in $args we satisfy the stream
			// contract by writing the bytes ourselves and returning a 200.
			if ( ! empty( $args['filename'] ) ) {
				file_put_contents( $args['filename'], $bytes );
			}
			return array(
				'headers'  => array( 'content-type' => 'image/png' ),
				'body'     => '',
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
				'filename' => $args['filename'] ?? null,
			);
		}, 10, 3 );

		$result = $this->mm->upload( array(
			'url'      => $url,
			'alt_text' => 'remote',
			'filename' => 'remote.png',
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	public function test_url_propagates_fetch_failure() {
		// No pre_http_request filter set — real HTTP fetch will fail because
		// 203.0.113.99 is unreachable. Wrapped as url_fetch_failed (502).
		add_filter( 'pre_http_request', static function () {
			return new \WP_Error( 'http_request_failed', 'connection refused' );
		}, 10, 0 );
		$result = $this->mm->upload( array( 'url' => 'https://203.0.113.99/x.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'url_fetch_failed', $result->get_error_code() );
	}

	public function test_url_blocks_link_local_metadata() {
		// AWS/GCP/Azure cloud metadata endpoint — must be rejected.
		$result = $this->mm->upload( array( 'url' => 'http://169.254.169.254/latest/meta-data/' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_url_blocks_rfc1918_private() {
		$result = $this->mm->upload( array( 'url' => 'http://10.0.0.5/admin.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	public function test_url_blocks_loopback() {
		$result = $this->mm->upload( array( 'url' => 'http://127.0.0.1/x.png' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}
}
