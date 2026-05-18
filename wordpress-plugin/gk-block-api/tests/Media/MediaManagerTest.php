<?php
/**
 * Tests for the Media_Manager class.
 *
 * Validation paths and stub-based base64 happy path. Real upload integration
 * (multipart from a real HTTP request, sideload of remote URLs, intermediate
 * size generation) is exercised by the gkclone E2E smoke.
 *
 * @package GravityKit\BlockAPI\Tests
 */

use GravityKit\BlockAPI\Media_Manager;

class MediaManagerTest extends WP_UnitTestCase {

	/** @var Media_Manager */
	private $mm;

	protected function setUp(): void {
		$GLOBALS['_gk_test_posts']            = array();
		$GLOBALS['_gk_test_post_meta']        = array();
		$GLOBALS['_gk_test_attached_files']   = array();
		$GLOBALS['_gk_test_attachment_meta']  = array();
		$GLOBALS['_gk_test_url_responses']    = array();
		$GLOBALS['_gk_test_next_post_id']     = 5000;
		$GLOBALS['_gk_test_max_upload_size']  = 26214400;
		// Reset $_FILES superglobal between tests (read+write in one expression
		// so intelephense recognizes it as used, not just declared).
		foreach ( array_keys( $_FILES ) as $field ) {
			unset( $_FILES[ $field ] );
		}
		$this->mm = new Media_Manager();
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
			'post_id'     => 99,
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertTrue( $result['success'] );
		$this->assertGreaterThan( 0, $result['id'] );
		$this->assertSame( 'image/png', $result['mime_type'] );
		$this->assertSame( 'sample', $result['alt_text'] );
		$this->assertSame( 'My Sample', $result['title'] );
		$this->assertSame( 'cap', $result['caption'] );
		$this->assertSame( 'desc', $result['description'] );
		$this->assertSame( 99, $result['post_parent'] );
	}

	public function test_base64_enforces_max_upload_size() {
		$GLOBALS['_gk_test_max_upload_size'] = 8;
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
		$result = $this->mm->upload( array( 'file_field' => 'file', 'alt_text' => 'alt' ) );
		$this->assertIsArray( $result );
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
		// Use a TEST-NET-3 documentation IP (RFC5737 203.0.113.0/24) — public
		// space, not in any SSRF-blocked range, and (when used as a literal in
		// the URL) bypasses DNS resolution.
		$src = __DIR__ . '/../fixtures/sample.png';
		$tmp = tempnam( sys_get_temp_dir(), 'fetched' );
		copy( $src, $tmp );
		$url = 'https://203.0.113.1/test.png';
		$GLOBALS['_gk_test_url_responses'][ $url ] = $tmp;

		$result = $this->mm->upload( array(
			'url'      => $url,
			'alt_text' => 'remote',
			'filename' => 'remote.png',
		) );
		$this->assertIsArray( $result, is_object( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'image/png', $result['mime_type'] );
	}

	public function test_url_propagates_fetch_failure() {
		// Public IP (RFC5737 documentation), no fixture → download_url fails →
		// wrapped as url_fetch_failed (502).
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
