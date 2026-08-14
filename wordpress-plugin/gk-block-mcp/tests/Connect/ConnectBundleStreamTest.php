<?php
/**
 * Connect_Page: the .mcpb download body must be the archive and nothing else.
 *
 * The installer is a zip. Claude Desktop reads it by seeking the "PK" signature
 * at byte zero and the end-of-central-directory record at the tail, so a single
 * byte printed by any other plugin or theme before the download is sent puts the
 * whole archive out of alignment and the bundle is rejected on open.
 *
 * Contracts pinned here:
 *
 *  - stream_bundle() emits the archive byte-for-byte when a plugin has already
 *    echoed into an open output buffer.
 *  - stream_bundle() emits the archive byte-for-byte through several nested
 *    buffers, each of which has output in it.
 *  - stream_bundle() emits the archive byte-for-byte when nothing else printed,
 *    with a Content-Length matching the archive.
 *  - stream_bundle() deletes the temporary archive after sending it.
 *  - stream_bundle() writes nothing, sends no headers, and reports failure when
 *    the response has already started.
 *  - stream_bundle() writes nothing when stray output survives the buffer sweep,
 *    which headers_sent() alone cannot detect.
 *  - stream_bundle() writes nothing when the surviving stray output sits below
 *    an empty buffer, which ob_get_length() alone cannot detect.
 *  - stream_bundle() writes nothing when the header stage itself commits the
 *    response, which happens after the sweep has finished.
 *  - stream_bundle() reports a write that produced no bytes rather than passing
 *    it off as a delivered download.
 *  - the sweep empties a buffer it may only clean, and gives up on one it may
 *    neither clean nor remove.
 *
 * @package GravityKit\BlockMCP\Tests\Connect
 */

declare( strict_types=1 );

use GravityKit\BlockMCP\Connect_Page;

/**
 * Test double exposing the streaming seam and keeping the test's own capture
 * buffer alive while the production discard loop runs.
 */
class Connect_Page_Stream_Spy extends Connect_Page {

	/**
	 * Buffer level the discard loop must not go below.
	 *
	 * @var int
	 */
	public $floor = 0;

	/**
	 * What response_already_started() should report.
	 *
	 * @var bool
	 */
	public $started = false;

	/**
	 * Headers the streaming path asked for.
	 *
	 * @var array<string,string>
	 */
	public $headers = array();

	/**
	 * Whether to stand in for a sweep that could not drop pending output.
	 *
	 * @var bool
	 */
	public $sweep_fails = false;

	/**
	 * Whether to stand in for a write that could not read the archive.
	 *
	 * @var bool
	 */
	public $write_fails = false;

	/**
	 * Whether sending headers should commit the response, as a printing filter would.
	 *
	 * @var bool
	 */
	public $headers_start_response = false;

	/**
	 * Send an archive through the real streaming path.
	 *
	 * @param string $path     Absolute path to the archive.
	 * @param string $filename Filename offered to the browser.
	 *
	 * @return string Empty when the archive was written, otherwise the reason it was not.
	 */
	public function stream( $path, $filename ) {
		return $this->stream_bundle( $path, $filename );
	}

	/**
	 * Keep the capture buffer the test opened.
	 *
	 * @return int
	 */
	protected function output_buffer_floor() {
		return $this->floor;
	}

	/**
	 * Optionally stand in for a buffer PHP will not let the sweep drop.
	 *
	 * @return void
	 */
	protected function discard_output_buffers() {
		if ( $this->sweep_fails ) {
			return;
		}

		parent::discard_output_buffers();
	}

	/**
	 * The test runner's own output must not read as a started response.
	 *
	 * @return bool
	 */
	protected function response_already_started() {
		return $this->started;
	}

