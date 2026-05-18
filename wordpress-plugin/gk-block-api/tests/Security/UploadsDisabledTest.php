<?php
/**
 * Hard kill-switch tests for the media-upload pipeline.
 *
 * Pins the contract that when `gk_block_api_uploads_enabled` is false (or
 * the matching filter returns false), every upload mode returns HTTP 403
 * `uploads_disabled` *before* any disk I/O, DNS lookup, or HTTP fetch.
 * The validation must short-circuit at the entry point — a half-validated
 * upload that touches disk before rejection is a footgun.
 *
 * @package GravityKit\BlockAPI\Tests\Security
 */

declare( strict_types=1 );

use GravityKit\BlockAPI\Media_Manager;

class UploadsDisabledTest extends WP_UnitTestCase {

	/** @var Media_Manager */
	private $mm;

	protected function setUp(): void {
		parent::setUp();
		$this->mm = new Media_Manager();
		$_FILES = array();
	}

	protected function tearDown(): void {
		$_FILES = array();
		delete_option( Media_Manager::UPLOADS_OPTION );
		parent::tearDown();
	}

	public function test_default_is_enabled() {
		$this->assertTrue( Media_Manager::uploads_enabled() );
	}

	public function test_option_false_disables_uploads() {
		update_option( Media_Manager::UPLOADS_OPTION, '0' );
		$this->assertFalse( Media_Manager::uploads_enabled() );
	}

	public function test_filter_overrides_option_to_false() {
		update_option( Media_Manager::UPLOADS_OPTION, '1' );
		add_filter( 'gk_block_api_uploads_enabled', '__return_false' );
		$this->assertFalse( Media_Manager::uploads_enabled() );
	}

	public function test_filter_can_override_option_to_true() {
		update_option( Media_Manager::UPLOADS_OPTION, '0' );
		add_filter( 'gk_block_api_uploads_enabled', '__return_true' );
		$this->assertTrue( Media_Manager::uploads_enabled() );
	}

	public function test_disabled_blocks_base64_path() {
		update_option( Media_Manager::UPLOADS_OPTION, '0' );
		$result = $this->mm->upload( array(
			'data_base64' => base64_encode( 'fake-png-bytes' ),
			'filename'    => 'evil.png',
		) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_disabled_blocks_url_path_before_any_dns_lookup() {
		update_option( Media_Manager::UPLOADS_OPTION, '0' );

		// If the kill-switch fails to short-circuit, the URL handler will
		// reach pre_http_request — fail loudly if it does so the test
		// surfaces the regression.
		$reached_http = false;
		add_filter( 'pre_http_request', static function ( $preempt ) use ( &$reached_http ) {
			$reached_http = true;
			return $preempt;
		} );

		$result = $this->mm->upload( array( 'url' => 'https://203.0.113.5/x.png' ) );
		$this->assertFalse( $reached_http, 'kill-switch must short-circuit BEFORE any HTTP request' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	public function test_disabled_blocks_multipart_path_without_touching_files_array() {
		update_option( Media_Manager::UPLOADS_OPTION, '0' );
		// Set up a fake $_FILES entry to prove the handler never reached
		// the disk-touching code: if it had, we'd see "Specified file
		// failed upload test" instead of uploads_disabled.
		$_FILES['file'] = array(
			'name'     => 'x.png',
			'type'     => 'image/png',
			'tmp_name' => __DIR__ . '/../fixtures/sample.png',
			'error'    => 0,
			'size'     => filesize( __DIR__ . '/../fixtures/sample.png' ),
		);
		$result = $this->mm->upload( array( 'file_field' => 'file' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	public function test_disabled_short_circuits_before_input_mode_validation() {
		// Empty args would normally produce 'missing_file' — but the
		// kill-switch outranks input validation so an attacker can't
		// fingerprint whether uploads exist on the site.
		update_option( Media_Manager::UPLOADS_OPTION, '0' );
		$result = $this->mm->upload( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'uploads_disabled', $result->get_error_code() );
	}

	public function test_re_enabling_restores_normal_validation() {
		update_option( Media_Manager::UPLOADS_OPTION, '0' );
		$this->assertInstanceOf( \WP_Error::class, $this->mm->upload( array() ) );

		update_option( Media_Manager::UPLOADS_OPTION, '1' );
		$result = $this->mm->upload( array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		// Normal "missing input" error path resumes.
		$this->assertSame( 'missing_file', $result->get_error_code() );
	}
}
