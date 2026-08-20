<?php
/**
 * Bootstrap buffering for the installer download.
 *
 * The .mcpb is a zip and has to be the whole response body. Themes and plugins
 * load after this plugin, and a stray newline after a closing PHP tag in any of
 * them is enough to corrupt the archive. On a server with no output buffering
 * of its own that output is already sent by the time the download handler runs,
 * so the handler's discard sweep has nothing left to drop and can only refuse
 * the download. Opening a buffer at plugin-load time keeps it recoverable.
 *
 * Contracts pinned here:
 *
 *  - buffer_installer_response() opens a buffer for the installer download.
 *  - The buffer is removable, which is what lets the discard sweep drop it.
 *  - Output printed after it opens is captured rather than sent.
 *  - Nothing is buffered for any other request, including our own other
 *    actions, front-end requests, and non-scalar input.
 *  - The bootstrap constant matches the action Connect_Page registers.
 *  - The bootstrap calls the function at file scope, before any theme loads.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Connect_Page;

use function GravityKit\BlockMCP\buffer_installer_response;

use const GravityKit\BlockMCP\CONNECT_DOWNLOAD_ACTION;

/**
 * Tests for the installer download buffer.
 *
 * @covers ::GravityKit\BlockMCP\buffer_installer_response
 */
class ConnectInstallerBufferTest extends WP_UnitTestCase {

	/**
	 * Buffer level before the test opened anything.
	 *
	 * @var int
	 */
	private $baseline = 0;

	public function set_up() {
		parent::set_up();
		$this->baseline = ob_get_level();
	}

	public function tear_down() {
		// Never leave a buffer behind for whatever runs next.
		while ( ob_get_level() > $this->baseline ) {
			ob_end_clean();
		}

		unset( $_POST['action'], $_GET['action'], $_REQUEST['action'] );
		set_current_screen( 'front' );

		parent::tear_down();
	}

	/**
	 * Put the request in the admin, where admin-post.php runs.
	 *
	 * @param mixed  $action Value for the action parameter.
	 * @param string $method Which superglobal carries it — 'post' or 'get'.
	 *
	 * @return void
	 */
	private function admin_request( $action, string $method = 'post' ) {
		set_current_screen( 'dashboard' );

		if ( null !== $action ) {
			if ( 'post' === $method ) {
				$_POST['action'] = $action;
			} else {
				$_GET['action'] = $action;
			}
		}
	}

	/**
	 * The installer download must be buffered, so output printed by anything
	 * loading after this plugin can still be discarded before the zip is sent.
	 */
	public function test_installer_download_is_buffered() {
		$this->admin_request( CONNECT_DOWNLOAD_ACTION );

		$opened = buffer_installer_response();

		$this->assertTrue( $opened, 'the installer download must open a buffer' );
		$this->assertSame( $this->baseline + 1, ob_get_level(), 'exactly one buffer must be opened' );
	}

	/**
	 * admin-post.php dispatches GET as well as POST, so both must be recognised.
	 */
	public function test_installer_download_is_buffered_for_a_get_request() {
		$this->admin_request( CONNECT_DOWNLOAD_ACTION, 'get' );

		$this->assertTrue( buffer_installer_response(), 'a GET download must open a buffer too' );
	}

	/**
	 * That buffer is only useful if the sweep can drop it — a buffer opened
	 * without PHP_OUTPUT_HANDLER_REMOVABLE could not be popped, and the stray
	 * output would survive into the archive.
	 */
	public function test_the_buffer_is_removable() {
		$this->admin_request( CONNECT_DOWNLOAD_ACTION );

		buffer_installer_response();
		$status = ob_get_status();
		$flags  = isset( $status['flags'] ) ? (int) $status['flags'] : 0;

		$this->assertTrue(
			(bool) ( $flags & PHP_OUTPUT_HANDLER_REMOVABLE ),
			'the installer buffer must be removable so the discard sweep can drop it'
		);
	}

	/**
	 * Stray output really does end up inside the buffer, where the sweep can
	 * reach it — this is the whole point of opening it at load time.
	 */
	public function test_output_printed_afterwards_lands_in_the_buffer() {
		$this->admin_request( CONNECT_DOWNLOAD_ACTION );

		buffer_installer_response();
		echo "\n\n\n\n";

		$this->assertSame( 4, ob_get_length(), 'later output must be captured, not sent' );
	}

	/**
	 * Every other request must be left alone. Buffering more than the installer
	 * would hold unrelated responses in memory until shutdown, including any
	 * front-end request an anonymous visitor can shape.
	 *
	 * @dataProvider provide_untouched_requests
	 *
	 * @param mixed $action Value for the action parameter, or null to omit it.
	 */
	public function test_other_admin_requests_are_not_buffered( $action ) {
		$this->admin_request( $action );

		$opened = buffer_installer_response();

		$this->assertFalse( $opened, 'only the installer download may be buffered' );
		$this->assertSame( $this->baseline, ob_get_level(), 'no buffer may be opened' );
	}

	/**
	 * Requests that must not be buffered.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public function provide_untouched_requests(): array {
		return array(
			'no action at all'         => array( null ),
			'another plugin s action'  => array( 'woocommerce_do_thing' ),
			'our authorize action'     => array( Connect_Page::ACTION_AUTHORIZE ),
			'our revoke action'        => array( Connect_Page::ACTION_REVOKE ),
			'a lookalike prefix'       => array( 'gk_block_api_connect_evil' ),
			'a sanitizer-only match'   => array( 'gk_block_api_connect!' ),
			'an array instead of text' => array( array( 'gk_block_api_connect' ) ),
		);
	}

	/**
	 * A front-end request must never be buffered, however it is shaped. The
	 * action is a public query parameter, so anyone can put it on any URL.
	 */
	public function test_front_end_requests_are_never_buffered() {
		$_GET['action'] = CONNECT_DOWNLOAD_ACTION;
		set_current_screen( 'front' );

		$this->assertFalse( buffer_installer_response(), 'front-end requests must not be buffered' );
		$this->assertSame( $this->baseline, ob_get_level(), 'no buffer may be opened on the front end' );
	}

	/**
	 * The bootstrap holds its own copy of the action so it can recognise the
	 * request without autoloading Connect_Page — an install whose class file is
	 * missing or emptied must reach the admin notice rather than fatal. That
	 * only works while the two agree.
	 */
	public function test_the_bootstrap_action_matches_the_registered_one() {
		$this->assertSame(
			Connect_Page::ACTION_CONNECT,
			CONNECT_DOWNLOAD_ACTION,
			'the bootstrap constant and the registered admin-post action must not drift apart'
		);
	}

	/**
	 * The call has to happen at file scope while the plugin loads. Moved into a
	 * hook, or dropped, the buffer opens too late (or never) and the customer
	 * failure returns — with every other test in this file still green, because
	 * they all invoke the function directly.
	 */
	public function test_the_bootstrap_calls_it_at_file_scope() {
		$bootstrap = file_get_contents( dirname( __DIR__, 2 ) . '/gk-block-mcp.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertMatchesRegularExpression(
			'/^buffer_installer_response\(\);$/m',
			(string) $bootstrap,
			'the bootstrap must call buffer_installer_response() at file scope'
		);
	}
}