	/**
	 * Record the headers instead of emitting them.
	 *
	 * @param string $path          Absolute path to the archive.
	 * @param string $download_name Sanitized filename offered to the browser.
	 *
	 * @return void
	 */
	protected function send_download_headers( $path, $download_name ) {
		if ( $this->headers_start_response ) {
			$this->started = true;
		}

		$this->headers = array(
			'Content-Type'        => 'application/octet-stream',
			'Content-Disposition' => 'attachment; filename="' . $download_name . '"',
			'Content-Length'      => (string) filesize( $path ),
		);
	}

	/**
	 * Optionally stand in for a write that produced nothing.
	 *
	 * @param string $path Absolute path to the file.
	 *
	 * @return int|false
	 */
	protected function emit_file( $path ) {
		if ( $this->write_fails ) {
			return false;
		}

		return parent::emit_file( $path );
	}
}

/**
 * Tests for the .mcpb response body.
 *
 * @covers \GravityKit\BlockMCP\Connect_Page
 */
class ConnectBundleStreamTest extends WP_UnitTestCase {

	/**
	 * Bytes of the archive the tests stream.
	 *
	 * @var string
	 */
	private $archive_bytes;

	public function set_up() {
		parent::set_up();

		// A real zip, so the assertions are about the delivered bytes rather
		// than about a placeholder string that happens to survive.
		$source = wp_tempnam( 'gk-block-mcp-stream-test.zip' );
		$zip    = new ZipArchive();

		$this->assertTrue( true === $zip->open( $source, ZipArchive::OVERWRITE ), 'the fixture archive must be creatable' );
		$zip->addFromString( 'manifest.json', '{"manifest_version":"0.3"}' );
		$zip->close();

		$this->archive_bytes = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		wp_delete_file( $source );
	}

	/**
	 * Write the fixture archive to a fresh temp file for one stream.
	 *
	 * @return string Absolute path.
	 */
	private function stage_archive(): string {
		$path = wp_tempnam( 'gk-block-mcp-stream.mcpb' );
		file_put_contents( $path, $this->archive_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		return $path;
	}

	/**
	 * Stream the fixture archive with $noise already echoed into $depth nested
	 * buffers, and return everything the client would have received.
	 *
	 * @param int    $depth Number of buffers open above the capture buffer.
	 * @param string $noise Text echoed into each of them.
	 *
	 * @return string The response body.
	 */
	private function capture_stream( int $depth, string $noise ): string {
		$page = new Connect_Page_Stream_Spy();
		$path = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();

		for ( $i = 0; $i < $depth; $i++ ) {
			ob_start();
			echo esc_html( $noise );
		}

		$page->stream( $path, 'block-mcp-example.mcpb' );

		return (string) ob_get_clean();
	}

	/**
	 * A plugin that echoes before the download must not corrupt the archive.
	 *
	 * A leading newline is the classic one — a blank line after a closing PHP
	 * tag in some other plugin's file — and it is enough on its own to make the
	 * bundle unreadable.
	 */
	public function test_stream_bundle_drops_buffered_output_from_other_plugins() {
		$body = $this->capture_stream( 1, "\n" );

		$this->assertSame(
			$this->archive_bytes,
			$body,
			'buffered output from another plugin must not reach the .mcpb body'
		);
	}

	/**
	 * Nested buffers must all be discarded, not just the innermost one.
	 */
	public function test_stream_bundle_drops_every_nested_buffer() {
		$body = $this->capture_stream( 3, '<!-- cache -->' );

		$this->assertSame(
			$this->archive_bytes,
			$body,
			'every open output buffer must be discarded before the archive is sent'
		);
	}

	/**
	 * The ordinary case must be unaffected, and Content-Length must describe
	 * the archive — it is read before the file is unlinked.
	 */
	public function test_stream_bundle_sends_the_archive_verbatim() {
		$page = new Connect_Page_Stream_Spy();
		$path = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();
		$failure     = $page->stream( $path, 'block-mcp-example.mcpb' );
		$body        = (string) ob_get_clean();

		$this->assertSame( '', $failure, 'a clean response must report the archive as delivered' );
		$this->assertSame(
			$this->archive_bytes,
			$body,
			'the archive must be delivered byte-for-byte when nothing else printed'
		);
		$this->assertSame(
			(string) strlen( $this->archive_bytes ),
			$page->headers['Content-Length'],
			'Content-Length must match the bytes actually written'
		);
	}

	/**
	 * The bundle carries a plaintext Application Password, so it must not
	 * survive the request that sent it.
	 */
	public function test_stream_bundle_deletes_the_temp_archive() {
		$page = new Connect_Page_Stream_Spy();
		$path = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();
		$page->stream( $path, 'block-mcp-example.mcpb' );
		ob_end_clean();

		$this->assertFileDoesNotExist( $path, 'the streamed bundle must be deleted' );
	}

	/**
	 * Output that has already left the process cannot be recalled, so nothing
	 * may be written and the caller must be told the download did not happen.
	 */
	public function test_stream_bundle_refuses_once_the_response_has_started() {
		$page          = new Connect_Page_Stream_Spy();
		$page->started = true;
		$path          = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();
		$failure     = $page->stream( $path, 'block-mcp-example.mcpb' );
		$body        = (string) ob_get_clean();

		$this->assertNotSame( '', $failure, 'a started response must be reported as not delivered' );
		$this->assertSame( '', $body, 'no archive bytes may be written onto a started response' );
		$this->assertSame( array(), $page->headers, 'a refused download must not send download headers' );
		$this->assertFileDoesNotExist( $path, 'the unsent bundle must not linger on disk' );
	}

	/**
	 * Stray output that survives the sweep must stop the download.
	 *
	 * PHP refuses to pop or empty a buffer opened without the matching flags,
	 * and headers_sent() stays false while its bytes sit there — so the "has
	 * the response started?" question cannot answer this on its own, and
	 * streaming would append the archive behind the stray bytes.
	 *
	 * The sweep is stubbed out rather than reproduced with a real restricted
	 * buffer, because PHP would not let the test close one either: the state
	 * under test is "bytes are still pending above the floor", which is what a
	 * failed sweep leaves behind.
	 */
	public function test_stream_bundle_refuses_when_stray_output_survives_the_sweep() {
		$page             = new Connect_Page_Stream_Spy();
		$page->sweep_fails = true;
		$path             = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();

		ob_start();
		echo 'STRAY';

		$failure = $page->stream( $path, 'block-mcp-example.mcpb' );
		$stranded  = (string) ob_get_clean();

		ob_end_clean();

		$this->assertNotSame( '', $failure, 'stray output that cannot be dropped must abort the download' );
		$this->assertSame( 'STRAY', $stranded, 'no archive bytes may be appended behind stray output' );
		$this->assertSame( array(), $page->headers, 'a refused download must not send download headers' );
		$this->assertFileDoesNotExist( $path, 'the unsent bundle must not linger on disk' );
	}

	/**
	 * Stray output below an empty buffer must be caught too.
	 *
	 * The sweep stops at the first buffer it cannot pop, so a dirty buffer can
	 * be left underneath an empty one. ob_get_length() describes only the
	 * innermost buffer and reports zero for that stack, which would wave the
	 * download through with bytes still queued ahead of the archive.
	 */
	public function test_stream_bundle_refuses_when_stray_output_sits_below_an_empty_buffer() {
		$page              = new Connect_Page_Stream_Spy();
		$page->sweep_fails = true;
		$path              = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();

		ob_start();
		echo 'STRAY';
		ob_start();

		$failure = $page->stream( $path, 'block-mcp-example.mcpb' );

		ob_end_clean();
		$stranded = (string) ob_get_clean();

		ob_end_clean();

		$this->assertNotSame( '', $failure, 'stray output below an empty buffer must abort the download' );
		$this->assertSame( 'STRAY', $stranded, 'no archive bytes may be appended behind stray output' );
		$this->assertSame( array(), $page->headers, 'a refused download must not send download headers' );
		$this->assertFileDoesNotExist( $path, 'the unsent bundle must not linger on disk' );
	}

	/**
	 * The header stage can commit the response, and that must stop the write.
	 *
	 * nocache_headers() runs a filter of its own, and by then the sweep has
	 * already finished — a callback that prints there flushes the response with
	 * no buffer left to catch it, so the archive would follow those bytes.
	 */
	public function test_stream_bundle_refuses_when_sending_headers_commits_the_response() {
		$page                         = new Connect_Page_Stream_Spy();
		$page->headers_start_response = true;
		$path                         = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();
		$failure     = $page->stream( $path, 'block-mcp-example.mcpb' );
		$body        = (string) ob_get_clean();

		$this->assertNotSame( '', $failure, 'a response committed while sending headers must abort the download' );
		$this->assertSame( '', $body, 'no archive bytes may follow output flushed by the header stage' );
		$this->assertFileDoesNotExist( $path, 'the unsent bundle must not linger on disk' );
	}

	/**
	 * A write that produced nothing must be reported, not passed off as sent.
	 *
	 * readfile() signals failure by returning false rather than throwing, so an
	 * unread archive would otherwise look like a delivered download and leave
	 * the credential minted for it live on the site.
	 */
	public function test_stream_bundle_reports_a_write_that_produced_nothing() {
		$page              = new Connect_Page_Stream_Spy();
		$page->write_fails = true;
		$path              = $this->stage_archive();

		ob_start();
		$page->floor = ob_get_level();
		$failure     = $page->stream( $path, 'block-mcp-example.mcpb' );
		$body        = (string) ob_get_clean();

		$this->assertNotSame( '', $failure, 'a write that produced nothing must be reported as not delivered' );
		$this->assertSame( '', $body, 'a failed write must leave the response body empty' );
		$this->assertFileDoesNotExist( $path, 'the undelivered bundle must not linger on disk' );
	}

	/**
	 * The sweep must treat a buffer by what its flags actually permit.
	 *
	 * A buffer opened without PHP_OUTPUT_HANDLER_REMOVABLE cannot be popped or
	 * flush-closed by anyone, this process included, so these run in a child
	 * process that exits with the buffer still open.
	 *
	 * @dataProvider provide_restricted_buffers
	 *
	 * @param string $flags    Flag set the child opens its buffer with.
	 * @param bool   $expected Whether the response should read as clean after the sweep.
	 * @param int    $pending  Bytes expected to remain above the floor.
	 */
	public function test_sweep_handles_buffers_it_cannot_remove( string $flags, bool $expected, int $pending ) {
		$fixture = dirname( __DIR__ ) . '/fixtures/connect-buffer-sweep.php';
		$command = escapeshellarg( PHP_BINARY ) . ' -d output_buffering=0 ' . escapeshellarg( $fixture ) . ' ' . escapeshellarg( $flags ) . ' 2>&1 1>/dev/null';

		$verdict = json_decode( (string) shell_exec( $command ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec

		$this->assertIsArray( $verdict, 'the buffer probe must return a JSON verdict' );
		$this->assertSame( $expected, $verdict['clean'], 'the guard must reflect what the sweep could actually drop' );
		$this->assertSame( $pending, $verdict['pending'], 'bytes left above the floor must match what the flags allow' );
	}

	/**
	 * Buffer flag sets and the verdict each must produce.
	 *
	 * @return array<string,array{0:string,1:bool,2:int}>
	 */
	public function provide_restricted_buffers(): array {
		return array(
			'neither removable nor cleanable' => array( 'none', false, 5 ),
			'cleanable but not removable'     => array( 'cleanable', true, 0 ),
			'flushable but not cleanable'     => array( 'flushable', false, 5 ),
		);
	}
}
